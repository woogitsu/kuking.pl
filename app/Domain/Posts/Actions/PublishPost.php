<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Models\AuditLogEntry;
use App\Models\Media;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Publikacja wpisu: zdjęcie + kilka słów.
 *
 * To najważniejsza operacja w produkcie i musi się udawać w mniej niż minutę.
 * Dlatego: brak wymaganego tekstu przy zdjęciu, brak wymaganej kategorii,
 * brak wymaganego opisu alternatywnego.
 *
 * Jedyny twardy warunek: wpis musi mieć CO NAJMNIEJ zdjęcie ALBO tekst.
 * Puste wpisy nie wnoszą nic i tylko zaśmiecają archiwum.
 */
final class PublishPost
{
    /**
     * @param  list<string>  $mediaIds  identyfikatory już wgranych zdjęć, w kolejności
     */
    public function handle(
        User $author,
        ?string $body,
        array $mediaIds = [],
        string $visibility = Post::VISIBILITY_PUBLIC,
        ?string $recipeId = null,
        ?string $topicId = null,
        ?string $ip = null,
    ): Post {
        $body = $this->cleanBody($body);

        if ($body === null && $mediaIds === []) {
            throw new \RuntimeException('Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować.');
        }

        // Bierzemy tylko zdjęcia należące do tej osoby. Bez tego ktoś mógłby
        // podstawić cudze media_id w formularzu (IDOR).
        $ownedMedia = Media::query()
            ->where('owner_id', $author->getKey())
            ->whereIn('id', $mediaIds)
            ->pluck('id')
            ->all();

        // Zachowujemy kolejność wybraną przez użytkownika.
        $orderedMedia = array_values(array_filter(
            $mediaIds,
            static fn (string $id): bool => in_array($id, $ownedMedia, true),
        ));

        $orderedMedia = array_slice($orderedMedia, 0, (int) config('kuking.media.max_per_post'));

        // Temat jest OPCJONALNY i musi pochodzić z zamkniętej listy (issue #31).
        // Sprawdzamy istnienie i to, czy temat nie jest wycofany — inaczej
        // podstawiony identyfikator wpuściłby wpis do tematu, którego redakcja
        // już nie prowadzi. `null` przy nieznanym: wpis bez tematu jest w pełni
        // poprawny, więc lepiej opublikować bez niego niż odmówić publikacji.
        $topicId = $topicId === null ? null : Topic::doWyboru()->whereKey($topicId)->value('id');

        $post = DB::transaction(function () use ($author, $body, $visibility, $recipeId, $topicId, $orderedMedia): Post {
            $post = Post::create([
                'author_id' => $author->getKey(),
                'body' => $body,
                'visibility' => $visibility,
                'status' => Post::STATUS_PUBLISHED,
                'recipe_id' => $recipeId,
                'topic_id' => $topicId,
                'published_at' => now(),
            ]);

            foreach ($orderedMedia as $position => $mediaId) {
                $post->media()->attach($mediaId, ['position' => $position]);
            }

            return $post;
        });

        AuditLogEntry::record(
            action: 'post.published',
            actor: $author,
            subject: $post,
            metadata: ['media_count' => count($orderedMedia), 'visibility' => $visibility],
            ip: $ip,
        );

        return $post;
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
