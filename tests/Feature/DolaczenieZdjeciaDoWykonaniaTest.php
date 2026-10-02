<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\DolaczZdjeciaDoWykonania;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dołączenie zdjęcia do już zapisanego „Ugotowałem” (#2500, V2, D-333 — paczka E).
 *
 * Czas zamrożony na 7 października 2026. Pomiary idą przez HTTP (prawdziwe
 * przekierowania i końcowy HTML) albo przez akcję domenową wołaną wprost tam,
 * gdzie sprawdzamy stan świeży pod blokadą, niezależny od trasy.
 */
final class DolaczenieZdjeciaDoWykonaniaTest extends TestCase
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
        putenv('KUKING_ROLLBACK_KASUJE_ZNACZNIKI_ZDJEC');
        parent::tearDown();
    }

    private function przepis(User $autor, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);
    }

    private function wykonanie(User $kucharz, Recipe $przepis, array $atrybuty = []): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'cooked_at' => now()->subDay(),
            ...$atrybuty,
        ]);
    }

    private function zdjecie(User $wlasciciel, array $atrybuty = []): Media
    {
        return Media::factory()->create(['owner_id' => $wlasciciel->getKey(), ...$atrybuty]);
    }

    private function przypnij(CookedEvent $wykonanie, Media $zdjecie, int $pozycja): void
    {
        $wykonanie->media()->attach($zdjecie->getKey(), ['position' => $pozycja]);
    }

    /** @return list<string> */
    private function przypiete(CookedEvent $wykonanie): array
    {
        return DB::table('cooked_event_media')->where('cooked_event_id', $wykonanie->getKey())
            ->orderBy('position')->pluck('media_id')->map(fn ($id): string => (string) $id)->all();
    }

    public function test_kucharz_dolacza_zdjecie_bez_nowego_wykonania_powiadomienia_i_zmiany_daty(): void
    {
        $kucharz = $this->user('kucharka');
        $autor = $this->user('autor');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis, ['note' => 'Wyszło pysznie']);
        $kiedy = $wykonanie->cooked_at->toIso8601String();
        $wersja = $wykonanie->recipe_version_id;
        $komentarz = new Comment;
        $komentarz->forceFill([
            'author_id' => $autor->getKey(), 'cooked_event_id' => $wykonanie->getKey(), 'body' => 'Gratulacje!',
            'status' => Comment::STATUS_PUBLISHED,
        ])->save();

        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
        ])->assertRedirect(route('cooked.show', $wykonanie))->assertSessionHas('status');

        $po = CookedEvent::query()->findOrFail($wykonanie->getKey());
        $this->assertCount(1, $this->przypiete($po), 'Zdjęcie nie zostało dołączone.');
        $this->assertNotNull($po->photos_added_at);
        $this->assertSame($kiedy, $po->cooked_at->toIso8601String(), 'Data gotowania nie może się zmienić.');
        $this->assertSame($wersja, $po->recipe_version_id);
        $this->assertSame('Wyszło pysznie', $po->note);
        $this->assertSame(1, CookedEvent::query()->count(), 'DOLACZENIE_2500_NOWE_WYKONANIE: powstało drugie wykonanie.');
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_COOKED)->count(), 'DOLACZENIE_2500_POWIADOMIENIE: dołączenie zdjęcia powiadomiło autora.');
        $this->assertSame(1, Comment::query()->where('cooked_event_id', $wykonanie->getKey())->count(), 'Rozmowa pod wykonaniem została.');

        $html = (string) $this->actingAs($autor)->get(route('cooked.show', $wykonanie))->assertOk()->getContent();
        $this->assertStringContainsString('Zdjęcie uzupełnione', $html);
        $this->assertStringContainsString('7 października 2026', $html);
    }

    public function test_dotychczasowe_zdjecia_zostaja_a_nowe_staje_na_koncu(): void
    {
        $kucharz = $this->user('kucharka');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($this->user('autor')));
        $a = $this->zdjecie($kucharz);
        $b = $this->zdjecie($kucharz);
        $this->przypnij($wykonanie, $a, 0);
        $this->przypnij($wykonanie, $b, 1);
        $nowe = $this->zdjecie($kucharz);

        $this->assertSame(1, app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie, [(string) $nowe->getKey()]));

        $this->assertSame([(string) $a->getKey(), (string) $b->getKey(), (string) $nowe->getKey()], $this->przypiete($wykonanie));

        // Ponowienie na poziomie akcji (np. dwie karty z tym samym wyborem): zdjęcie nie jest przypięte drugi raz.
        $this->assertSame(0, app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie, [(string) $nowe->getKey()]), 'DOLACZENIE_2500_PONOWIENIE: zdjęcie przypięte drugi raz.');
        $this->assertCount(3, $this->przypiete($wykonanie));
    }

    public function test_limit_liczy_zdjecia_juz_przypiete_i_nowe_razem(): void
    {
        config(['kuking.media.max_per_post' => 2]);
        $kucharz = $this->user('kucharka');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($this->user('autor')));
        $this->przypnij($wykonanie, $this->zdjecie($kucharz), 0);
        $dwa = [(string) $this->zdjecie($kucharz)->getKey(), (string) $this->zdjecie($kucharz)->getKey()];

        try {
            app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie, $dwa);
            $this->fail('DOLACZENIE_2500_LIMIT: limit nie liczył zdjęć już przypiętych.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('To wykonanie ma już 1 zdjęć', $e->getMessage());
        }
        $this->assertCount(1, $this->przypiete($wykonanie));
        $this->assertNull($wykonanie->fresh()?->photos_added_at);

        // Jedno wchodzi, drugie (kolejna, "równoległa" wysyłka ze starego formularza) już nie.
        $this->assertSame(1, app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie, [$dwa[0]]));
        $this->expectException(BladDlaCzlowieka::class);
        app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie, [$dwa[1]]);
    }

    public function test_limit_przez_http_wraca_z_bledem_przy_polu_i_zachowuje_przyjete_zdjecia(): void
    {
        config(['kuking.media.max_per_post' => 2]);
        $kucharz = $this->user('kucharka');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($this->user('autor')));
        $this->przypnij($wykonanie, $this->zdjecie($kucharz), 0);
        $zachowane = $this->zdjecie($kucharz);

        $this->actingAs($kucharz)->from(route('cooked.photos.create', $wykonanie))->post(route('cooked.photos.store', $wykonanie), [
            'media_ids' => [(string) $zachowane->getKey()],
            'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)],
        ])->assertRedirect(route('cooked.photos.create', $wykonanie))->assertSessionHasErrors('photos');

        $this->assertCount(1, $this->przypiete($wykonanie));
        $ekran = (string) $this->actingAs($kucharz)->withSession(['_old_input' => ['media_ids' => [(string) $zachowane->getKey()]]])
            ->get(route('cooked.photos.create', $wykonanie))->assertOk()->getContent();
        $this->assertStringContainsString('name="media_ids[]" value="'.$zachowane->getKey().'"', $ekran);
    }

    public function test_obcy_autor_przepisu_i_moderator_nie_dolacza_zdjec(): void
    {
        $kucharz = $this->user('kucharka');
        $autor = $this->user('autor');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));
        $moderator = $this->user('moderatorka', ['role' => User::ROLE_MODERATOR]);

        foreach ([$this->user('obca'), $autor, $moderator] as $ktos) {
            $obce = $this->zdjecie($ktos);
            $this->assertSame(403, $this->actingAs($ktos)->get(route('cooked.photos.create', $wykonanie))->getStatusCode(), 'DOLACZENIE_2500_PODMIANA_UUID: cudze konto otworzyło formularz dołączania zdjęcia.');
            $odpowiedz = $this->actingAs($ktos)->post(route('cooked.photos.store', $wykonanie), ['media_ids' => [(string) $obce->getKey()]]);
            $this->assertSame(403, $odpowiedz->getStatusCode(), 'DOLACZENIE_2500_PODMIANA_UUID: cudze konto dołączyło zdjęcie przez UUID.');
        }
        $this->assertSame([], $this->przypiete($wykonanie));

        auth()->logout();
        $this->get(route('cooked.photos.create', $wykonanie))->assertRedirect(route('login'));
    }

    public function test_okno_czasu_sankcja_konta_i_niedostepny_przepis_zamykaja_dolaczanie(): void
    {
        $kucharz = $this->user('kucharka');
        $autor = $this->user('autor');
        $przepis = $this->przepis($autor);

        $stare = $this->wykonanie($kucharz, $przepis, ['cooked_at' => now()->subDays(8)]);
        $this->actingAs($kucharz)->get(route('cooked.photos.create', $stare))->assertForbidden();

        $swieze = $this->wykonanie($kucharz, $przepis, ['cooked_at' => now()->subDays(6)]);
        $this->actingAs($kucharz)->get(route('cooked.photos.create', $swieze))->assertOk();

        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->actingAs($kucharz)->get(route('cooked.photos.create', $swieze))->assertForbidden();
        $przepis->forceFill(['visibility' => 'public'])->save();

        $kucharz->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($kucharz->refresh())->get(route('cooked.photos.create', $swieze))->assertForbidden();
    }

    public function test_sankcja_albo_utrata_przepisu_miedzy_otwarciem_a_zapisem_blokuje_zapis_pod_blokada(): void
    {
        $kucharz = $this->user('kucharka');
        $przepis = $this->przepis($this->user('autor'));
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $zdjecie = $this->zdjecie($kucharz);

        // Modele podane do akcji są STARE (jak formularz otwarty godzinę temu); stan w bazie się zmienił.
        $kucharz->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $staryKucharz = User::query()->findOrFail($kucharz->getKey());
        $staryKucharz->status = User::STATUS_ACTIVE;

        try {
            app(DolaczZdjeciaDoWykonania::class)->handle($staryKucharz, $wykonanie, [(string) $zdjecie->getKey()]);
            $this->fail('DOLACZENIE_2500_SWIEZY_STAN: zawieszone konto dołączyło zdjęcie na starym stanie.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('Nic nie zostało zapisane', $e->getMessage());
        }
        $this->assertSame([], $this->przypiete($wykonanie));

        $kucharz->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $przepis->delete();
        $this->expectException(BladDlaCzlowieka::class);
        app(DolaczZdjeciaDoWykonania::class)->handle($kucharz->refresh(), $wykonanie, [(string) $zdjecie->getKey()]);
    }

    public function test_cudze_zabezpieczone_i_przypiete_gdzie_indziej_zdjecia_nie_zostaja_przejete(): void
    {
        $kucharz = $this->user('kucharka');
        $obcy = $this->user('obcy');
        $przepis = $this->przepis($this->user('autor'));
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $inne = $this->wykonanie($kucharz, $przepis);

        $cudze = $this->zdjecie($obcy);
        $zabezpieczone = $this->zdjecie($kucharz, ['status' => Media::STATUS_SECURED]);
        $naInnym = $this->zdjecie($kucharz);
        $this->przypnij($inne, $naInnym, 0);
        $wlasne = $this->zdjecie($kucharz);

        $ile = app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie, [
            (string) $cudze->getKey(), (string) $zabezpieczone->getKey(), (string) $naInnym->getKey(), (string) $wlasne->getKey(),
        ]);

        $this->assertSame(1, $ile, 'DOLACZENIE_2500_WLASNOSC_MEDIOW: przypięto cudze, zabezpieczone albo cudzo-przypięte zdjęcie.');
        $this->assertSame([(string) $wlasne->getKey()], $this->przypiete($wykonanie), 'DOLACZENIE_2500_WLASNOSC_MEDIOW: przypięto cudze, zabezpieczone albo cudzo-przypięte zdjęcie.');
        $this->assertSame([(string) $naInnym->getKey()], $this->przypiete($inne));
    }

    public function test_ponowienie_tej_samej_wysylki_nie_przypina_zdjecia_drugi_raz(): void
    {
        $kucharz = $this->user('kucharka');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($this->user('autor')));
        $zdjecie = $this->zdjecie($kucharz);

        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), ['media_ids' => [(string) $zdjecie->getKey()]])
            ->assertRedirect(route('cooked.show', $wykonanie));
        $pierwsze = $wykonanie->fresh()?->photos_added_at;

        Carbon::setTestNow(now()->addHour());
        $this->actingAs($kucharz)->post(route('cooked.photos.store', $wykonanie), ['media_ids' => [(string) $zdjecie->getKey()]])
            ->assertRedirect(route('cooked.show', $wykonanie))
            ->assertSessionHas('status', 'To zdjęcie jest już przy Twoim wykonaniu. Niczego nie dopisaliśmy drugi raz.');

        $this->assertCount(1, $this->przypiete($wykonanie));
        $this->assertEquals($pierwsze, $wykonanie->fresh()?->photos_added_at, 'Ponowienie nie przesuwa znacznika.');
    }

    public function test_bez_wybranego_zdjecia_jest_blad_po_polsku_przy_polu(): void
    {
        $kucharz = $this->user('kucharka');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($this->user('autor')));

        $this->actingAs($kucharz)->from(route('cooked.photos.create', $wykonanie))->post(route('cooked.photos.store', $wykonanie), [])
            ->assertRedirect(route('cooked.photos.create', $wykonanie))
            ->assertSessionHasErrors(['photos' => 'Wybierz zdjęcie, które chcesz dołączyć do tego wykonania.']);
        $this->assertNull($wykonanie->fresh()?->photos_added_at);
    }

    public function test_ekran_mowi_ze_to_nie_drugie_gotowanie_i_wykonanie_ma_przycisk_tylko_w_oknie(): void
    {
        $kucharz = $this->user('kucharka');
        $przepis = $this->przepis($this->user('autor'));
        $swieze = $this->wykonanie($kucharz, $przepis);
        $stare = $this->wykonanie($kucharz, $przepis, ['cooked_at' => now()->subDays(20)]);

        $ekran = (string) $this->actingAs($kucharz)->get(route('cooked.photos.create', $swieze))->assertOk()->getContent();
        $this->assertStringContainsString('nie zgłasza drugiego gotowania', $ekran);
        $this->assertStringContainsString('przez 7 dni', $ekran);

        $this->assertStringContainsString(route('cooked.photos.create', $swieze), (string) $this->actingAs($kucharz)->get(route('cooked.show', $swieze))->getContent());
        $this->assertStringNotContainsString(route('cooked.photos.create', $stare), (string) $this->actingAs($kucharz)->get(route('cooked.show', $stare))->getContent());
    }

    public function test_paczka_danych_niesie_date_uzupelnienia(): void
    {
        $kucharz = $this->user('kucharka');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($this->user('autor')));
        $zwykle = $this->wykonanie($kucharz, $this->przepis($this->user('autor2')));
        app(DolaczZdjeciaDoWykonania::class)->handle($kucharz, $wykonanie, [(string) $this->zdjecie($kucharz)->getKey()]);

        $paczka = app(CollectUserExportData::class)->handle($kucharz, new ExportPhotoPlan($kucharz), now())['ugotowalem'];
        $pola = array_column($paczka, 'zdjecie_uzupelnione');

        $this->assertCount(2, $pola);
        $this->assertNotNull(array_values(array_filter($pola))[0] ?? null);
        $this->assertContains(null, $pola);
        unset($zwykle);
    }

    public function test_cofniecie_migracji_odmawia_przy_znacznikach_i_przechodzi_bez_nich(): void
    {
        $migracja = require base_path('database/migrations/2026_10_07_120000_add_photos_added_at_to_cooked_events.php');
        $kucharz = $this->user('kucharka');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($this->user('autor')));

        // Kontrola dodatnia: bez znaczników kolumna schodzi bez pytania i wraca.
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('cooked_events', 'photos_added_at'));
        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('cooked_events', 'photos_added_at'));

        CookedEvent::query()->whereKey($wykonanie->getKey())->update(['photos_added_at' => now()]);
        try {
            $migracja->down();
            $this->fail('Rollback zgubił znacznik uzupełnionego zdjęcia bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Liczba wykonań z dołączonym później zdjęciem', $e->getMessage());
            $this->assertStringContainsString('photos_added_at IS NOT NULL): 1', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('cooked_events', 'photos_added_at'));
    }

    public function test_znacznik_nie_jest_masowo_przypisywalny(): void
    {
        $this->assertNotContains('photos_added_at', (new CookedEvent)->getFillable());
    }
}
