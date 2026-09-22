<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trzy dokumenty zajmują stanowisko w tej samej sprawie — czy Kuking jest
 * zwolniony z Sekcji 3 rozdziału III DSA (art. 19–28). Ten test pilnuje,
 * żeby mówiły jedno i żeby każdy z nich podawał, na czym to stanowisko stoi.
 *
 * DLACZEGO TO JEST TEST, A NIE UWAGA W DOKUMENTACJI
 * Bo one już się rozjechały i nikt tego nie zauważył przez dobę. Stan
 * z 9 września 2026, zanim ta zmiana weszła:
 *
 *   - `docs/legal/COMPLIANCE.md` §1.2 miało w nagłówku „od których Kuking
 *     JEST ZWOLNIONY", bez słowa o tym, dlaczego akurat my;
 *   - `docs/decyzje/OPERATOR.md` §3 pisało, że operator „prawdopodobnie nie
 *     jest przedsiębiorstwem, a więc prawdopodobnie NIE MOŻE powołać się na
 *     zwolnienie z art. 19", i wprost nazywało założenie z COMPLIANCE.md
 *     „wątpliwym";
 *   - `docs/DECISIONS.md` D-038 wywodziło sześciomiesięczny termin odwołania
 *     z tego, że „tyle WYMAGA DSA art. 20 ust. 1", nie wspominając art. 19.
 *
 * Trzy dokumenty, trzy różne stany prawne, jeden serwis. Rozstrzygnęła to
 * decyzja D-040 (serwis prowadzi SAMSUFI sp. z o.o.), która zapadła DZIEŃ
 * WCZEŚNIEJ i której dwa z tych dokumentów po prostu nie zauważyły.
 *
 * Czego ten test NIE robi: nie rozstrzyga prawa i nie sprawdza, czy
 * zwolnienie faktycznie przysługuje. Sprawdza, że dokument, który zajmuje
 * stanowisko, podaje przy nim podstawę i warunki — żeby następna osoba nie
 * musiała czytać trzech plików, by zobaczyć, że są sprzeczne.
 */
class DokumentyNieRozjezdzajaSieOZwolnieniuDsaTest extends TestCase
{
    private function dokument(string $sciezka): string
    {
        $pelna = base_path($sciezka);

        $this->assertFileExists($pelna, "Nie ma pliku {$sciezka}. Jeśli został przeniesiony, popraw ścieżkę w tym teście — nie usuwaj testu.");

        return (string) file_get_contents($pelna);
    }

    /** Wycina fragment od nagłówka do następnego nagłówka tego samego poziomu. */
    private function sekcja(string $tresc, string $poczatek, string $poziom): string
    {
        $od = mb_strpos($tresc, $poczatek);
        $this->assertNotFalse($od, "Nie znalazłem w dokumencie nagłówka „{$poczatek}”. Jeśli sekcję przemianowano, popraw ten test razem z nią.");

        $do = mb_strpos($tresc, "\n{$poziom} ", $od + mb_strlen($poczatek));

        return $do === false ? mb_substr($tresc, $od) : mb_substr($tresc, $od, $do - $od);
    }

    #[Test]
    public function compliance_nie_oglasza_zwolnienia_bez_podania_podstawy(): void
    {
        $sekcja = $this->sekcja($this->dokument('docs/legal/COMPLIANCE.md'), '### 1.2 Obowiązki Sekcji 3', '###');

        $this->assertStringContainsString('D-040', $sekcja,
            '§1.2 twierdzi, że Kuking jest zwolniony z Sekcji 3 DSA, ale nie mówi na jakiej podstawie. '
            .'Zwolnienie z art. 19 przysługuje PRZEDSIĘBIORSTWU — u nas jest nim SAMSUFI sp. z o.o. (D-040). '
            .'Bez tego zdania rozdział jest twierdzeniem bez uzasadnienia, a dokładnie tak wyglądał 8 września.');

        $this->assertStringContainsString('2003/361/WE art. 6', $sekcja,
            '§1.2 nie mówi, że progi mikroprzedsiębiorstwa liczy się dla całego przedsiębiorstwa razem '
            .'z partnerskimi i powiązanymi (Zalecenie 2003/361/WE art. 6). Bez tego ktoś policzy próg dla '
            .'samego serwisu i uzna zwolnienie za pewne, gdy takie nie jest.');

        $this->assertStringContainsString('art. 4 ust. 2', $sekcja,
            '§1.2 nie mówi, kiedy zwolnienie znika. Status traci się dopiero po dwóch kolejnych latach '
            .'powyżej progu (art. 4 ust. 2 Zalecenia) — to jest okno na przygotowanie Sekcji 3 i trzeba '
            .'wiedzieć, że jest skończone.');
    }

