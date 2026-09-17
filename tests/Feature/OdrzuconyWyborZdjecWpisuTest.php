<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OdrzuconyWyborZdjecWpisuTest extends TestCase
{
    use RefreshDatabase;

    public static function wybory(): array
    {
        return ['zwykly limit' => [15, false], 'inny limit i zachowane zdjecie' => [2, true]];
    }

    #[DataProvider('wybory')]
    public function test_odmowa_wyjasnia_ponowny_wybor_nowych_zdjec_a_korekta_zapisuje_wpis(int $limitMb, bool $zachowane): void
    {
        Storage::fake('public');
        config(['kuking.media.max_bytes' => $limitMb * 1024 * 1024]);
        $user = $this->user();
        $mediaIds = $zachowane ? [Media::factory()->create(['owner_id' => $user->getKey()])->getKey()] : [];
        $body = 'Opis pozostaje po odrzuceniu nowych zdjęć.';
        $message = 'Jedno ze zdjęć waży za dużo. Wybierz ponownie wszystkie nowe zdjęcia — każde do '.$limitMb.' MB.';

        $this->actingAs($user)->get(route('posts.create'))->assertOk();

        $response = $this->followingRedirects()->actingAs($user)->from(route('posts.create'))->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg'), UploadedFile::fake()->image('duze.jpg')->size($limitMb * 1024 + 1)],
            'media_ids' => $mediaIds,
            'body' => $body,
            'visibility' => 'private',
        ])->assertOk();
        $this->followRedirects = false;

        $this->assertSame(0, Post::count());
        $this->assertSame(count($mediaIds), Media::count());

        $html = $response->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($dom);
        $this->assertSame($body, trim($xpath->query('//textarea[@name="body"]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//input[@name="visibility" and @value="private" and @checked]')->length);
        foreach ([
            '//*[contains(concat(" ", normalize-space(@class), " "), " error-summary ")]//a',
            '//form[contains(@class,"panel-formularza")]//div[.//input[@id="f-photos"]]/span[@class="field-error"]',
        ] as $selector) {
            $nodes = $xpath->query($selector);
            $this->assertNotFalse($nodes);
            $this->assertSame(1, $nodes->length, $selector);
            $this->assertSame($message, trim($nodes->item(0)->textContent));
        }
        $this->assertSame(count($mediaIds), $xpath->query('//input[@name="media_ids[]"]')->length);
        if ($zachowane) {
            $this->assertStringContainsString('Twoje zdjęcia są zachowane.', $html);
        }

        $this->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg')],
            'media_ids' => $mediaIds,
            'body' => $body,
            'visibility' => 'private',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $post = Post::sole();
        $this->assertSame($body, $post->body);
        $this->assertSame('private', $post->visibility);
        $this->assertSame(count($mediaIds) + 1, $post->media()->count());
        foreach ($mediaIds as $id) {
            $this->assertTrue($post->media()->whereKey($id)->exists());
        }
    }

    public function test_regula_bez_kontekstu_wpisu_zachowuje_dotychczasowy_komunikat(): void
    {
        $this->actingAs($this->user());
        $validator = Validator::make(['photo' => UploadedFile::fake()->image('duze.jpg')->size(16 * 1024)], ['photo' => [new ObslugiwaneZdjecie]]);
        $this->assertTrue($validator->fails());
        $this->assertSame(LimityZdjec::komunikatZaDuzyPlik(), $validator->errors()->first('photo'));
        $this->assertStringNotContainsString('wszystkie nowe zdjęcia', $validator->errors()->first('photo'));
    }
}
