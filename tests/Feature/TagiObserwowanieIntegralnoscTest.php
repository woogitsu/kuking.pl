<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\Actions\MergeTags;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Sekwencje HTTP i SQL. Nie są pomiarem przeplotu dwóch połączeń. */
class TagiObserwowanieIntegralnoscTest extends TestCase
{
    use RefreshDatabase;

    private function promoted(): Tag
    {
        $tag = Tag::factory()->create();
        TagPromotion::create(['tag_id' => $tag->id, 'position' => 0]);

        return $tag;
    }

    private function form(User $user): array
    {
        $response = $this->actingAs($user)->get(route('settings.tags'))->assertOk();
        preg_match('/name="form_scope"\s+value="([^"]+)"/', $response->getContent(), $match);

        return ['form_scope' => $match[1] ?? null];
    }

    public function test_854_stary_formularz_nie_usuwa_nowego_obserwowania(): void
    {
        $a = $this->promoted();
        $b = Tag::factory()->create();
        $user = $this->user();
        $user->followedTags()->attach($a->id, ['created_at' => now()]);
        $form = $this->form($user);
        $this->post(route('tags.follow', $b))->assertRedirect();
        $this->put(route('settings.tags.update'), $form + ['tags' => [$a->id]])->assertSessionHasNoErrors();
        $this->assertTrue($user->isFollowingTag($a));
        $this->assertTrue($user->isFollowingTag($b), 'Stary formularz usunął nowe obserwowanie.');
    }

    public function test_854_stary_formularz_nie_cofa_nowszego_odznaczenia(): void
    {
        $a = $this->promoted();
        $user = $this->user();
        $user->followedTags()->attach($a->id, ['created_at' => now()]);
        $form = $this->form($user);
        $this->delete(route('tags.unfollow', $a))->assertRedirect();
        $this->put(route('settings.tags.update'), $form + ['tags' => [$a->id]])->assertSessionHasNoErrors();
        $this->assertFalse($user->isFollowingTag($a), 'Stary formularz cofnął nowsze odznaczenie.');
    }

    public function test_854_pusty_wybor_usuwa_tylko_poczatkowe_obserwowania(): void
    {
        $a = $this->promoted();
        $b = $this->promoted();
        $user = $this->user();
        $user->followedTags()->attach($a->id, ['created_at' => now()]);
        $form = $this->form($user);
        $this->post(route('tags.follow', $b));
        $this->put(route('settings.tags.update'), $form)->assertSessionHasNoErrors();
        $this->assertFalse($user->isFollowingTag($a));
        $this->assertTrue($user->isFollowingTag($b));
    }

    public function test_854_stary_formularz_nie_zdejmuje_ponownie_rozpoczetej_relacji(): void
    {
        $a = $this->promoted();
        $user = $this->user();
        $user->followedTags()->attach($a->id, ['created_at' => now()->subMinute()]);
        $form = $this->form($user);
        $this->delete(route('tags.unfollow', $a));
        $this->post(route('tags.follow', $a));
        $this->put(route('settings.tags.update'), $form)->assertSessionHasNoErrors();
        $this->assertTrue($user->isFollowingTag($a));
    }

    public function test_854_brak_podpisu_podmiana_i_formularz_cudzego_konta_nie_zmieniaja_relacji(): void
    {
        $a = $this->promoted();
        $user = $this->user();
        $other = $this->user();
        $foreign = $this->form($other);
        $user->followedTags()->attach($a->id, ['created_at' => now()]);
        foreach ([[], ['form_scope' => 'podmieniony'], $foreign] as $form) {
            $this->actingAs($user)->put(route('settings.tags.update'), $form)->assertSessionHasErrors('tags');
            $this->assertTrue($user->isFollowingTag($a));
        }
    }

    public function test_857_powtorzenia_zachowuja_dokladny_czas_a_nowa_relacja_ma_nowy(): void
    {
        $tag = $this->promoted();
        $user = $this->user();
        $this->freezeTime();
        $this->actingAs($user)->post(route('tags.follow', $tag))->assertSessionHasNoErrors();
        $date = fn () => DB::table('tag_follows')->where('user_id', $user->id)->where('tag_id', $tag->id)->value('created_at');
        $first = $date();
        $this->assertNotNull($first);
        $this->travel(10)->seconds();
        $this->post(route('tags.follow', $tag))->assertSessionHasNoErrors();
        $this->assertSame($first, $date(), 'Ponowne obserwowanie przepisało datę.');
        $this->post(route('onboarding.interests'), ['tags' => [$tag->id]])->assertSessionHasNoErrors();
        $this->assertSame($first, $date(), 'Onboarding przepisał datę.');
        $this->delete(route('tags.unfollow', $tag));
        $this->post(route('tags.follow', $tag))->assertSessionHasNoErrors();
        $this->assertNotSame($first, $date());
        $this->assertSame(now()->timestamp, Carbon::parse($date())->timestamp);
    }

