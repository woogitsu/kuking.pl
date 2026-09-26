<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\KasujZdjecie;
use App\Domain\Media\OsieroconeZdjecia;
use App\Domain\Media\ZdjeciaDoPrzypiecia;
use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionObject;
use Tests\TestCase;

/**
 * MEDIA-01 (issue #285): sprzątacz osieroconych zdjęć a przypinanie zdjęcia.
 *
 * STAN SPRZED NAPRAWY — SPRAWDZONY W PLIKACH, NIE PRZEPISANY Z AUDYTU
 *
 * Ani przypinanie, ani sprzątanie nie brały żadnej blokady na wspólnym
 * wierszu `media`:
 *
 *  - `PublishPost::handle()` wybierał `media_id` należące do autora zwykłym
 *    `SELECT`-em PRZED transakcją i nigdy do tego wyboru nie wracał;
 *  - `KasujZdjecie::jesliNieuzywane()` pytał `exists()` (też bez blokady),
 *    a potem — WEWNĄTRZ transakcji otwartej przez `OsieroconeZdjecia` —
 *    kasował pliki z R2 i dopiero na końcu wiersz.
 *
 * Między `exists()` a skasowaniem plików mieściła się cała publikacja.
 *
 * JEDNA POPRAWKA DO OPISU ISSUE. Issue przewiduje, że sprzątacz „wchodzi
 * w konflikt z FK". Nie wchodzi: `post_media.media_id` ma w migracji
 * `2026_09_05_000500_create_posts_tables.php` `cascadeOnDelete()`, więc
 * skasowanie wiersza `media` po cichu zabiera świeżo wstawiony wiersz
 * `post_media`. Objaw jest więc GORSZY niż w opisie — nie ma ani wyjątku,
 * ani wpisu w logu: wpis zostaje bez zdjęcia, plik znika z R2, a jedyny
 * egzemplarz zdjęcia człowieka nie istnieje już nigdzie.
 *
 * CZEGO TE TESTY NIE PILNUJĄ — I DLACZEGO
 *
 * NIE odtwarzają wymuszonego przeplotu na dwóch połączeniach do PostgreSQL,
 * którego domaga się issue. `RefreshDatabase` trzyma wszystkie dane testu
 * w NIEZATWIERDZONEJ transakcji, więc drugie połączenie nie zobaczyłoby ani
 * użytkownika, ani zdjęcia — nie da się tego napisać w tym wzorcu, a wyjście
 * poza niego (osobna baza bez `RefreshDatabase`) oznaczałoby test, który
 * zostawia po sobie śmieci i nie chodzi równolegle z resztą zestawu.
 *
 * Testowany jest więc KONTRAKT, który naprawa wprowadza, i to na każdym
 * z jego czterech elementów osobno:
 *
 *  1. wybór zdjęć do przypięcia idzie `SELECT … FOR UPDATE`, w tej samej
 *     transakcji co `attach()`, w deterministycznej kolejności;
 *  2. sprzątacz rewaliduje użycie POD blokadą tego samego wiersza;
 *  3. zdjęcie przejęte do skasowania (`status = deleted`) nie da się już
 *     przypiąć ani do wpisu, ani do wykonania;
 *  4. pliki kasują się PO zatwierdzeniu transakcji przejmującej, a nieudane
 *     kasowanie zostawia wiersz jako uchwyt do ponowienia.
 *
 * Z tych czterech wynika brak przeplotu — ale to jest wnioskowanie, nie
 * pomiar, i tak trzeba to czytać. Czego naprawdę nie widać w żadnym teście:
 * czy PostgreSQL faktycznie serializuje `FOR UPDATE` z `FOR KEY SHARE`,
 * które sam bierze przy sprawdzaniu klucza obcego przy `INSERT`-cie do
 * `post_media`. To jest własność silnika, przyjęta z dokumentacji.
 *
 * Nie jest też pilnowany strażnik `DB::transactionLevel() === 0`
 * w `ZdjeciaDoPrzypiecia`: pod `RefreshDatabase` poziom transakcji NIGDY
 * nie jest zerem, więc tego wyjątku nie da się w tym zestawie wywołać.
 *
 * Poza zakresem tego pliku zostają cztery pozostałe drogi przypięcia
 * (`profiles.avatar_media_id`, `recipes.hero_media_id`,
 * `recipes.source_scan_media_id`, `recipe_steps.media_id`). D-083 zostawiło
 * je świadomie bez blokady; domknęło je dopiero D-103 i pilnuje ich
 * `tests/Feature/CzteryDrogiZdjeciaPodBlokadaTest.php` — po jednym teście na
 * drogę, żeby zepsucie jednej z nich oblewało dokładnie jeden test.
 */
