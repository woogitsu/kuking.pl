<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LimityIPodgladZdjecTest extends TestCase
{
    use RefreshDatabase;

    public static function limits(): array
    {
        return [['posts', 1, '1 zdjęcie'], ['posts', 6, '6 zdjęć'], ['cooked', 1, '1 zdjęcie'], ['cooked', 6, '6 zdjęć']];
    }

    #[DataProvider('limits')]
    public function test_obydwa_formularze_podaja_wspolny_limit_i_rozmiar(string $form, int $limit, string $words): void
    {
        config(['kuking.media.max_per_post' => $limit, 'kuking.media.max_bytes' => 9 * 1024 * 1024]);
        $this->actingAs($this->user());
        $recipe = Recipe::factory()->create();
        foreach ([$form === 'posts' ? route('posts.create') : route('cooked.create', $recipe->slug)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new DOMXPath($dom);
            $input = $xpath->query('//input[@id="f-photos"]')->item(0);
            $this->assertNotNull($input);
            $this->assertStringContainsString('f-photos-help', $input->getAttribute('aria-describedby'));
            $help = $xpath->query('//*[@id="f-photos-help"]')->item(0);
            $this->assertNotNull($help);
            $this->assertStringContainsString('Łącznie najwyżej '.$words, $help->textContent);
            $this->assertStringContainsString('9 MB', $help->textContent);
            $this->assertStringContainsString('wliczając zdjęcia zachowane', $help->textContent);
        }
    }

    public function test_zapisz_rzeczywiste_formularze_do_pomiaru_przegladarkowego(): void
    {
        $this->actingAs($this->user());
        $recipe = Recipe::factory()->create();
        foreach (['post' => route('posts.create'), 'cooked' => route('cooked.create', $recipe->slug)] as $name => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('enctype="multipart/form-data"', $html);
            $this->assertStringNotContainsString('f-photos-podglad', $html);
            if (getenv('PHOTO_BROWSER_FIXTURES')) {
                @mkdir(base_path('output/playwright'), 0777, true);
                file_put_contents(base_path('output/playwright/'.$name.'.html'), $html);
            }
        }
    }

    public static function uploads(): array
    {
        $cases = [];
        foreach (['posts', 'cooked'] as $form) {
            foreach ([1, 6] as $limit) {
                foreach ([false, true] as $saved) {
                    foreach ([false, true] as $excess) {
                        $cases[] = [$form, $limit, $saved, $excess];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('uploads')]
    public function test_serwer_pilnuje_sumy_i_zachowuje_tekst(string $form, int $limit, bool $saved, bool $excess): void
    {
        Storage::fake('public');
        Queue::fake();
        config(['kuking.media.max_per_post' => $limit]);
        $user = $this->user();
        $this->actingAs($user);
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $create = $form === 'posts' ? route('posts.create') : route('cooked.create', $recipe->slug);
        $store = $form === 'posts' ? route('posts.store') : route('cooked.store', $recipe->slug);
        $ids = $saved ? [Media::factory()->create(['owner_id' => $user->id])->id] : [];
        $photos = [];
        for ($i = 0; $i < $limit + (int) $excess - count($ids); $i++) {
            $photos[] = UploadedFile::fake()->image('obiad-'.$i.'.jpg');
        }
        $this->get($create)->assertOk();
        $result = $this->from($create)->post($store, [
            'media_ids' => $ids, 'photos' => $photos, 'visibility' => 'private',
            'body' => 'Zachowaj opis obiadu.', 'note' => 'Zachowaj opis obiadu.',
        ])->assertRedirect();
        if ($excess) {
            $result->assertSessionHasErrors('photos');
            $this->assertDatabaseCount($form === 'posts' ? 'posts' : 'cooked_events', 0);
            $returned = $this->get($create)->assertOk()->assertSee('Zachowaj opis obiadu.');
            foreach ($ids as $id) {
                $returned->assertSee('value="'.$id.'"', false);
            }
        } else {
            $result->assertSessionHasNoErrors();
            $entity = $form === 'posts' ? Post::sole() : CookedEvent::sole();
            $this->assertSame($limit, $entity->media()->count());
            foreach ($ids as $id) {
                $this->assertTrue($entity->media()->where('media.id', $id)->exists());
            }
        }
    }

    public static function avatars(): array
    {
        return [
            ['pending', null, true, 'Twoje nowe zdjęcie się przygotowuje'],
            ['processing', null, true, 'Twoje nowe zdjęcie się przygotowuje'],
            ['rejected', null, false, 'Wybierz zdjęcie ponownie'],
            ['rejected', 'podglad', false, 'To jest Twoje zdjęcie.'],
            ['ready', 'thumb', false, 'To jest Twoje zdjęcie.'],
            ['deleted', 'thumb', false, 'Nie masz jeszcze swojego zdjęcia.'],
            [null, null, false, 'Nie masz jeszcze swojego zdjęcia.'],
        ];
    }

    #[DataProvider('avatars')]
    public function test_stan_awatara_i_prawdziwy_komunikat(?string $status, ?string $variant, bool $waiting, string $message): void
    {
        $user = $this->user();
        if ($status !== null) {
            $media = Media::factory()->create([
                'owner_id' => $user->id, 'status' => $status,
                'metadata' => $variant === null ? [] : ['variants' => [$variant => ['key' => 'test/avatar.webp', 'width' => 320, 'height' => 320]]],
            ]);
            $user->profile->forceFill(['avatar_media_id' => $media->id])->save();
            $user->refresh();
        }
        $response = $this->actingAs($user)->get(route('settings.avatar'))->assertOk();
        $response->assertSeeText($message)->assertSee('id="f-avatar"', false)->assertSeeText('Zapisz zdjęcie');
        $this->assertSame($waiting, $user->profile->zdjecieSieJeszczePrzygotowuje());
        $this->assertSame($variant !== null && $status !== 'deleted', $user->profile->zdjecieDoPokazania() !== null);
        if (! $waiting) {
            $response->assertDontSeeText('Odśwież tę stronę za chwilę');
        }
    }
}
