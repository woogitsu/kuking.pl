<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\KasujZdjecie;
use App\Domain\Recipes\Actions\DolaczZdjeciaDoWykonania;
use App\Domain\Recipes\Actions\ZbierzZdjeciaWykonania;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** #2883: brak przypięcia nie jest potwierdzeniem wcześniejszego przypięcia. */
final class OdrzuconeZdjecieDoWykonaniaTest extends TestCase
{
    use RefreshDatabase;

    private const ODMOWA = 'Nie udało się dołączyć wybranego zdjęcia. Wybierz zdjęcie ponownie.';

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

    /** @return array{User, CookedEvent} */
    private function wykonanie(): array
    {
        $kucharz = $this->user('kucharz');
        $przepis = Recipe::factory()->create([
            'author_id' => $this->user('autor')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);

        return [$kucharz, CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(),
            'cooked_at' => now()->subDay(), 'note' => 'To wciąż ten sam obiad.',
        ])];
    }

    private function zdjecie(User $kucharz, string $status = Media::STATUS_READY): Media
    {
        return Media::factory()->create(['owner_id' => $kucharz->getKey(), 'status' => $status]);
    }

    /** @return list<string> */
    private function przypiete(CookedEvent $wykonanie): array
    {
        return $wykonanie->media()->orderBy('cooked_event_media.position')->pluck('media.id')
            ->map(fn (mixed $id): string => (string) $id)->all();
    }

    /** Prawdziwy sprzątacz przejmuje wiersz; awaria własnego dysku zatrzymuje usunięcie pliku. */
    private function przejmijZeZlymDyskiem(Media $zdjecie): void
    {
        $dysk = Storage::disk('public');
        $dysk->put($zdjecie->object_key, 'własny plik testu 2883');
        Storage::set('public', new class($dysk->getDriver(), $dysk->getAdapter(), $dysk->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths): bool
            {
                return false;
            }
        });

        $this->assertFalse(app(KasujZdjecie::class)->jesliNieuzywane($zdjecie));
        $this->assertSame(Media::STATUS_DELETED, $zdjecie->fresh()->status);
        $this->assertTrue(Storage::disk('public')->exists($zdjecie->object_key));
    }

    private function sprawdzOdmowe(string $html, CookedEvent $wykonanie, Media $odrzucone, string $klucz): void
    {
        $this->assertSame([], $this->przypiete($wykonanie));
        $this->assertNull($wykonanie->fresh()->photos_added_at);
        $this->assertSame([], $wykonanie->fresh()->photo_submission_keys);
        $this->assertSame('To wciąż ten sam obiad.', $wykonanie->fresh()->note);
        $this->assertStringContainsString(self::ODMOWA, $html, 'DOLACZENIE_2883_ODMOWA_ZAMIAST_PONOWIENIA');
        $this->assertStringContainsString('Dołącz zdjęcie do tego wykonania', $html);
        $this->assertStringNotContainsString('jest już przy wykonaniu', $html);
        $this->assertStringNotContainsString('To zdjęcie jest już przy Twoim wykonaniu', $html);
        $this->assertStringNotContainsString('Twoje zdjęcia są zachowane', $html, 'DOLACZENIE_2883_BEZ_FALSZYWEGO_ZACHOWANIA');
        $this->assertStringNotContainsString('name="media_ids[]" value="'.$odrzucone->getKey().'"', $html);
        $this->assertStringContainsString('name="klucz_wyslania" value="'.$klucz.'"', $html);

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $this->assertSame(self::ODMOWA, trim($xpath->query('//*[@id="f-photos-error"]')->item(0)->textContent ?? ''));
        $this->assertSame(self::ODMOWA, trim($xpath->query('//a[@href="#f-photos"]')->item(0)->textContent ?? ''));
        $this->assertSame('true', $xpath->query('//*[@id="f-photos"]')->item(0)?->attributes?->getNamedItem('aria-invalid')?->nodeValue);
    }

    public function test_http_odmawia_przejete_uuid_zamiast_potwierdzac_nieistniejace_przypiecie(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanie();
        $zdjecie = $this->zdjecie($kucharz);
        $klucz = (string) Str::uuid7();
        $formularz = route('cooked.photos.create', $wykonanie);
        $this->actingAs($kucharz)->withSession(['_old_input' => [
            'media_ids' => [(string) $zdjecie->getKey()], 'klucz_wyslania' => $klucz,
        ]])->get($formularz)->assertOk()->assertSee('name="media_ids[]" value="'.$zdjecie->getKey().'"', false);

        $this->przejmijZeZlymDyskiem($zdjecie);
        // Wiersz nadal istnieje i dotychczasowy helper naprawdę odzyskuje jego UUID.
        $this->assertSame([(string) $zdjecie->getKey()], app(ZbierzZdjeciaWykonania::class)->handle([(string) $zdjecie->getKey()], [], $kucharz));
        $html = (string) $this->followingRedirects()->actingAs($kucharz)->from($formularz)
            ->post(route('cooked.photos.store', $wykonanie), [
                'media_ids' => [(string) $zdjecie->getKey()], 'klucz_wyslania' => $klucz,
            ])->assertOk()->getContent();

        $this->sprawdzOdmowe($html, $wykonanie, $zdjecie, $klucz);
        $this->assertSame(Media::STATUS_DELETED, $zdjecie->fresh()->status);
    }

    public function test_http_odmawia_zabezpieczone_uuid_i_nie_odzyskuje_dowodu(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanie();
        $zdjecie = $this->zdjecie($kucharz, Media::STATUS_SECURED);
        $klucz = (string) Str::uuid7();
        $html = (string) $this->followingRedirects()->actingAs($kucharz)->from(route('cooked.photos.create', $wykonanie))
            ->post(route('cooked.photos.store', $wykonanie), [
                'media_ids' => [(string) $zdjecie->getKey()], 'klucz_wyslania' => $klucz,
            ])->assertOk()->getContent();

        $this->sprawdzOdmowe($html, $wykonanie, $zdjecie, $klucz);
        $this->assertSame(Media::STATUS_SECURED, $zdjecie->fresh()->status);
    }

    public function test_domena_odmawia_gdy_sprzatacz_przejmuje_zdjecie_po_odzyskaniu_uuid(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanie();
        $zdjecie = $this->zdjecie($kucharz);
        $ids = app(ZbierzZdjeciaWykonania::class)->handle([(string) $zdjecie->getKey()], [], $kucharz);
        $this->assertSame([(string) $zdjecie->getKey()], $ids);
        $this->przejmijZeZlymDyskiem($zdjecie);

        $blad = null;
        try {
            app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie, $ids);
        } catch (BladDlaCzlowieka $e) {
            $blad = $e;
        }
        $this->assertInstanceOf(BladDlaCzlowieka::class, $blad, 'DOLACZENIE_2883_DOMENA_SWIEZY_STAN');
        $this->assertSame(self::ODMOWA, $blad->getMessage());
        $this->assertSame([], $this->przypiete($wykonanie));
        $this->assertNull($wykonanie->fresh()->photos_added_at);
    }

    public function test_http_odmawia_po_przejeciu_miedzy_helperem_a_blokada_domeny(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanie();
        $zdjecie = $this->zdjecie($kucharz);
        $klucz = (string) Str::uuid7();
        $przejete = false;
        DB::listen(function (QueryExecuted $query) use ($zdjecie, &$przejete): void {
            if (! $przejete && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "media"')
                && str_contains($query->sql, '"owner_id"') && ! str_contains($query->sql, 'for update')
                && in_array((string) $zdjecie->getKey(), $query->bindings, true)) {
                $przejete = true;
                $this->przejmijZeZlymDyskiem($zdjecie);
            }
        });
        $html = (string) $this->followingRedirects()->actingAs($kucharz)->from(route('cooked.photos.create', $wykonanie))
            ->post(route('cooked.photos.store', $wykonanie), [
                'media_ids' => [(string) $zdjecie->getKey()], 'klucz_wyslania' => $klucz,
            ])->assertOk()->getContent();

        $this->assertTrue($przejete, 'Przeplot musi naprawdę przejąć wybrane zdjęcie.');
        $this->sprawdzOdmowe($html, $wykonanie, $zdjecie, $klucz);
    }

    public function test_nowy_uuid_i_plik_daja_po_jednym_przypieciu_a_rzeczywiste_ponowienia_sa_bezpieczne(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanie();
        $zdjecie = $this->zdjecie($kucharz);
        $akcja = app(DolaczZdjeciaDoWykonania::class);
        $this->assertSame(1, $akcja->handle($kucharz, $wykonanie, [(string) $zdjecie->getKey()]));
        $znacznik = $wykonanie->fresh()->photos_added_at;
        Carbon::setTestNow(now()->addHour());
        $this->assertSame(0, $akcja->handle($kucharz, $wykonanie, [(string) $zdjecie->getKey()]));
        $this->assertEquals($znacznik, $wykonanie->fresh()->photos_added_at);

        $klucz = (string) Str::uuid7();
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)], 'klucz_wyslania' => $klucz,
        ])->assertRedirect(route('cooked.show', $wykonanie));
        $this->assertCount(2, $this->przypiete($wykonanie));
        $plik = $wykonanie->fresh()->media()->where('media.id', '!=', $zdjecie->getKey())->firstOrFail();
        $plik->delete();
        $znacznik = $wykonanie->fresh()->photos_added_at;
        Carbon::setTestNow(now()->addHour());
        // Historyczny udany klucz pozostaje wynikiem ponowienia nawet bez Media (#2811).
        $this->assertSame(0, $akcja->handle($kucharz, $wykonanie, [(string) $plik->getKey()], $klucz));
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)], 'klucz_wyslania' => $klucz,
        ])->assertRedirect(route('cooked.show', $wykonanie));
        $this->assertSame([(string) $zdjecie->getKey()], $this->przypiete($wykonanie));
        $this->assertEquals($znacznik, $wykonanie->fresh()->photos_added_at);
        $this->assertSame([$klucz], $wykonanie->fresh()->photo_submission_keys);
    }

    public function test_mieszany_wybor_dolacza_dozwolone_zdjecie_bez_wskrzeszenia_odrzuconych(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanie();
        $dobre = $this->zdjecie($kucharz);
        $usuniete = $this->zdjecie($kucharz, Media::STATUS_DELETED);
        $zabezpieczone = $this->zdjecie($kucharz, Media::STATUS_SECURED);
        $klucz = (string) Str::uuid7();
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'media_ids' => [(string) $usuniete->getKey(), (string) $dobre->getKey(), (string) $zabezpieczone->getKey()],
            'klucz_wyslania' => $klucz,
        ])->assertRedirect(route('cooked.show', $wykonanie))->assertSessionHasNoErrors();

        $this->assertSame([(string) $dobre->getKey()], $this->przypiete($wykonanie));
        $this->assertNotNull($wykonanie->fresh()->photos_added_at);
        $this->assertSame([$klucz], $wykonanie->fresh()->photo_submission_keys);
        $this->assertSame(Media::STATUS_DELETED, $usuniete->fresh()->status);
        $this->assertSame(Media::STATUS_SECURED, $zabezpieczone->fresh()->status);
    }

    public function test_odmowa_limitu_zachowuje_poprawny_wybor_i_klucz_a_odrzuca_niedostepny_uuid(): void
    {
        config(['kuking.media.max_per_post' => 2]);
        [$kucharz, $wykonanie] = $this->wykonanie();
        $dobre = $this->zdjecie($kucharz);
        $usuniete = $this->zdjecie($kucharz, Media::STATUS_DELETED);
        $inne = [$this->zdjecie($kucharz), $this->zdjecie($kucharz)];
        $klucz = (string) Str::uuid7();
        $przypieto = false;
        DB::listen(function (QueryExecuted $query) use ($kucharz, $wykonanie, $dobre, $inne, &$przypieto): void {
            if (! $przypieto && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "media"')
                && str_contains($query->sql, '"owner_id"') && ! str_contains($query->sql, 'for update')
                && in_array((string) $dobre->getKey(), $query->bindings, true)) {
                $przypieto = true;
                $this->assertSame(2, app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie,
                    array_map(fn (Media $media): string => (string) $media->getKey(), $inne)));
            }
        });
        $html = (string) $this->followingRedirects()->actingAs($kucharz)->from(route('cooked.photos.create', $wykonanie))
            ->post(route('cooked.photos.store', $wykonanie), [
                'media_ids' => [(string) $usuniete->getKey(), (string) $dobre->getKey()], 'klucz_wyslania' => $klucz,
            ])->assertOk()->getContent();

        $this->assertTrue($przypieto);
        $this->assertStringContainsString('To wykonanie ma już 2 zdjęć', $html);
        $this->assertStringContainsString('Twoje zdjęcia są zachowane', $html);
        $this->assertStringContainsString('name="media_ids[]" value="'.$dobre->getKey().'"', $html);
        $this->assertStringNotContainsString('name="media_ids[]" value="'.$usuniete->getKey().'"', $html, 'DOLACZENIE_2883_BEZ_FALSZYWEGO_ZACHOWANIA');
        $this->assertStringContainsString('name="klucz_wyslania" value="'.$klucz.'"', $html);
        $this->assertSame(array_map(fn (Media $media): string => (string) $media->getKey(), $inne), $this->przypiete($wykonanie));
        $this->assertSame([], $wykonanie->fresh()->photo_submission_keys);
    }
}
