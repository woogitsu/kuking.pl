<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Czytanie joba `dostepnosc:` z `.github/workflows/ci.yml`.
 *
 * Wyodrębnione z testu, bo z tego samego fragmentu pliku korzysta więcej niż
 * jeden test, a sposób jego wycinania ma pułapkę, którą już raz wdepnąłem
 * (patrz komentarz przy przesunięciach w bajtach) — lepiej mieć ją w jednym
 * miejscu niż w dwóch kopiach, z których jedna zostanie poprawiona.
 */
trait CzytaJobDostepnosci
{
    private function workflowCi(): string
    {
        $sciezka = base_path('.github/workflows/ci.yml');

        $this->assertFileExists(
            $sciezka,
            'Nie ma .github/workflows/ci.yml. Jeśli plik przeniesiono, popraw ścieżkę tutaj.',
        );

        return (string) file_get_contents($sciezka);
    }

    /**
     * Fragment pliku od nagłówka joba `dostepnosc:` do następnego joba.
     */
    private function jobDostepnosci(): string
    {
        $ci = $this->workflowCi();

        // WSZYSTKO TUTAJ LICZY W BAJTACH, I TO NIE JEST DROBIAZG.
        //
        // Pierwsza wersja tej metody brała `mb_strpos()` (przesunięcie
        // ZNAKOWE) i podawała je jako przesunięcie do `preg_match()`, które
        // liczy w BAJTACH. Plik `ci.yml` jest pełen polskich znaków, więc te
        // dwie liczby się rozjeżdżają — wycinany fragment kończył się po 807
        // bajtach, w środku komentarza. Skutek był najgorszy z możliwych:
        // test oblewał się IDENTYCZNIE przed i po poprawce, czyli nie mierzył
        // niczego, a wyglądał na czujny.
        $od = strpos($ci, "\n  dostepnosc:");

        $this->assertNotFalse(
            $od,
            'W ci.yml nie ma już joba `dostepnosc:`. Jeśli zmienił nazwę, popraw ten test razem z nim.',
        );

        // Następny job to kolejna linia z DOKŁADNIE dwoma spacjami wcięcia
        // i dwukropkiem na końcu. `services:` ma cztery spacje, `postgres:`
        // sześć — dlatego wzorzec wymaga, żeby po dwóch spacjach od razu
        // szła nazwa, a po niej koniec wiersza.
        $do = preg_match('/\n  [a-z][a-z0-9_-]*:[ \t]*\n/', $ci, $dopasowanie, PREG_OFFSET_CAPTURE, $od + 15) === 1
            ? $dopasowanie[0][1]
            : strlen($ci);

        return substr($ci, $od, $do - $od);
    }
}
