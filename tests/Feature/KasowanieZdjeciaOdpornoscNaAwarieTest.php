<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PurgeExpiredAccountDeletions;
use App\Domain\Media\KasujZdjecie;
use App\Domain\Media\OsieroconeZdjecia;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Media;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionObject;
use RuntimeException;
use Tests\TestCase;

/**
 * Odporność kasowania zdjęć na awarię storage w połowie pętli (issue #17,
 * część (a)) i na kopię pozostawioną na `r2_legacy` (część (b), audyt N01).
 *
 * ZMIERZONE PRZED NAPRAWĄ (referencyjne uruchomienie tych samych scenariuszy
 * na kodzie sprzed tej zmiany — patrz raport zadania #17):
 *
 *  - Cichy `false` z `delete()` (dysk `throw => false`, jak `local`/`public`):
 *    `jesliNieuzywane()` KASOWAŁA wiersz `media`, mimo że plik fizycznie
 *    zostawał na dysku. Dokładnie „wiersz w bazie już nie istnieje, więc nie
 *    ma z czego ponowić" z opisu zadania.
 *  - Wyjątek z DRUGIEGO z trzech wariantów (dysk `throw => true`, jak każdy
 *    dysk R2 w tym serwisie): `skasujPliki()` przerywała się CAŁKOWICIE —
 *    trzeci wariant i oryginał nie były nawet PRÓBOWANE, wyjątek leciał
 *    surowy do wywołującego.
 *  - `EraseAccountData::handle()`: anonimizacja konta (osobna transakcja)
 *    przechodziła, ale wyjątek z kasowania PLIKÓW (poza transakcją) leciał
 *    dalej, nieobsłużony. Wiersz `media` zostawał, ALE `data_erased_at` było
 *    już ustawione — więc `kuking:usun-wygasle-konta`
 *    (`whereNull('data_erased_at')`) nigdy więcej po to zdjęcie nie sięgało.
 *    Zdjęcie z pełnym EXIF-em zostawało dostępne BEZTERMINOWO.
 *  - Kopia na `r2_legacy`: `dyskiDoWyczyszczenia()` nie istniało,
 *    `skasujPliki()` czyściła wyłącznie `disk`/`variants_disk` z wiersza —
 *    dla zdjęcia przeniesionego przez `kuking:przenies-zdjecia`
 *    (`disk = r2`, `variants_disk = r2_publiczne`) kopia pod tym samym
 *    kluczem w starym, publicznym buckecie NIGDY nie była nawet próbowana.
 *
 * PO NAPRAWIE (ten plik, asercje poniżej): żaden z tych czterech scenariuszy
 * się nie powtarza — a testy tego dowodzą, nie zakładają.
 */
