<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\EditPost;
use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Tags\Actions\MergeTags;
use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TagiInlinePochodzenieTest extends TestCase
{
    use RefreshDatabase;

    public function test_wpisany_lub_wklejony_token_publikuje_sie_bez_wyboru_sugestii(): void
    {
        $this->actingAs($this->user())->post(route('posts.store'), [
            'body' => '#żurek i #chleb', 'visibility' => 'private',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $post = Post::firstOrFail();
        $this->assertSame(['żurek' => false, 'chleb' => false], $this->origins($post));
        $this->get(route('posts.edit', $post))->assertOk()->assertViewHas('tagNames', []);
    }

    public function test_zmiana_tokenow_zachowuje_reczne_i_nie_zamienia_inline_na_reczne(): void
    {
        $user = $this->user();
        $post = app(PublishPost::class)->handle($user, '#chleb #zupa', tagNames: ['obiad']);
        $this->assertSame(['obiad' => true, 'chleb' => false, 'zupa' => false], $this->origins($post));
        app(EditPost::class)->handle($post, '#chleb #zupa', 'public', ['obiad']);
        $this->assertSame(['obiad' => true, 'chleb' => false, 'zupa' => false], $this->origins($post));
        app(EditPost::class)->handle($post, '#sernik', 'public', ['obiad']);
        $this->assertSame(['obiad' => true, 'sernik' => false], $this->origins($post));
        app(EditPost::class)->handle($post, 'Bez tokenów', 'private', ['obiad']);
        $this->assertSame(['obiad' => true], $this->origins($post));
    }

    public function test_reczny_i_inline_maja_niezalezne_przejscia_oraz_poprawna_kolejnosc(): void
    {
        $post = app(PublishPost::class)->handle($this->user(), '#chleb', tagNames: ['zupa']);
        app(EditPost::class)->handle($post, '#chleb', 'public', ['chleb', 'zupa']);
        $this->assertSame(['chleb' => true, 'zupa' => true], $this->origins($post));
        app(EditPost::class)->handle($post, 'Obiad', 'public', ['zupa', 'chleb']);
        $this->assertSame(['zupa' => true, 'chleb' => true], $this->origins($post));
        app(EditPost::class)->handle($post, '#chleb', 'public', ['zupa']);
        $this->assertSame(['zupa' => true, 'chleb' => false], $this->origins($post));
        app(EditPost::class)->handle($post, 'Obiad', 'public', []);
        $this->assertSame([], $this->origins($post));
    }

    public function test_slug_alias_i_scalony_slug_prowadza_do_kanonicznej_nazwy(): void
    {
        $target = $this->tag('zupa pomidorowa');
        $source = $this->tag('pomidorówka');
        app(MergeTags::class)->handle($source, $target);
        $post = app(PublishPost::class)->handle($this->user(), '#zupa-pomidorowa #pomidorowka #pomidorówka');
        $this->assertSame(['zupa pomidorowa' => false], $this->origins($post));
        $this->assertDatabaseCount('tags', 2);
        app(EditPost::class)->handle($post, '#pomidorowka', 'public');
        $this->assertSame(['zupa pomidorowa' => false], $this->origins($post));
        $final = $this->tag('pomidorowa zupa');
        app(MergeTags::class)->handle($target, $final);
        app(EditPost::class)->handle($post, '#pomidorowka', 'public');
        $this->assertSame(['pomidorowa zupa' => false], $this->origins($post));
    }

    public function test_limit_dotyczy_kanonicznej_unii_a_nie_liczby_aliasow(): void
    {
        $tag = $this->tag('sernik');
        $aliases = [];
        for ($i = 0; $i < 7; $i++) {
            $aliases[] = 'serniczek'.$i;
            TagAlias::create(['tag_id' => $tag->id, 'alias' => end($aliases), 'normalized_alias' => end($aliases), 'source' => TagAlias::SOURCE_ADMIN]);
        }
        $this->assertCount(1, app(ResolveTagsForPost::class)->handle($aliases));
        $post = app(PublishPost::class)->handle($this->user(), '#'.implode(' #', $aliases), tagNames: ['sernik']);
        $this->assertSame(['sernik' => true], $this->origins($post));
    }

    public function test_limit_unii_cofa_body_pivoty_i_tworzenie_nowych_tagow(): void
    {
        $post = app(PublishPost::class)->handle($this->user(), 'Opis', tagNames: ['sernik']);
        $before = DB::table('posts')->where('id', $post->id)->first();
        $pivot = DB::table('post_tags')->where('post_id', $post->id)->get()->toArray();
        try {
            app(EditPost::class)->handle($post, '#chleb #zupa #obiad', 'private', ['sernik', 'kolacja', 'sniadanie']);
            $this->fail('Sześć kanonicznych tagów nie zostało odrzuconych.');
        } catch (BladDlaCzlowieka) {
            $this->assertEquals($before, DB::table('posts')->where('id', $post->id)->first());
            $this->assertEquals($pivot, DB::table('post_tags')->where('post_id', $post->id)->get()->toArray());
            $this->assertDatabaseCount('tags', 1);
        }
    }

    public function test_limit_szesciu_inline_nie_zostawia_wpisu_ani_tagow(): void
    {
        $this->actingAs($this->user())->post(route('posts.store'), [
            'body' => '#chleb #sernik #zupa #obiad #kolacja #sniadanie', 'visibility' => 'public',
        ])->assertRedirect()->assertSessionHasErrors('tagi')->assertSessionHasInput('body', '#chleb #sernik #zupa #obiad #kolacja #sniadanie');
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('tags', 0);
    }

    public function test_hidden_zostaje_przy_dotychczasowym_wpisie_ale_nie_jest_nowym_powiazaniem(): void
    {
        $post = app(PublishPost::class)->handle($this->user(), '#sernik', tagNames: ['chleb']);
        Tag::query()->update(['status' => Tag::STATUS_HIDDEN]);
        app(EditPost::class)->handle($post, '#sernik poprawiony opis', 'private', ['chleb']);
        $this->assertSame(['chleb' => true, 'sernik' => false], $this->origins($post));
        $new = app(PublishPost::class)->handle($this->user(), '#sernik', tagNames: ['chleb']);
        $this->assertSame([], $this->origins($new));
        $this->assertDatabaseCount('tags', 2);
        app(EditPost::class)->handle($post, 'Bez tokenu', 'public', ['chleb']);
        $this->assertSame(['chleb' => true], $this->origins($post));
        $this->assertSame(2, Tag::where('status', Tag::STATUS_HIDDEN)->count());
        $this->get(route('posts.show', $post))->assertOk()
            ->assertDontSee(route('tags.show', Tag::where('name', 'chleb')->firstOrFail()), false);
    }

    public function test_historyczny_pivot_domyslnie_reczny_i_media_nie_znikaja_przy_fallbacku(): void
    {
        $user = $this->user();
        $post = Post::factory()->for($user, 'author')->create(['body' => '#sernik']);
        $tag = $this->tag('chleb');
        $post->tags()->attach($tag->id, ['position' => 0]);
        $media = Media::factory()->create(['owner_id' => $user->id]);
        $post->media()->attach($media->id, ['position' => 0]);
        $this->assertSame(['chleb' => true], $this->origins($post));
        $this->actingAs($user)->get(route('posts.edit', $post))->assertOk()->assertViewHas('tagNames', ['chleb']);
        $this->put(route('posts.update', $post), ['body' => '#sernik zmiana', 'visibility' => 'private', 'tag_names' => ['chleb'], 'dodaj_tag' => 'obiad'])
            ->assertRedirect()->assertSessionHasInput('body', '#sernik zmiana')->assertSessionHasInput('tag_names', ['chleb', 'obiad']);
        $this->assertSame('#sernik', $post->fresh()->body);
        $this->assertSame([$media->id], $post->media()->pluck('media.id')->all());
        $this->assertSame(['chleb' => true], $this->origins($post));
    }

    public function test_publiczna_karta_pomija_link_hidden_ale_zachowuje_body_i_aktywny_tag(): void
    {
        $post = app(PublishPost::class)->handle($this->user(), '#sernik #chleb');
        $hidden = Tag::where('name', 'sernik')->firstOrFail();
        $active = Tag::where('name', 'chleb')->firstOrFail();
        $hidden->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        $this->get(route('posts.show', $post))->assertOk()
            ->assertSee('#sernik #chleb')
            ->assertDontSee(route('tags.show', $hidden), false)
            ->assertSee(route('tags.show', $active), false);
        $this->assertSame(['sernik' => false, 'chleb' => false], $this->origins($post));
        $this->assertSame('#sernik #chleb', $post->fresh()->body);
    }

    public function test_idempotencja_publikacji_nie_zmienia_pochodzenia(): void
    {
        $user = $this->user();
        $key = (string) Str::uuid();
        $first = app(PublishPost::class)->handle($user, '#chleb', kluczWyslania: $key);
        $second = app(PublishPost::class)->handle($user, '#chleb', kluczWyslania: $key);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(['chleb' => false], $this->origins($first));
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_usuniete_wszystkie_reczne_tagi_nie_wracaja_do_formularza_po_bledzie(): void
    {
        $user = $this->user();
        $post = app(PublishPost::class)->handle($user, 'Opis', tagNames: ['chleb']);
        $edit = route('posts.edit', $post);
        foreach ([str_repeat('x', 4001), '#chleb #sernik #zupa #obiad #kolacja #sniadanie'] as $body) {
            $this->actingAs($user)->from($edit)->put(route('posts.update', $post), [
                'body' => $body, 'visibility' => 'private',
                // Pusta lista nie wysyła żadnego tag_names[] w HTML.
            ])->assertRedirect($edit)->assertSessionHasErrors();
            $this->withCookie((string) config('session.cookie'), session()->getId())->get($edit)
                ->assertOk()->assertViewHas('tagNames', []);
            $this->assertSame(['chleb' => true], $this->origins($post));
            $this->assertSame('Opis', $post->fresh()->body);
        }
    }

    public static function mergeOrigins(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    public function test_old_input_obcego_formularza_nie_usuwa_ani_nie_podmienia_recznych_tagow(): void
    {
        $user = $this->user();
        $post = app(PublishPost::class)->handle($user, 'Opis', tagNames: ['chleb']);
        foreach ([['body' => 'Inny formularz'], ['body' => 'Inny formularz', 'tag_names' => ['sernik']]] as $old) {
            $this->actingAs($user)->withSession(['_old_input' => $old])->get(route('posts.edit', $post))
                ->assertOk()->assertViewHas('tagNames', ['chleb']);
        }
    }

    public function test_blad_innego_wpisu_nie_zeruje_listy_i_marker_jest_ustalany_serwerowo(): void
    {
        $user = $this->user();
        $first = app(PublishPost::class)->handle($user, 'Pierwszy', tagNames: ['chleb']);
        $second = app(PublishPost::class)->handle($user, 'Drugi', tagNames: ['sernik']);
        $this->actingAs($user)->from(route('posts.edit', $first))->put(route('posts.update', $first), [
            'body' => str_repeat('x', 4001), 'visibility' => 'public',
            '_tag_form_post_id' => $second->id,
        ])->assertSessionHasErrors('body')->assertSessionHasInput('_tag_form_post_id', $first->id);
        $this->withCookie((string) config('session.cookie'), session()->getId())->get(route('posts.edit', $second))
            ->assertOk()->assertViewHas('tagNames', ['sernik']);
        $this->assertSame(['chleb' => true], $this->origins($first));
        $this->assertSame(['sernik' => true], $this->origins($second));
    }

    #[DataProvider('mergeOrigins')]
    public function test_scalenie_zachowuje_or_recznego_pochodzenia(bool $sourceManual, bool $targetManual): void
    {
        $source = $this->tag('serniczek');
        $target = $this->tag('sernik');
        $post = Post::factory()->create(['body' => '#serniczek #sernik']);
        $post->tags()->attach([$source->id => ['position' => 0, 'dodany_recznie' => $sourceManual], $target->id => ['position' => 1, 'dodany_recznie' => $targetManual]]);
        app(MergeTags::class)->handle($source, $target);
        $this->assertSame(['sernik' => $sourceManual || $targetManual], $this->origins($post));
        $this->assertSame('#serniczek #sernik', $post->fresh()->body);
    }

    public function test_scalenie_samego_zrodla_zachowuje_pozycje_i_false(): void
    {
        $source = $this->tag('serniczek');
        $target = $this->tag('sernik');
        $post = Post::factory()->create();
        $post->tags()->attach($source->id, ['position' => 3, 'dodany_recznie' => false]);
        app(MergeTags::class)->handle($source, $target);
        $this->assertSame(['sernik' => false], $this->origins($post));
        $this->assertSame(3, $post->tags()->firstOrFail()->pivot->position);
    }

    private function tag(string $name): Tag
    {
        return Tag::create(['name' => $name, 'normalized_name' => Tag::znormalizujNazwe($name), 'slug' => Tag::slugDlaNazwy($name)]);
    }

    private function origins(Post $post): array
    {
        return $post->tags()->get()->mapWithKeys(fn (Tag $tag): array => [$tag->name => $tag->pivot->dodany_recznie])->all();
    }
}
