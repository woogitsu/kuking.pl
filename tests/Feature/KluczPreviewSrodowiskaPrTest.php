<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Issue #975: zapieczętowany `APP_KEY` staginu nie trafia do Railway PR
 * Environments, więc preview startowało bez klucza i entrypoint kończył je
 * kodem 1. `docker/klucz-preview.sh` nadaje wtedy losowy klucz kontenera —
 * ale WYŁĄCZNIE w potwierdzonym środowisku PR. Produkcja i staging bez klucza
 * dalej nie wystartują.
 *
 * Testy uruchamiają prawdziwy skrypt w bashu z kontrolowanym środowiskiem
 * (`env -i`), więc nie czytają kodu, tylko mierzą jego zachowanie.
 */
class KluczPreviewSrodowiskaPrTest extends TestCase
{
    private const PR_NATYWNY = [
        'RAILWAY_ENVIRONMENT_ID' => '0b6f2c1e-0000-4000-8000-000000000975',
        'RAILWAY_ENVIRONMENT_NAME' => 'kuking-pr-975',
        'APP_ENV' => 'staging',
        'APP_URL' => 'https://web-kuking-pr-975.up.railway.app',
    ];

    /**
     * Źródłuje skrypt, woła funkcję i wypisuje wynik: kod powrotu i (tylko
     * w teście) wartość APP_KEY. Samo wyjście skryptu idzie osobno jako
     * `wyjscie`, żeby dało się sprawdzić, że klucz go nie opuszcza.
     *
     * @param  array<string, string>  $env
     * @return array{kod: int, klucz: string, wyjscie: string}
     */
    private function uruchom(array $env): array
    {
        $plik = base_path('docker/klucz-preview.sh');
        $wynik = sys_get_temp_dir().'/klucz-preview-'.bin2hex(random_bytes(6));
        $skrypt = 'set -Eeuo pipefail; source "$1"; '
            .'if kuking_klucz_preview; then kod=0; else kod=$?; fi; '
            .'printf "%s\n%s\n" "$kod" "${APP_KEY:-}" > "$2"';

        $polecenie = ['env', '-i', 'PATH='.getenv('PATH')];
        foreach ($env as $nazwa => $wartosc) {
            $polecenie[] = $nazwa.'='.$wartosc;
        }
        array_push($polecenie, 'bash', '-c', $skrypt, 'bash', $plik, $wynik);

        $proces = new Process($polecenie, base_path());
        $proces->run();
        $this->assertTrue($proces->isSuccessful(), $proces->getOutput().$proces->getErrorOutput());

        [$kod, $klucz] = array_pad(explode("\n", (string) file_get_contents($wynik)), 2, '');
        @unlink($wynik);

        return ['kod' => (int) $kod, 'klucz' => $klucz, 'wyjscie' => $proces->getOutput().$proces->getErrorOutput()];
    }

    public function test_natywne_srodowisko_pr_bez_klucza_dostaje_wlasny_losowy_klucz(): void
    {
        $pierwszy = $this->uruchom(self::PR_NATYWNY);
        $drugi = $this->uruchom(self::PR_NATYWNY);

        $this->assertSame(0, $pierwszy['kod']);
        $this->assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=$#', $pierwszy['klucz']);
        $this->assertSame(32, strlen(base64_decode(substr($pierwszy['klucz'], 7), true)));
        $this->assertNotSame($pierwszy['klucz'], $drugi['klucz'], 'Klucz ma być losowy, nie stały.');
        $this->assertStringNotContainsString($pierwszy['klucz'], $pierwszy['wyjscie'], 'Klucz nie może trafić do logu.');
        $this->assertStringNotContainsString(substr($pierwszy['klucz'], 7), $pierwszy['wyjscie']);
    }