class KasowanieZdjeciaOdpornoscNaAwarieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
    }

    /**
     * Deleguje wszystko do prawdziwego fejkowego dysku, ale steruje
     * zachowaniem N-tego wywołania `delete()` — udawana awaria R2 (albo
     * cichy `false` dysku `throw => false`) DOKŁADNIE w połowie serii.
     */
    private function udawanyDysk(string $nazwaDysku, int $lokalKtoryZawodzi, bool $wyjatek): FilesystemAdapter
    {
        $prawdziwy = Storage::disk($nazwaDysku);

        $dekorator = new class(...$this->argumentyDekoratora($prawdziwy)) extends FilesystemAdapter
        {
            public int $wywolania = 0;

            public array $proby = [];

            public int $lokalKtoryZawodzi = 0;

            public bool $wyjatek = false;

            public function delete($paths): bool
            {
                $this->wywolania++;
                $this->proby[] = $paths;

                if ($this->wywolania === $this->lokalKtoryZawodzi) {
                    if ($this->wyjatek) {
                        throw new RuntimeException('Udawana awaria R2 w połowie kasowania.');
                    }

                    // Dokładnie tak zachowuje się dysk `local`/`public` z
                    // `throw => false`: zwraca `false`, plik zostaje.
                    return false;
                }

                return parent::delete($paths);
            }
        };

        $dekorator->lokalKtoryZawodzi = $lokalKtoryZawodzi;
        $dekorator->wyjatek = $wyjatek;

        return $dekorator;
    }

    /** @return array{0: mixed, 1: mixed, 2: array} */
    private function argumentyDekoratora(FilesystemAdapter $prawdziwy): array
    {
        $ref = new ReflectionObject($prawdziwy);

        $driver = $ref->getProperty('driver');
        $driver->setAccessible(true);

        $adapter = $ref->getProperty('adapter');
        $adapter->setAccessible(true);

        $config = $ref->getProperty('config');
        $config->setAccessible(true);

        return [$driver->getValue($prawdziwy), $adapter->getValue($prawdziwy), $config->getValue($prawdziwy)];
    }

    public function test_cichy_false_nie_kasuje_wiersza_gdy_plik_zostaje(): void
    {
        Storage::fake('public');

        $zdjecie = Media::factory()->create([
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/oryginal.jpg',
            'metadata' => ['variants' => [
                'feed' => ['key' => 'media/basia/feed.webp'],
            ]],
        ]);

        Storage::disk('public')->put($zdjecie->object_key, 'oryginal');
        Storage::disk('public')->put('media/basia/feed.webp', 'wariant');

        // KONTROLA: zanim podmienimy dysk, plik naprawdę tam jest.
        Storage::disk('public')->assertExists($zdjecie->object_key);

        // Pierwsze wywołanie delete() (wariant "feed") ma się udać.
        // Drugie (oryginał) zwraca `false`, tak jak dysk `throw => false`.
        Storage::set('public', $this->udawanyDysk('public', lokalKtoryZawodzi: 2, wyjatek: false));

        $skasowano = (new KasujZdjecie)->jesliNieuzywane($zdjecie->fresh());

        // PO NAPRAWIE: `jesliNieuzywane()` sprawdza wynik przez `exists()`
        // i widzi, że plik NIE zniknął — więc zwraca `false`.
        $this->assertFalse($skasowano, 'skasujPliki() musi wykryć plik, którego delete() po cichu nie skasował.');

        // Wiersz ZOSTAJE — to jest cały mechanizm ponowienia: kolejny
        // przebieg `kuking:sprzataj-osierocone-zdjecia` zobaczy to samo
        // zdjęcie i spróbuje ponownie.
        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey()]);

        // Plik oryginału rzeczywiście wciąż tam jest (kontrola spójności ze
        // stanem wiersza — nie ma dwóch źródeł prawdy).
        Storage::disk('public')->assertExists($zdjecie->object_key);
    }

    public function test_wyjatek_w_polowie_petli_probuje_reszte_plikow_i_zglasza_niepowodzenie(): void
    {
        Storage::fake('public');

        $zdjecie = Media::factory()->create([
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/oryginal.jpg',
            'metadata' => ['variants' => [
                'thumb' => ['key' => 'media/basia/thumb.webp'],
                'feed' => ['key' => 'media/basia/feed.webp'],
                'large' => ['key' => 'media/basia/large.webp'],
            ]],
        ]);

        Storage::disk('public')->put($zdjecie->object_key, 'oryginal');
        Storage::disk('public')->put('media/basia/thumb.webp', 'thumb');
        Storage::disk('public')->put('media/basia/feed.webp', 'feed');
        Storage::disk('public')->put('media/basia/large.webp', 'large');

        // KONTROLA
        Storage::disk('public')->assertExists('media/basia/large.webp');

        // UWAGA NA KOLEJNOŚĆ: `metadata` wraca z Postgresa jako `jsonb`, które
        // sortuje klucze obiektu alfabetycznie — więc mimo że w kodzie wyżej
        // wpisaliśmy thumb/feed/large, po odczycie z bazy iteracja idzie
        // feed → large → thumb. Drugie faktyczne wywołanie delete() trafia
        // więc na wariant "large" i to na nim symulujemy awarię.
        Storage::set('public', $this->udawanyDysk('public', lokalKtoryZawodzi: 2, wyjatek: true));

        $wynik = (new KasujZdjecie)->skasujPliki($zdjecie->fresh());

        // PO NAPRAWIE: żaden wyjątek nie wylatuje z metody — jest złapany
        // i zamieniony na `false`.
        $this->assertFalse($wynik, 'Jedna nieudana operacja musi dać w wyniku `false`, nie wyjątek.');

        // "feed" (pierwszy w kolejności) zniknął — to się nie zmieniło.
        Storage::disk('public')->assertMissing('media/basia/feed.webp');

        // "large" (na nim symulujemy awarię) rzeczywiście zostaje —
        // niepowodzenie na TYM pliku jest prawdziwe, nie tylko zgłoszone.
        Storage::disk('public')->assertExists('media/basia/large.webp');

        // TO JEST NAJWAŻNIEJSZA PARA ASERCJI W TYM TEŚCIE: mimo wyjątku na
        // "large", metoda PRÓBOWAŁA DALEJ — "thumb" (trzeci wariant) i sam
        // oryginał zostały skasowane, mimo że w starym kodzie nigdy nie były
        // nawet dotknięte.
        Storage::disk('public')->assertMissing('media/basia/thumb.webp');
        Storage::disk('public')->assertMissing($zdjecie->object_key);
    }

    public function test_konto_wymazane_zdjecie_utyka_ale_nastepny_przebieg_dokancza(): void
    {
        Storage::fake('public');

        /** @var User $basia */
        $basia = $this->user('basia');

        $zdjecie = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/awaryjne.jpg',
            'metadata' => ['variants' => [
                'feed' => ['key' => 'media/basia/awaryjne_feed.webp'],
            ]],
        ]);

        Storage::disk('public')->put($zdjecie->object_key, 'oryginal');
        Storage::disk('public')->put('media/basia/awaryjne_feed.webp', 'wariant');

        $basia->markForDeletion();

        // Pierwsze delete() (wariant) przechodzi, drugie (oryginał) rzuca —
        // symulacja R2, które padło w połowie kasowania TEGO zdjęcia.
        Storage::set('public', $this->udawanyDysk('public', lokalKtoryZawodzi: 2, wyjatek: true));

        // PO NAPRAWIE: `handle()` NIE rzuca — anonimizacja się zatwierdza,
        // kasowanie plików robi, co może, i wraca `true` (bo TO wywołanie
        // faktycznie coś zrobiło: anonimizację konta), bez wywalania się.
        $wymazano = (new EraseAccountData)->handle($basia->fresh());

        $this->assertTrue($wymazano);
        $this->assertNotNull($basia->fresh()->data_erased_at, 'Anonimizacja to osobna transakcja i musi przejść.');

        // Zdjęcie NIE zostało skasowane w komplecie (oryginał nadal tam jest)
        // — wiersz `media` ZOSTAJE, celowo, jako ślad do dokończenia.
        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey()]);
        Storage::disk('public')->assertExists($zdjecie->object_key);
        // Wariant, który zdążył się skasować przed awarią, nie wraca.
        Storage::disk('public')->assertMissing('media/basia/awaryjne_feed.webp');

        // KONTROLA: bez sprawnego dysku drugie wywołanie NIE dokończyłoby
        // niczego — więc poniższy sukces naprawdę pochodzi z naprawy dysku,
        // nie z tego, że i tak nic nie było do zrobienia.
        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey()]);

        // NAJWAŻNIEJSZA CZĘŚĆ TEGO TESTU: kolejny przebieg (dysk naprawiony,
        // symulujemy koniec awarii R2) MUSI umieć dokończyć TO SAMO zdjęcie,
        // mimo że konto jest już zanonimizowane (`data_erased_at` ustawione).
        Storage::fake('public');
        Storage::disk('public')->put($zdjecie->object_key, 'oryginal');

        $dokonczono = (new EraseAccountData)->handle($basia->fresh());

        $this->assertTrue($dokonczono, 'Konto już zanonimizowane z zaległym zdjęciem musi dać się dokończyć.');
        $this->assertDatabaseMissing('media', ['id' => $zdjecie->getKey()]);
        Storage::disk('public')->assertMissing($zdjecie->object_key);

        // Drugie wywołanie NA KONCIE BEZ ZALEGŁYCH ZDJĘĆ nie ma już czego
        // robić — idempotencja ponowienia, tak samo jak reszta tej klasy.
        $this->assertFalse((new EraseAccountData)->handle($basia->fresh()));
    }

    public function test_kopia_na_r2_legacy_znika_razem_z_reszta_audyt_n01(): void
    {
        // Zdjęcie PO migracji `kuking:przenies-zdjecia`: `disk`/`variants_disk`
        // wskazują nowe dyski, ale pod TYM SAMYM kluczem została (celowo,
        // patrz `PrzeniesZdjeciaDoNowychBucketow`) kopia na `r2_legacy`.
        Storage::fake('nowy_oryginal');
        Storage::fake('nowe_warianty');
        Storage::fake('r2_legacy');

        config(['filesystems.disks.r2_legacy.bucket' => 'stary-publiczny-bucket']);

        $zdjecie = Media::factory()->create([
            'disk' => 'nowy_oryginal',
            'variants_disk' => 'nowe_warianty',
            'object_key' => 'media/basia/2026/01/oryginal.jpg',
            'metadata' => ['variants' => [
                'feed' => ['key' => 'media/basia/2026/01/oryginal_feed.webp'],
            ]],
        ]);

        // Kopie na NOWYCH dyskach — tam zdjęcie naprawdę dziś leży.
        Storage::disk('nowy_oryginal')->put($zdjecie->object_key, 'oryginal');
        Storage::disk('nowe_warianty')->put('media/basia/2026/01/oryginal_feed.webp', 'wariant');

        // KOPIA POD TYM SAMYM KLUCZEM na starym, wciąż publicznym buckecie —
        // migracja świadomie jej nie skasowała.
        Storage::disk('r2_legacy')->put($zdjecie->object_key, 'oryginal (legacy)');
        Storage::disk('r2_legacy')->put('media/basia/2026/01/oryginal_feed.webp', 'wariant (legacy)');

        // KONTROLA: kopia na starym buckecie naprawdę tam jest, zanim
        // cokolwiek skasujemy — inaczej test przechodziłby, nie sprawdzając
        // niczego.
        Storage::disk('r2_legacy')->assertExists($zdjecie->object_key);
        Storage::disk('r2_legacy')->assertExists('media/basia/2026/01/oryginal_feed.webp');

        $wynik = (new KasujZdjecie)->skasujPliki($zdjecie->fresh());

        $this->assertTrue($wynik);

        // Nowe dyski — jak dotychczas.
        Storage::disk('nowy_oryginal')->assertMissing($zdjecie->object_key);
        Storage::disk('nowe_warianty')->assertMissing('media/basia/2026/01/oryginal_feed.webp');

        // TO JEST N01: kopia na `r2_legacy`, pod tym samym kluczem, MUSI
        // zniknąć razem z resztą — inaczej zostaje w publicznym buckecie
        // na zawsze, bez wiersza `media`, który mówiłby komukolwiek, że tam
        // jest.
        Storage::disk('r2_legacy')->assertMissing($zdjecie->object_key);
        Storage::disk('r2_legacy')->assertMissing('media/basia/2026/01/oryginal_feed.webp');
    }

    public function test_zdjecie_bez_kopii_na_r2_legacy_nie_wywala_kasowania(): void
    {
        // KONTROLA W DRUGĄ STRONĘ: zdjęcie, które NIGDY nie przechodziło
        // przez stary bucket (bo np. konto zarejestrowało się już po
        // rozdzieleniu bucketów), nie ma tam żadnej kopii do skasowania.
        // `r2_legacy` skonfigurowany, ale pusty — próba usunięcia
        // nieistniejącego klucza nie może wywalić reszty kasowania.
        Storage::fake('public');
        Storage::fake('r2_legacy');

        config(['filesystems.disks.r2_legacy.bucket' => 'stary-publiczny-bucket']);

        $zdjecie = Media::factory()->create([
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/nowe-konto/oryginal.jpg',
            'metadata' => ['variants' => []],
        ]);

        Storage::disk('public')->put($zdjecie->object_key, 'oryginal');

        $wynik = (new KasujZdjecie)->skasujPliki($zdjecie->fresh());

        $this->assertTrue($wynik);
        Storage::disk('public')->assertMissing($zdjecie->object_key);
    }

    public function test_r2_legacy_niekonfigurowany_jest_pomijany_a_nie_awaria(): void
    {
        // KONTROLA: lokalnie i w testach `AWS_LEGACY_BUCKET` zwykle nie jest
        // ustawiony wcale (bucket = ''). `dyskiDoWyczyszczenia()` musi wtedy
        // pominąć `r2_legacy`, a nie próbować się z nim połączyć — inaczej
        // KAŻDE kasowanie zdjęcia na deweloperskim środowisku bez R2 zaczęłoby
        // padać na dysku, którego w ogóle nie ma.
        Storage::fake('public');

        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $zdjecie = Media::factory()->create([
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/oryginal.jpg',
            'metadata' => ['variants' => []],
        ]);

        Storage::disk('public')->put($zdjecie->object_key, 'oryginal');

        $wynik = (new KasujZdjecie)->skasujPliki($zdjecie->fresh());

        $this->assertTrue($wynik, 'r2_legacy bez skonfigurowanego bucketu nie może wywrócić zwykłego kasowania.');
        Storage::disk('public')->assertMissing($zdjecie->object_key);
    }

    public function test_sprzatanie_osieroconych_zostawia_wiersz_po_nieudanym_kasowaniu_i_ponawia(): void
    {
        Storage::fake('public');

        $basia = $this->user('basia');

        $stare = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'created_at' => now()->subDays(3),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/sierota.jpg',
            'metadata' => ['variants' => [
                'feed' => ['key' => 'media/basia/sierota_feed.webp'],
            ]],
        ]);

        Storage::disk('public')->put($stare->object_key, 'oryginal');
        Storage::disk('public')->put('media/basia/sierota_feed.webp', 'wariant');

        // Drugie wywołanie delete() (oryginał, po wariancie) rzuca.
        Storage::set('public', $this->udawanyDysk('public', lokalKtoryZawodzi: 2, wyjatek: true));

        $ile = (new OsieroconeZdjecia(24))->posprzataj();

        // PO NAPRAWIE: zdjęcie, którego nie udało się skasować w komplecie,
        // NIE liczy się jako skasowane...
        $this->assertSame(0, $ile);

        // ...wiersz ZOSTAJE (to jest cały mechanizm ponowienia dla sprzątania
        // osieroconych — wybiera po wieku, nie po flagach)...
        $this->assertNotNull($stare->fresh());

        // ...a oryginał, na którym symulujemy awarię, rzeczywiście wciąż tam
        // jest — porażka jest prawdziwa, nie tylko zgłoszona.
        Storage::disk('public')->assertExists($stare->object_key);

        // KOLEJNY PRZEBIEG, dysk naprawiony: to samo, wciąż stare zdjęcie
        // MUSI się teraz skasować w komplecie.
        Storage::fake('public');
        Storage::disk('public')->put($stare->object_key, 'oryginal');

        $ile = (new OsieroconeZdjecia(24))->posprzataj();

        $this->assertSame(1, $ile);
        $this->assertNull($stare->fresh());
        Storage::disk('public')->assertMissing($stare->object_key);
    }

    public function test_egzekutor_karencji_ponawia_kasowanie_zdjec_konta_juz_zanonimizowanego(): void
    {
        Storage::fake('public');

        $basia = $this->user('basia');

        $zdjecie = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/oryginal.jpg',
            'metadata' => ['variants' => []],
        ]);

        Storage::disk('public')->put($zdjecie->object_key, 'oryginal');

        $basia->markForDeletion();

        // Kasowanie PLIKU (jedyne wywołanie delete() dla tego zdjęcia) rzuca
        // — anonimizacja konta i tak przechodzi (osobna transakcja).
        Storage::set('public', $this->udawanyDysk('public', lokalKtoryZawodzi: 1, wyjatek: true));

        $wykonano = app(EraseAccountData::class)->handle($basia->fresh());

        $this->assertTrue($wykonano);
        $this->assertNotNull($basia->fresh()->data_erased_at);

        // KONTROLA: zdjęcie naprawdę zostało, dysk naprawdę zawodzi — inaczej
        // druga kolejka komendy niżej nie miałaby czego dokańczać.
        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey()]);
        Storage::disk('public')->assertExists($zdjecie->object_key);

        // Dysk „naprawiony" — koniec udawanej awarii R2.
        Storage::fake('public');
        Storage::disk('public')->put($zdjecie->object_key, 'oryginal');

        // NAJWAŻNIEJSZA CZĘŚĆ TEGO TESTU: `kuking:usun-wygasle-konta` musi
        // sam z siebie znaleźć to konto DRUGI RAZ, mimo że `data_erased_at`
        // jest już ustawione — bez tego zdjęcie nie miałoby żadnej drogi
        // powrotnej (patrz `PurgeExpiredAccountDeletions`, druga kolejka).
        $this->artisan(PurgeExpiredAccountDeletions::class)->assertSuccessful();

        $this->assertDatabaseMissing('media', ['id' => $zdjecie->getKey()]);
        Storage::disk('public')->assertMissing($zdjecie->object_key);
    }
}
