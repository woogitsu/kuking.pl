<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Jobs\ProcessUploadedImage;
use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PonowieniePublikacjiNieWgrywaZdjecTest extends TestCase
{
    use RefreshDatabase;

    public static function forms(): array
    {
        return [['posts'], ['questions'], ['cooked']];
    }

    #[DataProvider('forms')]
    public function test_ponowienie_nie_dodaje_mediow_plikow_ani_zadan(string $form): void
    {
        Storage::fake('public');
        Queue::fake();
        config(['kuking.questions.enabled' => true]);
        $author = $this->user();
        $cook = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $url = $form === 'cooked' ? route('cooked.store', $recipe->slug) : route($form.'.store');
        $key = (string) Str::uuid();
        $payload = ['klucz_wyslania' => $key, 'body' => 'Dobry obiad', 'title' => 'Jak ugotować dobry obiad?', 'visibility' => 'public'];
        $send = fn () => $this->actingAs($cook)->post($url, $payload + ['photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)]]);
        $first = $send()->assertRedirect()->assertSessionHasNoErrors();
        $files = Storage::disk('public')->allFiles();
        $this->assertNotEmpty($files);
        $this->assertDatabaseCount('media', 1);
        Queue::assertPushed(ProcessUploadedImage::class, 1);
        $second = $send()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame($files, Storage::disk('public')->allFiles(), 'Ponowienie zapisało dodatkowe obiekty.');
        $this->assertDatabaseCount('media', 1);
        Queue::assertPushed(ProcessUploadedImage::class, 1);
        if ($form === 'cooked') {
            $this->assertDatabaseCount('cooked_events', 1);
            $this->assertSame(1, Notification::where('type', Notification::TYPE_COOKED)->count());
        } else {
            $this->assertDatabaseCount('posts', 1);
        }
    }

    #[DataProvider('forms')]
    public function test_inny_klucz_i_inna_osoba_nadal_publikuja(string $form): void
    {
        Storage::fake('public');
        Queue::fake();
        config(['kuking.questions.enabled' => true]);
        $author = $this->user();
        $cook = $this->user();
        $other = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $url = $form === 'cooked' ? route('cooked.store', $recipe->slug) : route($form.'.store');
        $key = (string) Str::uuid();
        foreach ([[$cook, $key], [$cook, (string) Str::uuid()], [$other, $key]] as [$user, $submission]) {
            $this->actingAs($user)->post($url, [
                'klucz_wyslania' => $submission, 'body' => 'Dobry obiad',
                'title' => 'Jak ugotować dobry obiad?', 'visibility' => 'public',
                'photos' => [UploadedFile::fake()->image('obiad.jpg')],
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('media', 3);
        $this->assertDatabaseCount($form === 'cooked' ? 'cooked_events' : 'posts', 3);
        Queue::assertPushed(ProcessUploadedImage::class, 3);
        if ($form === 'cooked') {
            $this->assertSame(3, Notification::where('type', Notification::TYPE_COOKED)->count());
        }
    }

    public function test_blokada_autora_odrzuca_ponowienie_przed_zapisem_zdjec(): void
    {
        Storage::fake('public');
        Queue::fake();
        $author = $this->user();
        $cook = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $key = (string) Str::uuid();
        $this->actingAs($cook)->post(route('cooked.store', $recipe->slug), ['klucz_wyslania' => $key])->assertRedirect();
        app(BlockUser::class)->handle($author, $cook);
        $this->actingAs($cook)->post(route('cooked.store', $recipe->slug), [
            'klucz_wyslania' => $key, 'photos' => [UploadedFile::fake()->image('obiad.jpg')],
        ])->assertForbidden();
        $this->assertDatabaseCount('media', 0);
        Queue::assertNotPushed(ProcessUploadedImage::class);
    }
}
