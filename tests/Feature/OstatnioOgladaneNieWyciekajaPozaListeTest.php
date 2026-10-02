<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Lista ostatnio oglądanych przepisów (#2553) służy WYŁĄCZNIE do powrotu do
 * przepisu: nie zasila feedu, rekomendacji, statystyk ani powiadomień
 * (decyzja właściciela z 2.10.2026, D-333; zasada doboru treści z AGENTS.md §8).
 *
 * Test czyta kod aplikacji i widoki i oblewa, gdy tabela, model albo domena
 * tej listy pojawią się w pliku spoza zamkniętej listy poniżej. Nowe użycie
 * wymaga więc świadomej zmiany TEJ listy — i odpowiedzi na pytanie, czy dane
 * o zachowaniu człowieka mają tam wyjść.
 */
class OstatnioOgladaneNieWyciekajaPozaListeTest extends TestCase
{
    /** Fragmenty, po których rozpoznajemy użycie listy albo zgody na nią. */
    private const SLADY = [
        'recent_recipe_views',
        'RecentRecipeView',
        'recentRecipeViews',
        'OstatnioOgladane',
        'ostatnio_ogladane_wlaczone_at',
        'maWlaczoneOstatnioOgladane',
    ];

    /** Jedyne pliki, które wolno łączyć z listą: model, domena, ekran, sprzątanie, eksport, wymazanie. */
    private const DOZWOLONE = [
        'app/Console/Commands/SprzatajOstatnioOgladane.php',
        'app/Domain/Recipes/OstatnioOgladane.php',
        'app/Domain/Users/Actions/EraseAccountData.php',
        'app/Domain/Users/Exports/CollectUserExportData.php',
        'app/Domain/Users/Exports/InwentarzDanychKonta.php',
        'app/Http/Controllers/RecipeController.php',
        'app/Http/Controllers/Settings/OstatnioOgladaneController.php',
        'app/Models/RecentRecipeView.php',
        'app/Models/User.php',
    ];

    public function test_lista_jest_uzywana_tylko_przez_dozwolone_pliki(): void
    {
        $naruszenia = [];

        foreach ($this->pliki() as $sciezka => $tresc) {
            if (in_array($sciezka, self::DOZWOLONE, true)) {
                continue;
            }

            foreach (self::SLADY as $slad) {
                if (str_contains($tresc, $slad)) {
                    $naruszenia[] = "{$sciezka} używa „{$slad}”";
                }
            }
        }

        $this->assertSame(
            [],
            $naruszenia,
            "OSTATNIO_OGLADANE_2553_PRYWATNA_LISTA_POZA_ZAMKNIETA_LISTA\nLista ostatnio oglądanych przepisów służy tylko do powrotu do przepisu, a ten plik jej dotyka:\n"
            .implode("\n", $naruszenia)
            ."\nNie łącz jej z feedem, rekomendacjami, statystykami ani powiadomieniami (D-333, AGENTS.md §8).",
        );
    }

    public function test_kontrola_dodatnia_dozwolone_pliki_naprawde_uzywaja_listy(): void
    {
        $pliki = $this->pliki();
        $uzywajace = [];

        foreach ($pliki as $sciezka => $tresc) {
            foreach (self::SLADY as $slad) {
                if (str_contains($tresc, $slad)) {
                    $uzywajace[] = $sciezka;
                    break;
                }
            }
        }

        $this->assertGreaterThanOrEqual(6, count($uzywajace), 'Kontrola dodatnia: wyszukiwanie śladów listy nic nie znajduje, więc strażnik niczego nie pilnuje.');
        $this->assertContains('app/Domain/Recipes/OstatnioOgladane.php', $uzywajace);
        $this->assertContains('app/Http/Controllers/RecipeController.php', $uzywajace);
    }

    /** @return array<string, string> ścieżka względna => treść */
    private function pliki(): array
    {
        $wynik = [];

        foreach (['app', 'resources/views'] as $katalog) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($katalog), RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterator as $plik) {
                if (! $plik->isFile() || ! in_array($plik->getExtension(), ['php'], true)) {
                    continue;
                }

                $wynik[str_replace(base_path().'/', '', $plik->getPathname())] = (string) file_get_contents($plik->getPathname());
            }
        }

        return $wynik;
    }
}
