<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AnalitykaCloudflare;
use App\Support\Odmiana;
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
            'o Kuking' => ['/o-kuking', 'O Kuking'],
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
            'Umów powierzenia przetwarzania danych z tymi dostawcami jeszcze nie mamy podpisanych i mówimy to wprost, zamiast pisać, że mamy.',
            'Nie wyznaczyliśmy inspektora ochrony danych. Jeśli to się zmieni, podamy jego dane w tym miejscu.',
            'Jeśli w przyszłości dojdzie kolejny dostawca spoza EOG, dopiszemy go do tabeli wyżej.',
            'Ten dokument opisuje stan serwisu na 7 września 2026 i jest aktualizowany razem z nim.',
            'Nie zawiera terminów ani procedur, których serwis nie umie dziś wykonać.',
            'Serwis prowadzi na razie jedna osoba, więc nie obiecujemy, że odwołanie rozpatrzy ktoś inny.',
            'Nie mamy dziś zewnętrznego narzędzia do zbierania błędów.',
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
}
