<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRZY FAŁSZYWE ZIELENIE W `.github/workflows/deploy.yml`
 *
 *  #1012 — test dymny zapisywał wynik przy commicie ze zdarzenia wdrożenia,
 *          a odpytywał stały adres, nie sprawdzając, CO pod nim działa.
 *  #1332 — każde 301/302/307/308 po HTTP uchodziło za wymuszenie HTTPS,
 *          także przekierowanie na `http://` albo na obcy host.
 *  #974  — akcja `rollback` kończyła się zielono, niczego nie cofając.
 *
 * Zachowanie sond sprawdza na atrapach curl
 * `tests/skrypty/kontrola-sondy-wdrozenia.sh` (uruchamia go `check.sh`).
 * Ten plik pilnuje drugiej połowy: że workflow tych sond UŻYWA, we właściwym
 * miejscu, i że stara logika nie wróciła obok nich. GitHub Actions nie da się
 * uruchomić z testu, więc czytamy plik.
 */
class TestDymnyNieUdajeCudzegoWydaniaTest extends TestCase
{
    private function workflow(): string
    {
        $sciezka = base_path('.github/workflows/deploy.yml');

        $this->assertFileExists($sciezka, 'Nie ma .github/workflows/deploy.yml.');

        return (string) file_get_contents($sciezka);
    }

    /** Treść kroku o podanej nazwie — od `- name:` do następnego `- name:`. */
    private function krok(string $nazwa): string
    {
        $workflow = $this->workflow();
        $znacznik = '- name: '.$nazwa."\n";
        $poczatek = strpos($workflow, $znacznik);

        $this->assertNotFalse($poczatek, "W deploy.yml nie ma kroku „{$nazwa}”.");

        $koniec = strpos($workflow, '- name: ', $poczatek + strlen($znacznik));

        return substr($workflow, $poczatek, $koniec === false ? null : $koniec - $poczatek);
    }

