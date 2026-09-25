<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\User;
use App\Notifications\UstawienieNowegoHasla;
use App\Support\KluczeLimitow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * KAŻDY formularz, który sprawdza hasło przed zalogowaniem, liczy próby po
 * KONCIE — nie tylko `/login`.
 *
 * CO BYŁO ZEPSUTE (zmierzone na `61686213`)
 * `/odwolanie` i `/cofnij-usuniecie-konta` robią `User::findByLogin()` plus
 * `Hash::check()` na DOWOLNYM koncie, a chronił je wyłącznie
 * `throttle:5,60` liczony po adresie IP. Sześćdziesiąt prób hasła do jednego
 * konta z sześćdziesięciu różnych adresów kończyło się ZEREM odmów na
 * obu tych trasach, podczas gdy `/login` odmawia przy piętnastej. Napastnik
 * nie musiał więc ruszać `/login` — miał obok dwie takie same wyrocznie bez
 * licznika konta, a przy `/odwolanie` także bez Turnstile.
 *
 * CZEGO TEN TEST PILNUJE — CZTERY RZECZY, BO SAMA BLOKADA TO ZA MAŁO
 *
 *  1. ŻE CHRONI: rozproszony atak po wielu adresach zatrzymuje się na obu
 *     formularzach, i to na tym samym liczniku co `/login`, a nie na trzech
 *     osobnych wiadrach po 15 prób (bo trzy wiadra to 45 prób na kwadrans,
 *     czyli ochrona z nazwy).
 *  2. ŻE NIE KRZYWDZI: człowiek, który pomyli hasło kilka razy pod rząd
 *     z jednego miejsca, dalej złoży odwołanie. Prawdziwe odwołanie składa
 *     się raz, a koszyk pary ma 5 prób na minutę.
 *  3. ŻE JEST DROGA WYJŚCIA: koszyk konta z definicji pozwala OBCEMU
 *     zamknąć cudze konto, więc udany reset hasła zdejmuje tę blokadę,
 *     a komunikat nazywa szybszą drogę (logowanie linkiem).
 *  4. ŻE KOMUNIKAT NIE JEST WYROCZNIĄ: to samo zdanie dla konta, którego
 *     nie ma.
 *
 * Progów nie ruszamy — liczby pochodzą z `config/kuking.php` →
 * `login_limits` i są tu CZYTANE, nie wpisane.
 */
class LimitHaselPrzedLogowaniemTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = 'basia@example.com';

    private const HASLO = 'prawdziwe-haslo-basi';

    private function ofiara(): User
    {
        return $this->user('basia', [
            'email' => self::LOGIN,
            'password' => Hash::make(self::HASLO),
        ]);
    }

    /** Ile prób na konto wolno — z konfiguracji, nie z pamięci. */
    private function probyNaKonto(): int
    {
        return (int) config('kuking.login_limits.konto.proby');
    }

    private function adres(int $i): array
    {
        return ['X-Forwarded-For' => '203.0.113.'.(($i % 250) + 1)];
    }

    private function zleHaslo(string $trasa, int $i, array $dodatkowe = []): TestResponse
    {
        return $this->post($trasa, [
            'login' => self::LOGIN,
            'password' => 'zgaduje-'.$i,
            ...$dodatkowe,
        ], $this->adres($i));
    }

    private function odwolanie(int $i): TestResponse
    {
        return $this->zleHaslo('/odwolanie', $i, [
            'body' => 'Uważam, że decyzja jest błędna i proszę o ponowne rozpatrzenie sprawy.',
        ]);
    }

    private function cofniecie(int $i): TestResponse
    {
        return $this->zleHaslo('/cofnij-usuniecie-konta', $i);
    }

    /**
     * Treść błędu przy polu `login`, niezależnie od tego, w jakiej postaci
     * leży w sesji.
     *
     * W tym repozytorium sesja testowa oddaje worek błędów RAZ jako
     * `ViewErrorBag`, a RAZ jako zwykłą tablicę — pierwsza wersja tego
     * pomocnika czytała tylko tę pierwszą postać i dla drugiej zwracała
     * pusty ciąg. Wszystkie asercje niżej przechodziły wtedy „bo nic nie
     * znalazły". Ten sam kształt pułapki co w `PodrobionyNaglowekProxyTest`.
     */
    private function komunikat(TestResponse $odpowiedz): string
    {
        $bledy = $odpowiedz->getSession()->get('errors');

        if ($bledy instanceof ViewErrorBag || $bledy instanceof MessageBag) {
            return (string) $bledy->first('login');
        }

        return is_array($bledy)
            ? (string) json_encode($bledy, JSON_UNESCAPED_UNICODE)
            : '';
    }

    private function zatrzymany(TestResponse $odpowiedz): bool
    {
        return $odpowiedz->getStatusCode() === 429
            || str_contains($this->komunikat($odpowiedz), 'Za dużo prób');
    }

    // -----------------------------------------------------------------
    // 1. CZY CHRONI
    // -----------------------------------------------------------------

    public function test_odwolanie_zatrzymuje_rozproszony_atak_na_jedno_konto(): void
    {
        $this->ofiara();

        $proby = $this->probyNaKonto();
        $zatrzymano = false;

        // O JEDNĄ WIĘCEJ, niż wolno: ostatnia MUSI już dostać odmowę.
        for ($i = 1; $i <= $proby + 1; $i++) {
            if ($this->zatrzymany($this->odwolanie($i))) {
                $zatrzymano = true;
                break;
            }
        }

        $this->assertTrue(
            $zatrzymano,
            $proby.' prób hasła do jednego konta z tylu samo różnych adresów nie wywołało na '
            .'`/odwolanie` żadnej odmowy. Ten formularz sprawdza hasło do dowolnego konta, '
            .'a licznik po adresie IP nie widzi ataku rozproszonego.',
        );
    }

    public function test_cofniecie_usuniecia_zatrzymuje_rozproszony_atak_na_jedno_konto(): void
    {
        $this->ofiara();

        $proby = $this->probyNaKonto();
        $zatrzymano = false;

        for ($i = 1; $i <= $proby + 1; $i++) {
            if ($this->zatrzymany($this->cofniecie($i))) {
                $zatrzymano = true;
                break;
            }
        }

        $this->assertTrue(
            $zatrzymano,
            $proby.' prób hasła do jednego konta z tylu samo różnych adresów nie wywołało na '
            .'`/cofnij-usuniecie-konta` żadnej odmowy.',
        );
    }

    /**
     * JEDEN LICZNIK NA TRZY FORMULARZE, nie trzy osobne.
     *
     * To jest sedno naprawy. Gdyby każda trasa dostała własne wiadro,
     * napastnik miałby 3 × 15 prób na kwadrans zamiast 15 — czyli ochronę
     * wyłącznie z nazwy. Budżet jest wspólny, bo czynność jest jedna:
     * zgadywanie tego samego hasła do tego samego konta.
     */
    public function test_trzy_formularze_dziela_jeden_budzet_prob_na_konto(): void
    {
        $this->ofiara();

        $proby = $this->probyNaKonto();

        // Cały budżet wypalony NA ODWOŁANIU, z różnych adresów.
        for ($i = 1; $i <= $proby; $i++) {
            $this->odwolanie($i);
        }

        $this->assertTrue(
            $this->zatrzymany($this->cofniecie(900)),
            '`/cofnij-usuniecie-konta` przyjęło próbę mimo wyczerpanego budżetu konta na '
            .'`/odwolanie` — to są trzy osobne wiadra, czyli potrójny budżet dla napastnika.',
        );

        $this->assertTrue(
            $this->zatrzymany($this->post('/login', [
                'login' => self::LOGIN,
                'password' => 'zgaduje-dalej',
            ], $this->adres(901))),
            '`/login` przyjęło próbę mimo wyczerpanego budżetu konta na `/odwolanie`.',
        );
    }

    // -----------------------------------------------------------------
    // 2. CZY NIE KRZYWDZI
    // -----------------------------------------------------------------

    /**
     * Osoba, która pomyliła hasło kilka razy pod rząd z JEDNEGO miejsca,
     * dalej składa odwołanie. Koszyk pary ma 5 prób na minutę, a odwołanie
     * składa się raz — cztery pomyłki i trafienie za piątym razem to górny
     * brzeg tego, co zdarza się człowiekowi, nie wzorzec bota.
     */
    public function test_cztery_pomylki_z_jednego_miejsca_nie_zamykaja_odwolania(): void
    {
        $osoba = $this->ofiara();
        $decyzja = $this->decyzjaDoOdwolania($osoba);

        for ($i = 1; $i <= 4; $i++) {
            $odpowiedz = $this->post('/odwolanie', [
                'login' => self::LOGIN,
                'password' => 'pomylka-'.$i,
                'body' => 'Uważam, że decyzja jest błędna i proszę o ponowne rozpatrzenie sprawy.',
            ], ['X-Forwarded-For' => '198.51.100.5']);

            $this->assertFalse(
                $this->zatrzymany($odpowiedz),
                'Pomyłka nr '.$i.' z jednego miejsca już zamknęła formularz odwołania. '
                .'Limit, który łapie zwykłego człowieka, jest gorszy niż jego brak.',
            );
        }

        $this->post('/odwolanie', [
            'login' => self::LOGIN,
            'password' => self::HASLO,
            'body' => 'Uważam, że decyzja jest błędna i proszę o ponowne rozpatrzenie sprawy.',
        ], ['X-Forwarded-For' => '198.51.100.5'])->assertRedirect();

        $this->assertTrue(
            $decyzja->authorAppeal()->exists(),
            'Piąta próba z poprawnym hasłem nie złożyła odwołania — limit zjadł prawdziwą sprawę.',
        );
    }

    /**
     * DOBRE HASŁO CZYŚCI KOSZYK PARY I KONTA. Bez tego osoba, która
     * pomyliła się cztery razy i trafiła za piątym, zostawałaby z pełnym
     * licznikiem — na `/login` i na obu tych formularzach.
     */
    public function test_trafienie_haslem_zeruje_licznik_tej_osoby(): void
    {
        $osoba = $this->ofiara();
        $this->decyzjaDoOdwolania($osoba);

        for ($i = 1; $i <= 4; $i++) {
            $this->post('/odwolanie', [
                'login' => self::LOGIN,
                'password' => 'pomylka-'.$i,
                'body' => 'Uważam, że decyzja jest błędna i proszę o ponowne rozpatrzenie sprawy.',
            ], ['X-Forwarded-For' => '198.51.100.6']);
        }

        $this->post('/odwolanie', [
            'login' => self::LOGIN,
            'password' => self::HASLO,
            'body' => 'Uważam, że decyzja jest błędna i proszę o ponowne rozpatrzenie sprawy.',
        ], ['X-Forwarded-For' => '198.51.100.6']);

        $this->assertFalse(
            $this->zatrzymany($this->post('/login', [
                'login' => self::LOGIN,
                'password' => 'jeszcze-jedna-pomylka',
            ], ['X-Forwarded-For' => '198.51.100.6'])),
            'Licznik nie został wyzerowany po trafieniu hasłem — cztery pomyłki sprzed chwili '
            .'nadal zajmują budżet.',
        );
    }

    // -----------------------------------------------------------------
    // 3. DROGA WYJŚCIA DLA OSOBY ZABLOKOWANEJ PRZEZ KOGOŚ OBCEGO
    // -----------------------------------------------------------------

    /**
     * Koszyk konta pozwala obcemu zamknąć cudze konto — to jest zamierzona
     * cena ochrony przed atakiem rozproszonym. Test pilnuje, żeby ta cena
     * była zapłacona UCZCIWIE: komunikat ma mówić, co zrobić TERAZ, a nie
     * tylko „spróbuj później".
     */
    public function test_komunikat_po_blokadzie_nazywa_droge_wyjscia(): void
    {
        $this->ofiara();

        $proby = $this->probyNaKonto();

        for ($i = 1; $i <= $proby; $i++) {
            $this->post('/login', [
                'login' => self::LOGIN,
                'password' => 'napastnik-'.$i,
            ], $this->adres($i));
        }

        $wlascicielka = $this->post('/login', [
            'login' => self::LOGIN,
            'password' => self::HASLO,
        ], ['X-Forwarded-For' => '198.51.100.77']);

        $komunikat = $this->komunikat($wlascicielka);

        $this->assertStringContainsString('Za dużo prób logowania', $komunikat);
        $this->assertStringContainsString('min.', $komunikat,
            'Komunikat nie mówi, KIEDY można spróbować ponownie.');
        $this->assertStringContainsString('Wyślij mi link', $komunikat,
            'Komunikat nie mówi, CO ZROBIĆ W MIĘDZYCZASIE. Logowanie linkiem nie przechodzi '
            .'przez te koszyki, więc działa od razu — a człowiek, któremu konto zamknął ktoś '
            .'obcy, nie ma skąd o tym wiedzieć.');
    }

    /**
     * Komunikat nie może się różnić dla konta, którego nie ma.
     *
     * ZEGAR JEST ZAMROŻONY NA CZAS OBU SERII (issue #1557). Komunikat niesie
     * liczbę minut do końca blokady: `LimitProbHasla::zatrzymajJesliZaDuzo()`
     * liczy ją jako `ceil(RateLimiter::availableIn() / 60)`, a `availableIn()`
     * to znacznik `:timer` (zapisany przy pierwszym `hit()` jako
     * `Carbon::now() + sekundy`) minus `Carbon::now()` — oba z
     * `InteractsWithTime::currentTime()`. Seria bez konta startuje po serii
     * z kontem, więc na wolnym runnerze upływ czasu w każdej z nich
     * przesuwał się przez granicę minuty i test porównywał „za 14 min"
     * z „za 15 min", choć treść nie zależała od konta. Po zamrożeniu obie
     * serie widzą to samo `now()`, więc jedyną różnicą, jaka może zostać,
     * jest różnica od istnienia konta — czyli dokładnie wyrocznia, której
     * test pilnuje.
     *
     * KONTROLE (sprawdzone ręcznie przy #1557):
     *  - dodatnia: dopisanie w `LimitProbHasla::zatrzymajJesliZaDuzo()` do
     *    komunikatu czegokolwiek zależnego od konta, np.
     *    `(User::findByLogin($login) ? ' ' : '')`, OBLEWA `assertSame` niżej
     *    — zamrożenie usuwa szum zegara, nie osłabia porównania;
     *  - odtworzenie wady: bez `travelTo()` i z `$this->travel(61)->seconds()`
     *    po pierwszej próbie serii z kontem (symulacja wolnego runnera) test
     *    oblewa na „za 14 min" wobec „za 15 min".
     */
    public function test_komunikat_po_blokadzie_nie_zdradza_czy_konto_istnieje(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->ofiara();

        $proby = $this->probyNaKonto();
        $nieistniejacy = 'nie-ma-takiego@example.com';

        $wypal = function (string $login) use ($proby): string {
            for ($i = 1; $i <= $proby + 1; $i++) {
                $odpowiedz = $this->post('/login', [
                    'login' => $login,
                    'password' => 'zgaduje-'.$i,
                ], $this->adres($i));

                $tekst = $this->komunikat($odpowiedz);

                if (str_contains($tekst, 'Za dużo prób')) {
                    return $tekst;
                }
            }

            return '';
        };

        $zKontem = $wypal(self::LOGIN);
        $bezKonta = $wypal($nieistniejacy);

        $this->assertNotSame('', $zKontem, 'Konto istniejące nie doczekało się blokady.');
        $this->assertSame($zKontem, $bezKonta,
            'Komunikat blokady różni się dla adresu z kontem i bez konta — to wyrocznia '
            .'odpowiadająca na pytanie, kto ma tu konto.');
    }

    /**
     * NAJWAŻNIEJSZE WYJŚCIE: udany reset hasła ZDEJMUJE blokadę konta.
     *
     * Serwis przy każdej nieudanej próbie mówi „kliknij «Nie pamiętam
     * hasła»". Bez tej reguły ta podpowiedź prowadziła donikąd: człowiek
     * przechodził całą drogę przez skrzynkę, ustawiał nowe hasło i wracając
     * na `/login` dostawał tę samą odmowę.
     */
    public function test_ustawienie_nowego_hasla_zdejmuje_blokade_konta(): void
    {
        Notification::fake();

        $osoba = $this->ofiara();
        $proby = $this->probyNaKonto();

        for ($i = 1; $i <= $proby; $i++) {
            $this->post('/login', [
                'login' => self::LOGIN,
                'password' => 'napastnik-'.$i,
            ], $this->adres($i));
        }

        $this->assertTrue(
            $this->zatrzymany($this->post('/login', [
                'login' => self::LOGIN,
                'password' => self::HASLO,
            ], ['X-Forwarded-For' => '198.51.100.77'])),
            'Kontrola: po wyczerpaniu koszyka konta właścicielka powinna dostać odmowę.',
        );

        $token = Password::broker()->createToken($osoba);

        $this->post('/nowe-haslo', [
            'token' => $token,
            'email' => self::LOGIN,
            'password' => 'wtorek-parasol-cebula-2026',
            'password_confirmation' => 'wtorek-parasol-cebula-2026',
        ])->assertRedirect(route('login'));

        $this->assertFalse(
            $this->zatrzymany($this->post('/login', [
                'login' => self::LOGIN,
                'password' => 'wtorek-parasol-cebula-2026',
            ], ['X-Forwarded-For' => '198.51.100.78'])),
            'Po ustawieniu nowego hasła konto nadal jest zablokowane licznikiem. Podpowiedź '
            .'„kliknij «Nie pamiętam hasła»" prowadzi wtedy donikąd.',
        );

        $this->assertAuthenticatedAs($osoba->fresh());
    }

    /**
     * Reset czyści koszyk konta TAKŻE dla zapisu nazwą użytkownika.
     *
     * Jedno konto ma dwa klucze koszyka B (`KluczeLimitow`): po adresie
     * e-mail i po nazwie użytkownika. Wyczyszczenie jednego zostawiałoby
     * człowieka zablokowanego na tym zapisie, którego akurat używa.
     */
    public function test_reset_zdejmuje_blokade_takze_dla_zapisu_nazwa_uzytkownika(): void
    {
        Notification::fake();

        $osoba = $this->ofiara();
        $proby = $this->probyNaKonto();

        for ($i = 1; $i <= $proby; $i++) {
            $this->post('/login', [
                'login' => 'basia',
                'password' => 'napastnik-'.$i,
            ], $this->adres($i));
        }

        $token = Password::broker()->createToken($osoba);

        $this->post('/nowe-haslo', [
            'token' => $token,
            'email' => self::LOGIN,
            'password' => 'wtorek-parasol-cebula-2026',
            'password_confirmation' => 'wtorek-parasol-cebula-2026',
        ])->assertRedirect(route('login'));

        $this->assertFalse(
            $this->zatrzymany($this->post('/login', [
                'login' => 'basia',
                'password' => 'wtorek-parasol-cebula-2026',
            ], ['X-Forwarded-For' => '198.51.100.79'])),
            'Blokada zdjęta tylko dla zapisu adresem e-mail — kto loguje się nazwą, zostaje '
            .'zamknięty bez żadnej widocznej przyczyny.',
        );
    }

    /**
     * KOSZYK ADRESU RESET PRZEŻYWA — inaczej wystarczyłoby zresetować hasło
     * własnego, jednorazowego konta, żeby wyzerować licznik adresowy przed
     * powrotem do rozpylania po cudzych kontach.
     *
     * TEN JEDEN TEST PYTA LICZNIK WPROST, A NIE PRZEZ `/login` — i to jest
     * decyzja, nie skrót. Pierwsza wersja wypalała koszyk adresu setką
     * żądań na `/login`, ale ta trasa ma własny `throttle:5,1` po adresie
     * IP: od szóstego żądania odpowiadał middleware, kontroler nie był
     * wołany i koszyk adresu w ogóle nie rósł. Test świecił się na zielono,
     * mierząc CAŁKIEM INNY limiter niż ten, o który pytał — pokazała to
     * dopiero kontrola ujemna (`scripts/kontrola-ujemna.sh`), która
     * zgłosiła `STRAZNIK_NIE_STRZEZE`.
     */
    public function test_reset_nie_czysci_koszyka_adresu(): void
    {
        Notification::fake();

        $napastnik = $this->user('napastnik', [
            'email' => 'napastnik@example.com',
            'password' => Hash::make('haslo-napastnika-123'),
        ]);

        $klucze = app(KluczeLimitow::class);
        $adres = '198.51.100.99';
        $kluczAdresu = $klucze->adres($adres);
        $kluczKonta = $klucze->konto('napastnik@example.com');

        // Tyle nieudanych prób, ile zostawiłoby rozpylanie po cudzych
        // kontach z tego jednego miejsca.
        for ($i = 1; $i <= 7; $i++) {
            RateLimiter::hit($kluczAdresu, 300);
            RateLimiter::hit($kluczKonta, 900);
        }

        $token = Password::broker()->createToken($napastnik);

        $this->post('/nowe-haslo', [
            'token' => $token,
            'email' => 'napastnik@example.com',
            'password' => 'wtorek-parasol-cebula-2026',
            'password_confirmation' => 'wtorek-parasol-cebula-2026',
        ], ['X-Forwarded-For' => $adres])->assertRedirect(route('login'));

        // KONTROLA DODATNIA: koszyk KONTA ma zniknąć. Bez tej asercji test
        // niżej przechodziłby także wtedy, gdyby reset przestał czyścić
        // cokolwiek — czyli gdyby droga wyjścia w ogóle nie działała.
        $this->assertSame(
            0,
            RateLimiter::attempts($kluczKonta),
            'Kontrola: reset hasła miał wyczyścić koszyk KONTA, a go nie wyczyścił.',
        );

        $this->assertSame(
            7,
            RateLimiter::attempts($kluczAdresu),
            'Reset hasła ruszył koszyk ADRESU. Wystarczy wtedy zresetować hasło własnego, '
            .'jednorazowego konta, żeby wrócić do rozpylania po cudzych kontach z czystym '
            .'licznikiem adresowym.',
        );
    }

    /**
     * Kontrola do testu wyżej: list z linkiem do nowego hasła naprawdę
     * wychodzi, więc droga wyjścia opisana w komunikacie istnieje także
     * od strony poczty.
     */
    public function test_prosba_o_nowe_haslo_naprawde_wysyla_list(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);

        $osoba = $this->ofiara();

        $this->post('/nie-pamietam-hasla', ['email' => self::LOGIN]);

        Notification::assertSentTo($osoba, UstawienieNowegoHasla::class);
    }

    private function decyzjaDoOdwolania(User $osoba): ModerationAction
    {
        return ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'subject_user_id' => $osoba->getKey(),
            'target_type' => 'user',
            'target_id' => (string) $osoba->getKey(),
            'action' => ModerationAction::ACTION_SUSPEND,
            'reason_code' => 'spam',
        ]);
    }
}
