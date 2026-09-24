<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\DailyPick;
use App\Models\HeroPick;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresja #1329: wybór redakcyjny tablicy dnia i kolażu zmienia się RAZEM
 * z wpisem `audit_log` albo wcale (D-249, klasa 1).
 *
 * Awarię dziennika wstrzykujemy zdarzeniem `creating` modelu — to ten sam
 * `AuditLogEntry::create()`, który woła `record()`. Po nieudanym żądaniu
 * sprawdzamy OBIE tabele: wybór sprzed żądania musi stać nietknięty.
 * Kontrolą dodatnią jest ten sam scenariusz bez awarii — wtedy wybór
 * naprawdę się zmienia i powstaje dokładnie jeden wpis.
 */
class WyborRedakcjiAudytWTransakcjiTest extends TestCase
{
    use RefreshDatabase;

    private User $gospodarz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gospodarz = $this->moderator();
    }

    public function test_awaria_audytu_cofa_zastapienie_tablicy_dnia(): void
    {
        [$stary, $nowy] = Post::factory()->count(2)->create();
        $this->pozycjaTablicy($stary);

        $this->awariaAudytu();
        $this->actingAs($this->gospodarz)->put(route('admin.daily-board'), ['wpisy' => [$nowy->id]])->assertStatus(500);

        $this->assertSame([$stary->id], DailyPick::query()->pluck('subject_id')->all());
        $this->assertSame(0, $this->wpisyWyboru());
    }

    public function test_awaria_audytu_cofa_wyczyszczenie_tablicy_dnia(): void
    {
        $stary = Post::factory()->create();
        $this->pozycjaTablicy($stary);

        $this->awariaAudytu();
        $this->actingAs($this->gospodarz)->delete(route('admin.daily-board'))->assertStatus(500);

        $this->assertSame([$stary->id], DailyPick::query()->pluck('subject_id')->all());
        $this->assertSame(0, $this->wpisyWyboru());
    }

    public function test_awaria_audytu_cofa_zastapienie_kolazu(): void
    {
        [$staryWpis, $stare] = $this->wpisZeZdjeciem();
        [, $nowe] = $this->wpisZeZdjeciem();
        $this->pozycjaKolazu($staryWpis, $stare);

        $this->awariaAudytu();
        $this->actingAs($this->gospodarz)->put(route('admin.hero-kolaz'), ['zdjecia' => [$nowe->id]])->assertStatus(500);

        $this->assertSame([(string) $stare->id], HeroPick::query()->pluck('media_id')->map(fn ($id) => (string) $id)->all());
        $this->assertSame(0, $this->wpisyWyboru());
    }

    public function test_awaria_audytu_cofa_wyczyszczenie_kolazu(): void
    {
        [$staryWpis, $stare] = $this->wpisZeZdjeciem();
        $this->pozycjaKolazu($staryWpis, $stare);

        $this->awariaAudytu();
        $this->actingAs($this->gospodarz)->delete(route('admin.hero-kolaz'))->assertStatus(500);

        $this->assertSame(1, HeroPick::query()->count());
        $this->assertSame(0, $this->wpisyWyboru());
    }

    /** Kontrola dodatnia: bez awarii te same żądania zmieniają wybór i zostawiają jeden wpis. */
    public function test_bez_awarii_kazda_zmiana_ma_jeden_wpis_audytu(): void
    {
        [$stary, $nowy] = Post::factory()->count(2)->create();
        $this->pozycjaTablicy($stary);
        [, $zdjecie] = $this->wpisZeZdjeciem();

        $this->actingAs($this->gospodarz)->put(route('admin.daily-board'), ['wpisy' => [$nowy->id]])->assertRedirect();
        $this->assertSame([$nowy->id], DailyPick::query()->pluck('subject_id')->all());

        $this->actingAs($this->gospodarz)->put(route('admin.hero-kolaz'), ['zdjecia' => [$zdjecie->id]])->assertRedirect();
        $this->assertSame(1, HeroPick::query()->count());

        $this->actingAs($this->gospodarz)->delete(route('admin.hero-kolaz'))->assertRedirect();
        $this->assertSame(0, HeroPick::query()->count());

        foreach (['daily_board.updated', 'hero_kolaz.updated', 'hero_kolaz.cleared'] as $akcja) {
            $this->assertSame(1, AuditLogEntry::query()->where('action', $akcja)->where('actor_id', $this->gospodarz->id)->count(), $akcja);
        }
    }

    public function test_wyczyszczenie_tablicy_zostawia_wpis_z_gospodarzem_i_liczba_usunietych(): void
    {
        $this->pozycjaTablicy(Post::factory()->create());
        $this->pozycjaTablicy(Post::factory()->create());

        $this->actingAs($this->gospodarz)->delete(route('admin.daily-board'))
            ->assertSessionHas('status', 'Wyczyszczone. Tablica dobierze treści sama.');

        $this->assertSame(0, DailyPick::query()->count());
        $wpis = AuditLogEntry::query()->where('action', 'daily_board.cleared')->sole();
        $this->assertSame($this->gospodarz->id, $wpis->actor_id);
        $this->assertSame(['usunietych' => 2], $wpis->metadata);
    }

    /** Pusta tablica: kliknięcie „Wyczyść" to wciąż decyzja gospodarza — ślad z zerem. */
    public function test_wyczyszczenie_pustej_tablicy_tez_zostawia_wpis(): void
    {
        $this->actingAs($this->gospodarz)->delete(route('admin.daily-board'))->assertRedirect();

        $wpis = AuditLogEntry::query()->where('action', 'daily_board.cleared')->sole();
        $this->assertSame($this->gospodarz->id, $wpis->actor_id);
        $this->assertSame(['usunietych' => 0], $wpis->metadata);
    }

    private function wpisyWyboru(): int
    {
        return AuditLogEntry::query()->where(fn ($q) => $q->where('action', 'like', 'daily_board.%')->orWhere('action', 'like', 'hero_kolaz.%'))->count();
    }

    private function awariaAudytu(): void
    {
        AuditLogEntry::creating(static function (): never {
            throw new RuntimeException('Wstrzyknięta awaria dziennika audytu.');
        });
    }

    private function pozycjaTablicy(Post $wpis): void
    {
        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $wpis->id,
            'position' => DailyPick::query()->count(),
            'curator_id' => $this->gospodarz->id,
        ]);
    }

    private function pozycjaKolazu(Post $wpis, Media $zdjecie): void
    {
        HeroPick::create([
            'media_id' => $zdjecie->id,
            'post_id' => $wpis->id,
            'position' => 0,
            'curator_id' => $this->gospodarz->id,
        ]);
    }

    /** @return array{0: Post, 1: Media} */
    private function wpisZeZdjeciem(): array
    {
        $autor = $this->user();
        $wpis = Post::factory()->create([
            'author_id' => $autor->id,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);
        $zdjecie = Media::factory()->for($autor, 'owner')->create();
        $wpis->media()->attach($zdjecie->id, ['position' => 0]);

        return [$wpis, $zdjecie];
    }
}
