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
        ?string $questionTitle = null,
    ): Post {
        $body = $this->cleanBody($body);

        // Ten sam twardy warunek co przy publikacji (PublishPost): wpis musi
        // mieć CO NAJMNIEJ zdjęcie ALBO tekst. Zdjęć ten ekran nie dotyka,
        // więc liczy się to, co wpis ma już przypięte.
        if ($post->kind !== Post::KIND_QUESTION && $body === null && $post->media()->count() === 0) {
            throw new BladDlaCzlowieka('Wpis nie może być całkiem pusty. Napisz kilka słów.');
        }

        return DB::transaction(function () use ($post, $body, $visibility, $tagNames, $questionTitle): Post {
            TagMutationLock::forPost();
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->kind === Post::KIND_QUESTION) {
                if (! config('kuking.questions.enabled')) {
                    throw new BladDlaCzlowieka('Edycja pytań jest teraz niedostępna.');
                }
                $title = trim($questionTitle ?? $locked->title);
                if (mb_strlen($title) < 10 || mb_strlen($title) > 180) {
                    throw new BladDlaCzlowieka('Napisz pytanie w tytule — od 10 do 180 znaków.');
                }
                $locked->title = $title;
            }
            $tags = $this->resolveTags->handle($body, $tagNames, $locked);
            if ($locked->kind === Post::KIND_QUESTION && count($tags) > 3) {
                throw new BladDlaCzlowieka('Do pytania dodaj najwyżej 3 tagi, także te wpisane w opisie.');
            }
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
