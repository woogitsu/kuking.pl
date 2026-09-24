<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Jobs\NotifyUserExportReady;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\PrzeanalizujAwatar;
use App\Jobs\PrzeanalizujTresc;
use App\Jobs\PurgePublicMediaCache;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
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
 *
 * REJESTR JEST PEŁNY Z KONSTRUKCJI (issue #1312)
 * Lista `ZADANIA` była pisana z palca i pominęła dwa z sześciu zadań —
 * obie analizy moderacyjne. Zmiana kolejki analizy na nazwę, której worker
 * nie odbiera, przeszłaby wtedy przez cały ten plik, a treści omijałyby
 * przegląd bez żadnego błędu. Dlatego:
 *
 *  - `test_kazde_zadanie_z_app_jobs_jest_w_rejestrze()` czyta `app/Jobs`
 *    i pada na każdej klasie `ShouldQueue`, której tu nie wpisano;
 *  - `test_kazde_zadanie_trafia_na_kolejke_obslugiwana_przez_workera()`
 *    sprawdza kolejkę PRAWDZIWEGO obiektu zadania, nie tylko wpis w tabeli;
 *  - analiza wpisu i komentarza jest dodatkowo sprawdzona w realnym punkcie
 *    wysłania (`Queue::assertPushedOn('low', ...)` po żądaniu HTTP).
 *
 * Kontrola dodatnia (zmierzona 24.09.2026, wpis w `tests/mutacje/kolejki.txt`):
 * `KOLEJKA = 'moderacja'` w `PrzeanalizujTresc` wywraca trzy testy tego
 * pliku; nowa klasa `ShouldQueue` w `app/Jobs` bez wpisu w rejestrze
 * wywraca test rejestru.
 */
class UmowaKolejkiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Zadania, które chodzą przez kolejkę, wraz z oczekiwaną kolejką.
     * `null` = domyślna kolejka połączenia (`queue.connections.database.queue`).
     */
    private const ZADANIA = [
        ProcessUploadedImage::class => 'media',
        GenerateUserExport::class => 'low',
        NotifyUserExportReady::class => 'default', // jeden list — nie czeka w `low` za cudzą paczką
        PurgePublicMediaCache::class => null, // domyślna wystarcza — czyszczenie jest tanie
        PrzeanalizujTresc::class => 'low', // za wszystkim, co robi człowiek (D-052)
        // Nikt go już nie zleca (D-240, `ModeracjaZdjeciaProfilowegoTest`
        // pilnuje `assertNotPushed`). Zostaje dla zadań sprzed wdrożenia,
        // które czekają na `low` z zapisaną wtedy nazwą kolejki.
        PrzeanalizujAwatar::class => null,
    ];

    /**
     * Prawdziwy obiekt każdego zadania — kolejkę ustawia konstruktor, więc
     * pytamy obiekt, a nie tabelę wyżej.
     *
     * @return array<class-string, ShouldQueue>
     */
    private function instancje(): array
    {
        return [
            ProcessUploadedImage::class => new ProcessUploadedImage('media-id'),
            GenerateUserExport::class => new GenerateUserExport('export-id'),
            NotifyUserExportReady::class => new NotifyUserExportReady('export-id'),
            PurgePublicMediaCache::class => new PurgePublicMediaCache(['https://example.test/a.webp']),
            PrzeanalizujTresc::class => new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, 'post-id'),
            PrzeanalizujAwatar::class => new PrzeanalizujAwatar('media-id'),
        ];
    }

    /**
     * Wszystkie kolejki, które odbiera worker danej roli (suma list
     * `--queue` z procesyRoli()).
     *
     * @return list<string>
     */
    private function kolejkiWorkera(string $rola): array
    {
        return preg_split('/[, ]+/', implode(' ', $this->procesyRoli($rola)));
    }

    public function test_kazde_zadanie_z_app_jobs_jest_w_rejestrze(): void
    {
        $znalezione = [];

        foreach (Finder::create()->files()->in(app_path('Jobs'))->name('*.php') as $plik) {
            $klasa = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $plik->getRelativePathname());

            if (class_exists($klasa) && is_subclass_of($klasa, ShouldQueue::class)) {
                $znalezione[] = $klasa;
            }
        }

        // Pułapka 2: pusty skan przeszedłby każdą asercję w pętli niżej.
        $this->assertNotEmpty($znalezione, 'Skan app/Jobs nie znalazł żadnego zadania — sprawdź ścieżkę.');

        foreach ($znalezione as $klasa) {
            $this->assertArrayHasKey(
                $klasa,
                self::ZADANIA,
                class_basename($klasa).' chodzi przez kolejkę, ale nie ma go w UmowaKolejkiTest::ZADANIA. '.
                'Wpisz, na którą kolejkę idzie (albo `null` dla domyślnej) i dopisz je do instancje().',
            );
        }

        $this->assertEqualsCanonicalizing(
            array_keys(self::ZADANIA),
            array_keys($this->instancje()),
            'Rejestr i instancje() rozjechały się — każde zadanie z rejestru musi mieć obiekt do sprawdzenia.',
        );
    }

    public function test_kazde_zadanie_trafia_na_kolejke_obslugiwana_przez_workera(): void
    {
        $domyslna = (string) config('queue.connections.database.queue');

        foreach ($this->instancje() as $klasa => $zadanie) {
            $oczekiwana = self::ZADANIA[$klasa];

            $this->assertSame(
                $oczekiwana,
                $zadanie->queue,
                class_basename($klasa)." ustawia kolejkę `{$zadanie->queue}`, a rejestr mówi `{$oczekiwana}`.",
            );

            $naprawde = $zadanie->queue ?? $domyslna;

            // Obie role: osobny kontener `worker` i jeden kontener `all`.
            foreach (['worker', 'all'] as $rola) {
                $this->assertContains(
                    $naprawde,
                    $this->kolejkiWorkera($rola),
                    class_basename($klasa)." idzie na kolejkę `{$naprawde}`, której worker roli `{$rola}` nie odbiera. ".
                    'Zadanie zostanie w bazie na zawsze.',
                );
            }
        }
    }

    public function test_analiza_wpisu_i_komentarza_idzie_na_low_z_prawdziwej_publikacji(): void
    {
        Queue::fake();

        $autor = $this->user('publikujaca');

        $this->actingAs($autor)
            ->post(route('posts.store'), [
                'body' => 'Dziś rosół z kury zagrodowej.',
                'visibility' => Post::VISIBILITY_PUBLIC,
            ])
            ->assertSessionHasNoErrors();

        $wpis = Post::query()->where('author_id', $autor->getKey())->latest('id')->firstOrFail();

        Queue::assertPushedOn(
            'low',
            PrzeanalizujTresc::class,
            fn (PrzeanalizujTresc $z): bool => $z->typ === PrzeanalizujTresc::TYP_WPIS && $z->id === (string) $wpis->getKey(),
        );

        $this->actingAs($this->user('komentujaca'))
            ->post(route('posts.comment', $wpis), ['body' => 'Wygląda pysznie.'])
            ->assertSessionHasNoErrors();

        Queue::assertPushedOn(
            'low',
            PrzeanalizujTresc::class,
            fn (PrzeanalizujTresc $z): bool => $z->typ === PrzeanalizujTresc::TYP_KOMENTARZ,
        );
    }

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
