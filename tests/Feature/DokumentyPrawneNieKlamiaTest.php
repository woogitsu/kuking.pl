<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Support\AnalitykaCloudflare;
use App\Support\Odmiana;
use App\Support\Storage\DozwolonyHostR2;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\Laravel\ServiceProvider;
use Tests\TestCase;

/**
 * Trzy dokumenty prawne serwowane pod `/prywatnosc`, `/regulamin`
 * i `/zasady` nie mogą zawierać nieprawdy ani śladów redakcyjnych (D-024).
 *
 * DLACZEGO TO JEST TEST, A NIE UWAGA W DOKUMENTACJI
 * Bo ten sam błąd wystąpił już dwa razy i za każdym razem był NIEWIDOCZNY
 * z kodu: dokumenty leżą w `resources/legal/*.md`, `StaticPageController`
 * renderuje je pod publicznym adresem i nic nigdy nie sprawdzało, CO w nich
 * stoi. Zmierzone 7 września 2026 na żywej stronie:
 *
 *   - polityka prywatności miała w tytule „(SZKIC — wymaga weryfikacji
 *     prawnika przed publikacją)", a niżej `[NAZWA OPERATORA]`, `[ADRES]`
 *     i `[E-MAIL KONTAKTOWY]` — czyli człowiek szukający, kto administruje
 *     jego danymi, dostawał nawias kwadratowy;
 *   - zdanie „każdy z tych dostawców ma podpisaną z nami umowę powierzenia
 *     przetwarzania danych" — właściciel na wprost zapytany odpowiedział
 *     „nie, żadne nie są";
 *   - Sentry i PostHog jako podprocesorzy — żadne z nich nie jest wdrożone;
 *   - `[Wariant A — jeśli wdrożony baner:]` … `[Wariant B …]` — notatka
 *     redakcyjna dla autora, wyświetlana użytkownikowi;
 *   - „Staramy się odpowiadać w ciągu 48 godzin" w zasadach społeczności,
 *     przy zerze kodu mierzącego czas reakcji na zgłoszenie;
 *   - okresy przechowywania „orientacyjnie 12–24 miesiące" i „do 90 dni"
 *     dla danych, których nic nie usuwa.
 *
 * Ten test nie ocenia stylu ani nie pilnuje brzmienia zdań. Pilnuje trzech
 * rzeczy: (1) żadnych placeholderów i notatek redakcyjnych, (2) żadnych
 * narzędzi, których nie używamy, (3) każda LICZBA opisująca okres, która
 * w dokumencie została, zgadza się z konfiguracją, która ją egzekwuje.
 */
class DokumentyPrawneNieKlamiaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function dokumenty(): array
    {
        return [
            'polityka prywatności' => ['/prywatnosc', 'polityka-prywatnosci.md'],
            'regulamin' => ['/regulamin', 'regulamin.md'],
            'zasady społeczności' => ['/zasady', 'zasady.md'],
        ];
    }

    private function tresc(string $plik): string
    {
        $sciezka = resource_path('legal/'.$plik);
        $tresc = file_get_contents($sciezka);

        $this->assertIsString($tresc, "Nie da się wczytać {$sciezka}.");
        $this->assertNotSame('', trim((string) $tresc), "Dokument {$plik} jest pusty.");

        return (string) $tresc;
    }

    /**
     * KONTROLA. Wszystkie trzy adresy naprawdę odpowiadają i naprawdę
     * pokazują treść z tych plików — bez tego cały test niżej badałby pliki,
     * których nikt nie widzi.
     */
    #[DataProvider('dokumenty')]
    public function test_kontrola_dokument_jest_zywa_strona(string $adres, string $plik): void
    {
        $tresc = $this->tresc($plik);

        // Pierwszy nagłówek pierwszego poziomu — to jest tytuł, który widzi
        // człowiek.
        preg_match('/^# (.+)$/m', $tresc, $trafienie);
        $this->assertNotEmpty($trafienie, "Dokument {$plik} nie ma tytułu.");

        $this->get($adres)
            ->assertOk()
            ->assertSee(trim($trafienie[1]), escape: false);
    }

    /**
     * WŁAŚCIWY POMIAR. Żadnych placeholderów ani notatek redakcyjnych.
     */
    #[DataProvider('dokumenty')]
    public function test_brak_placeholderow_i_notatek_redakcyjnych(string $adres, string $plik): void
    {
        $tresc = $this->tresc($plik);

        $zakazane = [
            '[NAZWA OPERATORA]', '[ADRES]', '[E-MAIL KONTAKTOWY]',
            '[do ustalenia', '[X dni', '[Wariant', '[Jeśli dotyczy',
            '[do uzupełnienia', '[TODO', 'SZKIC', 'szkic roboczy',
            'wersja robocza', 'draft',
        ];

        foreach ($zakazane as $slad) {
            $this->assertStringNotContainsString(
                $slad,
                $tresc,
                "Dokument publikowany pod {$adres} zawiera „{$slad}” — to jest ślad redakcyjny, który widzi użytkownik.",
            );
        }

        // Nawias kwadratowy z wielkimi literami w środku to prawie zawsze
        // niewypełnione miejsce (`[COŚ DO UZUPEŁNIENIA]`). Adresy w formacie
        // Markdown (`[tekst](adres)`) są dozwolone i dlatego wzorzec wymaga,
        // żeby po nawiasie NIE było `(`.
        $this->assertSame(
            0,
            preg_match('/\[[A-ZĄĆĘŁŃÓŚŹŻ][A-ZĄĆĘŁŃÓŚŹŻ \-]{3,}\](?!\()/u', $tresc, $puste),
            "Dokument pod {$adres} zawiera niewypełnione miejsce: ".($puste[0] ?? ''),
        );
    }

    /**
     * Nie wymieniamy narzędzi, których nie używamy. Sentry i PostHog były
     * w polityce prywatności jako podprocesorzy; ani jedno, ani drugie nie
     * jest w kodzie (`grep` po całym repozytorium poza dokumentacją
     * badawczą).
     *
     * LISTA NIE JEST JUŻ STAŁA (D-092, 10 września 2026) — I TO JEST TU
     * RZECZ WAŻNIEJSZA NIŻ SAMA ZAWARTOŚĆ LISTY. Pyta o KAŻDE narzędzie, czy
     * kod go naprawdę używa, i zakazuje wyłącznie tych, których nie używa.
     * Dzięki temu wpięcie zewnętrznej analityki (dziś: Cloudflare Web
     * Analytics) nie wymaga ręcznego wykreślania nazwy z tablicy, a jej
     * usunięcie samo przywraca zakaz — bez zmiany w tym pliku. Póki wpięcie
     * żyje, obecności dostawcy w dokumencie pilnuje z drugiej strony
     * `PolitykaPrywatnosciWymieniaKazdaUslugeTest`.
     *
     * „Plausible" zostaje na liście jako narzędzie NIEUŻYWANE: stało tu przez
     * pół dnia jako wybrany kandydat i zostało odrzucone na cenie (D-092),
     * więc dokument prawny nie ma prawa go wymieniać inaczej niż w zdaniu
     * o tym, że go nie używamy.
     */
    #[DataProvider('dokumenty')]
    public function test_nie_wymieniamy_narzedzi_ktorych_nie_uzywamy(string $adres, string $plik): void
    {
        $tresc = $this->tresc($plik);

        // Narzędzie => czy kod go NAPRAWDĘ używa.
        $narzedzia = [
            'Sentry' => class_exists(ServiceProvider::class)
                && (string) config('sentry.dsn') !== '',
            'PostHog' => false,
            'Google Analytics' => false,
            'Matomo' => false,
            'Plausible' => false,
            // Pytanie brzmi „czy kod potrafi wysłać dane temu dostawcy", a nie
            // „czy akurat na tej maszynie wysyła": w testach i w CI token jest
            // pusty, więc `wlaczona()` oddaje `false` i cała pozycja
            // wypadałaby dokładnie tam, gdzie ma pilnować.
            'Cloudflare Web Analytics' => class_exists(AnalitykaCloudflare::class),
        ];

        $sprawdzone = 0;

        foreach (array_keys(array_filter($narzedzia, static fn (bool $uzywane): bool => ! $uzywane)) as $narzedzie) {
            $sprawdzone++;

            // Dokumentowi wolno NAZWAĆ narzędzie — byle w zdaniu o tym, że go
            // nie używamy. Sprawdzamy więc KAŻDE wystąpienie nazwy osobno,
            // w jego własnym zdaniu.
            //
            // POPRZEDNIA WERSJA TEGO SPRAWDZENIA BYŁA ZA SŁABA I WYSZŁO TO
            // DOPIERO PRZY KONTROLI UJEMNEJ (D-092, 10 września 2026).
            // Pytała `assertMatchesRegularExpression` o CAŁY dokument, czyli
            // „czy gdziekolwiek stoi zdanie zaprzeczające". Jedno takie
            // zdanie usprawiedliwiało wtedy wszystkie pozostałe wystąpienia
            // nazwy — dopisanie do polityki zdania „Do statystyk używamy
            // Google Analytics" NIE OBLAŁO testu, bo obok stało prawdziwe
            // „Nie korzystamy z Google Analytics". Dokładnie ten rodzaj
            // nieprawdy ten plik ma łapać.
            preg_match_all(
                '/'.preg_quote($narzedzie, '/').'/ui',
                $tresc,
                $wystapienia,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($wystapienia[0] as [$nazwa, $pozycja]) {
                // Zdanie, w którym stoi nazwa: wszystko od ostatniej kropki
                // przed nią. Kropka jest tu granicą celowo — „nie korzystamy
                // z X." i osobne „Używamy X." to dwa różne zdania i drugie
                // nie może się chować za pierwszym.
                $zdanie = (string) preg_replace('/.*\./su', '', substr($tresc, 0, (int) $pozycja));

                $this->assertMatchesRegularExpression(
                    '/(nie korzystamy|nie używamy|ani)/ui',
                    $zdanie,
                    "Dokument pod {$adres} wymienia „{$narzedzie}” inaczej niż w zdaniu o tym, że go NIE używamy. "
                    .'Sporne zdanie: „'.trim($zdanie.$nazwa).'”.',
                );
            }
        }

        // KONTROLA METODY POMIARU. Gdyby kiedyś okazało się, że „używamy"
        // wszystkich narzędzi z listy, pętla wyżej nie wykonałaby się ani
        // raz i test byłby zielony, nie sprawdzając niczego
        // (`docs/PULAPKI_TESTOW.md` §2).
        $this->assertGreaterThanOrEqual(
            3,
            $sprawdzone,
            'Lista narzędzi, których NIE używamy, skurczyła się do niczego — ten test przestał mierzyć.',
        );
    }

    /**
     * WŁAŚCIWY POMIAR (#619, D-255). Polityka prywatności mówi, że zdjęcia
     * w Cloudflare R2 leżą w jurysdykcji Unii Europejskiej. Bucket z tą
     * jurysdykcją jest osiągalny WYŁĄCZNIE przez endpoint
     * `<konto>.eu.r2.cloudflarestorage.com`, a endpoint jurysdykcyjny nie
     * sięga do bucketów spoza niej (`docs/infra/LOKALIZACJA_DANYCH_R2.md` §2).
     *
     * ŹRÓDŁEM PRAWDY JEST KOD, NIE ZMIENNA ŚRODOWISKOWA. Do 24.09.2026 ten
     * test czytał `AWS_ENDPOINT` z konfiguracji — czyli w CI, gdzie
     * prawdziwego endpointu nie ma, zawsze wymagał zdania „nie potwierdziliśmy”
     * i nie dało się napisać prawdy, którą potwierdził właściciel. Od D-255
     * aplikacja sama odmawia zbudowania dysku R2 pod adresem bez segmentu
     * `eu` (`App\Support\Storage\DozwolonyHostR2`, `/health` →
     * `magazyn_r2_zly_host`). Test pyta więc strażnika, jak potraktuje trzy
     * kształty adresu poza środowiskiem lokalnym:
     *
     *   - `<konto>.eu.r2…`  — musi przejść,
     *   - `<konto>.r2…`     — musi odpaść (bucket bez jurysdykcji),
     *   - `<konto>.us.r2…`  — musi odpaść (inna jurysdykcja).
     *
     * Dopiero wtedy polityka MOŻE (i MUSI) mówić „Unia Europejska”. Strażnik
     * poluzowany tak, że przepuszcza adres bez `eu`, oblewa ten test, dopóki
     * polityka tego obiecuje — kontrola dodatnia w
     * `scripts/kontrole-negatywne-alfa08.py` („Polityka obiecuje UE przy
     * strażniku bez eu”).
     */
    public function test_polityka_nie_obiecuje_jurysdykcji_r2_bez_pokrycia_w_endpoincie(): void
    {
        $tresc = $this->tresc('polityka-prywatnosci.md');

        $konto = str_repeat('0a', 16);
        $przechodzi = static fn (string $host): bool => DozwolonyHostR2::powod('https://'.$host, srodowiskoLokalne: false) === null;

        // KONTROLA METODY: gdyby strażnik odrzucał wszystko, „wymaga eu”
        // byłoby prawdą z pustego powodu (`docs/PULAPKI_TESTOW.md` §2).
        $this->assertTrue(
            $przechodzi("{$konto}.eu.r2.cloudflarestorage.com"),
            'Kontrola: strażnik R2 odrzuca nawet poprawny endpoint jurysdykcji UE — ten test przestał mierzyć.',
        );

        $potwierdzoneUE = ! $przechodzi("{$konto}.r2.cloudflarestorage.com")
            && ! $przechodzi("{$konto}.us.r2.cloudflarestorage.com");

        // Wiersz tabeli sekcji 3, gdzie R2 obiecuje lokalizację zdjęć.
        // Kotwicą jest nazwa dostawcy z pierwszej kolumny, tak jak przy
        // okresach retencji wyżej w tym pliku.
        $wiersze = array_values(array_filter(
            explode("\n", $tresc),
            static fn (string $linia): bool => str_starts_with(trim($linia), '| Cloudflare R2 '),
        ));

        // KONTROLA: bez dokładnie jednego wiersza pętla niżej sprawdzałaby
        // pustkę albo wiersz przypadkowy, a test byłby zielony, nie mierząc
        // niczego (`docs/PULAPKI_TESTOW.md` §2).
        $this->assertCount(
            1,
            $wiersze,
            'Kontrola: w polityce prywatności ma być dokładnie JEDEN wiersz o Cloudflare R2 — '
            .'znaleziono '.count($wiersze).'. Jeśli tabelę przebudowano, popraw kotwicę w tym teście.',
        );

        $wierszR2 = $wiersze[0];

        // Streszczenie na górze i akapit o przekazywaniu poza EOG powtarzają
        // tę samą obietnicę innymi słowami — każde z nich musi się zgadzać
        // z wierszem R2, inaczej dokument mówi dwie rzeczy naraz.
        preg_match('/## W skrócie\n\n(.+?)\n\n/su', $tresc, $skrot);
        $this->assertNotEmpty($skrot, 'Kontrola: nie znaleziono sekcji „W skrócie".');

        preg_match('/^\*\*Przekazywanie danych poza Europejski Obszar Gospodarczy:\*\*.*$/mu', $tresc, $eog);
        $this->assertNotEmpty($eog, 'Kontrola: nie znaleziono akapitu o przekazywaniu danych poza EOG.');

        $zdjeciaWUE = '/zdję[a-zć]*[^.]{0,80}Unii Europejskiej/iu';

        if ($potwierdzoneUE) {
            $this->assertStringContainsString(
                'Unia Europejska',
                $wierszR2,
                'Strażnik R2 wymaga jurysdykcji `eu` (D-255), ale wiersz Cloudflare R2 tego nie mówi czytelnikowi.',
            );
            $this->assertStringNotContainsString(
                'Nie potwierdziliśmy',
                $wierszR2,
                'Wiersz Cloudflare R2 mówi naraz „Unia Europejska” i „nie potwierdziliśmy”.',
            );
            $this->assertMatchesRegularExpression(
                $zdjeciaWUE,
                $skrot[1],
                'Streszczenie nie mówi tego, co wiersz Cloudflare R2: że zdjęcia leżą w Unii Europejskiej.',
            );
            $this->assertMatchesRegularExpression(
                $zdjeciaWUE,
                $eog[0],
                'Akapit o przekazywaniu poza EOG nie mówi, że zdjęcia leżą w Unii Europejskiej (Cloudflare R2).',
            );
        } else {
            $this->assertStringNotContainsString(
                'Unia Europejska',
                $wierszR2,
                'Wiersz Cloudflare R2 obiecuje „Unia Europejska”, ale strażnik `DozwolonyHostR2` '
                .'przepuszcza endpoint bez jurysdykcji `eu` — tej obietnicy kod nie pokrywa (#619, D-255).',
            );
            $this->assertStringContainsString(
                'Nie potwierdziliśmy',
                $wierszR2,
                'Wiersz Cloudflare R2 powinien wprost mówić, że lokalizacji zdjęć nie '
                .'potwierdzono, zamiast milczeć albo zgadywać kraj.',
            );
            $this->assertDoesNotMatchRegularExpression(
                $zdjeciaWUE,
                $skrot[1],
                'Streszczenie obiecuje zdjęcia w Unii Europejskiej, choć strażnik R2 tego nie wymusza.',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/zdjęć/u',
                (string) preg_replace('/Wyjątki są.*/su', '', $eog[0]),
                'Akapit o EOG zalicza zdjęcia do danych w UE, choć strażnik R2 tego nie wymusza.',
            );
        }
    }

    /**
     * Żadnych zdań o umowach powierzenia, których nie ma. Właściciel
     * odpowiedział na wprost zapytany: „nie, żadne nie są" podpisane.
     */
    public function test_nie_twierdzimy_ze_mamy_umowy_powierzenia(): void
    {
        $tresc = $this->tresc('polityka-prywatnosci.md');

        $this->assertStringNotContainsString('ma podpisaną z nami umowę powierzenia', $tresc);
        $this->assertStringNotContainsString('Każdy z tych dostawców ma podpisaną', $tresc);

        // KONTROLA: dokument mówi o tym wprost, a nie po prostu milczy.
        $this->assertStringContainsString('nie mamy podpisanych', $tresc);
    }

    /**
     * Hasła są HASZOWANE, nie szyfrowane. Różnica nie jest akademicka:
     * „zaszyfrowane" znaczy „da się odwrócić kluczem", a to jest zapewnienie
     * o innym poziomie ochrony niż ten, który dajemy.
     */
    public function test_nie_mowimy_ze_haslo_jest_zaszyfrowane(): void
    {
        $tresc = $this->tresc('polityka-prywatnosci.md');

        $this->assertSame(
            0,
            preg_match('/hasł\w*[^.]{0,60}zaszyfrowan|szyfrowanie hasł/ui', $tresc, $trafienie),
            'Polityka prywatności mówi, że hasło jest zaszyfrowane: '.($trafienie[0] ?? ''),
        );

        // KONTROLA: temat nie zniknął z dokumentu razem z nieprawdą.
        $this->assertStringContainsString('nieodwracaln', $tresc);
    }

    /**
     * WŁAŚCIWY POMIAR NAJWAŻNIEJSZY. Każda liczba dni, którą dokument
     * PODAJE, musi pochodzić z konfiguracji, która ją egzekwuje.
     *
     * Dziś są dwie takie liczby i obie mają za sobą kod: 90 dni retencji
     * sygnałów produktowych (komenda w harmonogramie) i 30 dni karencji
     * usunięcia konta (`kuking:usun-wygasle-konta`). Trzy pozostałe okresy
     * (zgłoszenia, dziennik zdarzeń, powiadomienia) zostały z dokumentu
     * USUNIĘTE, bo nic ich nie egzekwuje — patrz `docs/decyzje/ADR_RETENCJE.md`.
     */
    public function test_kazda_podana_liczba_dni_ma_za_soba_konfiguracje(): void
    {
        $tresc = $this->tresc('polityka-prywatnosci.md');

        $sygnaly = (int) config('kuking.analytics.signal_retention_days');
        $karencja = (int) config('kuking.account.delete_grace_days');

        $this->assertGreaterThan(0, $sygnaly, 'Kontrola: konfiguracja retencji sygnałów musi być liczbą dodatnią.');
        $this->assertGreaterThan(0, $karencja, 'Kontrola: konfiguracja karencji usunięcia konta musi być liczbą dodatnią.');

        $this->assertStringContainsString("**{$sygnaly} dni**", $tresc, 'Polityka nie podaje okresu retencji sygnałów zgodnego z konfiguracją.');
        $this->assertStringContainsString("**na {$karencja} dni**", $tresc, 'Polityka nie podaje karencji usunięcia konta zgodnej z konfiguracją.');

        // Liczby, które BYŁY w dokumencie bez pokrycia w kodzie.
        $this->assertStringNotContainsString('12–24 miesiące', $tresc);
        $this->assertStringNotContainsString('6–14 miesięcy', $tresc);
        $this->assertStringNotContainsString('30–90 dni', $tresc);
    }

    /**
     * Trzy okresy liczone w MIESIĄCACH też muszą się zgadzać z konfiguracją.
     *
     * DLACZEGO OSOBNA METODA, A NIE DOPISEK DO POPRZEDNIEJ
     * Tamta pilnuje dni i powstała, gdy dokument o tych trzech okresach
     * MILCZAŁ. Od 8 września nie milczy: właściciel rozstrzygnął, że polityka
     * ma podawać prawdziwe liczby, bo twierdziła wytłuszczonym drukiem, że
     * automatycznego usuwania nie ma — a trzy komendy kasują codziennie
     * o 04:10, 04:20 i 04:30. Zdanie było nieprawdziwe dwie godziny po tym,
     * jak kod je unieważnił.
     *
     * CZEGO TO PILNUJE NAPRAWDĘ
     * Okresy siedzą w zmiennych środowiskowych. Zmiana `KUKING_AUDIT_LOG_
     * RETENCJA...` na produkcji unieważniłaby opublikowany tekst prawny bez
     * żadnego ostrzeżenia — a to jest dokument, na którym polega podmiot
     * danych. Ten test jest jedyną rzeczą, która to zauważy.
     *
     * ODMIANA LICZEBNIKA JEST CZĘŚCIĄ SPRAWDZENIA, nie ozdobą. „3 miesiące"
     * i „12 miesięcy" to różne formy tego samego słowa; sztywny string
     * przepuściłby zmianę z 12 na 3 albo złamałby się przy niej z niewłaściwym
     * komunikatem. `Odmiana::rzeczownik()` liczy formę tak samo jak reszta
     * serwisu, więc test pada wtedy i tylko wtedy, gdy tekst naprawdę
     * rozjechał się z konfiguracją.
     */
    public function test_okresy_retencji_w_miesiacach_maja_za_soba_konfiguracje(): void
    {
        $tresc = $this->tresc('polityka-prywatnosci.md');

        // KAŻDY OKRES SZUKANY W SWOIM WIERSZU TABELI, NIE W CAŁYM DOKUMENCIE.
        //
        // Pierwsza wersja tego testu wołała `assertStringContainsString` na
        // całej treści i przez to nie odróżniała trzech okresów od siebie:
        // ustawienie retencji powiadomień na 12 miesięcy trafiałoby w napis
        // „**12 miesięcy**" z wiersza o dzienniku zdarzeń, test świeciłby na
        // zielono, a polityka prywatności mówiłaby o powiadomieniach nieprawdę.
        // Dokładnie ta usterka, przed którą ten plik ma bronić — tylko schowana
        // w narzędziu, nie w dokumencie.
        //
        // Kotwicą jest nazwa kategorii z pierwszej kolumny, bo to ona mówi
        // podmiotowi danych, o czym jest wiersz.
        $okresy = [
            'sprawy moderacyjne' => [
                'Obsługa zgłoszeń i moderacji',
                (int) config('kuking.moderation.case_retention_months'),
                ' od zamknięcia sprawy',
            ],
            'dziennik zdarzeń' => [
                'Bezpieczeństwo (dziennik ważnych zdarzeń',
                (int) config('kuking.audit_log.retention_months'),
                '',
            ],
            'powiadomienia' => [
                'Powiadomienia w serwisie',
                (int) config('kuking.notifications.retention_months'),
                '',
            ],
            // „Napisz do nas" — kontakt z operatorem. Kotwicą jest nazwa
            // kategorii z pierwszej kolumny, tak jak przy pozostałych.
            'wiadomości do nas' => [
                'Wiadomości do nas przez formularz',
                (int) config('kuking.kontakt.retention_months'),
                ' od załatwienia sprawy',
            ],
        ];

        foreach ($okresy as $nazwa => [$kotwica, $miesiecy, $dopisek]) {
            $wiersze = array_values(array_filter(
                explode("\n", $tresc),
                static fn (string $linia): bool => str_contains($linia, $kotwica),
            ));

            // DRUGA ASERCJA KONTROLNA: bez niej przemianowanie kategorii
            // w tabeli dawałoby zero wierszy, pętla nie sprawdzałaby niczego
            // i test byłby zielony przy dokumencie, którego nikt już nie pilnuje.
            $this->assertCount(
                1,
                $wiersze,
                'Kontrola: w polityce prywatności ma być dokładnie JEDEN wiersz '
                .'z kategorią: '.$kotwica.' — znaleziono '.count($wiersze).'. '
                .'Jeśli tabelę przebudowano, popraw kotwicę w tym teście.',
            );

            $wiersz = $wiersze[0];
            // ASERCJA KONTROLNA: pusta albo zerowa konfiguracja dałaby
            // oczekiwany fragment „**0 miesięcy**", którego w dokumencie nie
            // ma — test padłby z mylącym komunikatem zamiast powiedzieć, że
            // to konfiguracja jest zepsuta.
            $this->assertGreaterThan(
                0,
                $miesiecy,
                "Kontrola: konfiguracja retencji ({$nazwa}) musi być liczbą dodatnią.",
            );

            $oczekiwane = '**'.$miesiecy.' '
                .Odmiana::rzeczownik($miesiecy, 'miesiąc', 'miesiące', 'miesięcy')
                .$dopisek.'**';

            $this->assertStringContainsString(
                $oczekiwane,
                $wiersz,
                "Polityka prywatności nie podaje okresu retencji ({$nazwa}) zgodnego "
                ."z konfiguracją. Oczekiwane w tekście: {$oczekiwane} — jeśli okres "
                .'zmieniono świadomie, popraw dokument razem z konfiguracją. To jest '
                .'tekst, który czyta podmiot danych.',
            );
        }
    }

    /**
     * Zasady społeczności nie obiecują terminu odpowiedzi na zgłoszenie —
     * nic w kodzie nie mierzy czasu reakcji (`Report` nie ma odpowiednika
     * `Appeal::isOverdue()`), a to jest obietnica, której nie da się
     * spełnić przy jednej osobie bez żadnego przypomnienia.
     */
    public function test_zasady_nie_obiecuja_terminu_odpowiedzi(): void
    {
        $tresc = $this->tresc('zasady.md');

        $this->assertSame(
            0,
            preg_match('/w ciągu \d+ godzin|\d+ godzin(y|ach)? od zgłoszenia/ui', $tresc, $trafienie),
            'Zasady obiecują termin odpowiedzi na zgłoszenie: '.($trafienie[0] ?? ''),
        );

        // KONTROLA: obietnica została zastąpiona, a nie wycięta w milczeniu.
        $this->assertStringContainsString('bez zbędnej zwłoki', $tresc);
    }

    /**
     * Adres kontaktowy w dokumentach to TEN SAM adres, który kod pokazuje
     * w pouczeniach i z którego wysyła pocztę (decyzja właściciela,
     * 7 września 2026 — wcześniej kod pokazywał jeden, a wysyłał z drugiego).
     */
    public function test_adres_kontaktowy_zgadza_sie_z_konfiguracja(): void
    {
        $adres = (string) config('kuking.community.contact_email');

        $this->assertNotSame('', $adres, 'Kontrola: konfiguracja musi mieć adres kontaktowy.');
        $this->assertSame($adres, (string) config('mail.from.address'), 'Adres pokazywany ludziom i adres nadawcy poczty to dwa różne adresy.');
        $this->assertStringContainsString($adres, $this->tresc('polityka-prywatnosci.md'));
    }

    /**
     * TOŻSAMOŚĆ ADMINISTRATORA STOI W DOKUMENTACH I ZGADZA SIĘ Z KONFIGURACJĄ.
     *
     * RODO art. 13 ust. 1 lit. a każe podać, kto jest administratorem,
     * W MOMENCIE zbierania danych — czyli przy rejestracji, nie na żądanie.
     * Do 8 września 2026 oba dokumenty mówiły „serwis prowadzi osoba
     * fizyczna" i obiecywały dane później; to blokowało otwarcie rejestracji.
     *
     * Test pilnuje dwóch rzeczy naraz i obie są potrzebne:
     *
     *  1. że dane W OGÓLE tam są — inaczej pierwsza redakcja dokumentu
     *     wycięłaby je z powrotem i nikt by tego nie zauważył;
     *  2. że są TE SAME co w `config/kuking.php` — bo numer KRS albo adres
     *     zmienia się w rejestrze, nie w markdownie, i wtedy poprawka
     *     w jednym miejscu zostawia w drugim nieprawdę na żywej stronie.
     *
     * Sprawdzane są oba dokumenty, nie jeden: regulamin mówi, KTO PROWADZI
     * serwis, a polityka — KTO ADMINISTRUJE DANYMI. To dwie różne role tego
     * samego podmiotu i obie muszą być podpisane.
     */
    public function test_tozsamosc_administratora_zgadza_sie_z_konfiguracja(): void
    {
        $podmiot = [
            'nazwa spółki' => (string) config('kuking.podmiot.nazwa_pelna'),
            'ulica' => (string) config('kuking.podmiot.ulica'),
            'kod pocztowy' => (string) config('kuking.podmiot.kod_pocztowy'),
            'miejscowość' => (string) config('kuking.podmiot.miejscowosc'),
            'KRS' => (string) config('kuking.podmiot.krs'),
            'NIP' => (string) config('kuking.podmiot.nip'),
            'REGON' => (string) config('kuking.podmiot.regon'),
            'adres e-mail' => (string) config('kuking.podmiot.email'),
        ];

        foreach ($podmiot as $nazwa => $wartosc) {
            // KONTROLA: pusty wpis w konfiguracji przeszedłby przez
            // `assertStringContainsString` bez mrugnięcia, bo pusty ciąg
            // zawiera się w każdym tekście.
            $this->assertNotSame('', $wartosc, "Konfiguracja nie podaje pozycji „{$nazwa}”.");
        }

        foreach (['polityka-prywatnosci.md', 'regulamin.md'] as $plik) {
            $tresc = $this->tresc($plik);

            foreach ($podmiot as $nazwa => $wartosc) {
                $this->assertStringContainsString(
                    $wartosc,
                    $tresc,
                    "W dokumencie {$plik} nie ma pozycji „{$nazwa}” ({$wartosc}) z config/kuking.php. "
                    .'Człowiek czytający ten dokument nie dowie się, komu powierza swoje dane.',
                );
            }

            $this->assertStringNotContainsString(
                'prowadzi osoba fizyczna',
                $tresc,
                "Dokument {$plik} nadal mówi, że serwis prowadzi osoba fizyczna. "
                .'Prowadzi go spółka i to jest w tej chwili nieprawda na żywej stronie.',
            );
        }
    }

    // ---------------------------------------------------------------
    // Notatki autora do samego siebie, podane czytelnikowi (D-140)
    // ---------------------------------------------------------------

    /**
     * WZORCE NOTATKI ROBOCZEJ W DOKUMENCIE, KTÓRY CZYTA CZŁOWIEK.
     *
     * CO ZOBACZYŁ WŁAŚCICIEL 11 WRZEŚNIA 2026 na `/regulamin`, kursywą tuż
     * pod paragrafem 12:
     *
     *     „Czego w tym dokumencie jeszcze nie ma, a będzie: tożsamości
     *      i adresu osoby prowadzącej serwis."
     *
     * Polityka prywatności miała swój odpowiednik tej noty, a w sekcji
     * „Źródła" stało jeszcze `[numer artykułu do potwierdzenia — patrz
     * COMPLIANCE.md sekcja 5.1]` i „Zobacz pełną listę źródeł
     * w `COMPLIANCE.md`" — czyli odesłanie czytelnika do pliku, który leży
     * w repozytorium i którego nie ma jak otworzyć.
     *
     * DLACZEGO TO JEST BŁĄD, A NIE DROBIAZG
     * Dokument, który sam o sobie mówi „tego tu jeszcze nie ma", czyta się
     * jak brudnopis, a nie jak wiążąca umowa — a regulamin ma być czymś,
     * na czym da się polegać. To jest ta sama rodzina usterek co placeholdery
     * łapane wyżej (`[NAZWA OPERATORA]`, `[Wariant A …]`), tylko napisana
     * pełnym, ładnym zdaniem, więc poprzednie wzorce ją przepuszczały.
     *
     * CZEGO TEN TEST NIE ROBI
     * Nie rusza zdań o STANIE USŁUGI, także niewygodnych. „Nie podajemy tu
     * liczby dni, bo nie ustaliliśmy jej jeszcze z dostawcą",
     * „Umów powierzenia jeszcze nie mamy podpisanych" czy „Nie wyznaczyliśmy
     * inspektora ochrony danych" to fakty o serwisie i mają prawo stać
     * w dokumencie. Usterką jest wyłącznie notatka o PISANIU dokumentu.
     * Granicę trzyma metoda kontrolna na końcu tego pliku — obie strony,
     * łapie i przepuszcza, mają własną asercję.
     *
     * Luki, o których przypominały usunięte noty, są spisane
     * w `docs/legal/COMPLIANCE.md` §7.1 — usunięcie noty nie wypełnia
     * obowiązku prawnego, tylko przestaje o nim przypominać.
     *
     * @var array<string, string>
     */
    private const WZORCE_NOTATEK = [
        // `COMPLIANCE.md`, `AGENTS.md` — plik repozytorium w dokumencie dla
        // człowieka. Czytelnik strony nie ma do niego dostępu i nie wie,
        // czym jest.
        'nazwa pliku repozytorium' => '/(?<![\w\/])[A-Za-z][\w-]*\.md\b/u',

        // `docs/legal/...`, `resources/legal/...` — to samo, ścieżką.
        // Adresy widoczne dla człowieka (`/zasady`, `/prywatnosc`) zaczynają
        // się od ukośnika i tu nie wpadają.
        'ścieżka w repozytorium' => '/(?<![\w\/])(docs|resources|app|config|tests|database|routes)\/[a-z]/u',

        // „Czego w tym dokumencie jeszcze nie ma, a będzie: …" — dokument
        // mówiący o sobie, że jest niedokończony.
        'dokument mówi o sobie, że jest niepełny' => '/czego[^.\n]{0,80}jeszcze nie ma/ui',

        // „[numer artykułu do potwierdzenia]", „tożsamość do uzupełnienia",
        // „projekt do weryfikacji przez prawnika".
        'rzecz odłożona do potwierdzenia' => '/\bdo (potwierdzenia|uzupełnienia|ustalenia|dopisania|weryfikacji|rozstrzygnięcia)\b/ui',

        'znacznik roboczy z kodu' => '/\b(TODO|FIXME|XXX)\b/u',

        // Uwaga dla siebie w nawiasie kwadratowym. Odnośnik Markdown
        // (`[tekst](adres)`) ma po nawiasie `(` i jest wyłączony — poprzedni
        // wzorzec w tym pliku wymagał do tego WERSALIKÓW w środku, więc
        // notatkę pisaną małymi literami przepuszczał.
        'uwaga w nawiasie kwadratowym' => '/\[[^\]\n]{6,}\](?!\()/u',
    ];

    /**
     * @return array<int, string> opis wzorca → trafiony fragment
     */
    private static function trafieniaNotatek(string $tekst): array
    {
        $trafienia = [];

        foreach (self::WZORCE_NOTATEK as $opis => $wzorzec) {
            if (preg_match($wzorzec, $tekst, $dopasowanie) === 1) {
                $trafienia[] = $opis.' → „'.trim($dopasowanie[0]).'”';
            }
        }

        return $trafienia;
    }

    /**
     * WŁAŚCIWY POMIAR. Trzy dokumenty prawne bez notatek o ich pisaniu.
     */
    #[DataProvider('dokumenty')]
    public function test_brak_notatek_roboczych_o_pisaniu_dokumentu(string $adres, string $plik): void
    {
        $trafienia = self::trafieniaNotatek($this->tresc($plik));

        $this->assertSame(
            [],
            $trafienia,
            "Dokument publikowany pod {$adres} zawiera notatkę roboczą autora: \n  "
            .implode("\n  ", $trafienia)."\n"
            .'Czytelnik ma przed sobą wiążącą umowę, a nie brudnopis. Jeśli chodzi '
            .'o brak, który naprawdę jest — zapisz go w docs/legal/COMPLIANCE.md §7.1, '
            .'a nie w dokumencie dla ludzi.',
        );
    }

    /**
     * To samo na stronach informacyjnych składanych w Blade, bo tam notatka
     * też ma jak wejść — tyle że nie z Markdowna, a z widoku.
     *
     * Badany jest WYRENDEROWANY blok treści, nie plik: komentarze Blade
     * (`{{-- … --}}`) są w tym repozytorium gęste, mówią wprost o plikach
     * w `docs/` i nikt ich nigdy nie zobaczy. Test, który czytałby plik
     * źródłowy, oblewałby na nich wszystkich naraz i zostałby wyłączony
     * pierwszego dnia.
     */
    #[DataProvider('stronyInformacyjne')]
    public function test_strony_informacyjne_bez_notatek_roboczych(string $adres, string $naglowek): void
    {
        $html = $this->get($adres)->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/<article[^>]*>(.*)<\/article>/su', (string) $html, $blok),
            "Na stronie {$adres} nie ma bloku <article> z treścią — jeśli widok "
            .'przebudowano, popraw kotwicę w tym teście zamiast go kasować.',
        );

        $tekst = html_entity_decode(strip_tags($blok[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // KONTROLA METODY POMIARU: to naprawdę jest treść tej strony, a nie
        // pusty string, w którym nic się nie znajdzie.
        $this->assertStringContainsString(
            $naglowek,
            $tekst,
            "Kontrola: treść strony {$adres} nie zawiera własnego nagłówka.",
        );

        $trafienia = self::trafieniaNotatek($tekst);

        $this->assertSame(
            [],
            $trafienia,
            "Strona {$adres} pokazuje człowiekowi notatkę roboczą: \n  "
            .implode("\n  ", $trafienia),
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function stronyInformacyjne(): array
    {
        return [
            // Nagłówek to „O kuKING" — dwukolorowy zapis nazwy
            // (`docs/brand/GLOS_MARKI.md` §2) rozbija słowo na kilka
            // elementów, więc po `strip_tags` stoi tam „O  kuKING kuking".
            // Kontrolą metody pomiaru jest tu samo słowo; napisu „O Kuking"
            // w treści tej strony już nie ma i wpisanie go tutaj sprawdzałoby
            // nieistniejący stan.
            'o Kuking' => ['/o-kuking', 'kuKING'],
            'pomoc' => ['/pomoc', 'Pomoc'],
        ];
    }

    /**
     * KONTROLA WZORCÓW, w obie strony — wzór wzięty z
     * `TekstyNiePrzypisujaPlciTest::test_wzorce_lapia_to_co_wlasciciel_widzial_i_przepuszczaja_poprawne`.
     *
     * Skan bez tej metody jest zielony także wtedy, gdy wzorce przestaną
     * cokolwiek łapać — a zepsuć je da się tak, że nadal się kompilują.
     * Dlatego stoją tu PRAWDZIWE zdania: te, które właściciel widział na
     * ekranie, i te poprawne, które muszą przechodzić.
     */
    public function test_wzorce_notatek_lapia_to_co_wlasciciel_widzial_i_przepuszczaja_poprawne(): void
    {
        $zle = [
            // Dokładnie to, co było na `/regulamin` i `/prywatnosc`.
            'Czego w tym dokumencie jeszcze nie ma, a będzie: tożsamości i adresu osoby prowadzącej serwis.',
            'Czego w tym dokumencie jeszcze nie ma, a będzie: dostawcy poczty oraz liczby dni, przez które dane żyją w kopiach zapasowych.',
            'Ustawa Prawo komunikacji elektronicznej (2024) [numer artykułu do potwierdzenia — patrz `COMPLIANCE.md` sekcja 5.1]',
            'Zobacz pełną listę źródeł w `COMPLIANCE.md`',
            'Czy taki zapis w liście transakcyjnym wymaga od nas czegoś więcej — to zostaje do potwierdzenia.',
            // Ta sama klasa, inne brzmienie.
            'Tożsamość operatora do uzupełnienia przed startem.',
            'Okres przechowywania kopii zapasowych — do ustalenia z dostawcą.',
            'Klauzula o kolażach jest projektem do weryfikacji przez prawnika.',
            'TODO: dopisać adres spółki.',
            'Szczegóły w docs/legal/COMPLIANCE.md sekcja 5.1.',
            'Pełna analiza leży w AGENTS.md.',
            '[Wariant A — jeśli wdrożony baner:] Używamy plików cookies do statystyk.',
            '[do sprawdzenia, czy to nadal aktualne]',
        ];

        foreach ($zle as $zdanie) {
            $this->assertNotSame(
                [],
                self::trafieniaNotatek($zdanie),
                "Wzorce przepuściły notatkę roboczą: „{$zdanie}”. "
                .'Któryś wzorzec w WZORCE_NOTATEK przestał działać — napraw wzorzec, nie ten test.',
            );
        }

        $dobre = [
            // Zdania o STANIE USŁUGI. Mówią o braku, są niewygodne i zostają.
            'Nie podajemy tu liczby dni, bo nie ustaliliśmy jej jeszcze z dostawcą — podamy ją, gdy będzie potwierdzona.',
            // UWAGA: to zdanie przechodzi tutaj i OBLEWA nowy skan
            // samouzasadniania niżej — na ogonie „i mówimy to wprost,
            // zamiast pisać, że mamy". Fakt („umów nie mamy podpisanych")
            // jest chroniony w obu miejscach; oblewa retoryka dopisana za
            // nim. To nie jest sprzeczność między testami, a granica: jeden
            // pilnuje notatek o PISANIU dokumentu, drugi tonu.
            'Umów powierzenia przetwarzania danych z tymi dostawcami jeszcze nie mamy podpisanych i mówimy to wprost, zamiast pisać, że mamy.',
            'Nie wyznaczyliśmy inspektora ochrony danych. Jeśli to się zmieni, podamy jego dane w tym miejscu.',
            'Jeśli w przyszłości dojdzie kolejny dostawca spoza EOG, dopiszemy go do tabeli wyżej.',
            'Ten dokument opisuje stan serwisu na 7 września 2026 i jest aktualizowany razem z nim.',
            'Nie zawiera terminów ani procedur, których serwis nie umie dziś wykonać.',
            'Serwis prowadzi na razie jedna osoba, więc nie obiecujemy, że odwołanie rozpatrzy ktoś inny.',
            'Nie mamy dziś zewnętrznego narzędzia do zbierania błędów.',
            // Jak wyżej: notatką o pisaniu dokumentu to nie jest, ale
            // dokumentem mówiącym o sobie samym — tak, i dlatego stoi też
            // na liście złych zdań w skanie samouzasadniania.
            'Do tego czasu ten akapit stoi tu dlatego, że opisuje stan faktyczny.',
            // Podstawy prawne w sekcji „Źródła" — to jest wartość dla
            // czytelnika, nie notatka.
            'Rozporządzenie Parlamentu Europejskiego i Rady (UE) 2016/679 (RODO) — Art. 6, 8, 13–20, 28, 33–34',
            'Ustawa z dnia 4 lutego 1994 r. o prawie autorskim i prawach pokrewnych — Art. 1, Art. 81',
            'Ustawa Prawo komunikacji elektronicznej (2024) — przepisy o przechowywaniu informacji w urządzeniu końcowym (cookies)',
            // Odnośnik Markdown i adresy widoczne dla człowieka.
            'Co do niego wysyłamy — opisuje [polityka prywatności](/polityka-prywatnosci).',
            'Zasady Kuking są dostępne pod `/zasady`, a polityka pod `/prywatnosc`.',
            'Wejdź w Ustawienia → Twoje dane i kliknij „Przygotuj paczkę z moimi danymi”.',
            'Garnek.pl przestał działać 25 listopada 2024 roku.',
        ];

        foreach ($dobre as $zdanie) {
            $this->assertSame(
                [],
                self::trafieniaNotatek($zdanie),
                "Wzorce złapały poprawne zdanie: „{$zdanie}”. To jest zdanie o stanie "
                .'usługi albo podstawa prawna — wzorzec jest za szeroki i trzeba go zwęzić.',
            );
        }
    }

    // ---------------------------------------------------------------
    // Tok rozumowania autora podany czytelnikowi (D-???, 11 września 2026)
    // ---------------------------------------------------------------

    /**
     * WZORCE STYLU, KTÓRY TŁUMACZY SIĘ ZAMIAST INFORMOWAĆ.
     *
     * REGUŁA, KTÓREJ TE WZORCE PILNUJĄ
     * Użytkownikowi piszemy, CO SIĘ DZIEJE, CO TO DLA NIEGO ZNACZY i CO MA
     * ZROBIĆ. Powód architektoniczny, historia decyzji, odrzucone warianty
     * i dowód z naszego audytu zostają w repozytorium — w `docs/DECISIONS.md`
     * i w komentarzach kodu, gdzie są zaletą.
     *
     * CO STAŁO NA ŻYWEJ STRONIE 11 WRZEŚNIA 2026
     *
     *   - „Sprawdziliśmy to, czytając ten skrypt linijka po linijce, a nie
     *     wierząc na słowo…" — dziennik naszego audytu analityki w miejscu,
     *     w którym czytelnik szuka odpowiedzi, czy coś zapisuje mu się na
     *     telefonie;
     *   - „Sprawdziliśmy to 9 września 2026 na prawdziwym liście doręczonym
     *     do skrzynki, czytając jego surowe źródło…" — protokół debugowania
     *     poczty w polityce prywatności;
     *   - „Uważamy, że nie ma to prawa tak zostać, i mówimy dlaczego." wraz
     *     z „Do tego czasu ten akapit stoi tu dlatego, że opisuje stan
     *     faktyczny; zniknie razem z samym śledzeniem" — felieton i dokument
     *     mówiący o sobie samym;
     *   - „Szczegóły techniczne opisujemy w naszym wewnętrznym dokumencie
     *     bezpieczeństwa." — odesłanie do dokumentu, którego czytelnik nie
     *     ma; ta sama rodzina co usunięte w D-140 „patrz `COMPLIANCE.md`",
     *     tylko bez nazwy pliku, więc wzorzec na nazwę pliku jej nie łapał;
     *   - „nie podajemy tu liczby godzin, bo nie mamy dziś w serwisie nic,
     *     co ten termin mierzy i pilnuje" — brak mechanizmu w NASZYM kodzie
     *     podany jako odpowiedź na pytanie „kiedy odpowiecie".
     *
     * GRANICA, KTÓREJ TE WZORCE NIE PRZEKRACZAJĄ — I TO JEST TU RZECZ
     * NAJWAŻNIEJSZA
     * Zdanie o FAKCIE dotyczącym usługi zostaje, także niewygodne. Trzy
     * zdania są chronione wprost i stoją niżej na liście zdań, które MUSZĄ
     * przechodzić: „umów powierzenia jeszcze nie mamy podpisanych", „nie
     * wyznaczyliśmy inspektora ochrony danych" i „nie podajemy tu liczby
     * dni, bo nie ustaliliśmy jej jeszcze z dostawcą". Skasowanie
     * któregokolwiek z nich zrobiłoby dokument MNIEJ prawdziwym, a nie
     * mniej gadatliwym — i dlatego druga strona tego testu istnieje.
     * Braki, o których te zdania mówią, są śledzone w
     * `docs/legal/COMPLIANCE.md` §2.8 i §7.1.
     *
     * @var array<string, string>
     */
    private const WZORCE_SAMOUZASADNIANIA = [
        // „czytając ten skrypt linijka po linijce".
        'dziennik naszego audytu' => '/linijk[aęi]\s+po\s+linijce/ui',

        // „a nie wierząc na słowo" — polemika z niewypowiedzianym zarzutem.
        'polemika z niewypowiedzianym zarzutem' => '/nie wierząc na słowo/ui',

        // „Sprawdziliśmy to 9 września 2026 na prawdziwym liście…",
        // „Sprawdziliśmy to, czytając…", „i to sprawdziliśmy w jego treści".
        'protokół naszego sprawdzenia' => '/\b(sprawdziliśmy|zmierzyliśmy|przeczytaliśmy)\b[^.\n]{0,80}(czytając|surowe źródło|w jego treści|w jej treści|linijk|\b20\d\d\b)/ui',

        // „i mówimy dlaczego", „i mówimy to wprost", „mówimy o tym wprost".
        // Zapowiedź tłumaczenia się albo podkreślanie własnej uczciwości.
        'zapowiedź tłumaczenia się' => '/\bmówimy\s+(dlaczego|o tym wprost|to wprost)/ui',

        // „Uczciwie o granicy tego pierwszego wariantu:".
        'rama „uczciwie o…"' => '/\buczciwie\s+(o|mówiąc|wobec)\b/ui',

        // „Uważamy, że nie ma to prawa tak zostać", „wbrew naszemu zamiarowi".
        'ocena moralna własnej decyzji' => '/\b(uważamy, że|nie ma to prawa|naszym zdaniem|wbrew naszemu zamiarowi)\b/ui',

        // „Do tego czasu ten akapit stoi tu dlatego, że…", „Opisujemy to, bo
        // zachodzi" — dokument tłumaczący, po co sam siebie napisał.
        'dokument mówi o sobie samym' => '/\b(ten (akapit|ustęp|punkt|wiersz) (stoi|jest tu|zniknie)|opisujemy to, bo|piszemy o tym, bo)/ui',

        // „wewnętrzny dokument bezpieczeństwa" — odesłanie do papieru,
        // którego czytelnik nie ma i nie może dostać.
        // UWAGA NA ODMIANĘ: „w naszym wewnętrznym DOKUMENCIE" — rdzeń to
        // `dokumen`, nie `dokument`, i pierwsza wersja tego wzorca
        // (`dokument\p{L}*`) przepuszczała dokładnie to zdanie, które miała
        // łapać. Złapała to dopiero kontrola wzorców niżej.
        'odesłanie do naszego wewnętrznego dokumentu' => '/\b(wewnętrzn\p{L}*\s+(\p{L}+\s+)?dokumen\p{L}*|dokumen\p{L}*\s+wewnętrzn\p{L}*)/ui',

        // „i to jest okres, który serwis naprawdę pilnuje: co noc usuwa…".
        // Fakt (co noc usuwamy) zostaje, obrona liczby schodzi.
        'obrona podanej liczby' => '/okres, który serwis naprawdę pilnuje/ui',

        // „nie obiecujemy, że… — obiecujemy natomiast, że…" — retoryka
        // w miejscu, w którym czytelnik pyta, co się stanie z jego sprawą.
        'figura „nie obiecujemy — obiecujemy natomiast"' => '/nie obiecujemy[^.\n]{0,120}obiecujemy natomiast/ui',

        // „bo nie mamy dziś w serwisie nic, co ten termin mierzy i pilnuje" —
        // brak funkcji w naszym kodzie jako informacja dla czytelnika.
        // WĄSKO, bo „bo nie ustaliliśmy jej jeszcze z dostawcą" (chronione)
        // ma przechodzić: wzorzec wymaga i zaprzeczenia, i słowa o kodzie.
        'brak w kodzie jako odpowiedź dla czytelnika' => '/\bbo\s+(nie mamy|nie ma|nic)\b[^.\n]{0,80}(w serwisie|w kodzie|w repozytorium|mierzy|pilnuje)/ui',
    ];

    /**
     * @return array<int, string> opis wzorca → trafiony fragment
     */
    private static function trafieniaSamouzasadniania(string $tekst): array
    {
        $trafienia = [];

        foreach (self::WZORCE_SAMOUZASADNIANIA as $opis => $wzorzec) {
            if (preg_match($wzorzec, $tekst, $dopasowanie) === 1) {
                $trafienia[] = $opis.' → „'.trim($dopasowanie[0]).'”';
            }
        }

        return $trafienia;
    }

    /**
     * WŁAŚCIWY POMIAR. Trzy dokumenty prawne bez toku rozumowania autora.
     */
    #[DataProvider('dokumenty')]
    public function test_brak_samouzasadniania_w_dokumentach_prawnych(string $adres, string $plik): void
    {
        $trafienia = self::trafieniaSamouzasadniania($this->tresc($plik));

        $this->assertSame(
            [],
            $trafienia,
            "Dokument publikowany pod {$adres} tłumaczy sam siebie zamiast informować: \n  "
            .implode("\n  ", $trafienia)."\n"
            .'Czytelnikowi piszemy, co się dzieje, co to dla niego znaczy i co ma zrobić. '
            .'Powód, historia decyzji i dowód z audytu zostają w docs/DECISIONS.md. '
            .'UWAGA: poprawką jest SKRÓCENIE zdania do faktu, nigdy usunięcie faktu — '
            .'jeśli zdanie mówi o braku po naszej stronie (brak umów powierzenia, brak IOD, '
            .'nieustalony okres kopii zapasowych), fakt zostaje w dokumencie.',
        );
    }

    /**
     * KONTROLA WZORCÓW, w obie strony — wzór ten sam co przy notatkach
     * wyżej i co w `TekstyNiePrzypisujaPlciTest::test_wzorce_lapia_to_co_
     * wlasciciel_widzial_i_przepuszczaja_poprawne`.
     *
     * DRUGA POŁOWA TEJ METODY JEST WAŻNIEJSZA NIŻ PIERWSZA. Bez niej
     * najprostszym sposobem na zielony skan jest skasowanie zdania „umów
     * powierzenia nie mamy podpisanych" — i nikt by tego nie zauważył, bo
     * test świeciłby wtedy jeszcze mocniej na zielono.
     */
    public function test_wzorce_samouzasadniania_lapia_felieton_i_przepuszczaja_fakty(): void
    {
        // Zdania wzięte DOSŁOWNIE z `resources/legal/` z 11 września 2026,
        // przed tą poprawką.
        $zle = [
            'Sprawdziliśmy to, czytając ten skrypt linijka po linijce, a nie wierząc na słowo: nie ma w nim ani jednego odwołania do plików cookie ani do żadnej pamięci przeglądarki, więc nie ma czym Cię oznaczyć.',
            'Sprawdziliśmy to 9 września 2026 na prawdziwym liście doręczonym do skrzynki, czytając jego surowe źródło, a nie wierząc na słowo.',
            '**Uważamy, że nie ma to prawa tak zostać, i mówimy dlaczego.**',
            'Do tego czasu ten akapit stoi tu dlatego, że opisuje stan faktyczny; zniknie razem z samym śledzeniem, a nie zamiast niego.',
            'Szczegóły techniczne opisujemy w naszym wewnętrznym dokumencie bezpieczeństwa.',
            'Umów powierzenia przetwarzania danych z tymi dostawcami jeszcze nie mamy podpisanych i mówimy to wprost, zamiast pisać, że mamy.',
            'Przy logowaniu kontem Google i kontem Facebooka jest inaczej i mówimy to wprost:',
            'Wejście kontem Facebooka jest tu przypadkiem innym niż te trzy i mówimy o tym wprost:',
            '**36 miesięcy od zamknięcia sprawy** — i to jest okres, który serwis naprawdę pilnuje: co noc usuwa zamknięte zgłoszenia starsze niż 36 miesięcy.',
            'Uczciwie o granicy tego pierwszego wariantu: zdjęcie usuniętego podpisu nie czyni tekstu anonimowym.',
            'Do tego — dziś, i wbrew naszemu zamiarowi — nasz dostawca poczty rejestruje otwarcie takiego listu.',
            'My tych danych nie odczytujemy i do niczego nie używamy. Opisujemy to, bo zachodzi — i wyłączamy to u dostawcy, patrz sekcja 3.',
            'Nie jest to ustawienie, które ktoś mógłby nam po cichu przestawić — ten skrypt po prostu nie umie nic zapisać, i to sprawdziliśmy w jego treści.',
            'Odpowiadamy bez zbędnej zwłoki, a sprawy poważne bierzemy pierwsze — nie podajemy tu liczby godzin, bo nie mamy dziś w serwisie nic, co ten termin mierzy i pilnuje.',
            'Serwis prowadzi jedna osoba, więc nie obiecujemy, że Twoje odwołanie rozpatrzy ktoś inny niż autor pierwszej decyzji — obiecujemy natomiast, że tej samej decyzji nie da się podtrzymać od razu.',
        ];

        foreach ($zle as $zdanie) {
            $this->assertNotSame(
                [],
                self::trafieniaSamouzasadniania($zdanie),
                "Wzorce przepuściły tok rozumowania autora: „{$zdanie}”. "
                .'Któryś wzorzec w WZORCE_SAMOUZASADNIANIA przestał działać — napraw wzorzec, nie ten test.',
            );
        }

        $dobre = [
            // TRZY ZDANIA CHRONIONE WPROST. Mówią o braku po naszej stronie,
            // są niewygodne i są jedyną informacją, jaką czytelnik ma o tej
            // części przetwarzania. Wolno je skracać, nie wolno kasować.
            'Umów powierzenia przetwarzania danych z tymi dostawcami jeszcze nie mamy podpisanych.',
            'Nie wyznaczyliśmy inspektora ochrony danych. Jeśli to się zmieni, podamy jego dane w tym miejscu.',
            'Nie podajemy tu liczby dni, bo nie ustaliliśmy jej jeszcze z dostawcą — podamy ją, gdy będzie potwierdzona.',
            // Fakty o usłudze, które zostały po skróceniu felietonu.
            '**Tego liczenia otwarć nie da się wyłączyć z naszego kodu** — jest ustawieniem konta u dostawcy i wyłączamy je po jego stronie.',
            'My tych danych nie odczytujemy i do niczego nie używamy — patrz sekcja 3.',
            '**Nie zapisuje niczego na Twoim urządzeniu** — ani pliku cookie, ani nic w pamięci przeglądarki, więc nie ma czym Cię oznaczyć.',
            'Co noc usuwamy wpisy starsze niż 12 miesięcy.',
            'Serwis prowadzi jedna osoba, więc Twoje odwołanie rozpatrzy zwykle autor pierwszej decyzji.',
            'Podtrzymać własną decyzję może najwcześniej po 24 godzinach od jej podjęcia — cofnąć ją może od razu.',
            'Odpowiadamy bez zbędnej zwłoki, a sprawy poważne bierzemy pierwsze. Nie obiecujemy konkretnej liczby godzin.',
            'Nie mamy dziś zewnętrznego narzędzia do zbierania błędów.',
            'Jak długo go tam trzyma, zależy od planu, który mamy wykupiony u Railway.',
            'Nie podajemy tu liczby dni, bo nie potwierdziliśmy jej jeszcze w ustawieniach konta u dostawcy — podamy ją, gdy będzie potwierdzona.',
            'Ten dokument opisuje stan serwisu na 11 września 2026 i jest aktualizowany razem z nim.',
            // „naprawdę" NIE JEST SŁOWEM ZAKAZANYM. Wzorzec łapie jedną
            // frazę obronną, a nie samo słowo — inaczej padłoby zdanie
            // otwierające zasady społeczności i hasło całego serwisu.
            'Kuking to miejsce dla ludzi, którzy naprawdę gotują.',
            'Kuking to serwis, w którym poznajesz innych, którzy naprawdę gotują.',
            // Fakty o cudzych usługach i podstawy prawne.
            'Cloudflare deklaruje, że danych z Turnstile nie używa do profilowania reklamowego.',
            'Rozporządzenie Parlamentu Europejskiego i Rady (UE) 2016/679 (RODO) — Art. 6, 8, 13–20, 28, 33–34',
            'Ustawa Prawo komunikacji elektronicznej (2024) — przepisy o przechowywaniu informacji w urządzeniu końcowym (cookies)',
        ];

        foreach ($dobre as $zdanie) {
            $this->assertSame(
                [],
                self::trafieniaSamouzasadniania($zdanie),
                "Wzorce złapały poprawne zdanie: „{$zdanie}”. To jest FAKT o usłudze "
                .'albo podstawa prawna — zwęź wzorzec. Jeśli to zdanie zniknęło z dokumentu, '
                .'przywróć je: dokument bez niego jest mniej prawdziwy, nie mniej gadatliwy.',
            );
        }
    }

    // =====================================================================
    //  DOKUMENTY WEWNĘTRZNE — `docs/legal/`
    // =====================================================================
    //
    //  DLACZEGO TO TU DOSZŁO (audyt zgodności 19 września 2026, rozjazd R4)
    //  `docs/legal/COMPLIANCE.md` miała na liście gotowości DWA blokujące
    //  punkty P0 o Sentry i PostHog — usługach, których w tym projekcie nigdy
    //  nie było — i ani jednego punktu o Cloudflare Web Analytics, OpenAI
    //  i logowaniu Facebookiem, czyli o trzech integracjach, które naprawdę
    //  wysyłają dane na zewnątrz. Do tego dwa odnośniki do plików
    //  `REGULAMIN_DRAFT.md` i `POLITYKA_PRYWATNOSCI_DRAFT.md`, których nie ma.
    //
    //  Strażnik na to ISTNIAŁ — `test_nie_wymieniamy_narzedzi_ktorych_nie_uzywamy`
    //  wyżej — ale jego data provider wymieniał trzy opublikowane dokumenty
    //  i `docs/legal/` było poza zasięgiem. Rozjazd przeżył nie dlatego, że
    //  testu nie było, tylko dlatego, że test patrzył obok.
    //
    //  DLATEGO PROVIDER NIŻEJ SKANUJE KATALOG, A NIE WYMIENIA PLIKÓW.
    //  Gdyby wymieniał, następny dokument dołożony do `docs/legal/` znowu
    //  wypadłby spod nadzoru i R4 odrosłoby w nowym miejscu. To jest cała
    //  różnica między tą poprawką a wpisaniem sześciu nazw z palca.

    /**
     * Dokumenty wewnętrzne procedur, skanowane z katalogu.
     *
     * @return array<string, array{0: string}>
     */
    public static function dokumentyWewnetrzne(): array
    {
        $pliki = glob(self::katalogWewnetrzny().'/*.md') ?: [];

        $wynik = [];
        foreach ($pliki as $sciezka) {
            $nazwa = basename($sciezka);

            /*
             * RAPORTY AUDYTU SĄ WYŁĄCZONE I TO JEST DECYZJA, NIE NIEDOPATRZENIE.
             * Raport z audytu CYTUJE wadliwe zdanie dosłownie — na tym polega
             * dowód. Gdyby podlegał tej samej regule co procedura, nie dałoby
             * się opisać rozjazdu, nie łamiąc testu, który go pilnuje.
             * Wyłączenie jest wąskie (prefiks `AUDYT_`) i sprawdzone niżej:
             * test upomina się, gdyby pochłonęło większość katalogu.
             */
            if (str_starts_with($nazwa, 'AUDYT_')) {
                continue;
            }

            $wynik[$nazwa] = [$nazwa];
        }

        return $wynik;
    }

    /*
     * Katalog liczony ZE ŚCIEŻKI PLIKU, nie przez `base_path()`.
     * Data provider PHPUnit wykonuje się PRZED bootem aplikacji, więc
     * helpery Laravela jeszcze nie działają — pierwsza wersja tego kodu
     * oblała dokładnie tak: „Call to undefined method Container::basePath()".
     */
    private static function katalogWewnetrzny(): string
    {
        return dirname(__DIR__, 2).'/docs/legal';
    }

    private function trescWewnetrzna(string $plik): string
    {
        $sciezka = self::katalogWewnetrzny().'/'.$plik;
        $tresc = file_get_contents($sciezka);

        $this->assertIsString($tresc, "Nie da się wczytać {$sciezka}.");
        $this->assertNotSame('', trim((string) $tresc), "Dokument {$plik} jest pusty.");

        return (string) $tresc;
    }

    /**
     * KONTROLA METODY. Skan katalogu naprawdę coś znalazł, a wyłączenie
     * raportów audytu nie zjadło go w całości (`docs/PULAPKI_TESTOW.md` §2:
     * skan, który nic nie znajduje, uznaje to za sukces).
     */
    public function test_kontrola_skan_dokumentow_wewnetrznych_cos_znajduje(): void
    {
        $wszystkie = glob(self::katalogWewnetrzny().'/*.md') ?: [];
        $nadzorowane = self::dokumentyWewnetrzne();

        $this->assertGreaterThanOrEqual(
            4,
            count($nadzorowane),
            'Skan `docs/legal/` objął mniej niż cztery dokumenty — albo katalog zniknął, '
            .'albo wyłączenie raportów audytu jest za szerokie. Tak czy inaczej ten test przestał mierzyć.',
        );

        $this->assertGreaterThan(
            count($wszystkie) / 2,
            count($nadzorowane),
            'Wyłączenie raportów audytu pochłonęło więcej niż połowę katalogu `docs/legal/`.',
        );

        // Procedury, na których to sprawdzenie ma stać, MUSZĄ w nim być.
        // Bez tej asercji zmiana nazwy pliku po cichu zdejmowałaby nadzór.
        $wymagane = ['COMPLIANCE.md', 'BRAMKA_BETY.md', 'MODERATION_PLAYBOOK.md', 'SECURITY_BASELINE.md', 'SYGNALY_AUTOMATU.md'];

        foreach ($wymagane as $wymagany) {
            $this->assertArrayHasKey(
                $wymagany,
                $nadzorowane,
                "Dokumentu {$wymagany} nie ma pod nadzorem. Jeśli zmienił nazwę — popraw tę listę; "
                .'jeśli zniknął — sprawdź, czy procedura, którą opisywał, nadal gdzieś żyje.',
            );
        }
    }

    /**
     * TO JEST POPRAWKA R4. Dokument wewnętrzny też nie może wymieniać
     * narzędzia, którego kod nie używa — a to właśnie lista kontrolna
     * gotowości, nie polityka prywatności, decyduje o odblokowaniu #29.
     *
     * Lista narzędzi i sposób pytania są TE SAME co w teście dokumentów
     * publikowanych: pytamy kod, czy narzędzia używa, i zakazujemy wyłącznie
     * tych, których nie używa. Dzięki temu wpięcie albo usunięcie dostawcy
     * nie wymaga ręcznej zmiany w tym pliku.
     *
     * Granica zdania jest tu szersza niż w dokumentach publikowanych: kropka
     * ALBO koniec wiersza. Procedury są pisane tabelami i punktami listy,
     * gdzie kropek często nie ma, a wiersz tabeli jest osobną myślą.
     */
    #[DataProvider('dokumentyWewnetrzne')]
    public function test_dokument_wewnetrzny_nie_wymienia_narzedzi_ktorych_nie_uzywamy(string $plik): void
    {
        $wiersze = self::wierszeListyKontrolnej($this->trescWewnetrzna($plik));
        $nieuzywane = self::narzedziaNieuzywane();

        $this->assertGreaterThanOrEqual(
            3,
            count($nieuzywane),
            'Lista narzędzi, których NIE używamy, skurczyła się do niczego — ten test przestał mierzyć.',
        );

        foreach ($wiersze as $nr => $wiersz) {
            foreach ($nieuzywane as $narzedzie) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $narzedzie,
                    $wiersz,
                    "Wiersz listy gotowości w docs/legal/{$plik} (wiersz {$nr}) stawia warunek wobec „{$narzedzie}”, "
                    .'a tej usługi w projekcie nie ma. Sporny wiersz: „'.trim($wiersz).'”. '
                    .'To jest rozjazd klasy R4: bramki „czy można startować” nie da się odhaczyć w dobrej wierze, '
                    .'więc zostanie odhaczona na oko.',
                );
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Drugi rozjazd z R4: lista kontrolna odsyłała do `REGULAMIN_DRAFT.md`
     * i `POLITYKA_PRYWATNOSCI_DRAFT.md`, których nie ma — dokumenty żyją
     * w `resources/legal/`. Odnośnik do nieistniejącego pliku w liście
     * gotowości jest gorszy niż jego brak: każe komuś odhaczyć punkt,
     * którego nie da się otworzyć.
     */
    #[DataProvider('dokumentyWewnetrzne')]
    public function test_dokument_wewnetrzny_nie_odsyla_do_nieistniejacych_plikow(string $plik): void
    {
        $tresc = $this->trescWewnetrzna($plik);

        /*
         * DWIE FORMY ODSYŁACZA, BO R4 ZNALAZŁO TĘ DRUGĄ.
         * `REGULAMIN_DRAFT.md` i `POLITYKA_PRYWATNOSCI_DRAFT.md` nie stały
         * w odnośnikach Markdown, tylko w backtickach w środku zdania
         * („patrz `REGULAMIN_DRAFT.md`"). Test łapiący wyłącznie `[x](y.md)`
         * przeszedłby obok dokładnie tego rozjazdu, który ma pilnować.
         */
        preg_match_all('/\[[^\]]*\]\(([^)#\s]+\.md)[^)]*\)/u', $tresc, $markdown);
        preg_match_all('/`([A-Za-z0-9_\/.-]+\.md)`/u', $tresc, $backticki);

        $cele = array_unique(array_merge($markdown[1], $backticki[1]));

        foreach ($cele as $cel) {
            if (str_starts_with($cel, 'http')) {
                continue;
            }

            /*
             * Sprawdzamy tylko to, co WYGLĄDA na plik tego repozytorium:
             * gołą nazwę albo ścieżkę od znanego katalogu. Dokumenty cytują
             * też ścieżki z cudzych maszyn i z przykładów powłoki
             * (`bp/kuking-platform/docs/…`) — takiego pliku u nas nie ma
             * i mieć nie musi, a test nie jest od pilnowania cudzych dysków.
             */
            $znaneKorzenie = ['docs/', 'resources/', 'app/', 'scripts/', 'tests/', 'database/', 'config/'];
            $wlasny = ! str_contains($cel, '/')
                || array_any($znaneKorzenie, static fn (string $k): bool => str_starts_with($cel, $k));

            if (! $wlasny) {
                continue;
            }

            /*
             * Ścieżka sprawdzana wprost, a GOŁA NAZWA — w całym drzewie
             * dokumentów. Dokumenty wprowadzają plik pełną ścieżką
             * (`docs/decyzje/ADR_RETENCJE.md`), a kilka akapitów niżej wracają
             * do niego samą nazwą. Pierwsza wersja tego testu uznawała to
             * drugie za martwy odsyłacz — pilnowała formy zapisu zamiast tego,
             * czy plik istnieje.
             */
            $korzen = dirname(__DIR__, 2);
            $kandydaci = [
                self::katalogWewnetrzny().'/'.$cel,
                $korzen.'/'.$cel,
                $korzen.'/docs/'.$cel,
                $korzen.'/resources/legal/'.$cel,
            ];

            $istnieje = array_any($kandydaci, static fn (string $sciezka): bool => file_exists($sciezka))
                || (! str_contains($cel, '/') && in_array($cel, self::nazwyDokumentow(), true));

            $this->assertTrue(
                $istnieje,
                "Dokument docs/legal/{$plik} odsyła do „{$cel}”, a tego pliku nie ma w żadnym "
                .'ze sprawdzanych miejsc. Albo popraw ścieżkę, albo usuń odsyłacz — punkt listy, '
                .'którego nie da się otworzyć, zostanie odhaczony na oko.',
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Nazwy wszystkich dokumentów `.md` w drzewie `docs/` — do rozstrzygania
     * odsyłaczy podanych samą nazwą pliku.
     *
     * @return list<string>
     */
    private static function nazwyDokumentow(): array
    {
        static $nazwy = null;

        if ($nazwy === null) {
            $nazwy = [];
            $katalog = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/docs', \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($katalog as $plik) {
                if ($plik->isFile() && $plik->getExtension() === 'md') {
                    $nazwy[] = $plik->getFilename();
                }
            }
        }

        return $nazwy;
    }

    /**
     * Wiersze listy kontrolnej gotowości — i tylko one.
     *
     * Format w `docs/legal/` to tabela Markdown zaczynająca wiersz od
     * priorytetu: `| P0 | warunek | odpowiedź |`. Świadomie NIE obejmujemy
     * prozy: analiza ma prawo opisywać odrzuconych kandydatów, a razem z nimi
     * pomiar, na podstawie którego ich odrzucono.
     *
     * ŚWIADOMIE NIE MA TU TESTU NA `[do ustalenia]`. W dokumencie
     * publikowanym taki ślad jest usterką, bo widzi go użytkownik.
     * W wewnętrznej analizie prawnej jest odwrotnie: to uczciwe oznaczenie
     * pytania otwartego, czekającego na prawnika. Test zakazujący takich
     * miejsc kazałby zamienić szczerą niewiedzę na ciszę — a cisza w liście
     * gotowości jest groźniejsza niż jawne „do ustalenia".
     *
     * @return array<int, string> numer wiersza => treść
     */
    private static function wierszeListyKontrolnej(string $tresc): array
    {
        $wynik = [];

        foreach (explode("\n", $tresc) as $i => $wiersz) {
            if (preg_match('/^\|\s*P[0-2]\s*\|/u', $wiersz) === 1) {
                $wynik[$i + 1] = $wiersz;
            }
        }

        return $wynik;
    }

    /**
     * Narzędzia, których kod NIE używa — jedno źródło dla obu testów,
     * publikowanego i wewnętrznego.
     *
     * @return list<string>
     */
    private static function narzedziaNieuzywane(): array
    {
        $narzedzia = [
            'Sentry' => class_exists(ServiceProvider::class)
                && (string) config('sentry.dsn') !== '',
            'PostHog' => false,
            'Google Analytics' => false,
            'Matomo' => false,
            'Plausible' => false,
            'Cloudflare Web Analytics' => class_exists(AnalitykaCloudflare::class),
        ];

        return array_keys(array_filter($narzedzia, static fn (bool $uzywane): bool => ! $uzywane));
    }

    // =====================================================================
    //  BRAMKA BETY — lista gotowości ma mówić prawdę bez pytania człowieka
    // =====================================================================
    //
    //  Audyt procedur z 20 września 2026 znalazł w `docs/legal/` cztery
    //  rozjazdy, których nikt nie pilnował. Każdy dostał tu strażnika i każdy
    //  strażnik SKANUJE, zamiast wyliczać — bo rozjazd, który naprawiono bez
    //  strażnika, odrasta w następnym dopisanym wierszu.

    /**
     * Każdy wiersz listy gotowości ma dowód.
     *
     * DLACZEGO TO JEST NAJWAŻNIEJSZY TEST W TYM PLIKU
     * Lista gotowości jest bramką „czy można wpuścić pierwszych ludzi".
     * Wiersz bez dowodu nie jest neutralny — jest gorszy niż brak wiersza,
     * bo wygląda na sprawdzony. Właściciel ma móc ją odhaczyć, nie pytając
     * nikogo, czy mówi prawdę.
     *
     * Dowodem jest jedno z trzech: nazwa testu albo `plik:linia`,
     * jawne `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` albo jawne `BRAK:`.
     */
    #[DataProvider('dokumentyWewnetrzne')]
    public function test_kazdy_wiersz_listy_gotowosci_ma_dowod(string $plik): void
    {
        $wiersze = self::wierszeListyKontrolnej($this->trescWewnetrzna($plik));

        if ($wiersze === []) {
            $this->addToAssertionCount(1);

            return;
        }

        foreach ($wiersze as $nr => $wiersz) {
            $maDowod = preg_match(
                '/(DO SPRAWDZENIA PRZEZ CZŁOWIEKA:|BRAK:|[A-Z][A-Za-z0-9]*Test|\.php|routes\/|config\/)/u',
                $wiersz,
            ) === 1;

            $this->assertTrue(
                $maDowod,
                "Wiersz listy gotowości w docs/legal/{$plik} (wiersz {$nr}) nie ma dowodu. "
                .'Dopisz nazwę testu albo `plik:linia`, albo napisz wprost '
                .'„DO SPRAWDZENIA PRZEZ CZŁOWIEKA:" lub „BRAK:". '
                .'Sporny wiersz: „'.trim($wiersz).'”.',
            );
        }
    }

    /**
     * Procedura nie może przemilczeć sygnału, którym automat oznacza treść.
     *
     * ZNALEZIONE 20 WRZEŚNIA: `SYGNALY_AUTOMATU.md` wymieniał trzy sygnały
     * (`automat_wzorzec` 3, `automat_odnosnik` 2, `automat_powtorzenie` 1),
     * a kod miał CZWARTY — `automat_model` z wagą **4**, czyli wyższą niż
     * wszystkie opisane. Moderator czytający listę sygnałów nie wiedział,
     * że istnieje najcięższy z nich.
     *
     * To jest ta klasa rozjazdu, której nikt nie szuka: nie „dokument
     * obiecuje coś, czego nie ma", tylko „kod robi więcej, niż dokument mówi".
     */
    public function test_procedura_wymienia_kazdy_sygnal_automatu(): void
    {
        $sygnaly = array_keys(Report::REASONS_AUTOMAT);
        $dokument = $this->trescWewnetrzna('SYGNALY_AUTOMATU.md');

        $this->assertGreaterThanOrEqual(
            3,
            count($sygnaly),
            'Sygnałów automatu jest mniej niż trzy — ten test przestał mierzyć.',
        );

        foreach ($sygnaly as $sygnal) {
            $this->assertStringContainsString(
                $sygnal,
                $dokument,
                "Kod oznacza treść sygnałem „{$sygnal}” (waga ".Report::WAGA[$sygnal].'), '
                .'a `SYGNALY_AUTOMATU.md` w ogóle go nie wymienia. Moderator czyta ten dokument, '
                .'żeby wiedzieć, co automat potrafi podnieść — lista musi być pełna.',
            );
        }
    }

    /**
     * Komenda wymieniona w procedurze musi istnieć.
     *
     * Procedury obiecują siedem komend retencji. Gdy któraś zmieni nazwę
     * albo zniknie, dokument dalej będzie twierdził, że dane są sprzątane —
     * a to jest obietnica wobec człowieka, nie szczegół techniczny.
     */
    #[DataProvider('dokumentyWewnetrzne')]
    public function test_komendy_wymienione_w_procedurach_istnieja(string $plik): void
    {
        preg_match_all('/`(kuking:[a-z0-9:-]+)`/u', $this->trescWewnetrzna($plik), $trafienia);

        $komendy = array_unique($trafienia[1]);

        if ($komendy === []) {
            $this->addToAssertionCount(1);

            return;
        }

        $zarejestrowane = array_keys(Artisan::all());

        foreach ($komendy as $komenda) {
            $this->assertContains(
                $komenda,
                $zarejestrowane,
                "Dokument docs/legal/{$plik} wymienia komendę „{$komenda}”, a `php artisan` jej nie zna. "
                .'Albo zmieniła nazwę, albo zniknęła — w obu przypadkach dokument obiecuje sprzątanie, '
                .'którego nikt nie robi.',
            );
        }
    }

    /**
     * Liczba, którą procedura podaje jako pomiar, musi zgadzać się z pomiarem.
     *
     * ZNALEZIONE 20 WRZEŚNIA: `SECURITY_BASELINE.md` tłumaczył, dlaczego
     * `style-src` ma jeszcze `unsafe-inline`, liczbą „355 atrybutów `style=`
     * w 57 plikach". Naprawdę było ich **169 w 14 plikach** — dług skurczył
     * się o ponad połowę i nikt tego nie zauważył, bo liczba raz wpisana
     * w dokument nigdy więcej nie była mierzona.
     *
     * Ten test nie pilnuje konkretnej liczby. Pilnuje, żeby liczba w zdaniu
     * nadal opisywała rzeczywistość — i to on powie, kiedy zejdzie do zera,
     * czyli kiedy dyrektywę wolno wreszcie docisnąć.
     */
    public function test_liczba_atrybutow_style_w_dokumencie_zgadza_sie_z_pomiarem(): void
    {
        $dokument = $this->trescWewnetrzna('SECURITY_BASELINE.md');

        $znalezione = preg_match(
            '/(\d+)\s+atrybut[^.]{0,20}`style="?…?"?`?[^.]{0,20}w\s+(\d+)\s+plik/u',
            $dokument,
            $zapisane,
        );

        $this->assertSame(
            1,
            $znalezione,
            'Nie znalazłem w SECURITY_BASELINE.md zdania z liczbą atrybutów `style=`. '
            .'Jeśli zdanie zniknęło razem z długiem — usuń też ten test. '
            .'Jeśli tylko zmieniło brzmienie — popraw wzorzec, nie kasuj pomiaru.',
        );

        [$atrybuty, $pliki] = self::policzAtrybutyStyle();

        $this->assertSame(
            $atrybuty,
            (int) $zapisane[1],
            "SECURITY_BASELINE.md mówi o {$zapisane[1]} atrybutach `style=`, a jest ich {$atrybuty}. "
            .'Popraw liczbę w dokumencie — a jeśli doszła do zera, dociśnij `style-src` i usuń akapit.',
        );

        $this->assertSame(
            $pliki,
            (int) $zapisane[2],
            "SECURITY_BASELINE.md mówi o {$zapisane[2]} plikach z atrybutem `style=`, a jest ich {$pliki}.",
        );
    }

    /**
     * @return array{0: int, 1: int} liczba atrybutów i liczba plików
     */
    private static function policzAtrybutyStyle(): array
    {
        $katalog = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views', \FilesystemIterator::SKIP_DOTS),
        );

        $atrybuty = 0;
        $pliki = 0;

        foreach ($katalog as $plik) {
            if (! $plik->isFile() || $plik->getExtension() !== 'php') {
                continue;
            }

            $ile = preg_match_all('/style="[^"]*"/u', (string) file_get_contents($plik->getPathname()));

            if ($ile > 0) {
                $atrybuty += $ile;
                $pliki++;
            }
        }

        return [$atrybuty, $pliki];
    }
}