    public function test_reczna_sciezka_pr_z_kopii_staginu_tez_dostaje_klucz(): void
    {
        // preview.yml → manual-create: `railway environment new "pr-N" --copy staging`.
        $wynik = $this->uruchom(['RAILWAY_ENVIRONMENT_NAME' => 'pr-975'] + self::PR_NATYWNY);

        $this->assertSame(0, $wynik['kod']);
        $this->assertStringStartsWith('base64:', $wynik['klucz']);
    }

    public function test_istniejacy_klucz_zostaje_nietkniety(): void
    {
        $wlasny = 'base64:'.base64_encode(str_repeat('k', 32));
        $wynik = $this->uruchom(['APP_KEY' => $wlasny] + self::PR_NATYWNY);

        $this->assertSame(0, $wynik['kod']);
        $this->assertSame($wlasny, $wynik['klucz']);
    }

    /**
     * Kontrola dodatnia wąskości: każdy z tych przypadków to środowisko,
     * które NIE jest potwierdzonym PR-em — pusty klucz ma zostać pusty,
     * a entrypoint ma zatrzymać start.
     *
     * @return array<string, array{0: array<string, string|null>}>
     */
    public static function srodowiskaTrwale(): array
    {
        return [
            'produkcja' => [['RAILWAY_ENVIRONMENT_NAME' => 'production', 'APP_ENV' => 'production', 'APP_URL' => 'https://kuking.pl']],
            'staging' => [['RAILWAY_ENVIRONMENT_NAME' => 'staging', 'APP_URL' => 'https://staging.kuking.pl']],
            'poza Railwayem' => [['RAILWAY_ENVIRONMENT_ID' => null]],
            'nazwa PR z doklejką' => [['RAILWAY_ENVIRONMENT_NAME' => 'pr-975-production']],
            'nazwa PR bez numeru' => [['RAILWAY_ENVIRONMENT_NAME' => 'pr-']],
            'PR z APP_ENV produkcji' => [['APP_ENV' => 'production']],
            'PR z domeną produkcji' => [['APP_URL' => 'https://www.KUKING.pl/']],
            'PR z domeną staginu' => [['APP_URL' => 'https://staging.kuking.pl:443/zdrowie']],
        ];
    }

    /**
     * @param  array<string, string|null>  $zmiana
     */
    #[DataProvider('srodowiskaTrwale')]
    public function test_poza_potwierdzonym_pr_pusty_klucz_zostaje_pusty(array $zmiana): void
    {
        $env = array_filter($zmiana + self::PR_NATYWNY, fn ($v) => $v !== null);
        $wynik = $this->uruchom($env);

        $this->assertSame(1, $wynik['kod']);
        $this->assertSame('', $wynik['klucz']);
    }

    /**
     * Entrypoint musi dołączyć skrypt i zawołać go PRZED odmową startu.
     * Test czyta źródło — kontrola dodatnia: wpis
     * „Entrypoint bez klucza preview” w scripts/kontrole-negatywne-alfa08.py.
     */
    public function test_entrypoint_nadaje_klucz_preview_przed_odmowa_startu(): void
    {
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));
        $bezKomentarzy = implode("\n", array_filter(
            explode("\n", $entrypoint),
            fn (string $linia) => ! str_starts_with(ltrim($linia), '#'),
        ));

        $zrodlo = strpos($bezKomentarzy, 'source /app/docker/klucz-preview.sh');
        $wywolanie = strpos($bezKomentarzy, 'kuking_klucz_preview');
        $odmowa = strpos($bezKomentarzy, 'die "APP_KEY jest pusty');

        $this->assertNotFalse($odmowa, 'Entrypoint ma dalej odmawiać startu bez APP_KEY.');
        $this->assertNotFalse($zrodlo, 'Entrypoint nie dołącza docker/klucz-preview.sh.');
        $this->assertNotFalse($wywolanie, 'Entrypoint nie woła kuking_klucz_preview.');
        $this->assertLessThan($wywolanie, $zrodlo);
        $this->assertLessThan($odmowa, $wywolanie, 'Klucz preview musi powstać przed odmową startu.');
    }
}
