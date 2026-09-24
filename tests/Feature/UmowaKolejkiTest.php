<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Jobs\NotifyUserExportReady;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\PurgePublicMediaCache;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

/**
 * Umowa kolejki: `retry_after` nad najdłuższym zadaniem, zadania na właściwych
 * kolejkach (audyt W3-04, W3-05).
 *
 * DLACZEGO TO NIE JEST DROBIAZG
 * `retry_after` mówi, po ilu sekundach kolejka uznaje zarezerwowane zadanie za
 * porzucone i wydaje je komuś innemu. Stało 90 s, a zadania mają:
 *
 *     ProcessUploadedImage::$timeout = 120 s
 *     GenerateUserExport::$timeout   = 900 s
 *
 * Czyli KAŻDY eksport i większość przetworzeń zdjęć kwalifikowały się do
 * ponownego wydania, jeszcze pracując. Dziś worker jest jeden, więc nie boli —
 * i to jest najgorsze w tej usterce: czeka na drugą replikę, po czym dwa
 * procesy budują tę samą paczkę z danymi i piszą pod te same klucze w R2.
 *
 * Osobno: `docker/entrypoint.sh` uruchamia workera z
 * `--queue=high,default,media,low` i komentuje, że interakcje użytkownika mają
 * wyprzedzać przetwarzanie obrazów. Żadne zadanie nie przypisywało się jednak
 * do kolejki, więc wszystkie lądowały na `default`, a kolejność w tej fladze
 * nie robiła nic.
 */
class UmowaKolejkiTest extends TestCase
{
    /** Zadania, które chodzą przez kolejkę, wraz z oczekiwaną kolejką. */
    private const ZADANIA = [
        ProcessUploadedImage::class => 'media',
        GenerateUserExport::class => 'low',
        NotifyUserExportReady::class => 'default', // jeden list — nie czeka w `low` za cudzą paczką
        PurgePublicMediaCache::class => null, // domyślna wystarcza — czyszczenie jest tanie
    ];

    public function test_retry_after_jest_wieksze_niz_najdluzsze_zadanie(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $najdluzszy = 0;
        $ktore = '';

        foreach (array_keys(self::ZADANIA) as $klasa) {
            $wlasciwosci = (new ReflectionClass($klasa))->getDefaultProperties();
            $timeout = (int) ($wlasciwosci['timeout'] ?? 0);

            if ($timeout > $najdluzszy) {
                $najdluzszy = $timeout;
                $ktore = class_basename($klasa);
            }
        }

        $this->assertGreaterThan(0, $najdluzszy, 'Żadne zadanie nie deklaruje timeoutu — sprawdź listę.');

        $this->assertGreaterThan(
            $najdluzszy,
            $retryAfter,
            "retry_after ({$retryAfter} s) nie przekracza timeoutu {$ktore} ({$najdluzszy} s). ".
            'Przy więcej niż jednym workerze to samo zadanie zostanie wydane drugi raz, gdy '.
            'pierwszy jeszcze pracuje — dwa eksporty tego samego konta, dwa zapisy pod ten '.
            'sam klucz w R2.',
        );
    }

    public function test_zadania_ida_na_kolejki_opisane_w_entrypoincie(): void
    {
        Queue::fake();

        ProcessUploadedImage::dispatch('media-id');
        GenerateUserExport::dispatch('export-id');
        NotifyUserExportReady::dispatch('export-id');

        Queue::assertPushedOn('media', ProcessUploadedImage::class);
        Queue::assertPushedOn('low', GenerateUserExport::class);
        Queue::assertPushedOn('default', NotifyUserExportReady::class);
    }

    public function test_entrypoint_naprawde_obsluguje_te_kolejki(): void
    {
        // Nazwa kolejki wpisana w zadaniu, której worker nie odbiera, jest
        // gorsza niż brak nazwy: zadanie leży w bazie i nikt go nie bierze.
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertSame(
            1,
            preg_match('/QUEUE_NAMES:-([a-z,]+)/', $entrypoint, $trafienie),
            'Nie znalazłem listy kolejek w docker/entrypoint.sh.',
        );

        $obslugiwane = explode(',', $trafienie[1]);

        foreach (self::ZADANIA as $klasa => $kolejka) {
            if ($kolejka === null) {
                continue;
            }

            $this->assertContains(
                $kolejka,
                $obslugiwane,
                class_basename($klasa)." idzie na kolejkę `{$kolejka}`, której worker nie odbiera. ".
                'Zadanie zostanie w bazie na zawsze.',
            );
        }
    }

    public function test_zdjecie_wyprzedza_eksport_w_kolejnosci_workera(): void
    {
        // Kolejność w `--queue` decyduje, co worker weźmie NAJPIERW, gdy oba
        // czekają. Zdjęcie z wpisu czeka człowiek, który właśnie kliknął
        // „Opublikuj"; paczkę z danymi dostaje się e-mailem i nikt na nią
        // nie patrzy.
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        preg_match('/QUEUE_NAMES:-([a-z,]+)/', $entrypoint, $trafienie);
        $kolejnosc = explode(',', $trafienie[1]);

        $this->assertLessThan(
            array_search('low', $kolejnosc, true),
            array_search('media', $kolejnosc, true),
            'Kolejka `low` stoi przed `media` — paczka z danymi wyprzedzi zdjęcie z wpisu.',
        );
    }
}
