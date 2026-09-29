<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Numer wdrożenia zapisuje się dopiero po gotowości nowego kontenera
 * (issue #1932, D-318; luka z audytu z 28 września 2026).
 *
 * CO SIĘ DZIAŁO. `kuking:zarejestruj-wdrozenie` stała w `preDeployCommand`
 * PRZED `db:seed`, importem wartości odżywczych i healthcheckiem. Rollout, który
 * padł na którymkolwiek z nich, zostawiał zużyty numer („Alfa 0.69.006"
 * w stopce po wdrożeniu, którego nikt nie zobaczył) i funkcje opisane jako
 * dostępne „od Alfa …". `naglowek_slug` jest globalnie unikalny, więc późniejszy
 * udany rollout tej samej funkcji NIE naprawiał numeru.
 *
 * CO PILNUJE TEN TEST.
 *   1. Zachowanie: z `--po-gotowosci` komenda zapisuje tylko po 2xx z `/health`;
 *      bez odpowiedzi w limicie nie zapisuje niczego (ani `wdrozenia`, ani
 *      `wdrozenia_funkcje`), a numer i funkcje dostaje dopiero kolejny,
 *      udany kontener.
 *   2. Źródła: `preDeployCommand` w `.railway/railway.ts` NIE zawiera
 *      rejestracji, a `docker/entrypoint.sh` woła ją w rolach `web` i `all`.
 *      Odczyt źródeł ma wpis w `scripts/kontrole-negatywne-alfa08.py`.
 */
class RejestracjaWdrozeniaPoGotowosciTest extends TestCase
{
    use RefreshDatabase;

    private const SONDA = 'http://sonda.test/health';

    private function commit(int $bajt): string
    {
        return str_pad(dechex($bajt), 40, '0');
    }

    private function trescNowosci(): void
    {
        $plik = tempnam(sys_get_temp_dir(), 'nowosci');
        file_put_contents($plik, "## Najnowsze zmiany\n\n### Funkcja z nieudanego rolloutu\n\nOpis.\n");
        config(['kuking.nowosci.tresc' => $plik]);
    }

    /** @return int kod wyjścia komendy */
    private function komenda(int $commit, array $opcje = []): int
    {
        config(['kuking.wersja.commit' => $this->commit($commit), 'kuking.wersja.etykieta' => 'Alfa 0.69']);

        return $this->artisan('kuking:zarejestruj-wdrozenie', $opcje + [
            '--po-gotowosci' => true,
            '--adres' => self::SONDA,
            '--limit' => 9,
            '--odstep' => 3,
        ])->run();
    }

    #[Test]
    public function kontener_ktory_nie_przechodzi_health_nie_zuzywa_numeru_ani_funkcji(): void
    {
        Sleep::fake();
        Http::fake(['sonda.test/*' => Http::response('', 503)]);
        $this->trescNowosci();

        $this->assertSame(1, $this->komenda(1));

        $this->assertSame(0, DB::table('wdrozenia')->count());
        $this->assertSame(0, DB::table('wdrozenia_funkcje')->count());
        // limit 9 s / odstęp 3 s = 3 próby, przerwa tylko MIĘDZY próbami.
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    #[Test]
    public function nieudany_rollout_nie_zabiera_numeru_ani_funkcji_udanemu(): void
    {
        Sleep::fake();
        $this->trescNowosci();

        // Pierwszy kontener: 3 próby (limit 9 s / 3 s) i same 500; drugi: od razu 200.
        Http::fake(['sonda.test/*' => Http::sequence()
            ->pushStatus(500)->pushStatus(500)->pushStatus(500)->pushStatus(200)]);
        $this->assertSame(1, $this->komenda(1));
        $this->assertSame(0, $this->komenda(2));

        // Numer 1, nie 2 — nieudana próba go nie zużyła — i funkcja należy do udanego.
        $this->assertDatabaseHas('wdrozenia', ['commit' => $this->commit(2), 'numer' => 1]);
        $this->assertDatabaseMissing('wdrozenia', ['commit' => $this->commit(1)]);
        $this->assertDatabaseHas('wdrozenia_funkcje', [
            'naglowek_slug' => 'funkcja-z-nieudanego-rolloutu',
            'numer' => 1,
        ]);
    }

    #[Test]
    public function serwer_ktory_dopiero_wstaje_dostaje_kolejne_proby(): void
    {
        Sleep::fake();
        Http::fake(['sonda.test/*' => Http::sequence()->pushFailedConnection()->pushStatus(503)->pushStatus(200)]);

        $this->assertSame(0, $this->komenda(1));

        $this->assertSame(1, DB::table('wdrozenia')->count());
        Http::assertSentCount(3);
    }

    #[Test]
    public function sonda_idzie_z_hostem_healthchecku_railwaya(): void
    {
        Sleep::fake();
        Http::fake(['sonda.test/*' => Http::response('ok', 200)]);

        $this->komenda(1);

        Http::assertSent(fn ($zadanie) => $zadanie->header('Host') === ['healthcheck.railway.app']);
    }

    #[Test]
    public function wielokrotny_start_tego_samego_commita_jest_idempotentny(): void
    {
        Sleep::fake();
        Http::fake(['sonda.test/*' => Http::response('ok', 200)]);

        $this->assertSame(0, $this->komenda(7));
        $this->assertSame(0, $this->komenda(7));
        $this->assertSame(0, $this->komenda(8));

        $this->assertSame(1, DB::table('wdrozenia')->where('commit', $this->commit(7))->value('numer'));
        $this->assertSame(2, DB::table('wdrozenia')->where('commit', $this->commit(8))->value('numer'));
    }

    #[Test]
    public function bez_commita_komenda_nie_czeka_i_nie_pyta_o_health(): void
    {
        Http::fake();
        config(['kuking.wersja.commit' => null]);

        $this->artisan('kuking:zarejestruj-wdrozenie', ['--po-gotowosci' => true, '--adres' => self::SONDA])
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('wdrozenia')->count());
    }

    #[Test]
    public function pre_deploy_nie_rejestruje_wdrozenia(): void
    {
        $railway = (string) file_get_contents(base_path('.railway/railway.ts'));

        $this->assertSame(
            1,
            preg_match_all('/preDeployCommand:\s*\[(.*?)\]/s', $railway, $bloki),
            'Oczekiwano dokładnie jednego preDeployCommand w .railway/railway.ts.',
        );
        $this->assertStringContainsString('kuking:migruj-pod-blokada', $bloki[1][0]);
        $this->assertStringContainsString('db:seed', $bloki[1][0]);
        $this->assertStringNotContainsString(
            'zarejestruj-wdrozenie',
            $bloki[1][0],
            'preDeployCommand kończy się PRZED startem i healthcheckiem nowego kontenera — '
            .'rejestracja tam zużywa numer także przy nieudanym rolloucie (issue #1932).',
        );
    }

    #[Test]
    public function entrypoint_rejestruje_wdrozenie_po_gotowosci_w_rolach_web_i_all(): void
    {
        $skrypt = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertMatchesRegularExpression(
            '/rejestruj_wdrozenie_po_gotowosci\(\) \{.*?kuking:zarejestruj-wdrozenie --po-gotowosci.*?\n\}/s',
            $skrypt,
            'Funkcja musi wołać komendę z --po-gotowosci (samo wywołanie zapisałoby numer przed gotowością).',
        );

        $this->assertMatchesRegularExpression(
            '/start_web\(\) \{.*?\n  rejestruj_wdrozenie_po_gotowosci\n  exec frankenphp/s',
            $skrypt,
            'Rola web: rejestracja w tle PRZED exec frankenphp.',
        );

        $this->assertMatchesRegularExpression(
            '/^  all\)\n.*?frankenphp run .*?\n    rejestruj_wdrozenie_po_gotowosci\n/ms',
            $skrypt,
            'Rola all: rejestracja po starcie serwera.',
        );
    }
}
