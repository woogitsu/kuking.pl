<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `app/Domain/Collections` nie pogłębia zależności od `Illuminate\Http`
 * (issue #970, krok 5).
 *
 * Granica z `docs/ARCHITECTURE.md`: wejście HTTP należy do Form Requestu,
 * reguła i transakcja do akcji w domenie. Dwa pliki zastane przed #970
 * jeszcze biorą `Illuminate\Http\Request` (`ZeszytyDoWyboru`,
 * `CollectionSaveContext`) — stoją na liście dozwolonych i lista jest
 * dokładna w obie strony: nowy plik z importem oblewa test, a odczepienie
 * znanego też, żeby wpis nie został furtką dla kolejnego importu.
 *
 * Zakres to na razie tylko Collections; inne moduły (Recipes, Media,
 * Import, Security…) mają własne zastane importy do osobnych zadań.
 *
 * JAK CZYTA KOD: tokenizerem, nie wyrażeniem regularnym — komentarz
 * z nazwą klasy nie jest zależnością.
 *
 * Kontrola dodatnia: wpis w `scripts/kontrole-negatywne-alfa08.py` dokłada
 * import `Illuminate\Http\UploadedFile` do akcji w Collections.
 */
final class ZeszytyNieRosnaOdHttpTest extends TestCase
{
    /**
     * @var list<string> ścieżki względem app/Domain/Collections/
     */
    private const ZASTANE = [
        'CollectionSaveContext.php',
        'ZeszytyDoWyboru.php',
    ];

    public function test_zeszyty_nie_importuja_illuminate_http_poza_zastanymi(): void
    {
        $znalezione = self::plikiZImportemHttp();

        $this->assertNotEmpty($znalezione, 'Skaner nie widzi żadnego importu — test stracił przedmiot.');

        $this->assertSame(
            [],
            array_values(array_diff($znalezione, self::ZASTANE)),
            'Plik w app/Domain/Collections importuje Illuminate\\Http. Wejście HTTP idzie do Form Requestu '
            .'w app/Http/Requests/Collections, a akcja dostaje zwykłe wartości (#970).',
        );
    }

    public function test_lista_zastanych_jest_dokladna(): void
    {
        $this->assertSame(
            self::ZASTANE,
            array_values(array_intersect(self::ZASTANE, self::plikiZImportemHttp())),
            'Odczepiłeś plik od Illuminate\\Http — usuń go z ZASTANE, żeby nie został furtką.',
        );
    }

    public function test_skaner_pomija_komentarze_i_widzi_import(): void
    {
        $this->assertTrue(self::uzywaHttp("<?php\nuse Illuminate\\Http\\JsonResponse;\n"));
        $this->assertTrue(self::uzywaHttp("<?php\n\$a = new \\Illuminate\\Http\\UploadedFile();\n"));
        $this->assertFalse(self::uzywaHttp("<?php\n// Illuminate\\Http\\JsonResponse\n/** Illuminate\\Http\\JsonResponse */\nuse Illuminate\\Support\\Str;\n"));
    }

    /**
     * @return list<string>
     */
    private static function plikiZImportemHttp(): array
    {
        $baza = dirname(__DIR__, 2).'/app/Domain/Collections/';
        $wynik = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baza, FilesystemIterator::SKIP_DOTS)) as $plik) {
            if ($plik->getExtension() === 'php' && self::uzywaHttp((string) file_get_contents($plik->getPathname()))) {
                $wynik[] = substr($plik->getPathname(), strlen($baza));
            }
        }

        sort($wynik);

        return $wynik;
    }

    private static function uzywaHttp(string $kod): bool
    {
        foreach (token_get_all($kod) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $nazwa = ltrim($token[1], '\\');

            if (str_starts_with($nazwa, 'Illuminate\\Http\\')) {
                return true;
            }
        }

        return false;
    }
}
