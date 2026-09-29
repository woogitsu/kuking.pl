<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Granice modułów `app/Domain` — graf zależności bez cykli (issue #971).
 *
 * Modularny monolit ma sens tylko wtedy, gdy granica modułu mówi coś
 * o promieniu zmiany. Do #971 nic tego nie pilnowało i powstał cykl
 * `Users → Social → Users` (`ZalozKonto` importował `FollowUser`, a
 * `ZamekPary` używa `ZamekKonta`). Rozcięty jest kontraktem
 * `App\Domain\Users\ObserwowanieGospodarza`, a ten test pilnuje, żeby
 * żaden kolejny import nie zamknął cyklu po cichu.
 *
 * JAK CZYTA KOD: przez tokenizer PHP, nie wyrażenie regularne na tekście.
 * Liczą się nazwy kwalifikowane (`use`, `new`, `::class`, typy, pełne
 * nazwy w kodzie), a NIE komentarze — `ZamekPary` wspomina
 * `App\Domain\Users\ZamekKonta` w docblocku i to nie jest zależność.
 *
 * ZNANE CYKLE: lista `ZNANE_CYKLE` jest dokładna w obie strony. Nowy cykl
 * oblewa test, a rozcięcie znanego też oblewa — żeby wpis nie został na
 * liście jako furtka dla kolejnego importu.
 *
 * Kontrola dodatnia: wpis w `scripts/kontrole-negatywne-alfa08.py` dokłada
 * z powrotem import `Social` do `ZalozKonto` i test ma zapalić.
 */
final class GrafModulowDomenyBezCykliTest extends TestCase
{
    /**
     * Cykle zastane przed #971, każdy do rozcięcia osobnym zadaniem.
     * Moduły w cyklu posortowane alfabetycznie.
     *
     * OD #2149 (etap 3) LISTA JEST PUSTA: graf modułów `app/Domain` nie ma
     * żadnego cyklu. Historia składowej `Compliance`/`Moderation`/`Security`/
     * `Users` (wejście przez dostawcę #1035, potem Analytics i Media zastane
     * na `main` 25.09) i tego, jak ją rozcięto:
     *
     *  - Analytics → Compliance (etap 1): `UsuwanieWPartiach` to ogólny
     *    mechanizm bazy, więc mieszka w `App\Support`.
     *  - Media → Moderation (etap 2): nazwy typów celu zgłoszenia to stałe
     *    `Report::TARGET_*` (model, nie moduł).
     *  - Compliance → Moderation (etap 3): retencja spraw odświeża liczniki
     *    kolejek przez kontrakt `App\Support\OdswiezanieLicznikowKolejek`,
     *    który implementuje `KolejkiPanelu` (wiązanie w `AppServiceProvider`).
     *  - Moderation → Security (etap 3): `DziennyBudzetListow` to wspólny
     *    licznik dobowego sufitu poczty, używany też przez `Contact`, `Digest`
     *    i `Notifications`, więc mieszka w `App\Poczta` obok
     *    `ListZarezerwowany`, a nie w `Security`.
     *
     * Zostały krawędzie jednokierunkowe (m.in. `Security → Moderation`,
     * `Security → Users`, `Moderation → Users`, `Users → Compliance`,
     * `Users → Media`) — żadna nie wraca. Jeśli cykl wróci, dopisz go tutaj
     * tylko wtedy, gdy rozcięcie jest osobnym, opisanym zadaniem.
     *
     * @var list<list<string>>
     */
    private const ZNANE_CYKLE = [];

    public function test_graf_modulow_domeny_nie_ma_nowych_cykli(): void
    {
        $cykle = self::cykle(self::grafZKodu());

        $this->assertSame(
            self::ZNANE_CYKLE,
            $cykle,
            'Graf zależności modułów app/Domain zmienił swoje cykle. Nowy cykl oznacza, że moduł A importuje B, '
            .'a B (pośrednio) A — odwróć jedną krawędź (kontrakt po stronie wołającego, implementacja w module '
            .'niżej, wiązanie w AppServiceProvider — jak App\\Domain\\Users\\ObserwowanieGospodarza). '
            .'Jeśli rozciąłeś znany cykl, usuń go z ZNANE_CYKLE.',
        );
    }

    public function test_users_nie_zalezy_od_social(): void
    {
        $graf = self::grafZKodu();

        // Kontrola, że skaner w ogóle widzi krawędzie: `ZamekPary` używa
        // `ZamekKonta`. Bez tego pusty graf dawałby fałszywą zieleń.
        $this->assertArrayHasKey('Users', $graf['Social'] ?? [], 'Skaner nie widzi krawędzi Social → Users — test stracił przedmiot.');

        $this->assertArrayNotHasKey(
            'Social',
            $graf['Users'] ?? [],
            'app/Domain/Users importuje App\\Domain\\Social ('.implode(', ', $graf['Users']['Social'] ?? []).'). '
            .'To zamyka cykl Users ↔ Social (#971) — obserwowanie gospodarza idzie przez kontrakt '
            .'App\\Domain\\Users\\ObserwowanieGospodarza.',
        );
    }

    public function test_analytics_nie_zalezy_od_compliance(): void
    {
        $graf = self::grafZKodu();

        // Kontrola, że skaner widzi Media → Analytics (`StoreUploadedImage`);
        // bez niej pusty graf dawałby fałszywą zieleń.
        $this->assertArrayHasKey('Analytics', $graf['Media'] ?? [], 'Skaner nie widzi krawędzi Media → Analytics — test stracił przedmiot.');

        $this->assertArrayNotHasKey(
            'Compliance',
            $graf['Analytics'] ?? [],
            'app/Domain/Analytics importuje App\\Domain\\Compliance ('.implode(', ', $graf['Analytics']['Compliance'] ?? []).'). '
            .'To zamyka pierścień Analytics → Compliance → Moderation → Security → Media → Analytics (#2149). '
            .'Ogólne usuwanie partiami to App\\Support\\UsuwanieWPartiach.',
        );
    }

    public function test_media_nie_zalezy_od_moderation(): void
    {
        $graf = self::grafZKodu();

        $this->assertArrayHasKey('Media', $graf['Users'] ?? [], 'Skaner nie widzi krawędzi Users → Media — test stracił przedmiot.');

        $this->assertArrayNotHasKey(
            'Moderation',
            $graf['Media'] ?? [],
            'app/Domain/Media importuje App\\Domain\\Moderation ('.implode(', ', $graf['Media']['Moderation'] ?? []).'). '
            .'To zawraca Media do dawnej składowej Compliance/Moderation/Security/Users (#2149). Nazwy typów celu '
            .'zgłoszenia to App\\Models\\Report::TARGET_*.',
        );
    }

    public function test_compliance_nie_zalezy_od_moderation(): void
    {
        $graf = self::grafZKodu();

        // Kontrola, że skaner widzi krawędź w drugą stronę (Users → Compliance,
        // `EraseAccountData`); bez niej pusty graf dawałby fałszywą zieleń.
        $this->assertArrayHasKey('Compliance', $graf['Users'] ?? [], 'Skaner nie widzi krawędzi Users → Compliance — test stracił przedmiot.');

        $this->assertArrayNotHasKey(
            'Moderation',
            $graf['Compliance'] ?? [],
            'app/Domain/Compliance importuje App\\Domain\\Moderation ('.implode(', ', $graf['Compliance']['Moderation'] ?? []).'). '
            .'To zamyka pierścień Compliance → Moderation → Users → Compliance (#2149). Liczniki kolejek panelu '
            .'odświeża się przez kontrakt App\\Support\\OdswiezanieLicznikowKolejek.',
        );
    }

    public function test_moderation_nie_zalezy_od_security(): void
    {
        $graf = self::grafZKodu();

        $this->assertArrayHasKey('Moderation', $graf['Security'] ?? [], 'Skaner nie widzi krawędzi Security → Moderation — test stracił przedmiot.');

        $this->assertArrayNotHasKey(
            'Security',
            $graf['Moderation'] ?? [],
            'app/Domain/Moderation importuje App\\Domain\\Security ('.implode(', ', $graf['Moderation']['Security'] ?? []).'). '
            .'To zamyka cykl Moderation ↔ Security (#2149). Dobowy sufit listów to App\\Poczta\\DziennyBudzetListow.',
        );
    }

    public function test_wykrywanie_cykli_na_grafie_wzorcowym(): void
    {
        $this->assertSame([], self::cykle(['A' => ['B' => []], 'B' => ['C' => []]]));
        $this->assertSame([['A', 'B', 'C']], self::cykle([
            'A' => ['B' => []],
            'B' => ['C' => []],
            'C' => ['A' => []],
            'D' => ['A' => []],
        ]));
    }

    public function test_skaner_pomija_komentarze_i_widzi_import(): void
    {
        $kod = "<?php\nnamespace App\\Domain\\Users;\n// App\\Domain\\Social\\X w komentarzu\n"
            ."/** App\\Domain\\Posts\\Y w docblocku */\nuse App\\Domain\\Media\\Z;\n"
            ."\$a = new \\App\\Domain\\Tags\\W();\n";

        $this->assertSame(['Media', 'Tags'], self::modulyUzyteW($kod, 'Users'));
    }

    /**
     * @return array<string, array<string, list<string>>> moduł → moduł docelowy → pliki
     */
    private static function grafZKodu(): array
    {
        $korzen = dirname(__DIR__, 2);
        $domena = $korzen.'/app/Domain/';
        $graf = [];

        $pliki = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domena, FilesystemIterator::SKIP_DOTS));

        foreach ($pliki as $plik) {
            if ($plik->getExtension() !== 'php') {
                continue;
            }

            $wzgledna = substr($plik->getPathname(), strlen($domena));
            $modul = explode('/', $wzgledna)[0];

            if ($modul === $wzgledna) {
                continue; // plik bezpośrednio w app/Domain — nie należy do modułu
            }

            foreach (self::modulyUzyteW((string) file_get_contents($plik->getPathname()), $modul) as $cel) {
                $graf[$modul][$cel][] = 'app/Domain/'.$wzgledna;
            }
        }

        return $graf;
    }

    /**
     * @return list<string> moduły `App\Domain\*` użyte w kodzie, inne niż własny
     */
    private static function modulyUzyteW(string $kod, string $wlasny): array
    {
        $moduly = [];

        foreach (token_get_all($kod) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $nazwa = ltrim($token[1], '\\');

            // Grupowy import `use App\Domain\{A\X, B\Y}` ukryłby moduł przed
            // skanerem. Nie ma go dziś nigdzie — i niech tak zostanie.
            if ($nazwa === 'App\\Domain') {
                self::fail('Grupowy import z App\\Domain ukrywa zależności przed tym testem — rozpisz go na osobne `use`.');
            }

            if (preg_match('/^App\\\\Domain\\\\(\w+)\\\\/', $nazwa, $m) === 1 && $m[1] !== $wlasny) {
                $moduly[$m[1]] = true;
            }
        }

        $wynik = array_keys($moduly);
        sort($wynik);

        return $wynik;
    }

    /**
     * Silnie spójne składowe (Tarjan) o więcej niż jednym węźle — każda to cykl.
     *
     * @param  array<string, array<string, mixed>>  $graf
     * @return list<list<string>> posortowane
     */
    private static function cykle(array $graf): array
    {
        $indeks = [];
        $niski = [];
        $stos = [];
        $naStosie = [];
        $licznik = 0;
        $skladowe = [];

        $odwiedz = function (string $v) use (&$odwiedz, &$indeks, &$niski, &$stos, &$naStosie, &$licznik, &$skladowe, $graf): void {
            $indeks[$v] = $niski[$v] = $licznik++;
            $stos[] = $v;
            $naStosie[$v] = true;

            foreach (array_keys($graf[$v] ?? []) as $w) {
                if (! isset($indeks[$w])) {
                    $odwiedz($w);
                    $niski[$v] = min($niski[$v], $niski[$w]);
                } elseif (isset($naStosie[$w])) {
                    $niski[$v] = min($niski[$v], $indeks[$w]);
                }
            }

            if ($niski[$v] === $indeks[$v]) {
                $skladowa = [];
                do {
                    $w = array_pop($stos);
                    unset($naStosie[$w]);
                    $skladowa[] = $w;
                } while ($w !== $v);

                if (count($skladowa) > 1) {
                    sort($skladowa);
                    $skladowe[] = $skladowa;
                }
            }
        };

        foreach (array_keys($graf) as $v) {
            if (! isset($indeks[$v])) {
                $odwiedz((string) $v);
            }
        }

        usort($skladowe, fn (array $a, array $b): int => $a <=> $b);

        return $skladowe;
    }
}
