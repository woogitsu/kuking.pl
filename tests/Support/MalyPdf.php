<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimalne, poprawne pliki PDF budowane w pamięci — do testów importu PDF
 * (D-300). Bez binarnych plików w repozytorium i bez zależności od
 * generatora PDF.
 *
 * `zTekstem()` daje strony z prawdziwą warstwą tekstu (czcionka Helvetica,
 * tylko ASCII — wystarcza do sprawdzenia, że `pdftotext` czyta tekst),
 * `bezTekstu()` — strony z samym rysunkiem, czyli „skan" bez tekstu.
 */
final class MalyPdf
{
    /**
     * @param  list<list<string>>  $strony  wiersze tekstu każdej strony
     */
    public static function zTekstem(array $strony): string
    {
        return self::zbuduj(array_map(static function (array $wiersze): string {
            $tresc = "BT\n/F1 12 Tf\n14 TL\n50 780 Td\n";

            foreach ($wiersze as $wiersz) {
                $tresc .= '('.self::escapuj($wiersz).") Tj T*\n";
            }

            return $tresc."ET\n";
        }, $strony));
    }

    public static function bezTekstu(int $stron = 1): string
    {
        return self::zbuduj(array_fill(0, $stron, "0.5 g\n50 50 200 200 re f\n"));
    }

    /**
     * @param  list<string>  $tresci  strumienie treści kolejnych stron
     */
    private static function zbuduj(array $tresci): string
    {
        $obiekty = [];
        $liczbaStron = count($tresci);
        // 1: katalog, 2: drzewo stron, 3: czcionka, dalej pary (strona, treść).
        $kidy = [];

        for ($i = 0; $i < $liczbaStron; $i++) {
            $kidy[] = (4 + 2 * $i).' 0 R';
        }

        $obiekty[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $obiekty[2] = '<< /Type /Pages /Kids ['.implode(' ', $kidy).'] /Count '.$liczbaStron.' >>';
        $obiekty[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        foreach ($tresci as $i => $tresc) {
            $nrStrony = 4 + 2 * $i;
            $nrTresci = $nrStrony + 1;
            $obiekty[$nrStrony] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 3 0 R >> >> /Contents '.$nrTresci.' 0 R >>';
            $obiekty[$nrTresci] = '<< /Length '.strlen($tresc)." >>\nstream\n".$tresc.'endstream';
        }

        ksort($obiekty);
        $pdf = "%PDF-1.4\n";
        $przesuniecia = [];

        foreach ($obiekty as $nr => $obiekt) {
            $przesuniecia[$nr] = strlen($pdf);
            $pdf .= $nr." 0 obj\n".$obiekt."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($obiekty) + 1)."\n0000000000 65535 f \n";

        foreach ($przesuniecia as $przesuniecie) {
            $pdf .= sprintf("%010d 00000 n \n", $przesuniecie);
        }

        return $pdf.'trailer << /Size '.(count($obiekty) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private static function escapuj(string $tekst): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $tekst);
    }
}
