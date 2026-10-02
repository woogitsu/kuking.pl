<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DokumentacjaBazy;
use Tests\TestCase;

/**
 * `docs/DATABASE.md` jest KRÓTKIM INDEKSEM, a opis schematu leży w `docs/baza/*.md`
 * (decyzja właściciela z 2 października 2026, po tym jak jeden plik urósł do 481 KB).
 *
 * Indeks, który nie wie o pliku, jest gorszy niż brak indeksu: ktoś szuka tabeli,
 * nie znajduje jej w spisie i uznaje, że nie jest opisana. Dlatego:
 *  - każdy plik w `docs/baza/` ma być wymieniony w indeksie,
 *  - każdy plik wymieniony w indeksie ma istnieć,
 *  - pliki i indeks nie rosną ponad próg (po to je podzielono),
 *  - względne linki w plikach przeniesionych z `docs/` nadal trafiają w cel.
 *
 * Te progi i ten spis pilnuje TEN test; czy tabele z bazy są opisane — testy
 * `SchematBazyTrzymaSieDokumentuTest` i `DokumentacjaBazyOpisujeSchematTest`.
 */
final class IndeksDokumentacjiBazyTest extends TestCase
{
    private const MAKS_BAJTOW_PLIKU = 45 * 1024;

    private const MAKS_BAJTOW_INDEKSU = 10 * 1024;

    #[Test]
    public function kazdy_plik_obszaru_jest_w_indeksie_i_kazdy_wpis_indeksu_ma_plik(): void
    {
        $indeks = (string) file_get_contents(base_path(DokumentacjaBazy::INDEKS));
        $naDysku = array_map('basename', DokumentacjaBazy::pliki());

        $this->assertNotEmpty($naDysku, 'docs/baza/ jest pusty — indeks wskazuje w próżnię.');

        preg_match_all('~\]\(baza/([A-Za-z0-9._-]+\.md)\)~', $indeks, $m);
        $wIndeksie = array_values(array_unique($m[1]));

        $this->assertSame(
            [],
            array_values(array_diff($naDysku, $wIndeksie)),
            'Te pliki z docs/baza/ nie są wymienione w docs/DATABASE.md. Dopisz wiersz '
            .'`| [`nazwa`](baza/nazwa.md) | obszar | tabele |` do tabeli „Pliki i tabele".',
        );
        $this->assertSame(
            [],
            array_values(array_diff($wIndeksie, $naDysku)),
            'docs/DATABASE.md wskazuje pliki, których nie ma w docs/baza/. Usuń wiersz albo przywróć plik.',
        );
    }

    #[Test]
    public function pliki_i_indeks_nie_przekraczaja_progu_rozmiaru(): void
    {
        $this->assertLessThanOrEqual(
            self::MAKS_BAJTOW_INDEKSU,
            filesize(base_path(DokumentacjaBazy::INDEKS)),
            'docs/DATABASE.md ma być krótkim indeksem; opis schematu idzie do docs/baza/.',
        );

        foreach (DokumentacjaBazy::pliki() as $plik) {
            $this->assertLessThanOrEqual(
                self::MAKS_BAJTOW_PLIKU,
                filesize(base_path($plik)),
                $plik.' urósł ponad próg — podziel go po nagłówkach `###` na pliki o czytelnych nazwach '
                .'i wpisz je do indeksu.',
            );
        }
    }

    #[Test]
    public function wzgledne_linki_w_plikach_obszarow_trafiaja_w_istniejacy_cel(): void
    {
        $zepsute = [];

        foreach (DokumentacjaBazy::pliki() as $plik) {
            $tresc = (string) file_get_contents(base_path($plik));
            // Bez bloków kodu: tam `](...)` bywa fragmentem składni, nie linkiem.
            $tresc = (string) preg_replace('/```.*?```/su', '', $tresc);

            preg_match_all('~\]\(([^)\s]+)\)~', $tresc, $m);

            foreach ($m[1] as $cel) {
                if (preg_match('~^(https?:|mailto:|#)~', $cel) === 1) {
                    continue;
                }

                $sciezka = explode('#', $cel)[0];

                if ($sciezka !== '' && ! file_exists(dirname(base_path($plik)).'/'.$sciezka)) {
                    $zepsute[] = $plik.' -> '.$cel;
                }
            }
        }

        $this->assertSame([], $zepsute, 'Linki względne w docs/baza/ liczą się od tego katalogu (../ dla docs/ i korzenia).');
    }

    #[Test]
    public function kontrola_dodatnia_pomocnik_skleja_indeks_i_pliki_w_stalej_kolejnosci(): void
    {
        $pliki = DokumentacjaBazy::pliki();
        $posortowane = $pliki;
        sort($posortowane, SORT_STRING);

        $this->assertSame($posortowane, $pliki);
        $this->assertGreaterThan(10, count($pliki));

        $tresc = DokumentacjaBazy::tresc();

        $this->assertStringStartsWith('# Model danych', $tresc);
        $this->assertStringContainsString('### notifications', $tresc);
        $this->assertStringContainsString('### recipe_versions', $tresc);
    }
}
