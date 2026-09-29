<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `app/Domain` nie zależy od warstwy HTTP `Illuminate\Http` (issue #970).
 *
 * Granica z `docs/ARCHITECTURE.md`: wejście HTTP należy do adaptera
 * (Form Request, kontroler, `app/Http/Support`), reguła i transakcja do
 * domeny, która dostaje zwykłe wartości albo modele. Collections są już
 * czyste (`ZeszytyDoWyboru`, `CollectionSaveContext` dostają osobę i wartości
 * pól). Reszta zastanych importów stoi na jawnych listach niżej i lista jest
 * dokładna w obie strony: nowy import oblewa test, a odczepienie znanego też,
 * żeby wpis nie został furtką dla kolejnego importu.
 *
 * WYJĄTKI (każdy z powodem):
 *  - `Illuminate\Http\Client\*` — klient WYCHODZĄCYCH zapytań do cudzych usług,
 *    nie warstwa wejścia; dozwolony wszędzie.
 *  - `UploadedFile` — prawdziwy obiekt pliku z formularza, do czasu neutralnego
 *    DTO/portu na plik (wpis per plik).
 *  - `Request` w czterech plikach poniżej — zastane, poza zakresem tego etapu
 *    (#970 zawężone do Collections); osobne zadania.
 *
 * JAK CZYTA KOD: tokenizerem, nie wyrażeniem regularnym — komentarz
 * z nazwą klasy nie jest zależnością.
 *
 * Kontrola dodatnia: wpis w `scripts/kontrole-negatywne-alfa08.py` dokłada
 * `use Illuminate\Http\Request;` do pliku domeny spoza listy.
 */
final class DomenaNieZalezyOdHttpTest extends TestCase
{
    /**
     * Zastane, tymczasowe: stan żądania albo sesji trzymany w domenie.
     * Wyjęcie do adapterów w `app/Http` to osobne zadania — każdy wpis
     * znika razem z importem.
     *
     * @var list<string> "ścieżka względem app/Domain#klasa"
     */
    private const ZASTANE = [
        'Security/FacebookConnectionConfirmation.php#Illuminate\Http\Request', // sesja potwierdzeń połączenia z Facebookiem
        'Security/WejsciePrzezDostawce/WejdzPrzezDostawce.php#Illuminate\Http\Request', // sesja i wpuszczenie konta po OAuth (#1035)
        'Social/SkrotyObserwowania.php#Illuminate\Http\Request', // pamięć skrótów w atrybutach żądania
        'Ukrycia/Ukrycia.php#Illuminate\Http\Request', // zbiór ukryć liczony raz na żądanie
    ];

    /**
     * Stały wyjątek: prawdziwy plik z formularza (wpis per plik).
     *
     * @var list<string>
     */
    private const PLIK_Z_FORMULARZA = [
        'Import/ZlecImportPrzepisu.php#Illuminate\Http\UploadedFile',
        'Media/Actions/StoreUploadedImage.php#Illuminate\Http\UploadedFile',
        'Media/LokalnaKopiaZdjecia.php#Illuminate\Http\UploadedFile',
        'Recipes/Actions/ZapiszPrzepisZFormularza.php#Illuminate\Http\UploadedFile',
        'Users/Import/MagazynPaczek.php#Illuminate\Http\UploadedFile',
    ];

    public function test_domena_nie_importuje_illuminate_http_poza_jawnymi_wyjatkami(): void
    {
        $znalezione = self::importyHttp();

        $this->assertNotEmpty($znalezione, 'Skaner nie widzi żadnego importu — test stracił przedmiot.');

        $this->assertSame(
            [],
            array_values(array_diff($znalezione, self::ZASTANE, self::PLIK_Z_FORMULARZA)),
            'Plik w app/Domain importuje Illuminate\\Http. Wejście HTTP idzie do adaptera (Form Request, '
            .'kontroler, app/Http/Support), a domena dostaje zwykłe wartości albo osobę (#970).',
        );
    }

    public function test_listy_wyjatkow_sa_dokladne(): void
    {
        $znalezione = self::importyHttp();
        $wyjatki = array_merge(self::ZASTANE, self::PLIK_Z_FORMULARZA);
        sort($wyjatki);

        $this->assertSame(
            $wyjatki,
            array_values(array_intersect($wyjatki, $znalezione)),
            'Odczepiłeś plik od Illuminate\\Http — usuń jego wpis z listy wyjątków, żeby nie został furtką.',
        );
    }

    public function test_collections_nie_ma_zadnych_wyjatkow(): void
    {
        foreach (array_merge(self::ZASTANE, self::PLIK_Z_FORMULARZA) as $wpis) {
            $this->assertStringStartsNotWith('Collections/', $wpis, 'Collections są czyste — bez wyjątków (#970).');
        }
    }

    public function test_skaner_pomija_komentarze_i_widzi_import(): void
    {
        $this->assertSame(['Illuminate\Http\JsonResponse'], self::klasyHttp("<?php\nuse Illuminate\\Http\\JsonResponse;\n"));
        $this->assertSame(['Illuminate\Http\UploadedFile'], self::klasyHttp("<?php\n\$a = new \\Illuminate\\Http\\UploadedFile();\n"));
        $this->assertSame(['Illuminate\Http\*'], self::klasyHttp("<?php\nuse Illuminate\\Http\\{Request, JsonResponse};\n"));
        $this->assertSame([], self::klasyHttp("<?php\n// Illuminate\\Http\\JsonResponse\n/** Illuminate\\Http\\JsonResponse */\nuse Illuminate\\Support\\Str;\n"));
        $this->assertSame([], self::klasyHttp("<?php\nuse Illuminate\\Http\\Client\\Response;\n"), 'Klient wychodzący nie jest warstwą wejścia.');
    }

    /**
     * @return list<string> "ścieżka względem app/Domain#klasa", posortowane
     */
    private static function importyHttp(): array
    {
        $baza = dirname(__DIR__, 2).'/app/Domain/';
        $wynik = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baza, FilesystemIterator::SKIP_DOTS)) as $plik) {
            if ($plik->getExtension() !== 'php') {
                continue;
            }

            foreach (self::klasyHttp((string) file_get_contents($plik->getPathname())) as $klasa) {
                $wynik[] = substr($plik->getPathname(), strlen($baza)).'#'.$klasa;
            }
        }

        sort($wynik);

        return $wynik;
    }

    /**
     * @return list<string> klasy `Illuminate\Http\*` z pominięciem klienta wychodzącego
     */
    private static function klasyHttp(string $kod): array
    {
        $wynik = [];

        foreach (token_get_all($kod) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $nazwa = ltrim($token[1], '\\');

            if ($nazwa === 'Illuminate\Http') {
                // `use Illuminate\Http\{A, B};` — grupa, więc liczymy jak import całości.
                $wynik[] = 'Illuminate\Http\*';
            } elseif (str_starts_with($nazwa, 'Illuminate\Http\\') && ! str_starts_with($nazwa, 'Illuminate\Http\Client\\')) {
                $wynik[] = $nazwa;
            }
        }

        $wynik = array_values(array_unique($wynik));
        sort($wynik);

        return $wynik;
    }
}
