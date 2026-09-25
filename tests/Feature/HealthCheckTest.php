<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * /health — co ten punkt ma naprawdę wykrywać.
 *
 * DLACZEGO TEN PLIK POWSTAŁ
 * Produkcja przez pewien czas nie pokazywała ŻADNEGO zdjęcia: katalog
 * ze zdjęciami stał na dysku kontenera, który Railway kasuje przy każdym
 * wdrożeniu. `/health` przez cały ten czas meldował „ok", bo sprawdzał
 * wyłącznie bazę i migracje. Awaria dotyczyła głównej akcji produktu
 * („zdjęcie + kilka słów") i nie zauważył jej żaden automat — zauważył
 * ją człowiek, który zobaczył ikony zepsutych obrazków.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_zdrowa_instalacja_oddaje_ok(): void
    {
        // Świeże klonowanie repozytorium nie ma `public/storage` — to symlink,
        // którego git nie wersjonuje. Bez niego zdjęcia nie działają także
        // lokalnie, więc test najpierw doprowadza środowisko do stanu,
        // w jakim ma być, a dopiero potem sprawdza zdrowie. `storage:link`
        // jest idempotentny.
        Artisan::call('storage:link');

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.migrations.ok', true)
            ->assertJsonPath('checks.media.ok', true);
    }

    public function test_zepsuty_dysk_ze_zdjeciami_jest_widoczny_w_odpowiedzi(): void
    {
        // Dysk wskazujący na katalog, którego nie ma i którego nie da się
        // utworzyć — odpowiednik woluminu zamontowanego bez prawa zapisu.
        config([
            'kuking.media.disk' => 'zepsuty',
            'filesystems.disks.zepsuty' => [
                'driver' => 'local',
                'root' => '/proc/nie-ma-takiego-katalogu',
                'throw' => true,
            ],
        ]);

        $odpowiedz = $this->zdrowieZeSzczegolami();

        // NAJWAŻNIEJSZA ASERCJA W TYM PLIKU: awaria zdjęć jest RAPORTOWANA...
        $odpowiedz->assertJsonPath('checks.media.ok', false)
            ->assertJsonPath('status', 'degraded');

        // ...ale NIE oddaje 503. Healthcheck zwracający 503 potrafił już
        // położyć ten serwis: Railway restartuje kontener, restart niczego
        // nie naprawia, i z serwisu z połamanymi zdjęciami robi się serwis,
        // którego w ogóle nie ma. Strona bez zdjęć jest o wiele lepsza
        // niż pętla restartów.
        $odpowiedz->assertOk();
    }

    public function test_martwa_droga_publiczna_jest_awaria_mimo_dzialajacego_zapisu(): void
    {
        $katalog = storage_path('framework/testing/zdjecia-bez-linku');
        File::ensureDirectoryExists($katalog);

        config([
            'kuking.media.disk' => 'bez_linku',
            'filesystems.disks.bez_linku' => [
                'driver' => 'local',
                'root' => $katalog,
                'throw' => true,
            ],
        ]);

        // Zapis DZIAŁA, a mimo to przeglądarka dostaje 404 — bo `public/storage`
        // prowadzi gdzie indziej niż dysk, na który realnie piszemy. Dokładnie
        // tak wyglądała awaria na produkcji: `ProcessUploadedImage` kończył się
        // powodzeniem, plik leżał na dysku, a w interfejsie była ikona
        // zepsutego obrazka. Sprawdzanie samego zapisu przepuściłoby to.
        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('checks.media.ok', false)
            ->assertJsonPath('status', 'degraded');

        File::deleteDirectory($katalog);
    }

    public function test_sprawdzenie_nie_zostawia_po_sobie_smieci(): void
    {
        $katalog = storage_path('framework/testing/zdjecia-sprzatanie');
        File::deleteDirectory($katalog);
        File::ensureDirectoryExists($katalog);

        config([
            'kuking.media.disk' => 'sprzatanie',
            'filesystems.disks.sprzatanie' => [
                'driver' => 'local',
                'root' => $katalog,
                'throw' => true,
            ],
        ]);

        $this->zdrowieZeSzczegolami()->assertOk();
        $this->zdrowieZeSzczegolami()->assertOk();

        // Railway odpytuje /health co kilkadziesiąt sekund. Sprawdzenie, które
        // zostawia plik za każdym razem, po tygodniu zapycha wolumin — czyli
        // psuje dokładnie to, czego miało pilnować.
        $pliki = File::exists($katalog.'/.health')
            ? File::files($katalog.'/.health')
            : [];

        $this->assertCount(0, $pliki, 'Sprawdzenie zdrowia zostawiło plik na dysku.');

        File::deleteDirectory($katalog);
    }
}
