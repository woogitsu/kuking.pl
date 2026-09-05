<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dysk zdjęć — zgodność konfiguracji aplikacji z infrastrukturą.
 *
 * DLACZEGO TO MA TEST
 * Obie usterki, które ten plik pilnuje, są niewidoczne lokalnie i obie kładą
 * główną akcję serwisu („Co dziś ugotowałeś?" = zdjęcie + kilka słów) dopiero
 * na produkcji:
 *
 *   1. `config('kuking.media.disk')` zwracało null, bo klucz leżał obok, jako
 *      `kuking.media_disk`. `Storage::disk('')` bierze wtedy PO CICHU dysk
 *      domyślny — więc nic nie wybuchało, a zmienna KUKING_MEDIA_DISK nie
 *      robiła nic.
 *   2. `railway.ts` ustawia FILESYSTEM_DISK="r2", a takiego dysku nie było
 *      w config/filesystems.php. Odtworzone przed naprawą:
 *      InvalidArgumentException: Disk [r2] does not have a configured driver.
 *
 * Test jest o zgodności DWÓCH plików, których nic innego ze sobą nie wiąże.
 */
class KonfiguracjaDyskowTest extends TestCase
{
    public function test_klucz_dysku_zdjec_istnieje(): void
    {
        // Pytamy dokładnie tak, jak pyta StoreUploadedImage.
        $this->assertNotNull(
            config('kuking.media.disk'),
            'config(„kuking.media.disk") zwraca null — kod cicho użyje dysku '
            .'domyślnego, a KUKING_MEDIA_DISK nie będzie robić nic.',
        );
    }

    public function test_dysk_r2_jest_skonfigurowany(): void
    {
        // railway.ts ustawia FILESYSTEM_DISK="r2". Bez tego bloku każdy upload
        // na produkcji kończy się wyjątkiem.
        $this->assertArrayHasKey(
            'r2',
            config('filesystems.disks'),
            'Brak dysku „r2", a infrastruktura (railway.ts) na niego wskazuje.',
        );
    }

    public function test_dysk_r2_zglasza_bledy_zapisu(): void
    {
        // Przy `throw => false` nieudany zapis zwraca false, kod leci dalej,
        // użytkownik widzi „opublikowano", a pliku nie ma nigdzie.
        $this->assertTrue(
            config('filesystems.disks.r2.throw'),
            'Dysk R2 musi rzucać wyjątek przy nieudanym zapisie — inaczej '
            .'zdjęcie znika po cichu, a serwis melduje sukces.',
        );
    }

    public function test_zmienna_kuking_media_disk_faktycznie_dziala(): void
    {
        // To jest sedno usterki nr 1: zmienna istniała, była opisana
        // w .env.example i w dokumentacji — i nie robiła NIC, bo kod czytał
        // inny klucz. Środowisko testowe ustawia ją na „public" przy
        // FILESYSTEM_DISK="local", więc te dwie wartości muszą się tu różnić.
        // Gdyby klucz znowu przestał istnieć, `config()` zwróciłoby null
        // i asercja by padła.
        $this->assertSame(
            env('KUKING_MEDIA_DISK'),
            config('kuking.media.disk'),
            'KUKING_MEDIA_DISK nie ma wpływu na dysk zdjęć — dokładnie ten '
            .'błąd, przez który zmienna latami mogłaby nie robić nic.',
        );
    }

    public function test_bez_wlasnej_zmiennej_dysk_zdjec_podaza_za_domyslnym(): void
    {
        // Wartość domyślna, nie stan bieżący: w testach KUKING_MEDIA_DISK jest
        // ustawione jawnie i MA nadpisywać. Sprawdzamy więc samo wyrażenie
        // z pliku konfiguracyjnego, przy zmiennej nieustawionej.
        //
        // Po co: bez tego środowisko przestawione na R2 trzymałoby zdjęcia
        // gdzie indziej, dopóki ktoś nie ustawi DRUGIEJ zmiennej — a o tym się
        // zapomina dokładnie wtedy, gdy najbardziej boli.
        $poprzednia = $_ENV['KUKING_MEDIA_DISK'] ?? null;
        unset($_ENV['KUKING_MEDIA_DISK'], $_SERVER['KUKING_MEDIA_DISK']);
        putenv('KUKING_MEDIA_DISK');

        try {
            $swiezy = require base_path('config/kuking.php');

            $this->assertSame(
                env('FILESYSTEM_DISK'),
                $swiezy['media']['disk'],
                'Bez KUKING_MEDIA_DISK dysk zdjęć powinien podążać za FILESYSTEM_DISK.',
            );
        } finally {
            if ($poprzednia !== null) {
                $_ENV['KUKING_MEDIA_DISK'] = $poprzednia;
                $_SERVER['KUKING_MEDIA_DISK'] = $poprzednia;
                putenv("KUKING_MEDIA_DISK=$poprzednia");
            }
        }
    }

    public function test_kazdy_dysk_z_konfiguracji_da_sie_utworzyc(): void
    {
        // Literówka w nazwie sterownika albo brakujące pole nie ujawnia się
        // przy starcie aplikacji — dopiero przy pierwszym użyciu dysku.
        foreach (array_keys(config('filesystems.disks')) as $nazwa) {
            Storage::disk($nazwa);
        }

        $this->addToAssertionCount(1);
    }
}
