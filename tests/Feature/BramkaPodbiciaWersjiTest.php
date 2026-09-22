<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Bramka podbicia wersji — czy nadal stoi w CI i czy furtka nadal jest furtką.
 *
 * DWA KIERUNKI TEJ SAMEJ REGUŁY — I DLACZEGO SĄ DWA STRAŻNIKI.
 * Komentarz nad `'etykieta'` w `config/kuking.php` wiąże dwie rzeczy:
 *
 *   (a) „KAŻDE PODBICIE CYFRY MA WPIS W `CHANGELOG.md`" — czyli
 *       podbito wersję ⇒ musi być wpis. Tego pilnuje
 *       `PodbicieWersjiWymagaWpisuWChangelogTest` (21.09.2026): porównuje
 *       aktualną etykietę z nagłówkiem najświeższego wpisu, BEZ gita, więc
 *       chodzi też tam, gdzie historii nie ma (runtime floty z rsynca).
 *
 *   (b) „CYFRA ROŚNIE PRZY KAŻDEJ ZMIANIE, KTÓRĄ CZŁOWIEK ZOBACZY" — czyli
 *       zmiana w warstwie widocznej ⇒ musi być podbicie. Tego nie pilnowało
 *       NIC, i to właśnie ten kierunek zgłosił właściciel: etykieta
 *       „Alfa 0.67" weszła 18 września 2026 (06c8c7e5) i stała cztery dni,
 *       przez 292 commity na `main`, w tym kilkanaście zmieniających rzeczy
 *       widoczne dla człowieka. Tego pilnuje `scripts/bramka-wersji.sh`.
 *
 * To NIE są dwie kopie tej samej bramki. (a) jest niezmiennikiem stanu drzewa
 * i nie potrzebuje zakresu; (b) jest pytaniem o RÓŻNICĘ i bez zakresu gita nie
 * da się go zadać. Żadne z nich nie łapie tego, co drugie: (a) przechodzi
 * zielono przy zmianie widoku bez podbicia, (b) przechodzi zielono przy
 * podbiciu, którego nikt nie wymusił.
 *
 * CZEGO TEN TEST NIE ROBI. Nie odtwarza czerwieni bramki — ta liczy na
 * ZAKRESIE gita i mierzy się ją uruchomieniem skryptu na przygotowanych
 * gałęziach, nie PHPUnitem. Ten test pilnuje dwóch rzeczy, których skrypt
 * o sobie nie powie: że jest podpięty i że nie został po cichu rozbrojony.
 */
class BramkaPodbiciaWersjiTest extends TestCase
{
    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    }

    public function test_bramka_jest_podpieta_w_ci_i_naprawde_blokuje(): void
    {
        $this->assertFileExists(base_path('scripts/bramka-wersji.sh'));

        $matched = preg_match('/^  bramka_wersji:\R(.*?)(?=^  [a-z_]+:|\z)/ms', $this->workflow(), $matches);
        $this->assertSame(1, $matched,
            'Zniknął job CI `bramka_wersji` — reguła wersji znowu jest samym komentarzem.');

        $job = (string) $matches[1];

        $this->assertStringContainsString('bash scripts/bramka-wersji.sh', $job,
            'Job istnieje, ale nie woła bramki.');

        // `continue-on-error` zrobiłoby z bramki ozdobę: czerwień byłaby
        // widoczna i nic by nie blokowała, czyli dokładnie stan sprzed niej.
        $this->assertStringNotContainsString('continue-on-error:', $job);

        // Bramka czyta treści WSZYSTKICH commitów zakresu (tam stoi furtka)
        // oraz `config/kuking.php` z bazy. Płytki checkout ją oślepia.
        $this->assertStringContainsString('fetch-depth: 0', $job,
            'Bez pełnej historii bramka nie zobaczy ani zakresu, ani furtki.');
    }

    public function test_furtka_wymaga_powodu_a_nie_samej_nazwy(): void
    {
        // Umowa z nagłówka bramki: `Bez-podbicia-wersji:` musi mieć po
        // dwukropku treść. Rozluźnienie tego wzorca zamieniłoby świadomą
        // decyzję w jednolinijkowy wyłącznik bez śladu, po co go użyto.
        $skrypt = (string) file_get_contents(base_path('scripts/bramka-wersji.sh'));

        $this->assertStringContainsString(
            'Bez-podbicia-wersji:[[:space:]]*[^[:space:]]',
            $skrypt,
            'Wzorzec furtki przestał wymagać powodu po dwukropku.',
        );

        // Warstwa widoczna dla człowieka to co najmniej te trzy katalogi.
        // Zawężenie listy wyciszyłoby bramkę bez jednego czerwonego przebiegu.
        $this->assertStringContainsString(
            '^resources/(views|css|js)/',
            $skrypt,
            'Bramka przestała obejmować którąś z trzech warstw widocznych dla człowieka.',
        );
    }
}