    public function test_857_ponowny_onboarding_zachowuje_date(): void
    {
        $tag = $this->promoted();
        $user = $this->user();
        $date = now()->subSeconds(10)->startOfSecond();
        $user->followedTags()->attach($tag->id, ['created_at' => $date]);
        $this->actingAs($user)->post(route('onboarding.interests'), ['tags' => [$tag->id]])->assertSessionHasNoErrors();
        $actual = DB::table('tag_follows')->where('user_id', $user->id)->value('created_at');
        $this->assertSame($date->timestamp, Carbon::parse($actual)->timestamp);
    }

    public function test_853_ukrycie_po_otwarciu_nie_dodaje_nieaktywnego_i_nie_kasuje_poprawnych(): void
    {
        $a = $this->promoted();
        $b = $this->promoted();
        $user = $this->user();
        $user->followedTags()->attach($a->id, ['created_at' => now()]);
        $form = $this->form($user);
        $b->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        $this->put(route('settings.tags.update'), $form + ['tags' => [$b->id]])->assertSessionHasErrors('tags');
        $this->assertFalse($user->isFollowingTag($b));
        $this->assertTrue($user->isFollowingTag($a));
    }

    public function test_853_scalenie_po_otwarciu_nie_odtwarza_zrodla(): void
    {
        $source = $this->promoted();
        $target = Tag::factory()->create();
        $user = $this->user();
        $form = $this->form($user);
        app(MergeTags::class)->handle($source, $target);
        $this->put(route('settings.tags.update'), $form + ['tags' => [$source->id]])->assertSessionHasErrors('tags');
        $this->assertFalse($user->isFollowingTag($source));
        $this->post(route('tags.follow', $target))->assertSessionHasNoErrors();
        $this->assertTrue($user->isFollowingTag($target));
    }

    public function test_853_mozna_zdjac_istniejacy_ukryty_tag_i_dodac_aktywny(): void
    {
        $a = Tag::factory()->create();
        $a->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        $b = $this->promoted();
        $user = $this->user();
        $user->followedTags()->attach($a->id, ['created_at' => now()]);
        $form = $this->form($user);
        $this->put(route('settings.tags.update'), $form + ['tags' => [$b->id]])->assertSessionHasNoErrors();
        $this->assertFalse($user->isFollowingTag($a));
        $this->assertTrue($user->isFollowingTag($b));
    }