    /** Kod kroku bez komentarzy — komentarze mogą cytować starą logikę. */
    private function bezKomentarzy(string $tekst): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $tekst);
    }

    #[Test]
    public function test_dymny_najpierw_potwierdza_pelny_sha_zdarzenia(): void
    {
        $krok = $this->bezKomentarzy($this->krok('Test dymny'));

        $this->assertStringContainsString('source scripts/sonda-wdrozenia.sh', $krok);
        $this->assertMatchesRegularExpression(
            '/OCZEKIWANY_SHA:\s*\$\{\{\s*github\.event\.deployment\.sha\s*\}\}/',
            $krok,
            'Oczekiwany SHA ma pochodzić ze zdarzenia wdrożenia — to jest commit, przy którym zapisze się wynik.',
        );

        $sonda = strpos($krok, 'sonda_wydanie "$BASE_URL" "$OCZEKIWANY_SHA"');
        $pierwszyCheck = strpos($krok, 'check /health');

        // Kontrola metody: bez żadnego `check` porównanie kolejności nic by nie mówiło.
        $this->assertNotFalse($pierwszyCheck, 'Krok „Test dymny” nie ma już `check /health` — test czyta zły kształt kroku.');
        $this->assertNotFalse($sonda, 'Test dymny nie potwierdza, że pod adresem działa wdrażany commit (#1012).');
        $this->assertLessThan(
            $pierwszyCheck,
            $sonda,
            'Sonda wydania ma iść PRZED sprawdzeniami — inaczej sprawdzają one cudze wydanie.',
        );

        $this->assertMatchesRegularExpression(
            '/if ! sonda_wydanie[^\n]*\n(?:[^\n]*\n){0,3}?\s*exit 1/',
            $krok,
            'Niezgodny SHA ma kończyć krok od razu, a nie tylko ustawiać `fail`.',
        );
        $koncowa = strrpos($krok, 'sonda_wydanie_koncowa "$BASE_URL" "$OCZEKIWANY_SHA" || fail=1');
        $this->assertNotFalse(
            $koncowa,
            'Po sprawdzeniach wydanie trzeba potwierdzić jeszcze raz — mogło się zmienić w trakcie.',
        );
        $this->assertGreaterThan(
            (int) strrpos($krok, 'check '),
            $koncowa,
            'Końcowa sonda wydania ma iść PO sprawdzeniach, inaczej nie wykryje podmiany w trakcie.',
        );
        // Jedna próba na końcu robiła z chwilowej porażki sieci „nieudane
        // wdrożenie". Ponawianie (3 próby) siedzi w sonda_wydanie_koncowa.
        $this->assertStringNotContainsString(
            'SONDA_PROBY=1',
            $krok,
            'Końcowa sonda wróciła do jednej próby — chwilowa porażka znów obleje wdrożenie.',
        );
    }

    #[Test]
    public function podsumowanie_pokazuje_oczekiwany_i_potwierdzony_commit(): void
    {
        $krok = $this->krok('Podsumowanie');

        $this->assertStringContainsString('steps.dymny.outputs.oczekiwany', $krok);
        $this->assertStringContainsString('steps.dymny.outputs.potwierdzony', $krok);
        $this->assertStringNotContainsString(
            '| Commit | `${{ github.sha }}`',
            $krok,
            'Sam `github.sha` w podsumowaniu mówi, co MIAŁO być sprawdzone, nie co sprawdzono.',
        );
    }

    #[Test]
    public function przekierowanie_https_sprawdza_cel_a_nie_sam_kod(): void
    {
        $krok = $this->bezKomentarzy($this->krok('Test dymny'));

        $this->assertStringContainsString('sonda_https "${BASE_URL#https://}" || fail=1', $krok);
        $this->assertDoesNotMatchRegularExpression(
            '/301\|302\|307\|308\)/',
            $krok,
            'Wróciło `case 301|302|307|308` — przekierowanie na http:// albo obcy host znów przejdzie (#1332).',
        );
    }

    #[Test]
    public function zadna_akcja_nie_nazywa_sie_rollback_skoro_nic_nie_cofa(): void
    {
        $workflow = $this->workflow();

        $this->assertSame(
            1,
            preg_match('/^\s*options:\s*\[([^\]]*redeploy[^\]]*)\]/m', $workflow, $dopasowanie),
            'Nie znalazłem listy akcji workflow_dispatch — test czyta zły kształt pliku.',
        );

        $akcje = array_map('trim', explode(',', $dopasowanie[1]));

        // Kontrola dodatnia: lista jest ta, o którą chodzi.
        $this->assertContains('smoke', $akcje);
        $this->assertContains('instrukcja-cofniecia', $akcje);
        $this->assertNotContains(
            'rollback',
            $akcje,
            'Akcja `rollback` wróciła. Żaden krok nie wykonuje rollbacku, więc taka nazwa obiecuje '
            .'zmianę stanu, której nie ma (#974). Jeśli powstał prawdziwy rollback, popraw ten test razem z nim.',
        );
    }

    #[Test]
    public function instrukcja_cofniecia_mowi_wprost_ze_nic_nie_zmienila(): void
    {
        $krok = $this->krok('Instrukcja cofnięcia (niczego nie cofa)');

        $this->assertStringContainsString("github.event.inputs.action == 'instrukcja-cofniecia'", $krok);
        $this->assertStringContainsString('::warning title=Nic nie zostało cofnięte::', $krok);
        $this->assertStringNotContainsString('railway redeploy', $krok, 'Redeploy wdraża BIEŻĄCĄ wersję — to nie jest cofnięcie.');

        $podsumowanie = $this->bezKomentarzy(substr($this->workflow(), (int) strrpos($this->workflow(), '- name: Podsumowanie')));

        $this->assertStringContainsString('środowisko NIE zostało zmienione', $podsumowanie);
    }
}
