<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** #2811: tożsamość jednego multipart dołączenia zdjęć, nie treść pliku. */
final class DolaczenieZdjeciaPonowienieUploaduTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ponowiony_multipart_jednego_wyslania_nie_tworzy_drugiego_zdjecia(): void
    {
        $kucharz = $this->user('kucharka');
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public',
        ]);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(), 'cooked_at' => now()->subDay(),
        ]);
        $klucz = (string) Str::uuid7();
        $plik = UploadedFile::fake()->image('obiad.jpg', 800, 600);
        $bajty = file_get_contents($plik->getRealPath());
        $this->assertIsString($bajty);

        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'klucz_wyslania' => $klucz,
            'photos' => [UploadedFile::fake()->createWithContent('obiad.jpg', $bajty)],
        ])->assertRedirect(route('cooked.show', $wykonanie));
        $znacznik = $wykonanie->fresh()?->photos_added_at;

        Carbon::setTestNow(now()->addHour());
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'klucz_wyslania' => $klucz,
            'photos' => [UploadedFile::fake()->createWithContent('obiad.jpg', $bajty)],
        ])->assertRedirect(route('cooked.show', $wykonanie));

        $this->assertSame(1, DB::table('cooked_event_media')->where('cooked_event_id', $wykonanie->getKey())->count(), 'DOLACZENIE_2811_PONOWIONY_MULTIPART');
        $this->assertSame(1, Media::query()->where('owner_id', $kucharz->getKey())->count(), 'DOLACZENIE_2811_BEZ_OSIEROCONEGO_MEDIA');
        $this->assertEquals($znacznik, $wykonanie->fresh()?->photos_added_at);
        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_COOKED)->count());
    }

    public function test_dwa_rozne_klucze_dodaja_dwa_zdjecia_a_pierwszy_pozostaje_rozpoznany_po_usunieciu_media(): void
    {
        $kucharz = $this->user('kucharka');
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public',
        ]);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(), 'cooked_at' => now()->subDay(),
        ]);
        $pierwszy = (string) Str::uuid7();
        $drugi = (string) Str::uuid7();

        foreach ([$pierwszy, $drugi] as $klucz) {
            $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
                'klucz_wyslania' => $klucz,
                'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
            ])->assertRedirect(route('cooked.show', $wykonanie));
        }
        $this->assertSame(2, $wykonanie->fresh()->media()->count(), 'DOLACZENIE_2811_ROZNE_KLUCZE');

        $stareMedia = (string) $wykonanie->fresh()->media()->first()?->getKey();
        DB::table('cooked_event_media')->where('media_id', $stareMedia)->delete();
        Media::query()->whereKey($stareMedia)->delete();
        $znacznik = $wykonanie->fresh()->photos_added_at;

        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'klucz_wyslania' => $pierwszy,
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
        ])->assertRedirect(route('cooked.show', $wykonanie));

        $this->assertSame(1, $wykonanie->fresh()->media()->count(), 'DOLACZENIE_2811_KLUCZ_PO_USUNIECIU_MEDIA');
        $this->assertSame(1, Media::query()->where('owner_id', $kucharz->getKey())->count());
        $this->assertEquals($znacznik, $wykonanie->fresh()->photos_added_at);
    }

    public function test_siodme_wyslanie_po_usunieciu_zdjecia_przechodzi_a_stary_klucz_nadal_nie_dodaje_media(): void
    {
        $kucharz = $this->user('kucharka');
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public',
        ]);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(), 'cooked_at' => now()->subDay(),
        ]);
        $klucze = [];

        for ($i = 0; $i < 7; $i++) {
            if ($i > 0) {
                $stareMedia = (string) $wykonanie->fresh()->media()->first()?->getKey();
                DB::table('cooked_event_media')->where('media_id', $stareMedia)->delete();
                Media::query()->whereKey($stareMedia)->delete();
            }

            $klucz = (string) Str::uuid7();
            $klucze[] = $klucz;
            $odpowiedz = $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
                'klucz_wyslania' => $klucz,
                'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
            ]);
            if ($i === 6) {
                $this->assertTrue($odpowiedz->isRedirect(), 'DOLACZENIE_2811_SIODME_WYSLANIE_PO_USUNIECIU');
            }
            $odpowiedz->assertRedirect(route('cooked.show', $wykonanie));
            $this->assertSame(1, $wykonanie->fresh()->media()->count(), 'DOLACZENIE_2811_SIODME_WYSLANIE_PO_USUNIECIU');
        }

        $this->assertSame($klucze, $wykonanie->fresh()->photo_submission_keys, 'DOLACZENIE_2811_HISTORIA_KLUCZY');
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'klucz_wyslania' => $klucze[0],
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
        ])->assertRedirect(route('cooked.show', $wykonanie));

        $this->assertSame(1, $wykonanie->fresh()->media()->count(), 'DOLACZENIE_2811_STARY_KLUCZ_PO_SIODMYM');
        $this->assertSame($klucze, $wykonanie->fresh()->photo_submission_keys);
    }

    public function test_cudzy_klucz_i_wygasle_okno_nie_obchodza_uprawnien(): void
    {
        $kucharz = $this->user('kucharka');
        $obcy = $this->user('obcy');
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public',
        ]);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(), 'cooked_at' => now()->subDay(),
        ]);
        $klucz = (string) Str::uuid7();
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'klucz_wyslania' => $klucz,
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
        ])->assertRedirect(route('cooked.show', $wykonanie));

        $this->actingAs($obcy)->post(route('cooked.photos.store', $wykonanie), [
            'klucz_wyslania' => $klucz,
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
        ])->assertForbidden();

        Carbon::setTestNow(now()->addDays(8));
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'klucz_wyslania' => $klucz,
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
        ])->assertForbidden();
        $this->assertSame(1, Media::query()->where('owner_id', $kucharz->getKey())->count(), 'DOLACZENIE_2811_POLICY_PRZY_PONOWIENIU');
    }

    public function test_migracja_pustej_historii_daje_sie_cofnac_i_ponowic_po_czesciowym_ddl(): void
    {
        $migracja = require database_path('migrations/2026_10_08_080000_add_photo_submission_keys_to_cooked_events.php');
        $this->assertFalse($migracja->withinTransaction);
        $migracja->down();
        $this->assertFalse(Schema::hasColumn('cooked_events', 'photo_submission_keys'));
        DB::statement("ALTER TABLE cooked_events ADD COLUMN photo_submission_keys jsonb NOT NULL DEFAULT '[]'::jsonb");
        $migracja->up();
        $migracja->up();
        $ograniczenie = DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conrelid = 'cooked_events'::regclass AND conname = ?", ['cooked_events_photo_submission_keys_array']);
        $this->assertNotNull($ograniczenie);
        $this->assertTrue($ograniczenie->convalidated);
    }

    public function test_cofniecie_migracji_nie_gubi_udanych_kluczy(): void
    {
        $klucz = (string) Str::uuid7();
        $wykonanie = CookedEvent::factory()->create();
        $wykonanie->forceFill(['photo_submission_keys' => [$klucz]])->save();
        $migracja = require database_path('migrations/2026_10_08_080000_add_photo_submission_keys_to_cooked_events.php');
        try {
            $migracja->down();
            $this->fail('DOLACZENIE_2811_ROLLBACK_ZACHOWUJE_KLUCZE: cofnięcie powinno odmówić.');
        } catch (\RuntimeException $blad) {
            $this->assertStringContainsString('historii kluczy', $blad->getMessage());
        }
        $this->assertSame([$klucz], $wykonanie->fresh()->photo_submission_keys);
    }

    public function test_wymazanie_konta_usuwa_prywatna_historie_kluczy_lecz_zostawia_wykonanie(): void
    {
        $kucharz = $this->user('kucharka');
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public',
        ]);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(), 'cooked_at' => now()->subDay(),
        ]);
        $klucz = (string) Str::uuid7();
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'klucz_wyslania' => $klucz,
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
        ])->assertRedirect(route('cooked.show', $wykonanie));
        $this->assertSame([$klucz], $wykonanie->fresh()->photo_submission_keys);

        $kucharz->markForDeletion();
        $this->assertTrue(app(EraseAccountData::class)->handle($kucharz->fresh()));

        $zachowane = CookedEvent::query()->find($wykonanie->getKey());
        $this->assertNotNull($zachowane);
        $this->assertSame([], $zachowane->photo_submission_keys, 'DOLACZENIE_2811_WYMAZANE_KLUCZE');
    }
}
