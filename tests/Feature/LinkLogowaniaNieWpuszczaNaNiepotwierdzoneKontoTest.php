<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Notifications\UstawienieHaslaZamiastLinku;
use App\Notifications\UstawienieNowegoHasla;
use App\Support\AdresEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Link do logowania NIE WCHODZI na konto z niepotwierdzonym adresem (issue #317).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TU JEST NAPRAWIANE — PRZEJĘCIE Z WYPRZEDZENIEM
 * ────────────────────────────────────────────────────────────────────────
 *
 * Rejestracja w Kuking nie wymaga potwierdzenia adresu przed pierwszą
 * publikacją, a `LoginController` o potwierdzenie nie pyta wcale. Konto
 * założone na CUDZY adres jest więc kontem w pełni sprawnym. Napastnik
 * zakłada je na adres ofiary, hasła zna tylko on, adresu nie potwierdza —
 * bo nie ma jak, poczta idzie do ofiary. Ofiara przychodzi do Kuking,
 * prosi o link do zalogowania na SWÓJ adres, dostaje list NA SWOJĄ
 * SKRZYNKĘ i wchodzi — tylko że wchodzi NA KONTO NAPASTNIKA, z jego
 * hasłem i jego otwartymi sesjami.
 *
 * Naprawa: konto z `email_verified_at IS NULL` dostaje list z ustawieniem
 * nowego hasła (`WyslijOdzyskanieKonta`), a nie link wchodzący na konto.
 * Ustawienie hasła unieważnia hasło napastnika, jego sesje i oczekujące
 * linki, a przy okazji POTWIERDZA adres — bez tego ostatniego ofiara nie
 * odzyskałaby wygodnej drogi wejścia nigdy.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO PILNUJE KAŻDY Z TYCH TESTÓW
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. CAŁA DROGA ATAKU, sześć kroków z issue #317 w jednym przebiegu —
 *     od założenia konta na cudzy adres po wyrzucenie napastnika.
 *  2. KONTROLA DODATNIA: konto z POTWIERDZONYM adresem dalej dostaje link
 *     i dalej powstaje wiersz w `login_link_tokens`. Bez niej ten plik
 *     byłby zielony także dla naprawy, która zabija tę drogę WSZYSTKIM
 *     (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
 *  3. NIEODRÓŻNIALNOŚĆ ODPOWIEDZI, bajt w bajt, dla TRZECH przypadków:
 *     konto potwierdzone, konto niepotwierdzone, adres bez konta.
 *  4. BUDŻET DOBOWY POCZTY zajmuje się we wszystkich trzech przypadkach
 *     jednakowo — to jest kanał boczny, nie kosmetyka (patrz tam).
 *  5. WĄSKOŚĆ: konto zamknięte i konto moderatora z niepotwierdzonym
 *     adresem nadal nie dostają NICZEGO — kolejność warunków w `handle()`
 *     odcina je PRZED gałęzią #317.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TE TESTY NIE DOWODZĄ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Chodzą na JEDNYM połączeniu do bazy (`RefreshDatabase`), więc nie mówią
 * nic o dwóch równoległych żądaniach — pułapka 6 z `docs/PULAPKI_TESTOW.md`.
 * Nie sprawdzają też, co się dzieje z treścią, którą napastnik zdążył dodać:
 * naprawa świadomie jej nie rusza, a decyzja w tej sprawie należy do
 * właściciela (`WyslijOdzyskanieKonta`, sekcja „co zostaje do rozstrzygnięcia").
 */
class LinkLogowaniaNieWpuszczaNaNiepotwierdzoneKontoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Trzy adresy o TEJ SAMEJ PIERWSZEJ LITERZE I TEJ SAMEJ DOMENIE.
     *
     * To nie jest kosmetyka: komunikat po wysłaniu formularza niesie maskę
     *
     * adresu (`AdresEmail::maska` — `b***@example.com`) zbudowaną z tego, co
     * człowiek WPISAŁ. Przy adresach o różnych pierwszych literach maski
     * różniłyby się zawsze, a test nieodróżnialności oblewałby się
     * z niewłaściwego powodu — albo, co gorsza, kazałby komuś „naprawić"
     * rzecz, która działa.
     */
    private const ADRES_POTWIERDZONY = 'basia@example.com';

    private const ADRES_NIEPOTWIERDZONY = 'bogumila@example.com';

    private const ADRES_BEZ_KONTA = 'bronislawa@example.com';

    /** Hasło, które zna WYŁĄCZNIE napastnik — ofiara nigdy go nie widziała. */
    private const HASLO_NAPASTNIKA = 'haslo-ktore-zna-tylko-napastnik-1';

    /** Hasło, które ofiara ustawia listem „Ustaw nowe hasło". */
    private const HASLO_OFIARY = 'zupelnienowehaslo789';

    protected function setUp(): void
    {
        parent::setUp();

        // W testach `MAIL_MAILER=array`, a `App\Support\Poczta` uznaje to
        // (słusznie) za „poczta nie działa" — wtedy `LoginLinkController`
        // kończy się na komunikacie o braku poczty i nie dochodzi do żadnej
        // z gałęzi, których pilnuje ten plik. Sposób obejścia wzięty
        // z `LogowanieLinkiemTest::setUp()`.
        config(['mail.default' => 'smtp']);
    }

    // ------------------------------------------------------------------
    //  1. Pełna droga ataku z issue #317
    // ------------------------------------------------------------------

    /**
     * SZEŚĆ KROKÓW ATAKU W JEDNYM TEŚCIE REGRESYJNYM.
     *
     * Rozbicie tego na sześć testów kosztowałoby to, co jest tu
     * najcenniejsze: że kroki idą PO KOLEI, na tym samym koncie, i że
     * ostatni krok mierzy skutek pierwszego.
     */
    public function test_prosba_ofiary_o_link_do_logowania_nie_wpuszcza_jej_na_konto_napastnika(): void
    {
        // Sesje w bazie, żeby dało się zmierzyć, czy sesja napastnika
        // NAPRAWDĘ ginie. Sposób wzięty z
        // `PasswordResetSessionRotationTest::test_reset_hasla_kasuje_wszystkie_istniejace_sesje_uzytkownika`.
        config(['session.driver' => 'database']);

        // ── KROK 1 ── Napastnik zakłada konto na adres OFIARY. Hasła nie
        // zna nikt poza nim, adresu nie potwierdza (nie ma jak — poczta
        // idzie do ofiary).
        $konto = $this->niepotwierdzone('napastnik', self::ADRES_NIEPOTWIERDZONY, [
            'password' => Hash::make(self::HASLO_NAPASTNIKA),
        ]);

        $this->assertFalse($konto->hasVerifiedEmail(),
            'Konto miało wejść w ten test z NIEPOTWIERDZONYM adresem — inaczej mierzymy nie tę gałąź.');

        DB::table('sessions')->insert([
            'id' => 'sesja-napastnika',
            'user_id' => $konto->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'urzadzenie-napastnika',
            'payload' => '',
            'last_activity' => time(),
        ]);

        Notification::fake();

        // ── KROK 2 ── Ofiara wysyła formularz „wyślij mi link do zalogowania"
        // ze swoim własnym adresem.
        $this->wyslijFormularz(self::ADRES_NIEPOTWIERDZONY)->assertSessionHasNoErrors();

        // ── KROK 3 ── ŻADEN WIERSZ W `login_link_tokens` NIE POWSTAŁ.
        //
        // To jest sedno naprawy: ten wiersz był drogą wejścia na cudze
        // konto. Liczymy po surowej tabeli, a nie po tym, czy list poszedł —
        // token wystawiony, ale niewysłany, byłby dokładnie tak samo groźny,
        // bo `login.link.confirm` nie pyta, czy ktoś dostał list.
        $this->assertDatabaseCount('login_link_tokens', 0);
        $this->assertDatabaseMissing('login_link_tokens', ['user_id' => $konto->getKey()]);

        // ── KROK 4 ── Poszedł list „Ustaw nowe hasło", a NIE „Zaloguj mnie".
        Notification::assertSentTo($konto, UstawienieHaslaZamiastLinku::class);
        Notification::assertNotSentTo($konto, LinkDoLogowania::class);

        // ── KROK 5 ── Ofiara ustawia nowe hasło TYM listem, prawdziwą
        // trasą `password.update`.
        $token = $this->tokenZWiadomosciZHaslem($konto);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => self::ADRES_NIEPOTWIERDZONY,
            'password' => self::HASLO_OFIARY,
            'password_confirmation' => self::HASLO_OFIARY,
        ])->assertRedirect(route('login'));

        // ── KROK 6 ── NAPASTNIK ZOSTAŁ WYRZUCONY.
        //
        // Najpierw kontrola DODATNIA: nowe hasło naprawdę wpuszcza. Bez niej
        // asercja niżej przechodziłaby także wtedy, gdyby `Auth::attempt`
        // nie wpuszczało NIKOGO — pułapka 4 z `docs/PULAPKI_TESTOW.md`.
        $this->assertTrue(
            Auth::attempt(['email' => self::ADRES_NIEPOTWIERDZONY, 'password' => self::HASLO_OFIARY]),
            'Hasło ustawione przez ofiarę nie wpuszcza na konto — ta droga powrotu nie działa wcale.',
        );
        Auth::logout();

        $this->assertFalse(
            Auth::attempt(['email' => self::ADRES_NIEPOTWIERDZONY, 'password' => self::HASLO_NAPASTNIKA]),
            'Stare hasło napastnika dalej wpuszcza na konto ofiary.',
        );

        // Sesje napastnika też padły — samo hasło nie wystarcza, bo on jest
        // już zalogowany na swoim urządzeniu.
        $this->assertDatabaseMissing('sessions', ['id' => 'sesja-napastnika']);

        // I adres jest od teraz POTWIERDZONY. Bez tego ofiara nie odzyskałaby
        // wygodnej drogi wejścia nigdy: każda kolejna prośba o link kończyłaby
        // się tym samym listem z hasłem, i tak w kółko.
        $this->assertTrue($konto->fresh()->hasVerifiedEmail(),
            'Ustawienie hasła nie potwierdziło adresu — pętla z naprawy #317 zamyka się na człowieku.');
    }

    // ------------------------------------------------------------------
    //  2. Kontrola dodatnia — naprawa nie zabija tej drogi wszystkim
    // ------------------------------------------------------------------

    /**
     * TEN SAM TEST, TYLKO Z DRUGĄ STRONĄ WARUNKU.
     *
     * Bez tego cały plik przechodziłby także dla „naprawy", która przestaje
     * wystawiać token KOMUKOLWIEK — a to zamknęłoby drogę, która dla osób
     * 60+ jest drogą podstawową, nie awaryjną (D-056).
     */
    public function test_konto_z_potwierdzonym_adresem_dalej_dostaje_link_do_logowania(): void
    {
        $konto = $this->user('basia', ['email' => self::ADRES_POTWIERDZONY]);

        $this->assertTrue($konto->hasVerifiedEmail(),
            'Konto miało wejść w ten test z POTWIERDZONYM adresem — inaczej mierzymy tę samą gałąź co wyżej.');

        Notification::fake();

        $this->wyslijFormularz(self::ADRES_POTWIERDZONY)->assertSessionHasNoErrors();

        Notification::assertSentTo($konto, LinkDoLogowania::class);
        Notification::assertNotSentTo($konto, UstawienieHaslaZamiastLinku::class);

        // I stara wiadomość z odzyskiwania hasła też nie — konto potwierdzone
        // nie ma prawa dostać ŻADNEJ z dwóch wiadomości o haśle za to, że
        // poprosiło o link.
        Notification::assertNotSentTo($konto, UstawienieNowegoHasla::class);

        $this->assertDatabaseCount('login_link_tokens', 1);
        $this->assertDatabaseHas('login_link_tokens', ['user_id' => $konto->getKey()]);
    }

    // ------------------------------------------------------------------
    //  3. Własna treść wiadomości — człowiek dowiaduje się, co się stało
    // ------------------------------------------------------------------

    /**
     * KONTO NIEPOTWIERDZONE DOSTAJE WIADOMOŚĆ WŁASNĄ, NIE TĘ Z „NIE PAMIĘTAM
     * HASŁA".
     *
     * Do 11 września 2026 szło tędy `UstawienieNowegoHasla` — ta sama
     * wiadomość co przy odzyskiwaniu hasła, otwierana zdaniem „ktoś poprosił
     * o nowe hasło do konta". Nikt o nowe hasło nie prosił, więc człowiek
     * dostawał co innego, niż prosił, BEZ SŁOWA WYJAŚNIENIA.
     *
     * Asercja `assertNotSentTo(UstawienieNowegoHasla::class)` jest tu
     * ważniejsza niż wygląda: bez niej test byłby zielony także dla wersji,
     * która wysyła OBIE wiadomości naraz — a wtedy człowiek dostaje dwa
     * e-maile mówiące co innego o tej samej sprawie.
     */
    public function test_konto_niepotwierdzone_dostaje_wlasna_wiadomosc_a_nie_zwykly_reset_hasla(): void
    {
        $konto = $this->niepotwierdzone('bogumila', self::ADRES_NIEPOTWIERDZONY);

        Notification::fake();

        $this->wyslijFormularz(self::ADRES_NIEPOTWIERDZONY)->assertSessionHasNoErrors();

        Notification::assertSentTo($konto, UstawienieHaslaZamiastLinku::class);
        Notification::assertNotSentTo($konto, UstawienieNowegoHasla::class);
        Notification::assertNotSentTo($konto, LinkDoLogowania::class);
    }

    /**
     * TREŚĆ MÓWI PIĘĆ RZECZY — I NIE MÓWI CZTERECH.
     *
     * To jest jedyny test w tym pliku, który patrzy na SŁOWA, i ma po temu
     * powód: cała ta zmiana jest zmianą treści. Mechanizm został ten sam
     * (test niżej), więc gdyby nie ten test, „naprawę" dałoby się cofnąć do
     * `UstawienieNowegoHasla` bez ani jednej czerwonej asercji poza
     * `assertNotSentTo` wyżej.
     *
     * Sprawdzamy FRAGMENTY ZDAŃ, nie całe akapity: pełne zdania zamieniłyby
     * ten test w kopię szablonu, która oblewa przy każdej poprawce przecinka
     * i niczego przy tym nie pilnuje.
     */
    public function test_wiadomosc_tlumaczy_czlowiekowi_co_sie_stalo(): void
    {
        $konto = $this->niepotwierdzone('bogumila', self::ADRES_NIEPOTWIERDZONY);

        Notification::fake();

        $this->wyslijFormularz(self::ADRES_NIEPOTWIERDZONY)->assertSessionHasNoErrors();

        $tresc = $this->trescWiadomosci($konto);

        // 1. Wysyłamy co innego, niż prosiła — i to nie pomyłka.
        $this->assertStringContainsString('Wysyłamy co innego', $tresc,
            'Wiadomość nie mówi, że idzie co innego, niż człowiek zamówił.');
        $this->assertStringContainsString('To nie pomyłka', $tresc,
            'Wiadomość nie odbiera pierwszej myśli odbiorcy: „strona się pomyliła".');

        // 2. Dlaczego: adresu nikt nie potwierdził.
        $this->assertStringContainsString('nikt nigdy nie potwierdził', $tresc,
            'Wiadomość nie mówi, DLACZEGO idzie co innego.');
        $this->assertStringContainsString('cudze konto', $tresc,
            'Wiadomość nie mówi, czym byłoby wpuszczenie jednym kliknięciem.');

        // 3. Co zrobić i co się po tym stanie.
        $this->assertStringContainsString('Ustaw hasło i wejdź na konto', $tresc,
            'W wiadomości nie ma przycisku mówiącego, co się za nim dzieje.');
        $this->assertStringContainsString('przestaje', $tresc,
            'Wiadomość nie mówi, że poprzednie hasło przestaje działać.');

        // 4. Po ustawieniu hasła adres jest potwierdzony i można użyć linku.
        $this->assertStringContainsString('Ustawienie hasła potwierdzi adres e-mail', $tresc,
            'Wiadomość nie wyjaśnia, kiedy adres zostanie potwierdzony.');
        $this->assertStringContainsString('poprosić o link do wejścia na konto', $tresc,
            'Wiadomość nie mówi, jak zalogować się następnym razem.');

        // 5. Adres, pod którym odpowiada człowiek.
        $this->assertStringContainsString((string) config('kuking.community.contact_email'), $tresc,
            'W wiadomości nie ma adresu, pod którym da się zapytać człowieka.');

        // ── I CZTERY RZECZY, KTÓRYCH TAM BYĆ NIE MOŻE ──────────────────────

        // NIE STRASZY. Nie wiemy, czy ktokolwiek cokolwiek przejmował —
        // konto bez potwierdzonego adresu bierze się równie dobrze
        // z rejestracji porzuconej w połowie.
        foreach (['przejąć', 'przejęci', 'napastnik', 'włamani', 'zagrożen', 'uwaga!'] as $strach) {
            $this->assertStringNotContainsStringIgnoringCase($strach, $tresc,
                "Wiadomość straszy słowem „{$strach}” — a my nie wiemy, czy stało się cokolwiek złego.");
        }

        // NIE TŁUMACZY MECHANIKI. To jest nasz słownik, nie jej.
        foreach (['token', 'sesj', 'hijack', 'weryfikacj'] as $zargon) {
            $this->assertStringNotContainsStringIgnoringCase($zargon, $tresc,
                "Wiadomość tłumaczy mechanikę słowem „{$zargon}” zamiast powiedzieć rzecz po ludzku.");
        }

        // NIE MÓWI „LIST” — to jest poczta w kopercie (zgłoszenie
        // właściciela, `DrzwiWejsciowePrawdaTest`).
        foreach (['/\blistu\b/u', '/\blistem\b/u', '/\bten list\b/u', '/\bList\b/u'] as $forma) {
            $this->assertDoesNotMatchRegularExpression($forma, $tresc,
                'Wiadomość mówi o liście, a chodzi o e-mail.');
        }

        // NIE PRZYPISUJE RODZAJU (COPY_STYLE §2). Pełny skan widoków robi
        // `TekstyNiePrzypisujaPlciTest`; tutaj stoi ten jeden wzorzec, bo to
        // jest najświeższy tekst w serwisie i najłatwiej go tak napisać.
        $this->assertDoesNotMatchRegularExpression(
            '/\p{L}+ł(?:am|aś|em|eś|abym|abyś|bym|byś)(?![\p{L}])/u',
            str_replace('co dziś ugotowałeś', ' ', $tresc),
            'Wiadomość przypisuje czytelnikowi rodzaj — przebuduj zdanie (COPY_STYLE §2).',
        );
    }

    /**
     * WŁASNA TREŚĆ NIE ZMIENIŁA MECHANIZMU: TEN SAM TOKEN, TA SAMA TRASA,
     * TA SAMA WAŻNOŚĆ.
     *
     * To jest druga połowa zmiany i bez niej pierwsza jest niebezpieczna.
     * Gdyby nowa wiadomość niosła własny token albo prowadziła gdzie indziej,
     * kliknięcie przestałoby potwierdzać adres — a wtedy każda kolejna prośba
     * o link kończyłaby się tą samą wiadomością i tak w kółko.
     *
     * Token porównujemy z wierszem w `password_reset_tokens` przez
     * `Password::tokenExists()`, czyli tak, jak sprawdza go prawdziwy reset.
     * Samo „w bazie jest jakiś wiersz" byłoby zielone także dla tokenu
     * wystawionego obok, do niczego niepasującego.
     */
    public function test_wiadomosc_niesie_ten_sam_token_resetu_i_prowadzi_na_te_sama_trase(): void
    {
        $konto = $this->niepotwierdzone('bogumila', self::ADRES_NIEPOTWIERDZONY);

        Notification::fake();

        $this->wyslijFormularz(self::ADRES_NIEPOTWIERDZONY)->assertSessionHasNoErrors();

        $token = $this->tokenZWiadomosciZHaslem($konto);

        $this->assertTrue(
            Password::broker()->tokenExists($konto, $token),
            'Token z wiadomości nie pasuje do wiersza w `password_reset_tokens` — '
            .'to nie jest token resetu hasła, tylko coś wystawionego obok.',
        );

        // Adres z wiadomości prowadzi na `password.reset`, czyli tam, gdzie
        // `PasswordResetController::reset()` ustawia hasło I POTWIERDZA ADRES.
        $tresc = $this->trescWiadomosci($konto);

        $this->assertStringContainsString(
            route('password.reset', ['token' => $token]),
            $tresc,
            'Odnośnik z wiadomości nie prowadzi na trasę ustawiania hasła.',
        );

        // Ważność jest ta sama, co przy odzyskiwaniu hasła — nie własna
        // liczba dopisana obok. `expire` z `auth.php` to 60 minut, a widok
        // drukuje wtedy „przez godzinę".
        $this->assertSame(60, (int) config('auth.passwords.users.expire'),
            'Zmieniła się ważność tokenu resetu — sprawdź, czy wiadomość dalej mówi prawdę.');
        $this->assertStringContainsString('Przycisk działa przez godzinę', $tresc,
            'Wiadomość nie mówi, jak długo działa przycisk, albo mówi to inną liczbą niż `auth.passwords.users.expire`.');
    }

    // ------------------------------------------------------------------
    //  4. Nieodróżnialność odpowiedzi — bajt w bajt, dla trzech przypadków
    // ------------------------------------------------------------------

    /**
     * EKRAN MILCZY JEDNAKOWO DLA TRZECH RÓŻNYCH STANÓW ŚWIATA.
     *
     * Gdyby konto niepotwierdzone odpowiadało choćby o znak inaczej,
     * formularz odpowiadałby na pytanie „czy na tym adresie ktoś już założył
     * konto, którego właściciel skrzynki nie zakładał" — czyli zdradzałby
     * DOKŁADNIE tę sytuację, przed którą broni naprawa #317, i to komuś, kto
     * najczęściej jest jej sprawcą.
     *
     * PORÓWNUJEMY CAŁĄ ODPOWIEDŹ, nie samo słowo „wysłaliśmy": kod HTTP,
     * nagłówek `Location`, treść komunikatu co do znaku ORAZ to, czy zostały
     * błędy walidacji. Wzorzec wzięty z
     * `ZaproszenieDoRejestracjiTest::assertTakaSamaOdpowiedz` — tej
     * mocniejszej wersji, z `assertNotSame('', …)`, bo bez niej test jest
     * zielony także wtedy, gdy OBIE ścieżki wpadną w błąd walidacji i żadna
     * nie zostawi `status`.
     */
    public function test_odpowiedz_jest_nieodrozninalna_dla_konta_potwierdzonego_niepotwierdzonego_i_adresu_bez_konta(): void
    {
        $this->user('basia', ['email' => self::ADRES_POTWIERDZONY]);
        $this->niepotwierdzone('bogumila', self::ADRES_NIEPOTWIERDZONY);
        // ADRES_BEZ_KONTA świadomie nie dostaje konta.

        // Kontrola tego, że porównanie w ogóle ma sens: wszystkie trzy adresy
        // dają TĘ SAMĄ maskę. Gdyby ktoś kiedyś podmienił którąś stałą na
        // adres o innej pierwszej literze, test oblewałby się na masce —
        // czyli z niewłaściwego powodu — zamiast zgłosić to tutaj.
        $this->assertSame(
            AdresEmail::maska(self::ADRES_POTWIERDZONY),
            AdresEmail::maska(self::ADRES_NIEPOTWIERDZONY),
            'Trzy adresy tego testu muszą dawać tę samą maskę, inaczej porównanie mierzy maskę, nie naprawę.',
        );
        $this->assertSame(
            AdresEmail::maska(self::ADRES_POTWIERDZONY),
            AdresEmail::maska(self::ADRES_BEZ_KONTA),
            'Trzy adresy tego testu muszą dawać tę samą maskę, inaczej porównanie mierzy maskę, nie naprawę.',
        );

        $potwierdzone = $this->odpowiedzNa(self::ADRES_POTWIERDZONY);
        $niepotwierdzone = $this->odpowiedzNa(self::ADRES_NIEPOTWIERDZONY);
        $bezKonta = $this->odpowiedzNa(self::ADRES_BEZ_KONTA);

        $this->assertTakaSamaOdpowiedz($potwierdzone, $niepotwierdzone,
            'konta potwierdzonego', 'konta NIEPOTWIERDZONEGO');

        $this->assertTakaSamaOdpowiedz($potwierdzone, $bezKonta,
            'konta potwierdzonego', 'adresu BEZ KONTA');

        // Trzecia para jawnie, a nie „przez przechodniość": gdyby dwie
        // pierwsze asercje kiedyś przestały mierzyć (np. obie ścieżki
        // zaczną oddawać pustkę), ta trzecia nadal pokaże, która para
        // się rozjechała.
        $this->assertTakaSamaOdpowiedz($niepotwierdzone, $bezKonta,
            'konta NIEPOTWIERDZONEGO', 'adresu BEZ KONTA');

        // Kontrola, że test naprawdę oglądał komunikat tego formularza,
        // a nie pustkę po nieudanej walidacji.
        $this->assertStringContainsString('<ADRES>', $potwierdzone['komunikat'],
            'W komunikacie nie było maski adresu — to nie jest ekran, którego pilnuje ten test.');
    }

    // ------------------------------------------------------------------
    //  5. Dobowy budżet poczty — kanał boczny, nie kosmetyka
    // ------------------------------------------------------------------

    /**
     * BUDŻET ZAJMUJE SIĘ JEDNAKOWO WE WSZYSTKICH TRZECH PRZYPADKACH.
     *
     * `LoginLinkController::send()` rezerwuje miejsce PRZED wysyłką i oddaje
     * je przez `$budzet->zwolnij()`, gdy `handle()` odda `false`. Gdyby konto
     * z niepotwierdzonym adresem oddawało `false` (a taka „naprawa" jest
     * kusząco prosta — „po prostu nic nie wysyłajmy"), jego zużycie budżetu
     * różniłoby się od konta potwierdzonego i od adresu bez konta. Kto
     * ustawi się na OSTATNIEJ jednostce budżetu, wyczyta z tego jeden bit
     * o cudzym koncie — czyli dokładnie ten kanał, który D-085 zamknął dla
     * adresu bez konta.
     *
     * Zużycie czytamy wprost z `DziennyBudzetListow` (metoda `zuzyte()` jest
     * publiczna i jest ODCZYTEM), zamiast wnioskować o nim z tego, czy
     * następny list wyszedł — wzorzec z
     * `LogowanieLinkiemTest::test_adresy_bez_konta_nie_zjadaja_dobowego_budzetu`
     * mierzy to pośrednio, a tutaj potrzebujemy LICZBY, żeby porównać trzy
     * przypadki między sobą.
     */
    public function test_dobowy_budzet_poczty_zajmuje_sie_jednakowo_we_wszystkich_trzech_przypadkach(): void
    {
        config([
            // Z zapasem na trzy wysyłki — sufit nie jest tu przedmiotem
            // pomiaru, zużycie jest.
            'kuking.login_link.dzienny_budzet' => 10,
            // Adres bez konta dostaje zaproszenie (D-085) i właśnie dlatego
            // zajmuje miejsce w budżecie. Ustawiamy to jawnie, bo od tych
            // dwóch przełączników zależy, z czym porównujemy.
            //
            // PRZY WYŁĄCZONYCH ZAPROSZENIACH TEN TRZECI PRZYPADEK ZAJMUJE
            // ZERO — i to jest stan sprzed tej zmiany, nie jej skutek.
            // D-085 zamknęło kanał budżetowy dla adresu bez konta WŁAŚNIE
            // przez wysyłanie zaproszenia; wyłącznik `zaproszenia.wlaczone`
            // przywraca różnicę opisaną w D-056 jako znane ryzyko. Mierzymy
            // więc przy zaproszeniach WŁĄCZONYCH, bo inaczej porównywalibyśmy
            // naszą gałąź z wyłączoną cudzą funkcją, a nie z zachowaniem
            // serwisu.
            'kuking.login_link.zaproszenia.wlaczone' => true,
            'kuking.account.registration_open' => true,
        ]);

        $this->user('basia', ['email' => self::ADRES_POTWIERDZONY]);
        $this->niepotwierdzone('bogumila', self::ADRES_NIEPOTWIERDZONY);

        Notification::fake();

        $potwierdzone = $this->zuzycieBudzetuPrzy(self::ADRES_POTWIERDZONY);
        $niepotwierdzone = $this->zuzycieBudzetuPrzy(self::ADRES_NIEPOTWIERDZONY);
        $bezKonta = $this->zuzycieBudzetuPrzy(self::ADRES_BEZ_KONTA);

        // Kontrola dodatnia PIERWSZA: gdyby żaden z przypadków nie zajmował
        // ani jednej jednostki, wszystkie trzy byłyby równe zeru i test
        // przeszedłby, nie mierząc niczego (pułapka 4).
        $this->assertSame(1, $potwierdzone,
            'Konto z potwierdzonym adresem nie zajęło jednostki budżetu — porównanie niżej nic by nie mierzyło.');

        $this->assertSame($potwierdzone, $niepotwierdzone,
            'Konto z NIEPOTWIERDZONYM adresem zajmuje inaczej budżet poczty niż konto potwierdzone '
            .'— kto ustawi się na ostatniej jednostce, wyczyta z tego jeden bit o cudzym koncie.');

        $this->assertSame($potwierdzone, $bezKonta,
            'Konto z NIEPOTWIERDZONYM adresem zajmuje inaczej budżet poczty niż adres bez konta '
            .'— kto ustawi się na ostatniej jednostce, wyczyta z tego jeden bit o cudzym koncie.');
    }

    // ------------------------------------------------------------------
    //  6. Wąskość — naprawa nie rusza niczego poza swoim zakresem
    // ------------------------------------------------------------------

    /**
     * KONTO ZAMKNIĘTE Z NIEPOTWIERDZONYM ADRESEM NIE DOSTAJE NICZEGO.
     *
     * `wolnoWyslac()` stoi w `handle()` PRZED gałęzią #317 i ta kolejność
     * jest wymogiem: list z ustawieniem hasła wysłany na konto zablokowane
     * byłby obejściem decyzji moderacyjnej — wpuszczałby tam, gdzie nie
     * wpuszcza hasło (`LoginController`).
     *
     * W tym samym teście stoi KONTROLA DODATNIA na koncie zwykłym,
     * niepotwierdzonym. Bez niej asercja „nic nie poszło" byłaby zielona
     * także wtedy, gdyby ten formularz nie wysyłał już nic nikomu
     * (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
     */
    public function test_zamkniete_konto_z_niepotwierdzonym_adresem_nie_dostaje_ani_linku_ani_hasla(): void
    {
        $zamkniete = $this->niepotwierdzone('zablokowana', 'bozena@example.com', [
            'status' => User::STATUS_BANNED,
        ]);
        $kontrola = $this->niepotwierdzone('bogumila', self::ADRES_NIEPOTWIERDZONY);

        Notification::fake();

        $odmowa = $this->wyslijFormularz('bozena@example.com');
        $zwykla = $this->wyslijFormularz(self::ADRES_NIEPOTWIERDZONY);

        Notification::assertNotSentTo($zamkniete, LinkDoLogowania::class);
        Notification::assertNotSentTo($zamkniete, UstawienieHaslaZamiastLinku::class);

        // I ŻADNĄ INNĄ WIADOMOŚCIĄ O HAŚLE. `UstawienieNowegoHasla` stało tu
        // do 11 września 2026 i jest nadal wysyłane przez „nie pamiętam
        // hasła" — gdyby ktoś cofnął podmianę połowicznie, ta asercja to
        // złapie, a sama asercja wyżej nie.
        Notification::assertNotSentTo($zamkniete, UstawienieNowegoHasla::class);
        $this->assertDatabaseMissing('login_link_tokens', ['user_id' => $zamkniete->getKey()]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'bozena@example.com']);

        // Kontrola dodatnia: mechanizm żyje i dla zwykłego konta
        // niepotwierdzonego robi dokładnie to, co ma robić.
        Notification::assertSentTo($kontrola, UstawienieHaslaZamiastLinku::class);

        // I nikt się z ekranu nie dowiaduje, które z tych kont jest które.
        $this->assertSame($this->komunikat($zwykla), $this->komunikat($odmowa),
            'Ekran zdradza, że konto pod tym adresem jest zamknięte.');
    }

    /**
     * KONTO MODERATORA Z NIEPOTWIERDZONYM ADRESEM TAKŻE NIE DOSTAJE NICZEGO.
     *
     * Osobny test, a nie druga asercja w teście wyżej: to jest DRUGA GAŁĄŹ
     * warunku `wolnoWyslac()` i zgodnie z pułapką 3b każda gałąź potrzebuje
     * własnego testu — inaczej jeden sabotaż „obala" oba, a drugi nie obala
     * żadnego.
     *
     * Konta obsługi serwisu linku nie dostają z wyboru (issue #25): mają
     * hasło plus 2FA, a przeniesienie ich bezpieczeństwa na skrzynkę byłoby
     * rozluźnieniem. Naprawa #317 nie ma prawa otworzyć im TYLNYCH drzwi
     * listem z ustawieniem hasła.
     */
    public function test_moderator_z_niepotwierdzonym_adresem_nie_dostaje_ani_linku_ani_hasla(): void
    {
        $moderator = $this->niepotwierdzone('moderatorka', 'beata@example.com', [
            'role' => User::ROLE_MODERATOR,
        ]);
        $kontrola = $this->niepotwierdzone('bogumila', self::ADRES_NIEPOTWIERDZONY);

        Notification::fake();

        $odmowa = $this->wyslijFormularz('beata@example.com');
        $zwykla = $this->wyslijFormularz(self::ADRES_NIEPOTWIERDZONY);

        Notification::assertNotSentTo($moderator, LinkDoLogowania::class);
        Notification::assertNotSentTo($moderator, UstawienieHaslaZamiastLinku::class);

        // I ŻADNĄ INNĄ WIADOMOŚCIĄ O HAŚLE. `UstawienieNowegoHasla` stało tu
        // do 11 września 2026 i jest nadal wysyłane przez „nie pamiętam
        // hasła" — gdyby ktoś cofnął podmianę połowicznie, ta asercja to
        // złapie, a sama asercja wyżej nie.
        Notification::assertNotSentTo($moderator, UstawienieNowegoHasla::class);
        $this->assertDatabaseMissing('login_link_tokens', ['user_id' => $moderator->getKey()]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'beata@example.com']);

        Notification::assertSentTo($kontrola, UstawienieHaslaZamiastLinku::class);

        $this->assertSame($this->komunikat($zwykla), $this->komunikat($odmowa),
            'Ekran zdradza, że konto pod tym adresem należy do obsługi serwisu.');
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    /**
     * Konto z NIEPOTWIERDZONYM adresem, z profilem o poprawnej nazwie.
     *
     * Idzie przez `Tests\TestCase::user()`, a nie przez
     * `User::factory()->unverified()` wprost, z dwóch powodów: profil musi
     * powstać z nazwą przechodzącą `profiles_username_check`, a stan
     * fabryki i tak sprowadza się do tej jednej kolumny.
     *
     * @param  array<string, mixed>  $atrybuty
     */
    private function niepotwierdzone(string $nazwa, string $adres, array $atrybuty = []): User
    {
        return $this->user($nazwa, [
            ...$atrybuty,
            'email' => $adres,
            'email_verified_at' => null,
        ]);
    }

    /**
     * Wysłanie formularza `/logowanie/link` — dokładnie tak, jak robi to
     * `LogowanieLinkiemTest::wyslijFormularz()`.
     *
     * Bez tokenu Turnstile, bo w testach klucze są puste i reguła
     * `TurnstileJestPotwierdzony` wtedy przepuszcza (komplet sprawdzeń samego
     * Turnstile jest w `TurnstileWymagaPotwierdzeniaTest`). Limit po adresie
     * to 3 prośby na 60 minut, a limit trasowy po IP — 5 na 60; żaden test
     * w tym pliku nie wysyła formularza więcej niż trzy razy i żaden nie
     * powtarza tego samego adresu, więc mierzymy naprawę, a nie limity.
     */
    private function wyslijFormularz(string $adres): TestResponse
    {
        return $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => $adres]);
    }

    private function komunikat(TestResponse $odpowiedz): string
    {
        return (string) $odpowiedz->getSession()->get('status', '');
    }

    /**
     * Odpowiedź formularza SPISANA W CHWILI ŻĄDANIA.
     *
     * Wzorzec z `ZaproszenieDoRejestracjiTest::odpowiedzNa()` i powód jest
     * ten sam: `TestResponse::getSession()` oddaje sesję APLIKACJI, jedną
     * i tę samą dla wszystkich żądań, a komunikat leży w niej „na jeden
     * odczyt" (flash). Porównanie po fakcie czytałoby trzy razy tę samą
     * wartość — tę z żądania ostatniego — i przechodziłoby nawet wtedy, gdy
     * komunikaty naprawdę się różnią.
     *
     * Maskę wpisanego adresu wycinamy i to jedyna rzecz, którą tu wolno
     * znormalizować: bierze się ona z tego, co człowiek WPISAŁ, a nie z bazy,
     * więc nie mówi nic o istnieniu konta. Całą resztę porównujemy dosłownie.
     *
     * @return array{kod: int, gdzie: ?string, komunikat: string, bledy: array<string, mixed>}
     */
    private function odpowiedzNa(string $adres): array
    {
        $odpowiedz = $this->wyslijFormularz($adres);

        return [
            'kod' => $odpowiedz->getStatusCode(),
            'gdzie' => $odpowiedz->headers->get('Location'),
            'komunikat' => str_replace(
                AdresEmail::maska(User::normalizeEmail($adres)),
                '<ADRES>',
                (string) session('status', ''),
            ),
            'bledy' => $this->bledyWalidacji(),
        ];
    }

    /**
     * Błędy walidacji z sesji, sprowadzone do zwykłej tablicy komunikatów.
     *
     * NORMALIZACJA NIE JEST TU OZDOBĄ — `session('errors')` ma pod tym samym
     * kluczem RÓŻNE KSZTAŁTY. Bez błędów klucza nie ma wcale (`null`),
     * a po odbiciu się o walidację leży tam — przy sterowniku sesji z testów
     * — ZWYKŁA TABLICA `['default' => ['email' => [...]]]`, a nie
     * `ViewErrorBag`. Zmierzone: pierwsza wersja tej metody wołała wprost
     * `->getBag('default')` i kontrola ujemna oblała się na
     * „Call to a member function getBag() on array", czyli w innym miejscu
     * i z innego powodu niż sabotaż — a taka czerwień nie dowodzi niczego
     * (pułapka 8 §3 z `docs/PULAPKI_TESTOW.md`).
     *
     * @return array<string, mixed>
     */
    private function bledyWalidacji(): array
    {
        $bledy = session('errors');

        return match (true) {
            $bledy === null => [],
            $bledy instanceof ViewErrorBag => $bledy->getBag('default')->messages(),
            $bledy instanceof MessageBag => $bledy->messages(),
            is_array($bledy) => array_map(
                static fn ($worek) => $worek instanceof MessageBag ? $worek->messages() : (array) $worek,
                $bledy,
            ),
            default => ['nieznany_ksztalt' => get_debug_type($bledy)],
        };
    }

    /**
     * Porównuje CAŁE odpowiedzi: kod HTTP, adres przekierowania, komunikat
     * co do znaku i błędy walidacji. Różnica w którymkolwiek z tych czterech
     * jest wyrocznią „w jakim stanie jest konto pod tym adresem".
     *
     * @param  array{kod: int, gdzie: ?string, komunikat: string, bledy: array<string, mixed>}  $a
     * @param  array{kod: int, gdzie: ?string, komunikat: string, bledy: array<string, mixed>}  $b
     */
    private function assertTakaSamaOdpowiedz(array $a, array $b, string $coA, string $coB): void
    {
        $roznica = 'Odpowiedź dla '.$coA.' różni się od odpowiedzi dla '.$coB.' — ';

        $this->assertSame($a['kod'], $b['kod'],
            $roznica.'zdradza to kod HTTP.');

        $this->assertSame($a['gdzie'], $b['gdzie'],
            $roznica.'zdradza to adres przekierowania.');

        // BEZ TEJ ASERCJI test jest zielony także wtedy, gdy OBIE ścieżki
        // wpadną w błąd walidacji i żadna nie zostawi komunikatu. To jest ta
        // jedna rzecz, którą mocniejsza wersja wzorca ma ponad słabszą.
        $this->assertNotSame('', $a['komunikat'],
            $roznica.'a raczej: formularz nie zostawił żadnego komunikatu, więc porównanie nic nie mierzy.');

        $this->assertSame($a['komunikat'], $b['komunikat'],
            $roznica.'zdradza to treść komunikatu.');

        $this->assertSame($a['bledy'], $b['bledy'],
            $roznica.'zdradzają to błędy walidacji.');
    }

    /** Ile jednostek dobowego budżetu poczty zajęła JEDNA wysyłka formularza. */
    private function zuzycieBudzetuPrzy(string $adres): int
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        $przed = $budzet->zuzyte();

        $this->wyslijFormularz($adres)->assertSessionHasNoErrors();

        return $budzet->zuzyte() - $przed;
    }

    /**
     * Token jawny wyjęty Z WIADOMOŚCI, a nie z bazy — w bazie leży jego skrót.
     *
     * `Password::createToken()` (tak robi `PasswordResetSessionRotationTest`)
     * dałby token działający, ale WYSTAWIONY PRZEZ TEST. Tutaj chodzi o to,
     * żeby ofiara przeszła dokładnie tą drogą co człowiek: tokenem z wiadomości,
     * którą wysłał jej serwis.
     */
    private function tokenZWiadomosciZHaslem(User $konto): string
    {
        $token = null;

        Notification::assertSentTo(
            $konto,
            UstawienieHaslaZamiastLinku::class,
            function (UstawienieHaslaZamiastLinku $powiadomienie) use (&$token): bool {
                $token = $powiadomienie->token;

                return true;
            },
        );

        $this->assertIsString($token, 'Z wiadomości „Najpierw ustaw hasło" nie dało się wyjąć tokenu.');

        return $token;
    }

    /**
     * Wyrenderowana treść HTML wiadomości, którą dostało to konto.
     *
     * Renderujemy PRAWDZIWE powiadomienie przechwycone przez
     * `Notification::fake()`, a nie sam widok z ręcznie podstawionymi
     * zmiennymi. Widok renderowany osobno byłby zielony także wtedy, gdyby
     * `toMail()` przestał go w ogóle używać.
     */
    private function trescWiadomosci(User $konto): string
    {
        $html = null;

        Notification::assertSentTo(
            $konto,
            UstawienieHaslaZamiastLinku::class,
            function (UstawienieHaslaZamiastLinku $powiadomienie) use ($konto, &$html): bool {
                $html = (string) $powiadomienie->toMail($konto)->render();

                return true;
            },
        );

        $this->assertIsString($html, 'Wiadomości „Najpierw ustaw hasło" nie dało się wyrenderować.');

        return $html;
    }
}
