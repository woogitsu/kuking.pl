<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Routing\Route as Trasa;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Trzy obietnice `docs/API.md` i `routes/api.php` (D-270), sprawdzane na
 * PRAWDZIWEJ tablicy tras, a nie na liście przepisanej z palca:
 *
 *  1. każda trasa `/api/v1` ma wiersz w tabeli „Lista endpointów" —
 *     aplikacja mobilna powstaje z tego dokumentu, więc trasa bez opisu nie
 *     istnieje dla jej autora, a opis bez trasy obiecuje coś, czego nie ma;
 *  2. każda trasa poza wydaniem tokenu ma `auth:sanctum` — zapomniane
 *     `auth:sanctum` nie wywala niczego, tylko po cichu otwiera trasę;
 *  3. każda trasa z identyfikatorem w adresie jest w
 *     `KazdaTrasaZIdentyfikatoremPodPolicyTest`, czyli zmierzona żądaniem
 *     dla pięciu ról (UUID w adresie nie jest autoryzacją, AGENTS.md §7).
 *
 * Każda obietnica ma kontrolę dodatnią: wykrywacz umie odpowiedzieć „nie".
 */
class ApiJestUdokumentowaneTest extends TestCase
{
    private const DOKUMENT = 'docs/API.md';

    private const TEST_POLICY = 'tests/Feature/KazdaTrasaZIdentyfikatoremPodPolicyTest.php';

    /** Jedyne trasy bez tokenu — z tych tras token dopiero powstaje. */
    private const BEZ_TOKENU = [
        'api.tokeny.store',
        'api.tokeny.kod',
    ];

    /** Tyle tras miało API po etapie 1 (25.09.2026). Mniej = skan nie czyta tablicy. */
    private const MIN_TRAS = 17;

    public function test_kazda_trasa_api_jest_w_tabeli_endpointow(): void
    {
        $tabela = $this->wierszeTabeli();
        $brak = [];

        foreach ($this->trasyApi() as $trasa) {
            foreach ($this->podpisy($trasa) as $podpis) {
                if (! in_array($podpis, $tabela, true)) {
                    $brak[] = $podpis;
                }
            }
        }

        $this->assertSame([], $brak,
            'Trasy API bez wiersza w tabeli „Lista endpointów" w '.self::DOKUMENT.":\n".implode("\n", $brak)
            ."\n\nDopisz wiersz `METODA /api/v1/…` z nazwami parametrów dokładnie jak w routes/api.php.");
    }

    public function test_tabela_nie_opisuje_trasy_ktorej_nie_ma(): void
    {
        $prawdziwe = [];

        foreach ($this->trasyApi() as $trasa) {
            array_push($prawdziwe, ...$this->podpisy($trasa));
        }

        $widma = array_values(array_diff($this->wierszeTabeli(), $prawdziwe));

        $this->assertSame([], $widma,
            'Tabela w '.self::DOKUMENT." opisuje trasy, których nie ma w routes/api.php:\n".implode("\n", $widma));
    }

    public function test_kazda_trasa_poza_wydaniem_tokenu_wymaga_tokenu(): void
    {
        $bez = [];
        $zTokenem = 0;

        foreach ($this->trasyApi() as $trasa) {
            $maToken = in_array('auth:sanctum', $trasa->gatherMiddleware(), true);

            if (in_array($trasa->getName(), self::BEZ_TOKENU, true)) {
                $this->assertFalse($maToken, "Trasa logowania {$trasa->getName()} wymaga tokenu — nie da się jej użyć.");

                continue;
            }

            $maToken ? $zTokenem++ : $bez[] = $trasa->getName().' ('.$trasa->uri().')';
        }

        $this->assertSame([], $bez, "Trasy API bez `auth:sanctum`:\n".implode("\n", $bez));
        $this->assertGreaterThanOrEqual(self::MIN_TRAS - count(self::BEZ_TOKENU), $zTokenem);
    }

