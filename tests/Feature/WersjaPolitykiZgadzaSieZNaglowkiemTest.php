<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Wersja polityki w dzienniku zgód (`kuking.zgody.wersja_polityki`, D-072)
 * to DATA STANU dokumentu z jego nagłówka — i ma być tą samą datą.
 *
 * Komentarz w `config/kuking.php` każe podbijać obie wartości razem, ale do
 * 25.09.2026 nic tego nie pilnowało i rozjechały się o dwa tygodnie: nagłówek
 * mówił „24 września", dziennik zgód zapisywał „2026-09-10". Wiersz zgody
 * wskazywał wtedy brzmienie, którego nikt już nie mógł przeczytać.
 *
 * Istniejące wiersze dziennika zostają ze swoją starą wersją — to jest dowód,
 * na jakie brzmienie człowiek się wtedy zgodził, i nie wolno go przepisywać.
 * To, że nowa zgoda zapisuje bieżącą wersję, sprawdza `DowodZgodyNaDigestTest`.
 */
class WersjaPolitykiZgadzaSieZNaglowkiemTest extends TestCase
{
    private const MIESIACE = [
        'stycznia' => 1, 'lutego' => 2, 'marca' => 3, 'kwietnia' => 4,
        'maja' => 5, 'czerwca' => 6, 'lipca' => 7, 'sierpnia' => 8,
        'września' => 9, 'października' => 10, 'listopada' => 11, 'grudnia' => 12,
    ];

    public function test_wersja_w_dzienniku_zgod_to_data_z_naglowka_polityki(): void
    {
        $naglowek = self::dataZNaglowka((string) file_get_contents(resource_path('legal/polityka-prywatnosci.md')));

        $this->assertSame(
            $naglowek,
            config('kuking.zgody.wersja_polityki'),
            'Nagłówek polityki prywatności i `kuking.zgody.wersja_polityki` mówią inną datę. '
            .'Zmieniłeś politykę? Podbij OBIE wartości do dnia zmiany — dziennik zgód ma '
            .'wskazywać brzmienie, które człowiek mógł przeczytać.',
        );
    }

    public function test_parser_naglowka_czyta_date_i_odrzuca_dokument_bez_niej(): void
    {
        // Kontrola dodatnia parsera — bez niej test wyżej porównywałby null z nullem.
        $this->assertSame(
            '2026-09-07',
            self::dataZNaglowka('> **Ten dokument opisuje stan serwisu na 7 września 2026 i jest aktualizowany razem z nim.**'),
        );
        $this->assertNull(self::dataZNaglowka('> **Ten dokument jest aktualny.**'));
    }

    private static function dataZNaglowka(string $dokument): ?string
    {
        if (preg_match('/stan serwisu na (\d{1,2}) (\p{L}+) (\d{4})/u', $dokument, $m) !== 1) {
            return null;
        }

        $miesiac = self::MIESIACE[$m[2]] ?? null;

        return $miesiac === null ? null : sprintf('%04d-%02d-%02d', (int) $m[3], $miesiac, (int) $m[1]);
    }
}
