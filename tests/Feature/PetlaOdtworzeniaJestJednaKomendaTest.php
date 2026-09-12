<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Próba odtworzenia ma być JEDNĄ KOMENDĄ i ma kończyć się LICZBAMI (issue #193).
 *
 * PO CO TO JEST, SKORO JEST JUŻ `ProbaOdtworzeniaTest`
 * ----------------------------------------------------
 * Tamten test wciąga do `php artisan test` cały plik `tests/skrypty/`, czyli
 * pilnuje, że MECHANIZM odtworzenia działa. Ten pilnuje czegoś innego i węższego:
 * że da się go uruchomić **jedną komendą**, i że ta komenda naprawdę robi
 * cztery rzeczy, o które poszło issue — kopię, odtworzenie, porównanie liczby
 * wierszy w KAŻDEJ tabeli i `migrate:status`.
 *
 * Różnica nie jest formalna. Ćwiczenie rozpisane na siedem komend do
 * przepisania z dokumentu jest ćwiczeniem, którego właściciel nie zrobi —
 * i dokładnie dlatego liczba prób odtworzenia wynosi dziś zero. Jedna komenda
 * jest tu funkcją, nie wygodą.
 *
 * DLACZEGO TEN TEST URUCHAMIA SKRYPT, ZAMIAST W NIM SZUKAĆ NAPISÓW
 * ----------------------------------------------------------------
 * Bo `--petla-lokalna` stoi w tym pliku w trzech miejscach: w nagłówku, w
 * tekście pomocy i w `case` przetwarzającym argumenty. Test szukający napisu
 * przechodziłby po wycięciu tego jedynego miejsca, które coś robi — czyli
 * byłby zielony nad martwym przełącznikiem. To pułapka 1 z
 * `docs/PULAPKI_TESTOW.md`: dopasowanie łapie to samo słowo skądinąd.
 *
 * Dlatego pytamy skrypt o ZACHOWANIE: wołamy go z parą argumentów, która ma
 * się wykluczać, i żądamy konkretnego kodu wyjścia oraz konkretnego zdania.
 * Kod 2 na tej parze może paść wyłącznie z gałęzi, która ten przełącznik
 * rozumie.
 *
 * CZEGO TEN TEST NIE DOWODZI
 * --------------------------
 * Że kuking.pl ma kopię. Nie ma — liczba kopii produkcyjnej bazy wynosi dziś
 * zero. Nie dotyka też produkcji ani sieci: wszystkie wywołania niżej kończą
 * się na sprawdzeniu argumentów, zanim skrypt otworzy jakiekolwiek połączenie.
 * Pełny przebieg pętli na prawdziwej bazie chodzi w
 * `tests/skrypty/proba-odtworzenia.sh` (i stamtąd w `ProbaOdtworzeniaTest`).
 *
 * @see scripts/proba-odtworzenia.sh
 * @see scripts/kopia-lokalna.sh
 * @see docs/infra/DEPLOYMENT_RUNBOOK.md — KROK 13.0
 */
class PetlaOdtworzeniaJestJednaKomendaTest extends TestCase
{
    private function skrypt(): string
    {
        $sciezka = base_path('scripts/proba-odtworzenia.sh');

        $this->assertFileExists(
            $sciezka,
            'Nie ma scripts/proba-odtworzenia.sh — bez niego ten test nie sprawdza niczego.',
        );

        return $sciezka;
    }

    /** Uruchamia skrypt i oddaje wyjście razem z kodem wyjścia. */
    private function uruchom(string $argumenty): string
    {
        return (string) shell_exec(
            'bash '.escapeshellarg($this->skrypt()).' '.$argumenty.' 2>&1; printf "KOD=%s" "$?"',
        );
    }

    #[Test]
    public function test_przelacznik_petli_lokalnej_jest_naprawde_rozumiany_a_nie_tylko_opisany(): void
    {
        // `--petla-lokalna` sama robi kopię, więc razem z `--zrzut` nie ma
        // sensu. Ta odmowa może paść WYŁĄCZNIE z gałęzi, która oba te
        // przełączniki rozumie — czyli jest dowodem, że przełącznik żyje.
        $wyjscie = $this->uruchom('--petla-lokalna --zrzut /nie/ma/takiego/pliku.dump');

        $this->assertStringContainsString('KOD=2', $wyjscie,
            "Skrypt nie odmówił na parze --petla-lokalna + --zrzut.\n\n".$wyjscie);

        $this->assertStringContainsString('sama robi kopię', $wyjscie,
            "Odmowa przyszła, ale nie z tej gałęzi, o którą pytamy.\n\n".$wyjscie);
    }

    #[Test]
    public function test_pomoc_mowi_czlowiekowi_co_ta_jedna_komenda_robi(): void
    {
        $wyjscie = $this->uruchom('--help');

        $this->assertStringContainsString('KOD=0', $wyjscie, $wyjscie);

        // Cztery kroki pętli, każdy nazwany. Człowiek czytający `--help`
        // w dniu awarii nie ma czasu na czytanie kodu.
        foreach ([
            '--petla-lokalna',
            'KAŻDEJ tabeli',
            'migrate:status',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $wyjscie,
                "Pomoc nie mówi o „{$fragment}".'".'."\n\n".$wyjscie);
        }
    }

    #[Test]
    public function test_petla_nie_rusza_produkcji_nawet_poproszona_wprost(): void
    {
        // Bezpiecznik 1 ma zadziałać PRZED jakimkolwiek połączeniem — także
        // wtedy, gdy ktoś poda produkcyjny adres ręcznie. Adres jest zmyślony
        // i nikt się z nim nie łączy: odmowa pada na samym kształcie nazwy.
        $wyjscie = $this->uruchom(
            '--petla-lokalna --zrodlo '
            .escapeshellarg('postgresql://u:h@monorail.proxy.rlwy.net:1234/railway')
            .' --serwer '.escapeshellarg('postgresql://u:h@monorail.proxy.rlwy.net:1234/postgres'),
        );

        $this->assertStringContainsString('KOD=21', $wyjscie,
            "Pętla lokalna NIE odmówiła na adresie wyglądającym na produkcyjny.\n\n".$wyjscie);
    }

    #[Test]
    public function test_runbook_podaje_dokladnie_te_komende_ktora_skrypt_rozumie(): void
    {
        // Rozjazd między dokumentem a skryptem zauważa się w najgorszym
        // możliwym momencie — dlatego pilnuje go test, a nie czyjaś pamięć.
        // Ta sama zasada, co przy `KOPIE_I_ODTWORZENIE.md` w
        // `tests/skrypty/proba-odtworzenia.sh`.
        $runbook = base_path('docs/infra/DEPLOYMENT_RUNBOOK.md');

        $this->assertFileExists($runbook);

        $tresc = (string) file_get_contents($runbook);

        $this->assertStringContainsString(
            'scripts/proba-odtworzenia.sh --petla-lokalna',
            $tresc,
            'Runbook nie podaje komendy pętli lokalnej. Ćwiczenie, którego nie ma '.
            'w runbooku, jest ćwiczeniem, którego nikt nie zrobi.',
        );

        // Runbook ma mówić, ile kopii jest DZIŚ — zielony przebieg pętli
        // znaczy „mechanizm działa", nie „dane są bezpieczne", i dokument
        // nie ma prawa pozwolić pomylić tych dwóch rzeczy.
        $this->assertStringContainsString(
            'wynosi dziś **zero**',
            $tresc,
            'Runbook przestał mówić, że liczba kopii produkcyjnej bazy wynosi zero. '.
            'To jest jedyne zdanie, które odróżnia „mechanizm działa" od „dane są '.
            'bezpieczne".',
        );
    }

    #[Test]
    public function test_kontrola_ujemna_porownania_zostaje_w_testach_skryptow(): void
    {
        // Kontrola ujemna, którą da się skasować bez czerwieni, przestaje
        // być kontrolą. Ten test jest jej kotwicą: wycięcie sabotażu
        // z pliku testów powłoki ma oblać `php artisan test`.
        $plik = base_path('tests/skrypty/proba-odtworzenia.sh');

        $this->assertFileExists($plik);

        $tresc = (string) file_get_contents($plik);

        foreach ([
            'skasowane wiersze w odtworzonej bazie OBLEWAJĄ porównanie (kod 63)',
            'brakująca TABELA oblewa porównanie (kod 63)',
            'baza sprzed migracji OBLEWA migrate:status (kod 64)',
            'pusta tabela migrations OBLEWA migrate:status (kod 64)',
        ] as $kontrola) {
            $this->assertStringContainsString(
                $kontrola,
                $tresc,
                "Zniknęła kontrola ujemna „{$kontrola}”. Bez niej porównanie tabel "
                .'byłoby zielone także wtedy, gdyby nie porównywało niczego.',
            );
        }
    }
}