    public function test_kazda_trasa_z_identyfikatorem_jest_zmierzona_w_tescie_policy(): void
    {
        $zrodlo = (string) file_get_contents(base_path(self::TEST_POLICY));
        $brak = [];
        $zParametrem = 0;

        foreach ($this->trasyApi() as $trasa) {
            if (! str_contains($trasa->uri(), '{')) {
                continue;
            }

            $zParametrem++;

            if (! str_contains($zrodlo, "\$dodaj('".$trasa->getName()."'")) {
                $brak[] = (string) $trasa->getName();
            }
        }

        $this->assertGreaterThanOrEqual(10, $zParametrem, 'Skan nie widzi tras API z identyfikatorem.');
        $this->assertSame([], $brak,
            'Trasy API z identyfikatorem bez pomiaru w '.self::TEST_POLICY.":\n".implode("\n", $brak));
    }

    /**
     * Kontrola dodatnia wszystkich trzech wykrywaczy naraz: odpowiadają „nie"
     * na trasę, której nie ma w dokumencie, bez tokenu i bez pomiaru.
     */
    public function test_wykrywacze_umieja_odpowiedziec_przeczaco(): void
    {
        $widmo = Route::middleware('api')->prefix('api/v1')->get('/_widmo/{cos}', fn () => null)->name('api.widmo');
        Route::getRoutes()->refreshNameLookups();

        $this->assertNotContains('GET /api/v1/_widmo/{cos}', $this->wierszeTabeli());
        $this->assertNotContains('auth:sanctum', $widmo->gatherMiddleware());
        $this->assertStringNotContainsString("\$dodaj('api.widmo'", (string) file_get_contents(base_path(self::TEST_POLICY)));
        $this->assertContains('GET /api/v1/_widmo/{cos}', $this->podpisy($widmo));

        // I „tak" na trasę, która JEST opisana.
        $this->assertContains('GET /api/v1/feed', $this->wierszeTabeli());
    }

    /**
     * @return list<Trasa>
     */
    private function trasyApi(): array
    {
        $trasy = [];

        foreach (Route::getRoutes() as $trasa) {
            if (str_starts_with($trasa->uri(), 'api/v1/') && ! str_starts_with($trasa->uri(), 'api/v1/_')) {
                $trasy[] = $trasa;
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_TRAS, count($trasy),
            'Skan widzi podejrzanie mało tras pod /api/v1 — routes/api.php nie załadowany?');

        return $trasy;
    }

    /**
     * @return list<string> np. `GET /api/v1/feed`
     */
    private function podpisy(Trasa $trasa): array
    {
        $podpisy = [];

        foreach ($trasa->methods() as $metoda) {
            if ($metoda !== 'HEAD') {
                $podpisy[] = $metoda.' /'.$trasa->uri();
            }
        }

        return $podpisy;
    }

    /**
     * Pierwsza kolumna tabeli „Lista endpointów" — `METODA /api/v1/…`.
     *
     * @return list<string>
     */
    private function wierszeTabeli(): array
    {
        $dokument = (string) file_get_contents(base_path(self::DOKUMENT));
        $od = strpos($dokument, '## 7. Lista endpointów');

        $this->assertNotFalse($od, 'W '.self::DOKUMENT.' nie ma sekcji „## 7. Lista endpointów".');

        $sekcja = substr($dokument, $od);
        $koniec = strpos($sekcja, "\n### ");
        $sekcja = $koniec === false ? $sekcja : substr($sekcja, 0, $koniec);

        preg_match_all('#^\| `((?:GET|POST|PUT|PATCH|DELETE) /api/v1/[^`]+)` \|#m', $sekcja, $trafienia);

        $this->assertGreaterThanOrEqual(self::MIN_TRAS, count($trafienia[1]),
            'Wykrywacz nie widzi wierszy tabeli — zmienił się format tabeli?');

        return $trafienia[1];
    }
}
