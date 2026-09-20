<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrzeanalizujTresc;
use App\Models\Media;
use App\Models\Post;
use App\Moderacja\KlientOpenAI;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ModeracjaBezpieczneBledyTest extends TestCase
{
    use RefreshDatabase;

    public static function stages(): array
    {
        return [['http'], ['image'], ['job']];
    }

    #[DataProvider('stages')]
    public function test_obcy_wyjatek_nie_trafia_do_dziennika(string $stage): void
    {
        config(['kuking.moderation.model.klucz' => 'test', 'kuking.moderation.model.ocenia_zdjecia' => true]);
        $entries = [];
        Log::shouldReceive('warning')->once()->andReturnUsing(function ($message, $context) use (&$entries): void {
            $entries[] = [$message, $context];
        });
        $exception = new RuntimeException('TAJNY_TEKST user@example.invalid SECRET_TOKEN https://example.invalid/?token=SECRET_TOKEN');

        if ($stage === 'http') {
            Http::fake(fn () => throw $exception);
            $this->assertNull(app(KlientOpenAI::class)->ocenTekst('Zupa'));
        } elseif ($stage === 'image') {
            $media = Media::factory()->create();
            Storage::shouldReceive('disk')->andThrow($exception);
            $this->assertSame([], app(OcenaModelem::class)->dlaZdjecia($media));
        } else {
            $post = Post::factory()->create();
            Post::retrieved(fn () => throw $exception);
            try {
                app()->call([new PrzeanalizujTresc('post', $post->id), 'handle']);
            } finally {
                Post::flushEventListeners();
            }
        }

        $this->assertCount(1, $entries);
        $serialized = json_encode($entries);
        foreach (['TAJNY_TEKST', 'user@example.invalid', 'SECRET_TOKEN', 'example.invalid'] as $marker) {
            $this->assertStringNotContainsString($marker, $serialized);
        }
        $this->assertStringContainsString('RuntimeException', $serialized);
    }
}
