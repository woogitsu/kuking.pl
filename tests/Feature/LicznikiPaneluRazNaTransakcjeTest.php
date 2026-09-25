<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\KolejkiPanelu;
use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Domain\Moderation\UnansweredContent;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Liczniki panelu nie przeliczają całej historii przy każdym zapisie
 * `Report` (audyt B4 W3).
 *
 * Przed poprawką każdy zapis oznaczenia odpalał pełne `przelicz()`: cztery
 * `COUNT(*)` i trzy anti-joiny „Bez odpowiedzi" NA KAŻDEGO gospodarza.
 * „To nic takiego" przy fali spamu robiło to w pętli, pod blokadą grupy.
 * Teraz hak liczy cztery tanie liczby, po commicie, raz na transakcję;
 * mapę „Bez odpowiedzi" liczy tylko harmonogram.
 */
class LicznikiPaneluRazNaTransakcjeTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiele_zapisow_w_transakcji_to_jedno_tanie_przeliczenie(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autorsygnalow');
        $wpisy = Post::factory()->count(5)->create(['author_id' => $autor->getKey()]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        DB::transaction(function () use ($wpisy, $autor): void {
            foreach ($wpisy as $wpis) {
                $this->oznaczenie($wpis, $autor);
            }
        });

        $zapytania = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(1, $this->ile($zapytania, 'from "appeals"'), 'Liczniki przeliczone więcej niż raz na transakcję.');
        $this->assertSame(0, $this->ile($zapytania, 'queue_comments'), 'Hak liczy drogą kolejkę „Bez odpowiedzi".');

        $this->assertSame(5, app(KolejkiPanelu::class)->liczby($moderator)['sygnaly']);
    }

    public function test_hak_zostawia_mape_bez_odpowiedzi_z_harmonogramu(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autorwpisu');
        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHours(8),
        ]);

        app(KolejkiPanelu::class)->przelicz();
        $this->assertSame(1, app(KolejkiPanelu::class)->liczby($moderator)['bez_odpowiedzi']);

        $this->oznaczenie(Post::factory()->create(['author_id' => $autor->getKey()]), $autor);

        $liczby = app(KolejkiPanelu::class)->liczby($moderator);
        $this->assertSame(1, $liczby['bez_odpowiedzi'], 'Tanie przeliczenie zgubiło mapę gospodarzy.');
        $this->assertSame(1, $liczby['sygnaly']);
    }

    public function test_wycofana_transakcja_nie_blokuje_kolejnych_odswiezen(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autorwycofany');

        try {
            DB::transaction(function () use ($autor): void {
                $this->oznaczenie(Post::factory()->create(['author_id' => $autor->getKey()]), $autor);

                throw new RuntimeException('wycofaj');
            });
        } catch (RuntimeException) {
        }

        $this->oznaczenie(Post::factory()->create(['author_id' => $autor->getKey()]), $autor);

        $this->assertSame(1, app(KolejkiPanelu::class)->liczby($moderator)['sygnaly'],
            'Po wycofanej transakcji licznik przestał się odświeżać.');
    }

    public function test_zamkniecie_grupy_to_jeden_update_i_jedno_przeliczenie(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('hurtowniksygnalow');
        foreach (Post::factory()->count(6)->create(['author_id' => $autor->getKey()]) as $wpis) {
            $this->oznaczenie($wpis, $autor);
        }
        $this->assertSame(6, app(KolejkiPanelu::class)->liczby($moderator)['sygnaly']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($moderator)
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertSessionHasNoErrors();

        $zapytania = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(1, $this->ile($zapytania, 'update "reports"'), 'Oznaczenia zamykane po jednym.');
        $this->assertSame(1, $this->ile($zapytania, 'from "appeals"'), 'Liczniki przeliczane przy każdym oznaczeniu.');

        $this->assertSame(6, Report::query()->where('status', Report::STATUS_REJECTED)->whereNotNull('resolved_at')
            ->where('resolved_by', $moderator->getKey())->count());
        $this->assertSame(0, app(KolejkiPanelu::class)->liczby($moderator)['sygnaly']);
    }

    public function test_kolejka_bez_odpowiedzi_ma_okno_czasowe(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autorstarych');
        $swiezy = $this->wpisSprzed($autor, UnansweredContent::OKNO_DNI - 1);
        $stary = $this->wpisSprzed($autor, UnansweredContent::OKNO_DNI + 1);

        $kolejka = app(UnansweredContent::class);
        $wKolejce = $kolejka->posts($moderator)->pluck('id')->all();

        $this->assertContains($swiezy->getKey(), $wKolejce);
        $this->assertNotContains($stary->getKey(), $wKolejce, 'Wpis sprzed okna nadal wisi w kolejce.');

        // Prawo do odpowiedzi z listy nie ma okna.
        $this->assertTrue($kolejka->eligiblePosts($moderator)->whereKey($stary->getKey())->exists());
    }

    private function wpisSprzed(User $autor, int $dni): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDays($dni),
        ]);
    }

    private function oznaczenie(Post $wpis, User $autor): Report
    {
        return Report::create([
            'source' => Report::SOURCE_AUTOMAT,
            'autor_tresci_id' => $autor->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => WykrywaczSygnalow::KOD_WZORZEC,
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /** @param list<string> $zapytania */
    private function ile(array $zapytania, string $fragment): int
    {
        return count(array_filter($zapytania, fn (string $q): bool => str_contains($q, $fragment)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }
}
