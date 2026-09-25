<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\ZeszytyDoWyboru;
use App\Models\Post;
use App\Models\Recipe;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZapisDoWybranegoZeszytuTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_zeszytow_jest_izolowana_miedzy_osobami_i_zadaniami(): void
    {
        $first = $this->user('pierwszy_zeszyt');
        $second = $this->user('drugi_zeszyt');
        $own = $first->collections()->create(['name' => 'Prywatny', 'visibility' => 'private']);
        $other = $second->collections()->create(['name' => 'Drugi', 'visibility' => 'private']);
        $provider = new ZeszytyDoWyboru;
        $request = Request::create('/');
        $request->setUserResolver(fn () => $first);
        $list = $provider->dla($request);
        $this->assertSame([$own->id], $list->modelKeys());
        $this->assertSame($list, $provider->dla($request));
        $request->setUserResolver(fn () => $second);
        $this->assertSame([$other->id], $provider->dla($request)->modelKeys());
        $request->setUserResolver(fn () => null);
        $this->assertCount(0, $provider->dla($request));
        $own->delete();
        $next = Request::create('/');
        $next->setUserResolver(fn () => $first);
        $this->assertCount(0, $provider->dla($next));
    }

    public function test_usuniety_ostatni_zeszyt_pokazuje_blad_z_istniejacym_celem(): void
    {
        $user = $this->user('usuniety_zeszyt');
        $collection = $user->collections()->create(['name' => 'Obiady', 'visibility' => 'private']);
        $post = Post::factory()->create();
        $row = 'wpis-'.$post->id;
        $this->actingAs($user)->get($post->url())->assertOk();
        $id = $collection->id;
        $collection->delete();
        $this->from($post->url())->post(route('collections.save-post', $post), [
            'collection_id' => $id, '_wiersz' => $row,
        ])->assertRedirect($post->url())->assertSessionHasErrors('collection_id');
        $html = $this->withCookie((string) config('session.cookie'), session()->getId())
            ->get($post->url())->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $target = 'f-collection_id-'.$row;
        $this->assertCount(1, $xpath->query('//a[@href="#'.$target.'"]'));
        $this->assertCount(1, $xpath->query('//*[@id="'.$target.'"][@tabindex="-1"]'));
        $this->assertCount(0, $xpath->query('//input[@name="collection_id"]'));
        $this->assertDatabaseCount('collection_items', 0);
        $this->assertSame(0, $user->collections()->count());
    }

    public function test_blad_wyboru_rozwija_tylko_wyslana_karte_i_prowadzi_do_jej_pola(): void
    {
        $user = $this->user('blad_zeszytu');
        $user->collections()->create(['name' => 'Obiady', 'visibility' => 'private']);
        $first = Post::factory()->create();
        $second = Post::factory()->create();
        $row = 'wpis-'.$second->id;
        $this->actingAs($user)->from(route('discover'))
            ->post(route('collections.save-post', $second), ['collection_id' => 'bledny', '_wiersz' => $row])
            ->assertRedirect(route('discover'))->assertSessionHasErrors('collection_id');
        $session = session()->getId();
        $html = $this->withCookie((string) config('session.cookie'), $session)
            ->get(route('discover'))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $invalid = $xpath->query('//input[@name="collection_id"][@aria-invalid="true"][@id]');
        $this->assertCount(1, $invalid);
        $id = self::elementDom($invalid->item(0))->getAttribute('id');
        $this->assertSame('f-collection_id-'.$row, $id);
        $this->assertCount(1, $xpath->query('//details[@open][.//input[@id="'.$id.'"]]'));
        $this->assertCount(1, $xpath->query('//a[@href="#'.$id.'"]'));
        $this->assertCount(1, $xpath->query('//*[@id="'.self::elementDom($invalid->item(0))->getAttribute('aria-describedby').'"]'));
        $this->assertCount(0, $xpath->query('//details[@open][.//input[@id="f-collection_id-wpis-'.$first->id.'"]]'));
        $this->assertDatabaseCount('collection_items', 0);
    }

    public static function targets(): array
    {
        return [
            'przepis nowy' => ['recipe', false],
            'przepis zapisany' => ['recipe', true],
            'wpis nowy' => ['post', false],
            'wpis zapisany' => ['post', true],
        ];
    }

    #[DataProvider('targets')]
    public function test_formularz_pozwala_zapisac_do_wlasnego_zeszytu(string $kind, bool $alreadySaved): void
    {
        $user = $this->user('wybierajaca');
        $other = $this->user('inna');
        $own = $user->collections()->create(['name' => 'Moje niedziele', 'visibility' => 'private']);
        $foreign = $other->collections()->create(['name' => 'Tajny zeszyt drugiej osoby', 'visibility' => 'private']);
        $target = $kind === 'recipe' ? Recipe::factory()->create() : Post::factory()->create();
        $action = $kind === 'recipe' ? route('collections.save', $target->slug) : route('collections.save-post', $target);
        $page = $target->url();
        $column = $kind === 'recipe' ? 'recipe_id' : 'post_id';
        $this->actingAs($user);
        if ($alreadySaved) {
            $this->from($page)->post($action)->assertRedirect($page)->assertSessionHasNoErrors();
        }
        $before = $user->collections()->count();
        $html = $this->get($page)->assertOk()->getContent();
        $this->assertStringNotContainsString($foreign->name, $html);
        $this->assertSame($before, $user->collections()->count(), 'Renderowanie nie tworzy zeszytów.');
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $forms = $xpath->query('//form[@action="'.$action.'"][.//input[@type="radio"][@name="collection_id"]]');
        $this->assertCount(1, $forms, 'Wybór jest dostępny także po wcześniejszym zapisie.');
        $form = $forms->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        $this->assertCount(1, $xpath->query('.//input[@name="_token"]', $form));
        $options = $xpath->query('.//input[@type="radio"][@name="collection_id"][@value="'.$own->id.'"]', $form);
        $this->assertCount(1, $options);
        $this->assertCount(0, $xpath->query('.//input[@name="collection_id"][@value="'.$foreign->id.'"]', $form));
        $data = ['collection_id' => self::elementDom($options->item(0))->getAttribute('value')];
        foreach (self::elementyDom($xpath->query('.//input[@type="hidden"]', $form)) as $input) {
            if ($input->getAttribute('name') !== '_token') {
                $data[$input->getAttribute('name')] = $input->getAttribute('value');
            }
        }
        $this->from($page)->post($form->getAttribute('action'), $data)
            ->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('collection_items', ['collection_id' => $own->id, $column => $target->id]);
        $this->assertDatabaseCount('collection_items', $alreadySaved ? 2 : 1);
        $this->from($page)->post($action, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('collection_items', $alreadySaved ? 2 : 1);
        $this->get(route('collections.show', $own))->assertOk();
    }
}
