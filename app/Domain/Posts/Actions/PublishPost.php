<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
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
    public function __construct(
        private readonly NotifyUser $notify,
        private readonly ResolveTagsForPost $resolveTags,
    ) {}

    /**
     * @param  list<string>  $mediaIds  identyfikatory już wgranych zdjęć, w kolejności
     * @param  list<string>  $tagNames  to, co ktoś WPISAŁ jako tagi (wolny tekst, nie id) — D-021
     */
    public function handle(
        User $author,
        ?string $body,
        array $mediaIds = [],
        string $visibility = Post::VISIBILITY_PUBLIC,
        ?string $recipeId = null,
        ?string $topicId = null,
        array $tagNames = [],
        ?string $ip = null,
        string $displayMode = Post::DISPLAY_NORMAL,
    ): Post {
        $body = $this->cleanBody($body);

        if ($body === null && $mediaIds === []) {
            throw new BladDlaCzlowieka('Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować.');
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

        // Tagi (D-021) — rozwiązywane PRZED transakcją tworzącą wpis, żeby
        // `BladDlaCzlowieka` za zbyt wiele tagów przerwało publikację, zanim
        // cokolwiek trafi do bazy (dokładnie tak samo jak sprawdzenie
        // pustego wpisu wyżej).
        $tags = $this->resolveTags->handle($tagNames);

        // Sposób wyświetlania zdjęć (issue #92). Przy jednym zdjęciu wybór nie
        // znaczy nic — karuzela z jednym slajdem i kolaż z jednym polem to ten
        // sam widok co „zwykle" — więc zapisujemy `normal` zamiast trzymać
        // w bazie deklarację, której nie da się zobaczyć. Wartość spoza listy
        // też schodzi do `normal`: baza odrzuciłaby ją CHECK-iem, a wpis, który
        // nie zostaje opublikowany z powodu wyboru układu, to zła zamiana.
        $displayMode = count($orderedMedia) < 2 || ! in_array($displayMode, Post::dozwoloneTrybyWyswietlania(), true)
            ? Post::DISPLAY_NORMAL
            : $displayMode;

        $post = DB::transaction(function () use ($author, $body, $visibility, $recipeId, $topicId, $orderedMedia, $displayMode, $tags): Post {
            $post = Post::create([
                'author_id' => $author->getKey(),
                'body' => $body,
                'visibility' => $visibility,
                'status' => Post::STATUS_PUBLISHED,
                'display_mode' => $displayMode,
                'recipe_id' => $recipeId,
                'topic_id' => $topicId,
                'published_at' => now(),
            ]);

            foreach ($orderedMedia as $position => $mediaId) {
                $post->media()->attach($mediaId, ['position' => $position]);
            }

            foreach ($tags as $position => $tag) {
                $post->tags()->attach($tag->getKey(), ['position' => $position]);
            }

            return $post;
        });

        AuditLogEntry::record(
            action: 'post.published',
            actor: $author,
            subject: $post,
            metadata: ['media_count' => count($orderedMedia), 'visibility' => $visibility, 'display_mode' => $displayMode, 'tag_count' => count($tags)],
            ip: $ip,
        );

        $this->powiadomGospodarzaOPierwszymWpisie($author, $post);

        return $post;
    }

    /**
     * Pierwszy wpis nowej osoby — powiadomienie dla gospodarza (issue #6).
     *
     * 55% osób 55-64 i 62% osób 65+ w mediach społecznościowych to WYŁĄCZNIE
     * odbiorcy treści. Kto opublikuje pierwszy raz, robi to wbrew własnemu
     * nawykowi — i jeśli nikt nie odpowie, drugi raz już nie spróbuje.
     *
     * Powiadomienie idzie NATYCHMIAST, a nie przy najbliższym zajrzeniu
     * do panelu: doba to cały budżet czasu, jaki mamy na odpowiedź.
     *
     * Wpis prywatny pomijamy — nikt poza autorem go nie widzi, więc nie ma
     * na co odpowiadać.
     */
    private function powiadomGospodarzaOPierwszymWpisie(User $author, Post $post): void
    {
        if ($post->visibility === Post::VISIBILITY_PRIVATE) {
            return;
        }

        // Liczymy DOKŁADNIE DO DWÓCH: przy autorze z dwustoma wpisami
        // pełne `count()` przelicza całą historię, żeby odpowiedzieć
        // na pytanie „czy to pierwszy".
        $ilePierwszych = Post::query()
            ->where('author_id', $author->getKey())
            ->published()
            ->limit(2)
            ->count();

        if ($ilePierwszych !== 1) {
            return;
        }

        $nazwaGospodarza = (string) config('kuking.community.host_username');

        if ($nazwaGospodarza === '') {
            return;
        }

        $gospodarz = Profile::poNazwie($nazwaGospodarza)?->user;

        // `NotifyUser` sam pomija sytuację, w której gospodarz jest autorem —
        // a to jest częsty przypadek przy pierwszych dwudziestu osobach.
        if ($gospodarz === null) {
            return;
        }

        $this->notify->handle(
            recipient: $gospodarz,
            type: Notification::TYPE_FIRST_POST,
            actor: $author,
            data: ['post_id' => $post->getKey(), 'display_name' => $author->displayName()],
        );
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
