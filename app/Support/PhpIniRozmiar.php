<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parsowanie rozmiarów w stylu php.ini ("28M", "512K", "1G").
 *
 * Używa jej `UploadLimitsAgreementTest` — czyta `docker/php.ini` jako
 * TEKST (nie przez `ini_get`, bo w testach PHP-CLI ma zupełnie inny plik
 * ini niż produkcyjny kontener — patrz `docs/DATABASE.md`-owy odpowiednik
 * dla configów, czyli komentarz przy `max_bytes` w `config/kuking.php`)
 * i porównuje wynik z budżetem zdjęć z `config/kuking.php`. Samo Laravel
 * (`Illuminate\Http\Middleware\ValidatePostSize`) parsuje `post_max_size`
 * PONOWNIE, ale w runtime przez `ini_get` — to jest jego własny kod
 * frameworka, nie coś, co ta klasa ma zastępować czy synchronizować.
 * Gdyby ten test parsował "28M" swoim, trzecim sposobem, dokładnie taki
 * rozjazd (różne parsowanie tej samej liczby w różnych miejscach) byłby
 * możliwy w SAMYM MECHANIZMIE SPRAWDZANIA — więc jest jedna metoda,
 * używana konsekwentnie.
 */
final class PhpIniRozmiar
{
    /**
     * Zamienia wartość w składni php.ini na liczbę bajtów.
     *
     * php.ini (inaczej niż np. dyski twarde) liczy K/M/G jako potęgi 1024,
     * nie 1000 — to samo, co robi sam silnik PHP przy wczytywaniu ini.
     */
    public static function naBajty(string $wartosc): int
    {
        $wartosc = trim($wartosc);

        if ($wartosc === '' || $wartosc === '-1') {
            // "-1" w php.ini znaczy "bez limitu". Nie ma to zastosowania
            // do żadnej z dyrektyw, które tu sprawdzamy (są zawsze
            // ograniczone w docker/php.ini), ale zwracamy PHP_INT_MAX
            // zamiast rzucać wyjątkiem, żeby wywołujący kod nie musiał
            // znać tego szczególnego przypadku.
            return PHP_INT_MAX;
        }

        if (! preg_match('/^(\d+)([KMG]?)$/i', $wartosc, $dopasowanie)) {
            throw new \InvalidArgumentException("Nie rozumiem rozmiaru php.ini: \"{$wartosc}\".");
        }

        $liczba = (int) $dopasowanie[1];
        $jednostka = strtoupper($dopasowanie[2]);

        return match ($jednostka) {
            'G' => $liczba * 1024 * 1024 * 1024,
            'M' => $liczba * 1024 * 1024,
            'K' => $liczba * 1024,
            default => $liczba,
        };
    }

    /**
     * Czyta wartość dyrektywy wprost z TEKSTU pliku php.ini (nie z `ini_get`,
     * które zwróciłoby ustawienia PHP-CLI uruchamiającego testy, a nie
     * produkcyjnego `docker/php.ini` — te dwa są celowo różne).
     */
    public static function dyrektywaZTekstuIni(string $trescPliku, string $nazwaDyrektywy): ?string
    {
        $wzorzec = '/^\s*'.preg_quote($nazwaDyrektywy, '/').'\s*=\s*(\S+)/m';

        if (! preg_match($wzorzec, $trescPliku, $dopasowanie)) {
            return null;
        }

        return trim($dopasowanie[1]);
    }
}