class ZdjecieNieZnikaPrzyPrzypinaniuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
    }

    private function autorka(): User
    {
        return $this->user('basia');
    }

    /**
     * Zapytania SQL wykonane w trakcie działania `$co`, w kolejności.
     *
     * @return list<string>
     */
    private function zapytaniaPodczas(callable $co): array
    {
        $zapytania = [];

        DB::listen(static function ($zdarzenie) use (&$zapytania): void {
            $zapytania[] = strtolower($zdarzenie->sql);
        });

        $co();

        // Nasłuch zostaje do końca testu — `DB::listen` nie ma odpięcia,
        // a każdy test dostaje świeżą aplikację.
        return $zapytania;
    }

    /** @param  list<string>  $zapytania */
    private function zapytaniaOMedia(array $zapytania): array
    {
        return array_values(array_filter(
            $zapytania,
            static fn (string $sql): bool => str_contains($sql, 'from "media"'),
        ));
    }

    // ---------------------------------------------------------------
    // 1. Wybór zdjęć do przypięcia — pod blokadą i w tej samej transakcji
    // ---------------------------------------------------------------

    public function test_publikacja_wybiera_zdjecia_pod_blokada_wiersza(): void
    {
        $basia = $this->autorka();
        $zdjecie = Media::factory()->create(['owner_id' => $basia->getKey()]);

        $zapytania = $this->zapytaniaPodczas(function () use ($basia, $zdjecie): void {
            app(PublishPost::class)->handle(
                author: $basia,
                body: 'Pierogi z jagodami.',
                mediaIds: [(string) $zdjecie->getKey()],
            );
        });

        $wybor = $this->zapytaniaOMedia($zapytania);

        $this->assertNotEmpty($wybor, 'Publikacja w ogóle nie zapytała o tabelę `media`.');

        $zBlokada = array_values(array_filter(
            $wybor,
            static fn (string $sql): bool => str_contains($sql, 'for update'),
        ));

        $this->assertNotEmpty(
            $zBlokada,
            "Wybór zdjęć do przypięcia musi iść `SELECT … FOR UPDATE`.\nZapytania o `media`:\n".implode("\n", $wybor),
        );

        // Deterministyczna kolejność blokowania — inaczej dwa równoległe
        // wysłania z częściowo wspólnym zestawem zdjęć zakleszczą się.
        $this->assertStringContainsString(
            'order by "id" asc',
            $zBlokada[0],
            'Blokada wielu wierszy `media` musi iść w deterministycznej kolejności (rosnąco po id).',
        );

        // Rewalidacja POD blokadą, a nie tylko sama blokada (D-079 §3):
        // warunki własności i stanu stoją w TYM SAMYM zapytaniu.
        $this->assertStringContainsString('"owner_id"', $zBlokada[0]);
        $this->assertStringContainsString('"status"', $zBlokada[0]);

        // Kontrola dodatnia: zdjęcie naprawdę zostało przypięte.
        $this->assertDatabaseHas('post_media', ['media_id' => $zdjecie->getKey()]);
    }

    public function test_sprzatacz_rewaliduje_uzycie_pod_blokada_wiersza(): void
    {
        Storage::fake('public');

        $basia = $this->autorka();
        Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'created_at' => now()->subDays(3),
        ]);

        $zapytania = $this->zapytaniaPodczas(function (): void {
            (new OsieroconeZdjecia(24))->posprzataj();
        });

        $zBlokada = array_values(array_filter(
            $this->zapytaniaOMedia($zapytania),
            static fn (string $sql): bool => str_contains($sql, 'for update'),
        ));

        $this->assertNotEmpty(
            $zBlokada,
            'Sprzątacz musi wziąć wiersz `media` `FOR UPDATE`, zanim uzna go za nieużywany.',
        );
    }

    // ---------------------------------------------------------------
    // 2. Zdjęcie przejęte do skasowania nie da się już przypiąć
    // ---------------------------------------------------------------

    public function test_zdjecia_przejetego_do_skasowania_nie_da_sie_przypiac_do_wpisu(): void
    {
        $basia = $this->autorka();

        $zdrowe = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $przejete = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'status' => Media::STATUS_DELETED,
        ]);

        $wpis = app(PublishPost::class)->handle(
            author: $basia,
            body: 'Rosół niedzielny.',
            mediaIds: [(string) $zdrowe->getKey(), (string) $przejete->getKey()],
        );

        $this->assertDatabaseHas('post_media', [
            'post_id' => $wpis->getKey(),
            'media_id' => $zdrowe->getKey(),
        ]);

        $this->assertDatabaseMissing('post_media', [
            'media_id' => $przejete->getKey(),
        ]);

        // Wpis powstaje mimo wszystko — poprawne dane nie znikają człowiekowi
        // z powodu cudzego sprzątania (AGENTS.md, UX 50+).
        $this->assertSame('Rosół niedzielny.', $wpis->body);
    }

    public function test_zdjecia_przejetego_do_skasowania_nie_da_sie_przypiac_do_wykonania(): void
    {
        $autorka = $this->user('autorka');
        $kucharka = $this->user('kucharka');

        $przepis = Recipe::factory()->create([
            'author_id' => $autorka->getKey(),
            'title' => 'Zupa ogórkowa babci',
        ]);

        $zdrowe = Media::factory()->create(['owner_id' => $kucharka->getKey()]);
        $przejete = Media::factory()->create([
            'owner_id' => $kucharka->getKey(),
            'status' => Media::STATUS_DELETED,
        ]);

        $wykonanie = app(RecordCookedEvent::class)->handle(
            cook: $kucharka,
            recipe: $przepis,
            note: 'Ugotowane w niedzielę.',
            mediaIds: [(string) $zdrowe->getKey(), (string) $przejete->getKey()],
        );

        $this->assertDatabaseHas('cooked_event_media', [
            'cooked_event_id' => $wykonanie->getKey(),
            'media_id' => $zdrowe->getKey(),
        ]);

        $this->assertDatabaseMissing('cooked_event_media', [
            'media_id' => $przejete->getKey(),
        ]);
    }

    public function test_zablokuj_pomija_cudze_zdjecia(): void
    {
        $basia = $this->autorka();
        $obcy = $this->user('obcy');

        $moje = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $cudze = Media::factory()->create(['owner_id' => $obcy->getKey()]);

        // `RefreshDatabase` trzyma otwartą transakcję, więc blokada ma się
        // czego trzymać — dokładnie tak, jak w środku `PublishPost`.
        $wynik = ZdjeciaDoPrzypiecia::zablokuj(
            (string) $basia->getKey(),
            [(string) $moje->getKey(), (string) $cudze->getKey()],
        );

        $this->assertSame([(string) $moje->getKey()], $wynik, 'Bramka własności (IDOR) musi zostać nietknięta.');
    }

    // ---------------------------------------------------------------
    // 3. Pliki po commicie, wiersz jako uchwyt do ponowienia
    // ---------------------------------------------------------------

    /**
     * Dysk, który przy każdym `delete()` zapisuje poziom zagnieżdżenia
     * transakcji i to, co w tej chwili stoi w kolumnie `status`.
     *
     * Dekorator jest tym samym wzorcem co w
     * `KasowanieZdjeciaOdpornoscNaAwarieTest` — nie drugą kopią pomysłu.
     *
     * @return FilesystemAdapter&object{kontekst: list<array{poziom: int, status: string|null}>}
     */
    private function dyskZapisujacyKontekst(string $nazwaDysku, string $mediaId, bool $zawodzi = false): FilesystemAdapter
    {
        $prawdziwy = Storage::disk($nazwaDysku);

        $dekorator = new class(...$this->argumentyDekoratora($prawdziwy)) extends FilesystemAdapter
        {
            /** @var list<array{poziom: int, status: string|null}> */
            public array $kontekst = [];

            public string $mediaId = '';

            public bool $zawodzi = false;

            public function delete($paths): bool
            {
                $this->kontekst[] = [
                    'poziom' => DB::transactionLevel(),
                    'status' => DB::table('media')->where('id', $this->mediaId)->value('status'),
                ];

                if ($this->zawodzi) {
                    return false;
                }

                return parent::delete($paths);
            }
        };

        $dekorator->mediaId = $mediaId;
        $dekorator->zawodzi = $zawodzi;

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

    public function test_pliki_kasuja_sie_po_zatwierdzeniu_transakcji_przejmujacej(): void
    {
        Storage::fake('public');

        $basia = $this->autorka();
        $zdjecie = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/oryginal.jpg',
            'created_at' => now()->subDays(3),
        ]);

        Storage::disk('public')->put((string) $zdjecie->object_key, 'oryginal');
        Storage::disk('public')->assertExists((string) $zdjecie->object_key);

        $poziomPrzed = DB::transactionLevel();

        $dysk = $this->dyskZapisujacyKontekst('public', (string) $zdjecie->getKey());
        Storage::set('public', $dysk);

        $this->assertTrue((new KasujZdjecie)->jesliNieuzywane($zdjecie->fresh()));

        $this->assertNotEmpty($dysk->kontekst, 'Nie doszło do ani jednej próby skasowania pliku.');

        foreach ($dysk->kontekst as $proba) {
            // ŻADNA transakcja przejmująca nie może być w tym momencie
            // otwarta: kasowanie pliku jest nieodwracalne, a wycofanie
            // transakcji go nie przywróci (wzorzec `EraseAccountData`).
            $this->assertSame(
                $poziomPrzed,
                $proba['poziom'],
                'Plik kasował się WEWNĄTRZ otwartej transakcji — a nie po jej zatwierdzeniu.',
            );

            // Uchwyt do ponowienia już stoi: wiersz istnieje i jest
            // oznaczony, więc nic go w tym czasie nie przypnie.
            $this->assertSame(
                Media::STATUS_DELETED,
                $proba['status'],
                'Zdjęcie nie było przejęte (`status = deleted`) w chwili kasowania plików.',
            );
        }

        $this->assertDatabaseMissing('media', ['id' => $zdjecie->getKey()]);
    }

    public function test_nieudane_kasowanie_zostawia_uchwyt_do_ponowienia(): void
    {
        Storage::fake('public');

        $basia = $this->autorka();
        $zdjecie = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/oryginal.jpg',
            'created_at' => now()->subDays(3),
        ]);

        Storage::disk('public')->put((string) $zdjecie->object_key, 'oryginal');

        Storage::set('public', $this->dyskZapisujacyKontekst('public', (string) $zdjecie->getKey(), zawodzi: true));

        $this->assertFalse((new KasujZdjecie)->jesliNieuzywane($zdjecie->fresh()));

        // Wiersz ZOSTAJE i niesie znacznik: to jest cały mechanizm
        // ponowienia i jednocześnie bariera przed przypięciem.
        $this->assertDatabaseHas('media', [
            'id' => $zdjecie->getKey(),
            'status' => Media::STATUS_DELETED,
        ]);

        $this->assertSame([], ZdjeciaDoPrzypiecia::zablokuj(
            (string) $basia->getKey(),
            [(string) $zdjecie->getKey()],
        ));

        // Ponowienie na sprawnym dysku kończy pracę — wiersz znika dopiero
        // wtedy, gdy plik naprawdę zniknął.
        Storage::set('public', Storage::disk('public'));
        Storage::fake('public');

        $this->assertTrue((new KasujZdjecie)->jesliNieuzywane($zdjecie->fresh()));
        $this->assertDatabaseMissing('media', ['id' => $zdjecie->getKey()]);
    }

    // ---------------------------------------------------------------
    // 4. Kontrola dodatnia — naprawa nie może polegać na wyłączeniu sprzątacza
    // ---------------------------------------------------------------

    public function test_naprawde_osierocone_zdjecie_po_karencji_nadal_znika(): void
    {
        Storage::fake('public');

        $basia = $this->autorka();

        $stare = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'created_at' => now()->subDays(3),
        ]);
        $swieze = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'created_at' => now(),
        ]);

        $ile = (new OsieroconeZdjecia(24))->posprzataj();

        $this->assertSame(1, $ile, 'Sprzątacz musi nadal kasować naprawdę osierocone zdjęcia po karencji.');
        $this->assertDatabaseMissing('media', ['id' => $stare->getKey()]);
        $this->assertDatabaseHas('media', ['id' => $swieze->getKey()]);
    }

    /**
     * PIERWSZA POŁOWA OKNA Z ISSUE, ODTWORZONA BEZ DRUGIEGO POŁĄCZENIA.
     *
     * Sprzątacz wybrał kandydatów (`$kandydat` to model z TAMTEGO odczytu),
     * a zanim doszedł do kasowania, publikacja przypięła zdjęcie do wpisu.
     * Wywołanie `jesliNieuzywane()` wprost na nieświeżym modelu jest dokładnie
     * tym stanem — i to jest jedyny fragment przeplotu, który da się odtworzyć
     * na jednym połączeniu.
     *
     * Zdjęcie musi zostać: i wiersz, i plik.
     */
    public function test_sprzatacz_odmawia_zdjeciu_przypietemu_po_wyborze_kandydatow(): void
    {
        Storage::fake('public');

        $basia = $this->autorka();

        $zdjecie = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/basia/oryginal.jpg',
            'created_at' => now()->subDays(3),
        ]);

        Storage::disk('public')->put((string) $zdjecie->object_key, 'oryginal');

        // Model sprzed przypięcia — tak samo nieświeży jak ten, który
        // `OsieroconeZdjecia` niesie z zapytania wybierającego kandydatów.
        $kandydat = Media::query()->whereKey($zdjecie->getKey())->firstOrFail();

        app(PublishPost::class)->handle(
            author: $basia,
            body: 'Zdjęcie sprzed tygodnia, opisane dzisiaj.',
            mediaIds: [(string) $zdjecie->getKey()],
        );

        $this->assertFalse(
            (new KasujZdjecie)->jesliNieuzywane($kandydat),
            'Sprzątacz skasował zdjęcie, które w międzyczasie zostało przypięte do wpisu.',
        );

        $this->assertDatabaseHas('media', [
            'id' => $zdjecie->getKey(),
            'status' => Media::STATUS_READY,
        ]);
        $this->assertDatabaseHas('post_media', ['media_id' => $zdjecie->getKey()]);
        Storage::disk('public')->assertExists((string) $zdjecie->object_key);
    }

    public function test_zdjecie_przypiete_do_wpisu_przezywa_sprzatanie(): void
    {
        Storage::fake('public');

        $basia = $this->autorka();

        $zdjecie = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'created_at' => now()->subDays(3),
        ]);

        app(PublishPost::class)->handle(
            author: $basia,
            body: 'Zdjęcie sprzed tygodnia, opisane dzisiaj.',
            mediaIds: [(string) $zdjecie->getKey()],
        );

        $this->assertSame(0, (new OsieroconeZdjecia(24))->posprzataj());
        $this->assertDatabaseHas('media', [
            'id' => $zdjecie->getKey(),
            'status' => Media::STATUS_READY,
        ]);
        $this->assertDatabaseHas('post_media', ['media_id' => $zdjecie->getKey()]);
    }
}
