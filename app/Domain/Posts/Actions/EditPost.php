<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Domain\Tags\Actions\ResolvePostTags;
use App\Domain\Tags\TagMutationLock;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * Edycja wpisu: tekst, widoczność, tagi (issue: menu „…" pokazywało tylko
 * „Otwórz wpis" dla autora — luka MVP, `docs/FEATURES.md` sekcja „Wpis").
 *
 * ZDJĘCIA ZOSTAJĄ POZA TĄ AKCJĄ. Kolejność i sposób wyświetlania zdjęć mają
 * już swój ekran („Zdjęcia w tym wpisie", `ArrangePostMedia`) — powielanie
 * tego tutaj dałoby dwie drogi do tego samego stanu i dwie okazje do rozjazdu.
 *
 * DLACZEGO OSOBNA AKCJA, A NIE `PublishPost::handle()` DRUGI RAZ
 * `PublishPost` tworzy NOWY wpis: liczy „czy to pierwszy wpis" i wysyła
 * powiadomienie do gospodarza. Wywołanie jej na istniejącym wpisie
 * wysłałoby to powiadomienie drugi raz przy zwykłej poprawce literówki.
 */
final class EditPost
{
    public function __construct(private readonly ResolvePostTags $resolveTags) {}

    /** @param  list<string>  $tagNames  to, co ktoś WPISAŁ jako tagi (wolny tekst, nie id) — D-021 */
    public function handle(
        Post $post,
        ?string $body,
        string $visibility,
        array $tagNames = [],
    ): Post {
        $body = $this->cleanBody($body);

        // Ten sam twardy warunek co przy publikacji (PublishPost): wpis musi
        // mieć CO NAJMNIEJ zdjęcie ALBO tekst. Zdjęć ten ekran nie dotyka,
        // więc liczy się to, co wpis ma już przypięte.
        if ($body === null && $post->media()->count() === 0) {
            throw new BladDlaCzlowieka('Wpis nie może być całkiem pusty. Napisz kilka słów.');
        }

        return DB::transaction(function () use ($post, $body, $visibility, $tagNames): Post {
            TagMutationLock::forPost();
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $tags = $this->resolveTags->handle($body, $tagNames, $locked);
            $locked->forceFill(['body' => $body, 'visibility' => $visibility])->save();

            // Cały pivot ma tylko pozycję i pochodzenie. Odtworzenie go
            // atomowo unika kolizji UNIQUE(post_id, position) przy zamianie
            // kolejności tagów. Media, status i published_at pozostają.
            $locked->tags()->detach();
            $locked->tags()->attach($tags);

            return $locked;
        }, 3);
    }

    private function cleanBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $trimmed = trim($body);

        return $trimmed === '' ? null : $trimmed;
    }
}
