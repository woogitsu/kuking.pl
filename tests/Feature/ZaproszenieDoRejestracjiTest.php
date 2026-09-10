<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneZaproszenia;
use App\Domain\Security\DziennyBudzetListow;
use App\Domain\Security\WyslijZaproszenieDoRejestracji;
use App\Models\AuditLogEntry;
use App\Models\RegistrationInvite;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Notifications\ZaproszenieDoZalozeniaKonta;
use App\Support\AdresEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wysyłka zaproszenia do założenia konta — adres BEZ konta (D-085).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN PLIK PILNUJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ta droga dokłada do formularza „wyślij mi link" DRUGIE zachowanie, zależne
 * od tego, czy na podanym adresie jest konto — czyli dokłada dokładnie tę
 * rzecz, której formularzowi NIE WOLNO zdradzić (D-056, D-075). Większość
 * tego pliku sprawdza więc nie to, że zaproszenie działa, tylko to, że
 * ODPOWIEDŹ NIE ZMIENIA SIĘ ANI O ZNAK, cokolwiek by się w środku nie stało:
 * konto jest, konta nie ma, zaproszenia wyłączone, sufit wyczerpany,
 * rejestracja zamknięta, dwie prośby naraz.
 *
 * Porównujemy CAŁE odpowiedzi, nie same komunikaty — inaczej test przepuści
 * różnicę w kodzie HTTP, a to ona była wyrocznią w D-075.
 */
class ZaproszenieDoRejestracjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // W testach `MAIL_MAILER=array`, a `App\Support\Poczta` uznaje to
        // (słusznie) za „poczta nie działa" — formularz chowa się wtedy
        // w całości. Ten sam zabieg co w `LogowanieLinkiemTest`.
        config([
            'mail.default' => 'smtp',
            'kuking.login_link.zaproszenia.wlaczone' => true,
            'kuking.account.registration_open' => true,
        ]);
    }

    // ------------------------------------------------------------------
    //  Droga szczęśliwa
    // ------------------------------------------------------------------

    public function test_adres_bez_konta_dostaje_zaproszenie_a_adres_z_kontem_link_do_logowania(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $this->wyslijFormularz('nikogo-takiego@example.com');
        $this->wyslijFormularz($basia->email);

        Notification::assertSentOnDemand(ZaproszenieDoZalozeniaKonta::class);
        Notification::assertSentTo($basia, LinkDoLogowania::class);

        // Zaproszenie powstaje TYLKO dla adresu bez konta.
        $this->assertDatabaseCount('registration_invites', 1);
        $this->assertDatabaseHas('registration_invites', ['email' => 'nikogo-takiego@example.com']);
    }

    /**
     * ADRES WCHODZI ZNORMALIZOWANY — inaczej „Nikt@Example.COM" i
     * „nikt@example.com" byłyby dwoma zaproszeniami na tę samą skrzynkę,
     * a unikalność `email` przestałaby cokolwiek znaczyć.
     */
    public function test_adres_zapisuje_sie_znormalizowany(): void
    {
        Notification::fake();

        $this->wyslijFormularz('  Nikogo.Takiego@Example.COM  ');

        $this->assertDatabaseHas('registration_invites', ['email' => 'nikogo.takiego@example.com']);
    }

    // ------------------------------------------------------------------
    //  Nieodróżnialność — sedno tej zmiany
    // ------------------------------------------------------------------

    /**
     * CAŁA ODPOWIEDŹ IDENTYCZNA DLA ADRESU Z KONTEM I BEZ KONTA.
     *
     * To jest ten sam kontrakt, który D-075 naprawiło dla logowania linkiem
     * — i ta zmiana miała go NIE złamać z powrotem. Porównujemy kod HTTP,
     * adres przekierowania i komunikat.
     */
    public function test_odpowiedz_jest_nieodroznialna_dla_adresu_z_kontem_i_bez_konta(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $zKontem = $this->odpowiedzNa($basia->email);
        $bezKonta = $this->odpowiedzNa('nikogo-takiego@example.com');

        $this->assertTakaSamaOdpowiedz($zKontem, $bezKonta);
    }

    /**
     * Wyłączenie tej połowy drogi też nie ma prawa niczego zdradzić —
     * a wyłącznik jest po to, żeby dało się wrócić do zachowania z D-056
     * jedną zmienną środowiskową, bez wycofywania migracji.
     */
    public function test_wylaczone_zaproszenia_nie_wysylaja_nic_i_nie_zmieniaja_odpowiedzi(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $zKontem = $this->odpowiedzNa($basia->email);

        config(['kuking.login_link.zaproszenia.wlaczone' => false]);

        $bezKonta = $this->odpowiedzNa('nikogo-takiego@example.com');

        Notification::assertNotSentTo(new AnonymousNotifiable, ZaproszenieDoZalozeniaKonta::class);
        $this->assertDatabaseCount('registration_invites', 0);
        $this->assertTakaSamaOdpowiedz($zKontem, $bezKonta);
    }

    /**
     * ZAMKNIĘTA REJESTRACJA — zaproszenie prowadziłoby na ekran 503.
     * Zaproszenie do drzwi, które są zamknięte, jest gorsze niż jego brak.
     */
    public function test_zamknieta_rejestracja_nie_wysyla_zaproszenia(): void
    {
        config(['kuking.account.registration_open' => false]);

        Notification::fake();

        $this->wyslijFormularz('nikogo-takiego@example.com');

        Notification::assertNotSentTo(new AnonymousNotifiable, ZaproszenieDoZalozeniaKonta::class);
        $this->assertDatabaseCount('registration_invites', 0);
    }

    /**
     * PO WYCZERPANIU SUFITU ZAPROSZEŃ EKRAN SIĘ NIE ZMIENIA.
     *
     * Gdyby się zmieniał, napastnik miałby wyrocznię „na tym adresie nie ma
     * konta", i to wyrocznię, którą umie WYWOŁAĆ SAM — wysyłając tyle
     * zaproszeń, ile wchodzi w dobowy sufit. Cena tego wyboru jest wypisana
     * w D-085: w takim dniu osoba bez konta wiadomości nie dostanie.
     */
    public function test_wyczerpany_sufit_zaproszen_nie_zmienia_ekranu(): void
    {
        config(['kuking.login_link.zaproszenia.dzienny_sufit' => 1]);

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $this->wyslijFormularz('pierwszy@example.com');

        $zKontem = $this->odpowiedzNa($basia->email);
        $poSuficie = $this->odpowiedzNa('drugi@example.com');

        // Drugie zaproszenie nie wyszło…
        $this->assertDatabaseCount('registration_invites', 1);
        $this->assertDatabaseMissing('registration_invites', ['email' => 'drugi@example.com']);

        // …ale człowiek przy formularzu nie ma jak tego zobaczyć.
        $this->assertTakaSamaOdpowiedz($zKontem, $poSuficie);
    }

    // ------------------------------------------------------------------
    //  Wyścig o ten sam adres — usterka bezpieczeństwa naprawiona w tym PR
    // ------------------------------------------------------------------

    /**
     * DWIE PROŚBY NARAZ O TEN SAM ADRES BEZ KONTA NIE KOŃCZĄ SIĘ BŁĘDEM 500.
     *
     * ══════════════════════════════════════════════════════════════════
     *  CO BYŁO ZŁAMANE W SZKICU
     * ══════════════════════════════════════════════════════════════════
     *
     * `registration_invites.email` jest unikalne. Szkic kasował poprzedni
     * wiersz i zakładał nowy BEZ przechwycenia konfliktu — więc dwie prośby
     * naraz przechodziły oba `DELETE` (każda kasując zero wierszy) i obie
     * szły do `INSERT`. Druga odbijała się o constraint i przewracała żądanie
     * na 500.
     *
     * A przewracała je WYŁĄCZNIE na ścieżce adresu BEZ konta: adres z kontem
     * nie dochodzi do tej klasy, a jego własny wyścig `WyslijLinkDoLogowania`
     * sprowadza do 302 (D-075). Para równoległych próśb odpowiadała więc 500
     * dla adresu bez konta i 302 dla adresu z kontem — czyli DOKŁADNIE ta
     * wyrocznia „kto ma konto w Kuking", którą D-075 dopiero co zamknęło,
     * tylko odbita w lustrze. Po ludzkiej stronie tego wyścigu stoi zwykły
     * dwuklik „Wyślij".
     *
     * ══════════════════════════════════════════════════════════════════
     *  JAK ODTWARZAMY WYŚCIG BEZ DRUGIEGO PROCESU
     * ══════════════════════════════════════════════════════════════════
     *
     * Wstrzykujemy konflikt DOKŁADNIE MIĘDZY `DELETE` I `INSERT`: nasłuch
     * `DB::listen` łapie zapytanie kasujące i wstawia wtedy wiersz na ten sam
     * adres. To jest ten sam stan bazy, który zastaje przegrywające żądanie
     * prawdziwego wyścigu — bez dwóch procesów i bez zależności od czasu.
     */
    public function test_wyscig_o_ten_sam_adres_nie_konczy_sie_bledem_500(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $wzorzec = $this->odpowiedzNa($basia->email);

        $wstrzyknieto = false;

        DB::listen(function ($zapytanie) use (&$wstrzyknieto): void {
            if ($wstrzyknieto || ! str_contains($zapytanie->sql, 'delete from "registration_invites" where "email"')) {
                return;
            }

            // Flaga PRZED zapisem — bez niej własny `INSERT` wywołałby
            // nasłuch po raz drugi i test badałby własny ogon.
            $wstrzyknieto = true;

            DB::table('registration_invites')->insert([
                'id' => (string) Str::uuid(),
                'email' => 'nikogo-takiego@example.com',
                'token_hash' => RegistrationInvite::skrot(RegistrationInvite::nowyToken()),
                'created_at' => now(),
                'expires_at' => now()->addHours(24),
            ]);
        });

        $wyscig = $this->odpowiedzNa('nikogo-takiego@example.com');

        $this->assertTrue($wstrzyknieto, 'Konflikt nie został wstrzyknięty — test nie sprawdził wyścigu.');

        // Nie 500 — i nie „prawie to samo", tylko dokładnie ta sama odpowiedź
        // co dla adresu z kontem.
        $this->assertTakaSamaOdpowiedz($wzorzec, $wyscig);
    }

    /**
     * PRZEGRANY WYŚCIG ODDAJE MIEJSCE W SUFICIE.
     *
     * Z tej prośby nie wyszła żadna wiadomość, więc nie ma za co płacić.
     * Bez tego dwuklik kosztowałby dwa miejsca z czterdziestu.
     */
    public function test_przegrany_wyscig_oddaje_miejsce_w_suficie(): void
    {
        Notification::fake();

        $sufit = DziennyBudzetListow::dlaZaproszenDoRejestracji();
        $przed = $sufit->zuzyte();

        $wstrzyknieto = false;

        DB::listen(function ($zapytanie) use (&$wstrzyknieto): void {
            if ($wstrzyknieto || ! str_contains($zapytanie->sql, 'delete from "registration_invites" where "email"')) {
                return;
            }

            $wstrzyknieto = true;

            DB::table('registration_invites')->insert([
                'id' => (string) Str::uuid(),
                'email' => 'nikogo-takiego@example.com',
                'token_hash' => RegistrationInvite::skrot(RegistrationInvite::nowyToken()),
                'created_at' => now(),
                'expires_at' => now()->addHours(24),
            ]);
        });

        $this->wyslijFormularz('nikogo-takiego@example.com');

        $this->assertTrue($wstrzyknieto, 'Konflikt nie został wstrzyknięty — test nie sprawdził wyścigu.');
        $this->assertSame($przed, $sufit->zuzyte());
    }

    // ------------------------------------------------------------------
    //  Czego ta klasa nie wolno jej zrobić
    // ------------------------------------------------------------------

    /**
     * ZAPROSZENIE NIGDY NA ADRES Z KONTEM — nawet wołane wprost.
     *
     * `WyslijLinkDoLogowania` już wie, że konta nie ma, ale ta klasa jest
     * publiczna i zawoła ją kiedyś ktoś inny. Zaproszenie na adres Z KONTEM
     * byłoby gotową drogą do drugiego konta na cudzej skrzynce.
     */
    public function test_akcja_wolana_wprost_nie_wysyla_zaproszenia_na_adres_z_kontem(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $poszlo = app(WyslijZaproszenieDoRejestracji::class)->handle($basia->email);

        $this->assertFalse($poszlo);
        Notification::assertNotSentTo(new AnonymousNotifiable, ZaproszenieDoZalozeniaKonta::class);
        $this->assertDatabaseCount('registration_invites', 0);
    }

    /**
     * W BAZIE LEŻY SKRÓT, NIGDY TOKEN.
     *
     * Pytamy SUROWĄ kolumnę przez `DB::table()`, a nie przez model — żeby
     * żaden przyszły akcesor nie mógł tego upiększyć.
     */
    public function test_w_bazie_lezy_skrot_a_nie_token(): void
    {
        Notification::fake();

        $this->wyslijFormularz('nikogo-takiego@example.com');

        $token = $this->tokenZWiadomosci();

        $zapisany = (string) DB::table('registration_invites')
            ->where('email', 'nikogo-takiego@example.com')
            ->value('token_hash');

        $this->assertNotSame($token, $zapisany);
        $this->assertSame(RegistrationInvite::skrot($token), $zapisany);
    }

    /**
     * NOWA PROŚBA UNIEWAŻNIA POPRZEDNIE ZAPROSZENIE — jeden ważny link na
     * adres, ta sama własność co przy logowaniu linkiem (D-056).
     */
    public function test_nowa_prosba_uniewaznia_poprzednie_zaproszenie(): void
    {
        Notification::fake();

        $this->wyslijFormularz('nikogo-takiego@example.com');
        $pierwszy = $this->tokenZWiadomosci();

        $this->wyslijFormularz('nikogo-takiego@example.com');

        $this->assertDatabaseCount('registration_invites', 1);
        $this->assertNull(RegistrationInvite::znajdzPoTokenie($pierwszy));
    }

    /**
     * DZIENNIK NOTUJE FAKT, NIGDY TOKENU I NIGDY PEŁNEGO ADRESU
     * (SECURITY_BASELINE §7). To jest jedyna droga w serwisie, na której
     * nieznajomy każe nam wysłać wiadomość na dowolny adres — bez tego wpisu
     * nie umielibyśmy odpowiedzieć na „dostaję wiadomości, o które nie
     * prosiłam".
     */
    public function test_dziennik_notuje_fakt_bez_tokenu_i_bez_pelnego_adresu(): void
    {
        Notification::fake();

        $this->wyslijFormularz('nikogo-takiego@example.com');

        $wpis = AuditLogEntry::query()
            ->where('action', 'account.registration_invite_sent')
            ->firstOrFail();

        $zapis = json_encode($wpis->metadata, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('nikogo-takiego@example.com', (string) $zapis);
        $this->assertStringNotContainsString($this->tokenZWiadomosci(), (string) $zapis);
        // Aktora nie ma, bo konta nie ma — a kolumna jest nullowalna.
        $this->assertNull($wpis->actor_id);
    }

    // ------------------------------------------------------------------
    //  Sprzątanie — higiena danych, nie bramka bezpieczeństwa
    // ------------------------------------------------------------------

    /**
     * W wierszu leży adres e-mail osoby, która NIE MA u nas konta. Sprzątanie
     * przy okazji prośby czyści tabelę w dniu z ruchem; ta komenda jest
     * siatką bezpieczeństwa na dni, w których nikt o nic nie prosi — i to ona
     * daje polityce prywatności prawo napisać „najwyżej dobę".
     */
    public function test_komenda_sprzata_wygasle_zaproszenia_a_zywe_zostawia(): void
    {
        Notification::fake();

        $this->wyslijFormularz('zywe@example.com');
        $this->wyslijFormularz('wygasle@example.com');

        // CHECK w bazie wymusza `expires_at > created_at`, więc cofamy OBIE
        // kolumny — a nie samą datę wygaśnięcia. To jest ta sama reguła, którą
        // sprawdza `test_baza_odrzuca_zaproszenie_wygasajace_przed_powstaniem`.
        RegistrationInvite::query()
            ->where('email', 'wygasle@example.com')
            ->update(['created_at' => now()->subHours(30), 'expires_at' => now()->subHours(6)]);

        $ile = app(PrzedawnioneZaproszenia::class)->posprzataj();

        $this->assertSame(1, $ile);
        $this->assertDatabaseHas('registration_invites', ['email' => 'zywe@example.com']);
        $this->assertDatabaseMissing('registration_invites', ['email' => 'wygasle@example.com']);
    }

    public function test_komenda_na_sucho_niczego_nie_kasuje(): void
    {
        Notification::fake();

        $this->wyslijFormularz('wygasle@example.com');

        RegistrationInvite::query()->update([
            'created_at' => now()->subHours(30),
            'expires_at' => now()->subHours(6),
        ]);

        $this->artisan('kuking:sprzataj-zaproszenia', ['--na-sucho' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('registration_invites', 1);
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    private function wyslijFormularz(string $adres): TestResponse
    {
        return $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => $adres]);
    }

    /**
     * Odpowiedź formularza SPISANA W CHWILI ŻĄDANIA.
     *
     * ══════════════════════════════════════════════════════════════════
     *  DLACZEGO NIE DA SIĘ PORÓWNAĆ DWÓCH `TestResponse`
     * ══════════════════════════════════════════════════════════════════
     *
     * Bo `TestResponse::getSession()` oddaje sesję APLIKACJI, jedną i tę
     * samą dla obu żądań — a komunikat jest w niej zapisany „na jeden
     * odczyt" (flash). Porównanie po fakcie czytało więc DWA RAZY TĘ SAMĄ
     * wartość, tę z żądania późniejszego, i przechodziło nawet wtedy, gdy
     * komunikaty naprawdę się różniły. Złapała to kontrola ujemna: sabotaż
     * wstawiający osobny komunikat dla adresu Z KONTEM nie obalił testu.
     *
     * Dlatego komunikat spisujemy natychmiast po żądaniu, zanim pójdzie
     * następne.
     *
     * @return array{kod: int, gdzie: ?string, komunikat: string}
     */
    private function odpowiedzNa(string $adres): array
    {
        $odpowiedz = $this->wyslijFormularz($adres);

        /*
         * SKRÓT WPISANEGO ADRESU WYCINAMY — i to jest jedyna rzecz, którą
         * wolno tu znormalizować.
         *
         * Komunikat niesie maskę adresu (`b***@example.com`), ale bierze ją
         * z tego, co człowiek WPISAŁ W FORMULARZ, a nie z bazy — więc różni
         * się zawsze, gdy porównujemy dwa różne adresy, i nie mówi niczego
         * o tym, czy konto istnieje. Gdybyśmy jej nie wycięli, test nie dałby
         * się napisać wcale; gdybyśmy zamiast tego porównywali sam fragment
         * komunikatu, przepuścilibyśmy każdą inną różnicę. Wycinamy więc
         * dokładnie tę jedną wartość i porównujemy CAŁĄ resztę.
         */
        return [
            'kod' => $odpowiedz->getStatusCode(),
            'gdzie' => $odpowiedz->headers->get('Location'),
            'komunikat' => str_replace(
                AdresEmail::maska(User::normalizeEmail($adres)),
                '<ADRES>',
                (string) session('status', ''),
            ),
        ];
    }

    /**
     * Porównuje CAŁE odpowiedzi, nie same komunikaty: kod HTTP, adres
     * przekierowania i treść komunikatu. Różnica w którymkolwiek z tych
     * trzech jest wyrocznią „kto ma konto".
     *
     * @param  array{kod: int, gdzie: ?string, komunikat: string}  $a
     * @param  array{kod: int, gdzie: ?string, komunikat: string}  $b
     */
    private function assertTakaSamaOdpowiedz(array $a, array $b): void
    {
        $this->assertSame($a['kod'], $b['kod'],
            'Kod HTTP zdradza, czy na podanym adresie jest konto.');

        $this->assertSame($a['gdzie'], $b['gdzie'],
            'Adres przekierowania zdradza, czy na podanym adresie jest konto.');

        $this->assertNotSame('', $a['komunikat'],
            'Formularz nie zostawił żadnego komunikatu — porównanie niczego by nie sprawdziło.');

        $this->assertSame($a['komunikat'], $b['komunikat'],
            'Komunikat zdradza, czy na podanym adresie jest konto.');
    }

    /** Token jawny wyjęty z wysłanej wiadomości — jedyne miejsce, gdzie żyje. */
    private function tokenZWiadomosci(): string
    {
        $link = null;

        Notification::assertSentOnDemand(
            ZaproszenieDoZalozeniaKonta::class,
            function (ZaproszenieDoZalozeniaKonta $powiadomienie, array $kanaly, object $odbiorca) use (&$link): bool {
                $link = $powiadomienie->toMail($odbiorca)->viewData['linkUrl'] ?? null;

                return true;
            },
        );

        $this->assertIsString($link, 'Z wiadomości nie dało się wyjąć adresu linku.');

        return (string) mb_substr($link, mb_strrpos($link, '/') + 1);
    }
}
