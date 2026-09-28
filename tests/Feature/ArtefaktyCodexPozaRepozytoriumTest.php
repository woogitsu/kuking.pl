<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `.codex/heic-119` śledził surowe media z telefonu, wygenerowane logi
 * przebiegów i lokalne pomiary, mimo że `.gitignore` deklarował cały
 * katalog `/.codex` jako ignorowany (#1911). Same „usunięte z indeksu" nie
 * wystarczy — bez strażnika następne `git add -A` na tym samym drzewie
 * roboczym (np. po ponownym uruchomieniu sondy #119) wciągnęłoby je z powrotem.
 *
 * Pytamy PRAWDZIWEGO gita (`git check-ignore --no-index`), tak jak
 * `ZrzutyBazyPozaRepozytoriumTest` — chodzi o to, co zrobi `git add`, nie
 * o to, co myślimy, że zrobi. Kontrola dodatnia jest tu równie ważna: dwa
 * narzędzia poprawione i dodane świadomie w #1863 (`phone-proxy.py`
 * i jego test) nie mogą zostać zakryte, inaczej zmiana w nich nie dałaby
 * się zacommitować.
 */
class ArtefaktyCodexPozaRepozytoriumTest extends TestCase
{
    private function ignorowany(string $sciezka): bool
    {
        if (! is_dir(base_path('.git')) && ! is_file(base_path('.git'))) {
            $this->markTestSkipped('Drzewo bez `.git` — nie ma kogo zapytać o `.gitignore`.');
        }

        exec('git -C '.escapeshellarg(base_path()).' check-ignore -q --no-index -- '.escapeshellarg($sciezka), $wyjscie, $kod);

        $this->assertContains($kod, [0, 1], "git check-ignore zakończył się kodem {$kod}.");

        return $kod === 0;
    }

    /** @return array<string, array{0: string}> */
    public static function artefakty(): array
    {
        return [
            'nagranie z telefonu' => ['.codex/heic-119/nowe-nagranie.mov'],
            'zdjęcie HEIC' => ['.codex/heic-119/nowe-zdjecie.heic'],
            'log przebiegu testów' => ['.codex/heic-119/kolejny-log.txt'],
            'zapytanie badawcze SQL' => ['.codex/heic-119/nowe-zapytanie.sql'],
            'pomiary urządzenia' => ['.codex/heic-119/nowe-pomiary.jsonl'],
            'skrypt jednej maszyny' => ['.codex/heic-119/nowy-skrypt.sh'],
            'inny katalog roboczy pod .codex' => ['.codex/inna-sonda/plik.txt'],
        ];
    }

    #[DataProvider('artefakty')]
    public function test_nowy_artefakt_pod_codex_jest_ignorowany(string $sciezka): void
    {
        $this->assertTrue($this->ignorowany($sciezka), "{$sciezka} wjechałby do repozytorium przez `git add .` — dokładnie to, co zdarzyło się w #1911.");
    }

    /** @return array<string, array{0: string}> */
    public static function narzedzia(): array
    {
        return [
            'proxy LAN dla telefonu' => ['.codex/heic-119/phone-proxy.py'],
            'test proxy' => ['.codex/heic-119/phone-proxy.test.py'],
        ];
    }

    #[DataProvider('narzedzia')]
    public function test_swiadomie_utrzymywane_narzedzie_nie_jest_zakryte(string $sciezka): void
    {
        $this->assertFalse($this->ignorowany($sciezka), "{$sciezka} jest zakryty przez `.gitignore` — zmiany w tym narzędziu z #1863 przestałyby dawać się dodać do commitu.");
    }

    /**
     * Kontrola z drugiej strony — tylko te dwa pliki są dziś śledzone pod
     * `.codex`. Jeśli ta lista kiedyś urośnie, ma to być świadoma decyzja
     * (nowy wpis w `narzedzia()` wyżej i wyjątek `!` w `.gitignore`), nie
     * przypadkowy skutek `git add -A`.
     */
    public function test_pod_codex_sledzone_sa_wylacznie_znane_narzedzia(): void
    {
        exec('git -C '.escapeshellarg(base_path()).' ls-files -- .codex', $sledzone, $kod);

        $this->assertSame(0, $kod, 'git ls-files zakończył się błędem.');

        sort($sledzone);

        $this->assertSame(
            ['.codex/heic-119/phone-proxy.py', '.codex/heic-119/phone-proxy.test.py'],
            $sledzone,
            "Pod `.codex` jest śledzone coś innego niż dwa znane narzędzia:\n- ".implode("\n- ", $sledzone),
        );
    }
}