    public function test_855_usuniety_wybor_nie_kasuje_pozostalych_a_ponowienie_nie_niszczy_innej_karty(): void
    {
        $a = $this->promoted();
        $b = $this->promoted();
        $c = $this->promoted();
        $d = Tag::factory()->create();
        $user = $this->user();
        $user->followedTags()->attach($a->id, ['created_at' => now()]);
        $form = $this->form($user);
        $c->delete();
        $this->post(route('tags.follow', $d));
        $this->from(route('settings.tags'))->put(route('settings.tags.update'), $form + ['tags' => [$b->id, $c->id]])->assertSessionHasErrors('tags');
        // Kolejne żądanie niesie tę samą sesję, tak jak przekierowanie w przeglądarce.
        $this->withCookie(config('session.cookie'), session()->getId());
        $response = $this->get(route('settings.tags'))->assertOk();
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*value="'.$b->id.'"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*value="'.$a->id.'"[^>]*checked/', $html);
        $this->assertStringNotContainsString('value="'.$c->id.'"', $html);
        $this->assertStringContainsString('href="#f-tags"', $html);
        $this->assertStringContainsString('id="f-tags-error"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('Sprawdź pozostałe zaznaczenia i zapisz ponownie.', $html);
        $this->assertSame($form['form_scope'], $response->viewData('formScope'));
        $this->put(route('settings.tags.update'), ['form_scope' => $response->viewData('formScope'), 'tags' => [$b->id]])->assertSessionHasNoErrors();
        $this->assertFalse($user->isFollowingTag($a));
        $this->assertTrue($user->isFollowingTag($b));
        $this->assertTrue($user->isFollowingTag($d));
    }

    public function test_855_pusty_wybor_po_odmowie_nie_przywraca_zaznaczen_z_bazy(): void
    {
        $tag = $this->promoted();
        $user = $this->user();
        $user->followedTags()->attach($tag->id, ['created_at' => now()]);
        $this->actingAs($user)->from(route('settings.tags'))->put(route('settings.tags.update'), ['form_scope' => 'uszkodzony'])->assertSessionHasErrors('tags');
        $html = $this->get(route('settings.tags'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*value="'.$tag->id.'"[^>]*checked/', $html);
    }

    public function test_859_opis_i_trzy_zrodla_sa_zgodne(): void
    {
        $tag = $this->promoted();
        $user = $this->user();
        $friend = $this->user();
        $other = $this->user();
        $user->followedTags()->attach($tag->id, ['created_at' => now()]);
        DB::table('follows')->insert(['follower_id' => $user->id, 'followed_id' => $friend->id, 'created_at' => now()]);
        $post = Post::factory()->create(['author_id' => $other->id, 'status' => Post::STATUS_PUBLISHED, 'visibility' => 'public', 'published_at' => now()]);
        $this->actingAs($user)->get(route('home'))->assertViewHas('zrodloFeedu', 'odkrywanie');
        $post->tags()->attach($tag->id, ['position' => 0]);
        $this->get(route('home'))->assertViewHas('zrodloFeedu', 'tagi');
        $friendPost = Post::factory()->create(['author_id' => $friend->id, 'status' => Post::STATUS_PUBLISHED, 'visibility' => 'public', 'published_at' => now()]);
        $feed = $this->get(route('home'))->assertViewHas('zrodloFeedu', 'obserwowani');
        $this->assertSame([$friendPost->id], $feed->viewData('posts')->pluck('id')->all());
        $html = $this->get(route('settings.tags'))->assertOk()->getContent();
        $this->assertStringNotContainsString('dopóki nikogo nie obserwujesz', $html);
        $this->assertStringNotContainsString('to one pojawią się na górze', $html);
        $this->assertStringContainsString('Gdy nie ma wpisów od obserwowanych osób, pokazujemy wpisy z Twoich tagów.', $html);
    }

    public function test_861_rozmiar_i_format_odcinaja_zapytania_a_granica_sprawdza_istnienie(): void
    {
        $tags = collect(range(1, 50))->map(fn () => $this->promoted());
        $user = $this->user();
        $form = $this->form($user);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'select count') && str_contains($query->sql, '"tags"')) {
                $queries++;
            }
        });
        $tooMany = $tags->pluck('id')->push((string) Str::uuid())->all();
        foreach (['settings.tags.update', 'onboarding.interests'] as $route) {
            foreach ([$tooMany, ['nie-uuid'], [['zagniezdzone']], [null]] as $ids) {
                $queries = 0;
                $method = $route === 'settings.tags.update' ? 'put' : 'post';
                $this->$method(route($route), $form + ['tags' => $ids])->assertSessionHasErrors('tags');
                $this->assertSame(0, $queries, 'Odrzucone wejście uruchomiło weryfikację istnienia.');
                $this->assertSame(0, $user->followedTags()->count());
            }
        }
        $queries = 0;
        $this->put(route('settings.tags.update'), $form + ['tags' => $tags->pluck('id')->all()])->assertSessionHasNoErrors();
        $this->assertSame(1, $queries, 'Poprawne UUID muszą być sprawdzone jednym zapytaniem.');
        $this->assertSame(50, $user->followedTags()->count());
    }

    public function test_861_powtorzenia_i_pusty_wybor_sa_poprawne(): void
    {
        $a = $this->promoted();
        $user = $this->user();
        $form = $this->form($user);
        $this->put(route('settings.tags.update'), $form + ['tags' => array_fill(0, 500, $a->id)])->assertSessionHasNoErrors();
        $this->assertSame(1, $user->followedTags()->count());
        $this->post(route('onboarding.interests'), ['tags' => [$a->id, $a->id]])->assertSessionHasNoErrors();
        $form = $this->form($user);
        $this->put(route('settings.tags.update'), $form)->assertSessionHasNoErrors();
        $this->assertSame(0, $user->followedTags()->count());
    }

    public function test_861_lista_ponad_250_tagow_nie_dostaje_arbitralnego_limitu(): void
    {
        $user = $this->user();
        $tags = Tag::factory()->count(251)->create();
        $user->followedTags()->attach($tags->pluck('id')->all(), ['created_at' => now()]);
        $form = $this->form($user);
        $this->put(route('settings.tags.update'), $form + ['tags' => $tags->pluck('id')->all()])->assertSessionHasNoErrors();
        $this->assertSame(251, $user->followedTags()->count());
    }
}
