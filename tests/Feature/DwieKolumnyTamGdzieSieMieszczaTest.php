<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dwie kolumny tam, gdzie się mieszczą — i jedna tam, gdzie nie (#365).
 *
 * DWA ZGŁOSZENIA WŁAŚCICIELA, jedna reguła:
 *   * „na głównej (…) »Świeżo z Kuking« też można rozdzielić na dwie kolumny
 *     by było więcej treści a nie wydłużona strona";
 *   * „w »co się dziś gotuje« można zrobić dwie kolumny".
 *
 * `docs/UX_50_PLUS.md` §Desktop pozwala na to WPROST („maks. 2 główne
 * kolumny"). Zakazana jest ŚCIANA MAŁYCH KAFELKÓW — więc test pilnuje nie
 * tylko tego, że kolumny są, ale i tego, że karta ma z czego być duża:
 * zdjęcie dania zostaje przy 120 px, a próg dwóch kolumn jest na tyle wysoki,
 * że przy podkręconej czcionce przeglądarki kolumny same wracają do jednej.
 *
 * ZMIERZONE (Chromium, dane demo):
 *
 *   „Świeżo z Kuking" na `/`, okno 1280 i 1920 px
 *       przed: 1 kolumna, lista 4956 px, strona 9909 px
 *       po:    2 kolumny, lista 2685 px, strona 7169 px
 *       przy czcionce przeglądarki 200%: 1 kolumna (próg 64rem = 2048 px)
 *
 *   tablica dnia
 *       w pasie strony powitalnej (992 px): OSOBY obok DAŃ
 *       w szynie `/home` (352 px):          jedna kolumna, bez zmian
 */
class DwieKolumnyTamGdzieSieMieszczaTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    // --- TABLICA DNIA ----------------------------------------------------

    public function test_tablica_dnia_ma_dwie_kolumny_na_osoby_i_dania(): void
    {
        $autor = $this->user('gotujaca');
        Post::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'published_at' => now()->subHour(),
            'body' => 'Pierogi z niedzieli, jak u mamy.',
        ]);

        $html = (string) $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'kuking-board-kolumny',
            $html,
            'Tablica dnia nie ma wspólnego kontenera na obie listy — nie ma czego ustawić obok siebie.',
        );

        $this->assertGreaterThanOrEqual(
            1,
            substr_count($html, 'kuking-board-kolumna"'),
            'Listy tablicy nie stoją we własnych kolumnach.',
        );
    }

    public function test_arkusz_wlacza_dwie_kolumny_dopiero_przy_szerokiej_tablicy(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.kuking-board\s*\{[^}]*container-type:\s*inline-size\s*;/s',
            $css,
            'Tablica nie jest kontenerem zapytania — nie ma jak zapytać o WŁASNĄ szerokość, '
            .'a stoi w miejscach o różnych szerokościach (szyna 352 px — od 11 września także '
            .'na `/odkryj`; pas strony powitalnej 992 px; jeden ciąg na telefonie).',
        );

        // Stan podstawowy: JEDNA kolumna. To jest kontrola ujemna wpisana
        // w test — bez niej „dwie kolumny w zapytaniu" przeszłoby także
        // wtedy, gdyby dwie kolumny były wszędzie, także w szynie.
        $this->assertSame(
            1,
            preg_match('/\.kuking-board-kolumny\s*\{([^}]*)\}/', $css, $podstawa),
            'Brak reguły `.kuking-board-kolumny`.',
        );

        $this->assertMatchesRegularExpression(
            '/grid-template-columns:\s*minmax\(\s*0\s*,\s*1fr\s*\)\s*;/',
            $podstawa[1],
            'Tablica zaczyna od dwóch kolumn — w szynie (310 px wnętrza karty) awatar spadłby '
            .'nad podpis.',
        );

        $this->assertSame(
            1,
            preg_match(
                '/@container\s+tablica\s*\(min-width:\s*(\d+)rem\)\s*\{\s*\.kuking-board-kolumny\s*\{([^}]*)\}/s',
                $css,
                $zapytanie,
            ),
            'Brak zapytania o kontener, które włącza dwie kolumny tablicy.',
        );

        $this->assertGreaterThanOrEqual(
            44,
            (int) $zapytanie[1],
            'Próg dwóch kolumn zszedł poniżej 44rem — kolumna węższa niż 22rem nie mieści rzędu '
            .'trzech miniatur (3 × 72 + 2 × 8 = 232 px), a to jedyny uczciwy argument, żeby kogoś '
            .'zaobserwować.',
        );

        $this->assertMatchesRegularExpression(
            '/grid-template-columns:\s*repeat\(\s*2\s*,/',
            $zapytanie[2],
            'Zapytanie o kontener nie daje dwóch kolumn.',
        );
    }

    public function test_zdjecia_w_tablicy_zostaja_duze(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.kuking-board\s*\{[^}]*--tablica-zdjecie:\s*120px\s*;/s',
            $this->css(),
            'Zdjęcie w tablicy zmalało, żeby zmieściły się dwie kolumny — to jest dokładnie ta '
            .'„ściana miniaturowych kart", której zabrania docs/UX_50_PLUS.md §Desktop.',
        );
    }

    // --- ŚWIEŻO Z KUKING -------------------------------------------------

    public function test_swiezo_z_kuking_ma_dwie_kolumny_na_szerokim_ekranie(): void
    {
        $autor = $this->user('piekarz');
        Post::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);

        $html = (string) $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'landing-wpisy-dwie',
            $html,
            'Sekcja „Świeżo z Kuking" nie prosi o dwie kolumny.',
        );

        $css = $this->css();

        $this->assertSame(
            1,
            preg_match(
                '/@media\s*\(min-width:\s*(\d+)rem\)\s*\{\s*\.landing-wpisy-dwie\s*\{([^}]*)\}/s',
                $css,
                $trafienie,
            ),
            'Dwie kolumny „Świeżo z Kuking" nie są zamknięte w progu szerokości — na telefonie '
            .'karta miałaby połowę ekranu.',
        );

        $this->assertGreaterThanOrEqual(
            64,
            (int) $trafienie[1],
            'Próg zszedł poniżej 64rem. Przy czcionce przeglądarki 200% ma się NIE załapać '
            .'(64rem to wtedy 2048 px) — inaczej dwie kolumny zostają przy podwojonym piśmie '
            .'i wiersz robi się słupkiem.',
        );

        $this->assertMatchesRegularExpression(
            '/grid-template-columns:\s*repeat\(\s*2\s*,/',
            $trafienie[2],
            'Reguła nie daje dwóch kolumn.',
        );

        $this->assertMatchesRegularExpression(
            '/align-items:\s*start\s*;/',
            $trafienie[2],
            'Bez `align-items: start` karta niższa od sąsiadki rozciąga się do dołu rzędu — '
            .'pustka wygląda wtedy na pustą połowę karty, a nie na odstęp.',
        );
    }
}
