<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
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
     */
    #[DataProvider('dokumenty')]
    public function test_nie_wymieniamy_narzedzi_ktorych_nie_uzywamy(string $adres, string $plik): void
    {
        $tresc = $this->tresc($plik);

        foreach (['Sentry', 'PostHog', 'Google Analytics', 'Matomo', 'Plausible'] as $narzedzie) {
            // Wzorzec dopuszcza zdanie „nie korzystamy z Google Analytics" —
            // chodzi o to, żeby dokument nie PRZYPISYWAŁ nam narzędzia,
            // a nie o to, żeby nie wolno było go nazwać.
            if (! str_contains($tresc, $narzedzie)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/(nie korzystamy|nie używamy|ani)[^.]{0,120}'.preg_quote($narzedzie, '/').'/ui',
                $tresc,
                "Dokument pod {$adres} wymienia „{$narzedzie}” inaczej niż w zdaniu o tym, że go NIE używamy.",
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
}
