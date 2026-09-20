<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZdjeciaFormularzyPublikacjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        config(['kuking.questions.enabled' => true]);
        $this->actingAs($this->user());
    }

    public static function invalidFields(): array
    {
        return [['actual_minutes', '1h 30'], ['note', str_repeat('a', 2001)], ['changes_note', str_repeat('a', 1001)]];
    }

    #[DataProvider('invalidFields')]
    public function test_ugotowalem_zachowuje_zdjecie_przez_dwa_bledy_i_publikuje_bez_powtornego_uploadu(string $field, string $invalid): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $form = route('cooked.create', $recipe->slug);
        $url = route('cooked.store', $recipe->slug);
        $this->get($form)->assertOk();
        $this->from($form)->post($url, ['photos' => [UploadedFile::fake()->image('obiad.jpg')], $field => $invalid])
            ->assertSessionHasErrors($field);
        $ids = session()->getOldInput('media_ids');
        $this->assertIsArray($ids);
        $this->assertCount(1, $ids);
        $this->get($form)->assertOk()->assertSee('name="media_ids[]"', false)->assertSee('Usuń zdjęcie', false);
        $this->from($form)->post($url, ['media_ids' => $ids, 'actual_minutes' => 'nadal błąd', 'note' => 'Wyszło dobrze.'])
            ->assertSessionHasErrors('actual_minutes');
        $this->assertSame($ids, session()->getOldInput('media_ids'));
        $this->get($form)->assertOk()->assertSee($ids[0]);
        $this->post($url, ['media_ids' => $ids, 'actual_minutes' => '90', 'note' => 'Wyszło dobrze.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($ids, CookedEvent::sole()->media()->pluck('media.id')->all());
        $this->assertDatabaseCount('media', 1);
    }

    public static function malformedIds(): array
    {
        $cases = [];
        foreach (['posts', 'questions', 'cooked'] as $form) {
            foreach (['tekst' => ['nie-uuid'], 'zagnieżdżone' => [['x' => 'y']], 'null' => null, 'puste' => []] as $name => $ids) {
                $cases[$form.' '.$name] = [$form, $ids];
            }
        }

        return $cases;
    }

    #[DataProvider('malformedIds')]
    public function test_powrot_po_walidacji_nie_pyta_bazy_o_bledny_uuid(string $form, ?array $ids): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $create = $form === 'cooked' ? route('cooked.create', $recipe->slug) : route($form.'.create');
        $store = $form === 'cooked' ? route('cooked.store', $recipe->slug) : route($form.'.store');
        $this->get($create)->assertOk();
        $response = $this->followingRedirects()->from($create)->post($store, ['media_ids' => $ids, 'body' => 'Zachowany opis obiadu', 'note' => 'Zachowany opis obiadu', 'actual_minutes' => 'zły czas', 'visibility' => 'błędna', 'title' => 'x'])->assertOk()->assertSee('Zachowany opis obiadu')->assertSee('Sprawdź formularz');
        $this->followRedirects = false;
    }

    public static function photoErrors(): array
    {
        $cases = [];
        foreach (['posts', 'questions', 'cooked'] as $form) {
            foreach ([0, 1, -1] as $index) {
                foreach ([false, true] as $recovered) {
                    $cases[] = [$form, $index, $recovered];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('photoErrors')]
    public function test_blad_pliku_prowadzi_do_istniejacego_pola(string $form, int $index, bool $recovered): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $create = $form === 'cooked' ? route('cooked.create', $recipe->slug) : route($form.'.create');
        $store = $form === 'cooked' ? route('cooked.store', $recipe->slug) : route($form.'.store');
        $this->get($create)->assertOk();
        $media = $recovered ? Media::factory()->create(['owner_id' => auth()->id()]) : null;
        $photos = $index === -1 ? array_fill(0, 9, UploadedFile::fake()->image('obiad.jpg')) : [$index => UploadedFile::fake()->create('obiad.txt', 1, 'text/plain')];
        $response = $this->followingRedirects()->from($create)->post($store, ['photos' => $photos, 'media_ids' => $media ? [$media->id] : []])->assertOk();
        $this->followRedirects = false;
        $html = $response->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        $link = $xpath->query('//div[contains(@class,"error-summary")]//a[starts-with(@href,"#f-photos")]')->item(0);
        $this->assertNotNull($link);
        $id = substr($link->getAttribute('href'), 1);
        $target = $xpath->query('//*[@id="'.$id.'"]')->item(0);
        $this->assertNotNull($target, 'Podsumowanie prowadzi do nieistniejącego pola '.$id);
        $this->assertSame('true', $target->getAttribute('aria-invalid'));
        $this->assertNotEmpty($target->getAttribute('aria-describedby'));
    }

    public function test_usuniecie_zachowanego_zdjecia_nie_publikuje_i_nie_gubi_tekstu(): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $media = Media::factory()->create(['owner_id' => auth()->id()]);
        $url = route('cooked.store', $recipe->slug);
        $create = route('cooked.create', $recipe->slug);
        $this->get($create)->assertOk();
        $this->followingRedirects()->from($create)->post($url, [
            'media_ids' => [$media->id], 'usun_zdjecie' => $media->id, 'note' => 'Zostaw ten tekst',
        ])->assertOk()->assertSee('Zostaw ten tekst')->assertDontSee('name="media_ids[]"', false);
        $this->followRedirects = false;
        $this->assertDatabaseCount('cooked_events', 0);
        $this->post($url, ['note' => 'Zostaw ten tekst'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, CookedEvent::sole()->media()->count());
    }

    public static function forms(): array
    {
        return [['posts'], ['questions'], ['cooked']];
    }

    #[DataProvider('forms')]
    public function test_odtwarzanie_pokazuje_tylko_wlasne_nieprzypiete_zywe_zdjecie(string $form): void
    {
        $userId = auth()->id();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $own = Media::factory()->create(['owner_id' => $userId]);
        $foreign = Media::factory()->create(['owner_id' => $recipe->author_id]);
        $deleted = Media::factory()->create(['owner_id' => $userId, 'status' => Media::STATUS_DELETED]);
        $attached = Media::factory()->create(['owner_id' => $userId]);
        $recipe->forceFill(['hero_media_id' => $attached->id])->save();
        $ids = [$own->id, $foreign->id, $deleted->id, $attached->id];
        config(['kuking.media.max_per_post' => 8]);
        $create = $form === 'cooked' ? route('cooked.create', $recipe->slug) : route($form.'.create');
        $store = $form === 'cooked' ? route('cooked.store', $recipe->slug) : route($form.'.store');
        $this->get($create)->assertOk();
        $response = $this->followingRedirects()->from($create)->post($store, [
            'media_ids' => $ids, 'body' => 'Zachowany tekst', 'title' => 'x',
            'visibility' => 'zła', 'actual_minutes' => 'błąd',
        ])->assertOk();
        $this->followRedirects = false;
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $inputs = $xpath->query('//input[@name="media_ids[]"]');
        $this->assertSame(1, $inputs->length);
        $this->assertSame($own->id, $inputs->item(0)->getAttribute('value'));
    }

    public function test_mapa_zdjec_nie_zmienia_indeksowanego_pola_kroku(): void
    {
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['steps.0.instruction' => 'Opisz krok.']));
        view()->share('errors', $errors);
        $html = $this->blade('<x-error-summary :targets="[\'photos.*\' => \'photos\']" />', ['errors' => $errors]);
        $html->assertSee('href="#f-steps-0-instruction"', false);
    }
}
