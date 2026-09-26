<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Start kontenera nie czyści tabeli `cache` (audyt B10-03 = B8-01).
 *
 * Przy CACHE_STORE=database w tabeli `cache` leżą liczniki `RateLimiter`
 * (hasło, 2FA, linki logowania), dobowy sufit listów (D-076) i okna
 * deduplikacji. Do 25 września 2026 `docker/entrypoint.sh` wołał
 * `cache:clear` przy każdym starcie web, workera i schedulera — każdy deploy
 * dawał zgadującemu nową pulę prób i zerował licznik poczty.
 *
 * Dwie części:
 *   1. odczyt skryptu: żadna WYKONYWANA linijka nie czyści cache aplikacji
 *      (`cache:clear`, `optimize:clear`, `Cache::flush`),
 *   2. wykonanie: każde polecenie `artisan …:clear` z entrypointu uruchomione
 *      na `database` zostawia licznik `RateLimiter` i budżet poczty w spokoju.
 *      Kontrola dodatnia: `cache:clear` w tej samej próbie zeruje oba —
 *      czyli próba naprawdę widzi to, czego pilnuje.
 */
class StartKonteneraNieCzysciCacheTest extends TestCase
{
    use RefreshDatabase;

    private const ZAKAZANE = '/\bcache:clear\b|\boptimize:clear\b|Cache::flush/';

    #[Test]
    public function entrypoint_nie_wola_czyszczenia_cache_aplikacji(): void
    {
        $wykonywane = $this->linieWykonywane($this->entrypoint());

        $this->assertNotEmpty($wykonywane);
        $this->assertSame([], array_values(preg_grep(self::ZAKAZANE, $wykonywane)),
            'docker/entrypoint.sh czyści cache aplikacji — to zeruje RateLimiter, sufit listów i blokady.');

        // Kontrola ujemna detektora: stara linijka z entrypointu byłaby złapana,
        // a ta sama nazwa w komentarzu — nie.
        $stara = "if ! php /app/artisan cache:clear --no-interaction >/dev/null 2>&1; then\n#  cache:clear w komentarzu";
        $this->assertCount(1, preg_grep(self::ZAKAZANE, $this->linieWykonywane($stara)));
    }

    #[Test]
    public function polecenia_clear_z_entrypointu_nie_zeruja_licznikow(): void
    {
        preg_match_all('/artisan\s+([a-z]+:clear)\b/', implode("\n", $this->linieWykonywane($this->entrypoint())), $m);
        $polecenia = array_values(array_unique($m[1]));
        $this->assertContains('config:clear', $polecenia, 'Parser nie znalazł poleceń czyszczących w entrypoincie.');

        // Własny katalog skompilowanych widoków: `view:clear` nie ma prawa
        // skasować plików równoległym procesom testów.
        $widoki = sys_get_temp_dir().'/kuking-view-clear-'.getmypid();
        @mkdir($widoki);
        config(['cache.default' => 'database', 'view.compiled' => $widoki]);

        RateLimiter::hit('proba-hasla:ktos', 3600);
        RateLimiter::hit('proba-hasla:ktos', 3600);
        DziennyBudzetListow::dlaLinkuLogowania()->zajmij();
        $this->assertSame(2, RateLimiter::attempts('proba-hasla:ktos'));
        $this->assertSame(1, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());

        foreach ($polecenia as $polecenie) {
            Artisan::call($polecenie, ['--no-interaction' => true]);
            $this->assertSame(2, RateLimiter::attempts('proba-hasla:ktos'), "{$polecenie} wyzerował RateLimiter.");
            $this->assertSame(1, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte(), "{$polecenie} wyzerował sufit listów.");
        }

        // Kontrola dodatnia: to, co stało w entrypoincie do 25.09.2026.
        Artisan::call('cache:clear');
        $this->assertSame(0, RateLimiter::attempts('proba-hasla:ktos'));
        $this->assertSame(0, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());
    }

    private function entrypoint(): string
    {
        return (string) file_get_contents(base_path('docker/entrypoint.sh'));
    }

    /** @return list<string> */
    private function linieWykonywane(string $skrypt): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $skrypt)),
            fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));
    }
}
