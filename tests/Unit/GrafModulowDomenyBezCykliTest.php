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
     * Moderation ↔ Security: `AlarmujOPilnymZgloszeniu` używa
     * `DziennyBudzetListow`, a `KomunikatZamknietegoKonta` —
     * `UzasadnienieDecyzji`.
     *
     * Ten cykl urósł o Compliance i Users przez wejście przez dostawcę
     * (#1035, PR #1635 na `main`): `Security\WejsciePrzezDostawce\
     * WejdzPrzezDostawce` używa `Users\Actions\ZalozKonto` i `ZamekKonta`,
     * a dalej Users → Compliance (`EraseAccountData`, `CancelAccountDeletion`)
     * → Moderation (`PrzedawnioneSprawyModeracyjne`) → Security. Zastany
     * na `main`, do rozcięcia osobnym zadaniem — tak jak Users → Social
     * kontraktem `ObserwowanieGospodarza`.
     *
     * I dalej o Analytics i Media, też zastane na `main` (stan z 25.09):
     * Users → Media (`EraseAccountData` kasuje pliki), Media → Moderation
     * (`DostepDoZdjecia` pyta `ModeratedContent`, 24.09), Media → Analytics
     * (`StoreUploadedImage`, zdarzenie `photo_upload_failed`) i Analytics →
     * Compliance (`PrzedawnioneSygnaly` używa `UsuwanieWPartiach`, #1657).
     * Do rozcięcia osobnym zadaniem; ta gałąź żadnej z tych krawędzi nie
     * dokłada.
     *
     * @var list<list<string>>
     */
    private const ZNANE_CYKLE = [
        ['Analytics', 'Compliance', 'Media', 'Moderation', 'Security', 'Users'],
    ];

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
