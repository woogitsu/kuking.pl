<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\MetrykiDoboru;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1814 (D-281) — metryki doboru bez profilowania: agregaty
 * z istniejących tabel, wskaźnik zastępczy trzeciego progu, tylko admin.
 *
 * Kontrole ujemne (sprawdzone przy pisaniu):
 *  - `break` po `page_size` innych autorach zdjęty z `bezPierwszejStrony()` →
 *    `test_wskaznik_zastepczy_liczy_czas_na_pierwszej_stronie` (nikt nie wypada);
 *  - warunek `+ interval '24 hours'` zdjęty z odpowiedzi komentarzem →
 *    `test_pierwsze_wpisy_z_odpowiedzia…` liczy spóźniony komentarz;
 *  - `przegladajMetryki` zwracające `true` dla moderatora → `test_tylko_admin…`.
 */
class MetrykiDoboruTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, CarbonImmutable $kiedy, array $inne = []): Post
    {
        return Post::factory()->create(['author_id' => $autor->id, 'published_at' => $kiedy, ...$inne]);
    }

    private function pierwszy(Post $wpis): void
    {
        DB::table('first_post_events')->insert(['author_id' => $wpis->author_id, 'post_id' => $wpis->id]);
    }

    /** @return array<string, mixed> */
    private function metryki(CarbonImmutable $teraz): array
    {
        return app(MetrykiDoboru::class)->wszystkie($teraz);
    }

    public function test_tylko_admin_widzi_panel_i_bez_nazw_osob(): void
    {
        $autorka = $this->user('widoczna_autorka');
        $this->wpis($autorka, CarbonImmutable::now()->subDays(2));

        $this->get('/admin/metryki')->assertRedirect(route('login'));
        // Zwykłe konto nie widzi, że panel istnieje (middleware `moderator`).
        $this->actingAs($this->user('zwykla'))->get('/admin/metryki')->assertNotFound();
        $this->actingAs($this->moderator())->get('/admin/metryki')->assertForbidden();

        $this->actingAs($this->admin())->get('/admin/metryki')->assertOk()
            ->assertSee('Metryki doboru')
            ->assertSee('Progi rewizji')
            ->assertDontSee('widoczna_autorka');
    }

    public function test_pierwsze_wpisy_z_odpowiedzia_czlowieka_w_24_godziny(): void
    {
        $teraz = CarbonImmutable::now();
        $gosc = $this->user('odpowiada');
        $zOdpowiedzia = $this->wpis($this->user('autor1'), $teraz->subDays(3));
        $spozniona = $this->wpis($this->user('autor2'), $teraz->subDays(3));
        $bez = $this->wpis($this->user('autor3'), $teraz->subDays(3));
        $zaMlody = $this->wpis($this->user('autor4'), $teraz->subHours(5));
        foreach ([$zOdpowiedzia, $spozniona, $bez, $zaMlody] as $w) {
            $this->pierwszy($w);
        }
        Comment::factory()->create(['post_id' => $zOdpowiedzia->id, 'author_id' => $gosc->id, 'created_at' => $teraz->subDays(3)->addHours(2)]);
        Comment::factory()->create(['post_id' => $spozniona->id, 'author_id' => $gosc->id, 'created_at' => $teraz->subDays(3)->addHours(30)]);
        // Własny komentarz autora nie jest odpowiedzią.
        Comment::factory()->create(['post_id' => $bez->id, 'author_id' => $bez->author_id, 'created_at' => $teraz->subDays(3)->addHour()]);

        $wynik = $this->metryki($teraz)['pierwsze_wpisy_z_odpowiedzia_24h'];
        $this->assertSame(['mianownik' => 3, 'licznik' => 1, 'procent' => 33.3], $wynik);
    }

    public function test_autorzy_ponownie_w_28_dni(): void
    {
        $teraz = CarbonImmutable::now();
        $wraca = $this->user('wraca');
        $nieWraca = $this->user('nie_wraca');
        $this->pierwszy($p1 = $this->wpis($wraca, $teraz->subDays(40)));
        $this->wpis($wraca, $teraz->subDays(30));
        $this->pierwszy($this->wpis($nieWraca, $teraz->subDays(40)));
        // Za świeży na kohortę.
        $this->pierwszy($this->wpis($this->user('swiezy'), $teraz->subDays(5)));

        $this->assertSame(['mianownik' => 2, 'licznik' => 1, 'procent' => 50.0], $this->metryki($teraz)['autorzy_ponownie_28_dni']);
    }

    public function test_udzial_najaktywniejszych_autorzy_dziennie_i_tagi(): void
    {
        $teraz = CarbonImmutable::now()->setTimezone('Europe/Warsaw')->setTime(12, 0)->utc();
        $czesta = $this->user('czesta');
        for ($i = 1; $i <= 9; $i++) {
            $this->wpis($czesta, $teraz->subDays($i));
        }
        $inni = [];
        for ($i = 1; $i <= 9; $i++) {
            $inni[] = $this->wpis($this->user("osoba{$i}"), $teraz->subDays(1));
        }
        // Prywatny wpis nie liczy się nigdzie.
        $this->wpis($this->user('prywatna'), $teraz->subDays(1), ['visibility' => Post::VISIBILITY_PRIVATE]);
        $tag = Tag::create(['slug' => 'zupy', 'name' => 'Zupy', 'normalized_name' => 'zupy']);
        foreach (array_slice($inni, 0, 6) as $w) {
            $tag->posts()->attach($w->id, ['position' => 0]);
        }

        $m = $this->metryki($teraz);
        // 10 autorów → 1 najaktywniejsza, 9 z 18 wpisów.
        $this->assertSame(['autorow' => 10, 'wpisow' => 18, 'najaktywniejszych' => 1, 'procent' => 50.0], $m['udzial_najaktywniejszych_10_procent']);
        $wczoraj = $teraz->setTimezone('Europe/Warsaw')->subDay()->toDateString();
        $this->assertSame(10, $m['autorzy_dziennie']['dni'][$wczoraj]);
        $this->assertCount(28, $m['autorzy_dziennie']['dni']);
        $this->assertSame(round((10 + 8) / 28, 1), $m['autorzy_dziennie']['srednia_28_dni']);
        $this->assertSame(['mianownik' => 18, 'licznik' => 6, 'procent' => 33.3], $m['publiczne_z_tagiem']);
    }

    public function test_wskaznik_zastepczy_liczy_czas_na_pierwszej_stronie(): void
    {
        config(['kuking.feed.page_size' => 2, 'kuking.metryki.minut_na_pierwszej_stronie' => 60]);
        $teraz = CarbonImmutable::now();
        $start = $teraz->subDays(4);
        $a = $this->user('osoba_a');
        $b = $this->user('osoba_b');
        $c = $this->user('osoba_c');
        // A spada po 20 minutach (po nim B i C = dwie inne osoby); B stoi
        // do dziś (po nim tylko C); C tak samo.
        $this->wpis($a, $start);
        $this->wpis($b, $start->addMinutes(10));
        $this->wpis($c, $start->addMinutes(20));
        // Za świeży, żeby autor wszedł do mianownika — ale spycha innych.
        $this->wpis($this->user('osoba_d'), $teraz->subHours(2));

        $wynik = $this->metryki($teraz)['bez_pierwszej_strony_7_dni'];
        $this->assertSame(3, $wynik['mianownik']);
        $this->assertSame(1, $wynik['licznik']);
        $this->assertSame(33.3, $wynik['procent']);

        // Nowszy wpis tej samej osoby zastępuje starszy — czas się sumuje,
        // a nie liczy podwójnie.
        $this->actingAs($this->admin())->get('/admin/metryki')->assertOk()->assertSee('33,3%');
    }

    public function test_nie_czyta_ukryc_ani_reakcji(): void
    {
        foreach ([app_path('Domain/Analytics/MetrykiDoboru.php'), app_path('Http/Controllers/Admin/MetrykiController.php'), resource_path('views/pages/admin/metryki.blade.php')] as $plik) {
            $kod = (string) file_get_contents($plik);
            $this->assertDoesNotMatchRegularExpression('/\bhides\b|Models\\\\Hide\b|post_reactions|PostReaction|Reakcje\\\\/', $kod, "{$plik}: ukrycia i reakcje nie są źródłem analityki (D-278, D-280).");
        }
    }
}
