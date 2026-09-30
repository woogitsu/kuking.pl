<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Audyt zależności, Larastan i build assetów w jednym jobie, bez utraty
 * kontroli i bez zmiany wymaganych checków — #2299.
 *
 * Decyzja właściciela z 30.09.2026: krótkie joby scalić, żeby zwolnić sloty
 * równoległych jobów. Twarde warunki: żadna kontrola nie przestaje się
 * uruchamiać ani blokować, a ochrona gałęzi (lista wymaganych checków, którą
 * zmienia wyłącznie właściciel) nie może zablokować scalania PR-ów. Stąd:
 *   1. job `kontrole_krotkie` uruchamia audyt z bramką, PHPStana i pełne
 *      `npm run build`, na tym samym warunku co dawne joby;
 *   2. każdy krok po przygotowaniu ma `!cancelled()` — czerwony audyt nie
 *      może ukryć wyniku Larastana ani assetów (osobne joby tego nie robiły);
 *   3. dawne nazwy checków noszą LUSTRA: jeden krok, zielony tylko po
 *      sukcesie `kontrole_krotkie` albo po świadomym pominięciu przez
 *      `zakres` (kod != true), `!cancelled()` na jobie — pominięty wymagany
 *      check GitHub liczy jak zielony, więc lustro nie może się pominąć po
 *      czerwieni.
 */
class KrotkieKontroleWJednymJobieTest extends TestCase
{
    private const LUSTRA = [
        'audit' => 'Audyt zależności (blokuje high i critical)',
        'static-analysis' => 'Larastan (analiza statyczna)',
        'assets' => 'Build assetów (Vite)',
    ];

    public function test_wspolny_job_uruchamia_wszystkie_trzy_kontrole(): void
    {
        $job = $this->joby()['kontrole_krotkie'] ?? '';
        $this->assertNotSame('', $job, 'Brak joba `kontrole_krotkie` (#2299).');

        $this->assertMatchesRegularExpression("/^    if: needs\\.zakres\\.outputs\\.kod == 'true'\\s*$/m", $job);
        $this->assertMatchesRegularExpression('/^    needs: zakres\s*$/m', $job);
        $this->assertDoesNotMatchRegularExpression('/^    continue-on-error/m', $job);
        foreach ([
            'composer audit --locked',
            'npm audit --json',
            'python3 scripts/audyt-zaleznosci.py',
            'vendor/bin/phpstan analyse',
            'run: npm run build',
            'node scripts/skala-proporcje.mjs',
        ] as $polecenie) {
            $this->assertTrue(str_contains($job, $polecenie), "Job `kontrole_krotkie` nie uruchamia `{$polecenie}` — kontrola zniknęła przy scalaniu (#2299).");
        }
    }

    public function test_kazda_kontrola_rusza_takze_po_czerwieni_poprzedniej(): void
    {
        $job = $this->joby()['kontrole_krotkie'] ?? '';
        preg_match_all('/^      - (?:name: ([^\n]+)|uses: ([^\n]+))\n((?:        [^\n]*\n)*)/m', $job, $kroki, PREG_SET_ORDER);
        $this->assertGreaterThan(20, count($kroki), 'Odczyt kroków `kontrole_krotkie` zepsuty.');

        foreach (array_slice($kroki, 3) as $krok) {
            $nazwa = trim($krok[1] !== '' ? $krok[1] : $krok[2]);
            $this->assertMatchesRegularExpression(
                '/^        if: \$\{\{ !cancelled\(\)( && \(.+\))? \}\}\s*$/m',
                $krok[3],
                "Krok „{$nazwa}” w `kontrole_krotkie` nie ma `!cancelled()` — czerwona wcześniejsza kontrola ukryłaby jego wynik (#2299).",
            );
        }
    }

    public function test_dawne_nazwy_nosza_lustra_czerwone_po_czerwieni_wspolnego_joba(): void
    {
        $joby = $this->joby();

        foreach (self::LUSTRA as $id => $nazwa) {
            $this->assertArrayHasKey($id, $joby, "Brak lustra `{$id}` — wymagany check „{$nazwa}” przestałby powstawać i PR-y czekałyby bez końca (#2299).");
            $job = $joby[$id];
            $this->assertMatchesRegularExpression('/^    name: '.preg_quote($nazwa, '/').'\s*$/m', $job);
            $this->assertMatchesRegularExpression('/^    needs: \[zakres, kontrole_krotkie\]\s*$/m', $job);
            $this->assertMatchesRegularExpression(
                '/^    if: \$\{\{ !cancelled\(\) \}\}\s*$/m',
                $job,
                "Lustro `{$id}` bez `!cancelled()` byłoby pominięte po czerwonych krótkich kontrolach, a pominięty wymagany check liczy się jak zielony (#2299).",
            );
            $this->assertTrue(str_contains($job, 'WYNIK: ${{ needs.kontrole_krotkie.result }}'));
            $this->assertTrue(
                str_contains($job, "if [ \"\${WYNIK}\" = \"success\" ]; then\n            exit 0"),
                "Lustro `{$id}` jest zielone nie tylko po sukcesie krótkich kontroli (#2299).",
            );
            $this->assertTrue(str_contains($job, 'if [ "${WYNIK}" = "skipped" ] && [ "${WYNIK_ZAKRESU}" = "success" ] && [ "${KOD}" != "true" ]; then'));
            $this->assertMatchesRegularExpression('/exit 1\s*$/', rtrim($job), "Lustro `{$id}` nie kończy się porażką poza dwoma zielonymi stanami.");
            $this->assertSame(1, preg_match_all('/^      - /m', $job), "Lustro `{$id}` ma jeden krok — bez własnych kontroli, które mogłyby się rozjechać.");
            $this->assertDoesNotMatchRegularExpression('/^\s+uses: /m', $job);
            preg_match_all('/^    name: '.preg_quote($nazwa, '/').'\s*$/m', implode("\n", $joby), $ile);
            $this->assertCount(1, $ile[0], "Nazwa „{$nazwa}” należy do więcej niż jednego joba.");
        }
    }

    /** @return array<string, string> */
    private function joby(): array
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        $workflow = (string) preg_replace('/^[ \t]*#.*\n/m', '', $workflow);
        preg_match_all('/^  ([a-z_0-9-]+):\n(.*?)(?=^  [a-z_0-9-]+:|\z)/ms', substr($workflow, (int) strpos($workflow, "\njobs:\n")), $m, PREG_SET_ORDER);
        $joby = [];
        foreach ($m as $dopasowanie) {
            $joby[$dopasowanie[1]] = $dopasowanie[2];
        }

        return $joby;
    }
}
