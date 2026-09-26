<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CHANGELOG.md nie ma dwóch identycznych wpisów.
 *
 * PO CO TO JEST
 * Konflikt w `CHANGELOG.md` rozwiązuje się w tym repozytorium zasadą
 * „zostaw obie strony”. Gdy gałąź PR-a wcześniej wciągnęła gałąź innego
 * PR-a (a nie tylko `main`), obie strony konfliktu niosą TEN SAM wpis —
 * i wpis ląduje w pliku dwa razy. Tak było po fali z 26 września 2026:
 * wpisy #1377 i #1378 stały w „Nieopublikowane” dwukrotnie, słowo w słowo
 * (audyt `docs/audyt/2026-09-26-PO-FALI.md`), a dzień wcześniej to samo
 * spotkało #887, #889 i #890. Żaden PR tego nie widział, bo każdy osobno
 * miał wpis dokładnie raz.
 *
 * CO SPRAWDZA
 * Wpis to punkt listy („- …”) razem z liniami kontynuacji (wcięcie). Dwa
 * wpisy o tej samej treści po złączeniu białych znaków w całym pliku =
 * czerwień z numerami linii. Nie ocenia wpisów podobnych, ale inaczej
 * sformułowanych — to zostaje audytowi.
 */
class ChangelogBezZdublowanychWpisowTest extends TestCase
{
    public function test_changelog_nie_ma_dwoch_identycznych_wpisow(): void
    {
        $duplikaty = self::zdublowaneWpisy((string) file_get_contents(base_path('CHANGELOG.md')));

        $this->assertSame([], $duplikaty, implode("\n", [
            'CHANGELOG.md ma zdublowane wpisy — najczęściej skutek rozwiązania konfliktu „obie strony”,',
            'gdy obie strony niosły ten sam wpis. Zostaw jeden egzemplarz:',
            ...$duplikaty,
        ]));
    }

    /**
     * KONTROLA DODATNIA na sztucznym pliku: parser musi widzieć duplikat
     * rozłożony na kilka linii, a nie widzieć go w dwóch różnych wpisach.
     */
    public function test_parser_widzi_duplikat_wieloliniowy_i_przepuszcza_rozne_wpisy(): void
    {
        $plik = implode("\n", [
            '# Zmiany',
            '',
            '## Nieopublikowane',
            '',
            '- Pierwszy wpis, który ciągnie się',
            '  na drugą linię (#1).',
            '- Drugi wpis (#2).',
            '- Pierwszy wpis, który ciągnie się na drugą linię (#1).',
            '',
            '## Alfa 0.1',
            '',
            '- Drugi wpis (#3).',
        ]);

        $this->assertSame(
            ['linie 5, 8: Pierwszy wpis, który ciągnie się na drugą linię (#1).'],
            self::zdublowaneWpisy($plik),
        );
    }

    /**
     * @return list<string>
     */
    private static function zdublowaneWpisy(string $tresc): array
    {
        /** @var array<string, list<int>> $wpisy */
        $wpisy = [];
        $biezacy = null;
        $linia = null;

        $zamknij = static function () use (&$wpisy, &$biezacy, &$linia): void {
            if ($biezacy !== null) {
                $klucz = trim((string) preg_replace('/\s+/u', ' ', $biezacy));
                $wpisy[$klucz][] = $linia;
            }
            $biezacy = null;
        };

        foreach (explode("\n", $tresc) as $i => $wiersz) {
            if (str_starts_with($wiersz, '- ')) {
                $zamknij();
                $biezacy = substr($wiersz, 2);
                $linia = $i + 1;
            } elseif ($biezacy !== null && str_starts_with($wiersz, '  ') && trim($wiersz) !== '') {
                $biezacy .= ' '.trim($wiersz);
            } else {
                $zamknij();
            }
        }
        $zamknij();

        $duplikaty = [];

        foreach ($wpisy as $wpis => $linie) {
            if (count($linie) > 1) {
                $duplikaty[] = 'linie '.implode(', ', $linie).': '.mb_substr($wpis, 0, 120);
            }
        }

        return $duplikaty;
    }
}
