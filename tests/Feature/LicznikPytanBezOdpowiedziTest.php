<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Questions\PytaniaBezOdpowiedzi;
use App\Domain\Questions\QuestionList;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Jobs\PrzeliczPytaniaBezOdpowiedzi;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * „Czeka na odpowiedź (N)” liczone w tle, bez omijania blokad (#372,
 * decyzja właściciela 25.09.2026). Liczba gościa leży w cache, liczba
 * zalogowanego to liczba gościa z dokładną poprawką na blokady, obserwowanych
 * i własne niepubliczne pytania (`PytaniaBezOdpowiedzi`).
 */
class LicznikPytanBezOdpowiedziTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kuking.questions.enabled' => true]);
    }

    private function licznik(?User $widz, ?string $tag = null): int
    {
        return app(PytaniaBezOdpowiedzi::class)->dla($widz, $tag);
    }

    /** Tekst przycisku na stronie — to, co widzi człowiek. */
    private function naStronie(?User $widz, string $adres = '/pytania'): string
    {
        $widz === null ? $this->app['auth']->forgetGuards() : $this->actingAs($widz);
        $html = $this->get($adres)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/Czeka na odpowiedź \((\d+)\)/u', (string) $html, $m));

        return $m[0];
    }

    public function test_blokada_zmniejsza_licznik_widza_ale_nie_goscia(): void
    {
        $widz = $this->user('widz');
        $autor = $this->user('autor');
        Post::factory()->question()->create(['author_id' => $autor->id]);
        Post::factory()->question()->create();

        $this->assertSame('Czeka na odpowiedź (2)', $this->naStronie($widz));

        app(BlockUser::class)->handle($widz, $autor);

        $this->assertSame('Czeka na odpowiedź (1)', $this->naStronie($widz));
        $this->assertSame('Czeka na odpowiedź (2)', $this->naStronie(null));
    }

    public function test_blokada_w_druga_strone_tez_ukrywa_pytanie(): void
    {
        $widz = $this->user('widz');
        $autor = $this->user('autor');
        Post::factory()->question()->create(['author_id' => $autor->id]);

        app(BlockUser::class)->handle($autor, $widz);

        $this->assertSame(0, $this->licznik($widz));
        $this->assertSame(1, $this->licznik(null));
    }

    /** Dla wszystkich odpowiedziane, dla widza nie: jedyna odpowiedź jest od osoby w blokadzie. */
    public function test_odpowiedz_osoby_w_blokadzie_nie_zdejmuje_pytania_z_licznika_widza(): void
    {
        $widz = $this->user('widz');
        $odpowiadajacy = $this->user('odpowiadajacy');
        $pytanie = Post::factory()->question()->create();
        Comment::factory()->create(['post_id' => $pytanie->id, 'author_id' => $odpowiadajacy->id]);

        $this->assertSame(0, $this->licznik($widz));

        app(BlockUser::class)->handle($widz, $odpowiadajacy);

        $this->assertSame(1, $this->licznik($widz));
        $this->assertSame(0, $this->licznik(null));
    }

    public function test_nowa_odpowiedz_odswieza_licznik_bez_recznego_przeliczenia(): void
    {
        $pytanie = Post::factory()->question()->create();
        $this->assertSame('Czeka na odpowiedź (1)', $this->naStronie(null));
        $this->assertSame(1, Cache::get(PytaniaBezOdpowiedzi::KLUCZ_CACHE)['wszystkie']);

        app(PublishComment::class)->handle($this->user('pomocna'), $pytanie, 'Dodaj łyżkę octu.');

        $this->assertSame(0, Cache::get(PytaniaBezOdpowiedzi::KLUCZ_CACHE)['wszystkie']);
        $this->assertSame('Czeka na odpowiedź (0)', $this->naStronie(null));
    }

    /** Zlecenie idzie tylko za zapisem dotyczącym pytania — danie nie dokłada pracy kolejce. */
    public function test_przeliczenie_zlecane_tylko_przy_pytaniach(): void
    {
        $pytanie = Post::factory()->question()->create();
        $danie = Post::factory()->create();
        Queue::fake();

        Comment::factory()->create(['post_id' => $danie->id]);
        Queue::assertNotPushed(PrzeliczPytaniaBezOdpowiedzi::class);

        Comment::factory()->create(['post_id' => $pytanie->id]);
        Queue::assertPushed(PrzeliczPytaniaBezOdpowiedzi::class);
    }

    public function test_niepubliczne_pytania_licza_sie_tylko_temu_kto_je_widzi(): void
    {
        $widz = $this->user('widz');
        $obserwowana = $this->user('obserwowana');
        $obcy = $this->user('obcy');
        app(FollowUser::class)->handle($widz, $obserwowana);
        Post::factory()->question()->followersOnly()->create(['author_id' => $obserwowana->id]);
        Post::factory()->question()->private()->create(['author_id' => $widz->id]);

        $this->assertSame(2, $this->licznik($widz));
        $this->assertSame(0, $this->licznik($obcy));
        $this->assertSame(0, $this->licznik(null));
    }

    /**
     * Poprawka widza ma dawać DOKŁADNIE ten sam wynik co pełne zapytanie listy
     * „Czeka na odpowiedź” — dla różnych widzów i tagów naraz.
     */
    public function test_licznik_zgadza_sie_z_pelnym_zapytaniem_listy(): void
    {
        $widz = $this->user('widz');
        $zablokowany = $this->user('zablokowany');
        $obserwowana = $this->user('obserwowana');
        $odpowiadajacy = $this->user('odpowiadajacy');
        $tag = Tag::factory()->create();

        $zwykle = Post::factory()->question()->create();
        $zwykle->tags()->attach($tag);
        $odZablokowanego = Post::factory()->question()->create(['author_id' => $zablokowany->id]);
        $odZablokowanego->tags()->attach($tag);
        $odpowiedzianeTylkoPrzezZablokowanego = Post::factory()->question()->create();
        $odpowiedzianeTylkoPrzezZablokowanego->tags()->attach($tag);
        Comment::factory()->create(['post_id' => $odpowiedzianeTylkoPrzezZablokowanego->id, 'author_id' => $zablokowany->id]);
        $odpowiedziane = Post::factory()->question()->create();
        Comment::factory()->create(['post_id' => $odpowiedziane->id, 'author_id' => $odpowiadajacy->id]);
        Post::factory()->question()->followersOnly()->create(['author_id' => $obserwowana->id]);
        Post::factory()->question()->private()->create();
        Post::factory()->create();

        app(FollowUser::class)->handle($widz, $obserwowana);
        app(BlockUser::class)->handle($widz, $zablokowany);

        $lista = new QuestionList;
        foreach ([null, $widz, $zablokowany, $obserwowana] as $ktos) {
            foreach ([null, $tag->slug] as $slug) {
                $this->assertSame(
                    $lista->query($ktos, true, $slug)->count(),
                    $this->licznik($ktos, $slug),
                    'Rozjazd dla widza '.($ktos?->id ?? 'gość').' i tagu '.($slug ?? '—'),
                );
            }
        }
    }

    public function test_pusty_cache_liczy_na_miejscu_zamiast_pokazac_zero(): void
    {
        Post::factory()->question()->create();
        Cache::forget(PytaniaBezOdpowiedzi::KLUCZ_CACHE);

        $this->assertSame(1, $this->licznik(null));
    }

    public function test_komenda_harmonogramu_przelicza_a_przy_wylaczonym_dziale_czysci(): void
    {
        Post::factory()->question()->create();
        Cache::forget(PytaniaBezOdpowiedzi::KLUCZ_CACHE);

        $this->artisan('kuking:policz-pytania')->assertExitCode(0);
        $this->assertSame(1, Cache::get(PytaniaBezOdpowiedzi::KLUCZ_CACHE)['wszystkie']);

        config(['kuking.questions.enabled' => false]);
        $this->artisan('kuking:policz-pytania')->assertExitCode(0);
        $this->assertNull(Cache::get(PytaniaBezOdpowiedzi::KLUCZ_CACHE));
    }
}
