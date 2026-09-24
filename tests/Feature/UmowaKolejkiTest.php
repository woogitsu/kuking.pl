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

    /**
     * Domyślne procesy `queue:work` z `listy_kolejek()` w entrypoincie:
     * każdy element to lista `--queue` jednego procesu.
     *
     * `worker` (osobny kontener) — proces na kolejkę; `all` (jeden kontener
     * z WWW, produkcja dziś) — jeden proces, żeby szczyty pamięci zdjęcia
     * i eksportu nie zeszły się z WWW (przegląd #1030).
     *
     * @return list<string>
     */
    private function procesyRoli(string $rola): array
    {
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));
        $wzor = $rola === 'worker'
            ? '/local osobne="([a-z, ]+)"/'
            : '/QUEUE_NAMES:-([a-z,]+)\}/';

        $this->assertSame(1, preg_match($wzor, $entrypoint, $trafienie), "Nie znalazłem listy kolejek roli `{$rola}` w docker/entrypoint.sh.");

        return preg_split('/ +/', trim($trafienie[1]));
    }

    public function test_entrypoint_naprawde_obsluguje_te_kolejki(): void
    {
        // Nazwa kolejki wpisana w zadaniu, której worker nie odbiera, jest
        // gorsza niż brak nazwy: zadanie leży w bazie i nikt go nie bierze.
        foreach (['worker', 'all'] as $rola) {
            $obslugiwane = preg_split('/[, ]+/', implode(' ', $this->procesyRoli($rola)));

            foreach (self::ZADANIA as $klasa => $kolejka) {
                $this->assertContains(
                    $kolejka ?? 'default',
                    $obslugiwane,
                    class_basename($klasa).' idzie na kolejkę `'.($kolejka ?? 'default')."`, której worker roli `{$rola}` nie odbiera. ".
                    'Zadanie zostanie w bazie na zawsze.',
                );
            }
        }
    }

    public function test_rola_worker_ma_proces_na_kazda_kolejke_z_producentem(): void
    {
        // Issue #1030. Kolejność w jednym `--queue` to ścisły priorytet:
        // przy stałym napływie `default` zdjęcie i eksport nie ruszyłyby
        // nigdy. W osobnym kontenerze workera każda kolejka z producentem
        // stoi więc na PIERWSZYM miejscu jakiegoś procesu.
        $pierwsze = array_map(
            fn (string $lista) => explode(',', $lista)[0],
            $this->procesyRoli('worker'),
        );

        foreach (array_unique(array_map(fn ($k) => $k ?? 'default', self::ZADANIA)) as $kolejka) {
            $this->assertContains(
                $kolejka,
                $pierwsze,
                "Kolejka `{$kolejka}` nie ma procesu, który bierze ją jako pierwszą — przy zaległości wyżej czeka bez końca.",
            );
        }
    }

    public function test_rola_all_ma_jeden_proces_ze_zdjeciem_przed_eksportem(): void
    {
        // Jeden kontener 1024 MB z WWW: trzy procesy mogłyby mieć szczyt
        // naraz (zdjęcie ~452 MB, eksport do 512M) i OOM położyłby stronę.
        $procesy = $this->procesyRoli('all');
        $this->assertCount(1, $procesy, 'Rola `all` ma uruchamiać JEDEN proces `queue:work`.');

        // Kolejność w `--queue` to priorytet: zdjęcie z wpisu czeka człowiek,
        // paczkę z danymi dostaje się e-mailem.
        $kolejnosc = explode(',', $procesy[0]);
        $this->assertLessThan(
            array_search('low', $kolejnosc, true),
            array_search('media', $kolejnosc, true),
            'Kolejka `low` stoi przed `media` — paczka z danymi wyprzedzi zdjęcie z wpisu.',
        );
    }

    public function test_media_bierze_dokladnie_jeden_proces_w_kazdej_roli(): void
    {
        foreach (['worker', 'all'] as $rola) {
            $this->assertCount(
                1,
                array_filter($this->procesyRoli($rola), fn ($l) => in_array('media', explode(',', $l), true)),
                "Kolejkę `media` ma brać dokładnie jeden proces roli `{$rola}` — dwa zdjęcia 50 Mpx naraz nie mieszczą się w pamięci kontenera.",
            );
        }
    }
}