    #[Test]
    public function decyzja_o_terminie_odwolania_nie_wywodzi_go_z_artykulu_wylaczonego(): void
    {
        $sekcja = $this->sekcja($this->dokument('docs/DECISIONS.md'), '## D-038', '##');

        $this->assertStringContainsString('art. 20', $sekcja,
            'Kontrola tego testu: D-038 przestało w ogóle wspominać art. 20. Jeśli to celowe, usuń tę '
            .'asercję świadomie — ale najpierw sprawdź, czy poniższe sprawdzenie nie zrobiło się przez to puste.');

        $this->assertStringContainsString('art. 19', $sekcja,
            'D-038 opiera sześciomiesięczny termin odwołania na art. 20 DSA, nie mówiąc, że art. 19 '
            .'wyłącza całą Sekcję 3 dla mikroprzedsiębiorstw. Termin zostaje — ale jego źródłem jest '
            .'regulamin §8, nie rozporządzenie. Kto uwierzy, że wiąże nas art. 20, wyprowadzi z tego '
            .'także art. 21, 22 i 24 i zacznie budować miesiące niepotrzebnej pracy.');

        $this->assertStringContainsString('regulamin', mb_strtolower($sekcja),
            'D-038 nie mówi, skąd naprawdę bierze się sześć miesięcy. Skoro nie z art. 20, to zostaje '
            .'obietnica z regulaminu §8 — i to trzeba w tym miejscu napisać, bo inaczej liczba wisi bez podstawy.');
    }

    #[Test]
    public function operator_nie_zostawia_pytania_o_przedsiebiorstwo_jako_otwartego(): void
    {
        $dokument = $this->dokument('docs/decyzje/OPERATOR.md');

        $this->assertStringContainsString('prawdopodobnie nie jest przedsiębiorstwem', $dokument,
            'Kontrola tego testu: OPERATOR.md przestał zawierać analizowany fragment o statusie operatora. '
            .'Jeśli sekcję §3 przepisano od zera, dostosuj ten test do nowego brzmienia — nie kasuj go.');

        $this->assertStringContainsString('SAMSUFI', $dokument,
            'OPERATOR.md rozważa, czy operator jest przedsiębiorstwem, i nie wie, że to już rozstrzygnięte: '
            .'serwis prowadzi SAMSUFI sp. z o.o. (D-040). Dokument, który stawia zamknięte pytanie jako '
            .'otwarte, kosztuje kolejnego czytelnika godzinę i wizytę u prawnika po odpowiedź, którą już masz.');

        $this->assertStringContainsString('D-040', $dokument,
            'OPERATOR.md nie odsyła do decyzji, która go unieważniła. Bez numeru decyzji czytelnik nie ma '
            .'jak sprawdzić, co dokładnie się zmieniło i kiedy.');
    }

    #[Test]
    public function zadne_zwolnienie_nie_dotyka_zgloszen_i_uzasadnien(): void
    {
        $sekcja = $this->sekcja($this->dokument('docs/legal/COMPLIANCE.md'), '### 1.2 Obowiązki Sekcji 3', '###');

        $this->assertStringContainsString('Sekcji 2', $sekcja,
            'Rozdział o zwolnieniu nie ostrzega, że Art. 16 (zgłaszanie treści) i Art. 17 (uzasadnienie '
            .'decyzji) leżą w Sekcji 2 i obowiązują niezależnie od wielkości. To jedyne miejsce, w którym '
            .'ktoś szukający oszczędności mógłby uznać, że formularz zgłoszeń też jest opcjonalny.');
    }
}
