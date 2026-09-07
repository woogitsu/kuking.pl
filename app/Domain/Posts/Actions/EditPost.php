<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Topic;

/**
 * Edycja wpisu: tekst, widoczność, temat (issue: menu „…" pokazywało tylko
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
    public function __construct(private readonly ResolveTagsForPost $resolveTags) {}

    /** @param  list<string>  $tagNames  to, co ktoś WPISAŁ jako tagi (wolny tekst, nie id) — D-021 */
    public function handle(
        Post $post,
        ?string $body,
        string $visibility,
        ?string $topicId = null,
        array $tagNames = [],
    ): Post {
        $body = $this->cleanBody($body);

        // Ten sam twardy warunek co przy publikacji (PublishPost): wpis musi
        // mieć CO NAJMNIEJ zdjęcie ALBO tekst. Zdjęć ten ekran nie dotyka,
        // więc liczy się to, co wpis ma już przypięte.
        if ($body === null && $post->media()->count() === 0) {
            throw new BladDlaCzlowieka('Wpis nie może być całkiem pusty. Napisz kilka słów.');
        }

        // Temat musi pochodzić z zamkniętej listy i być wciąż aktywny — ta
        // sama zasada co w PublishPost. `null` przy nieznanym/wycofanym
        // identyfikatorze: zapisanie poprawki nie może się nie udać z powodu
        // tematu, który redakcja właśnie wycofała.
        $topicId = $topicId === null ? null : Topic::doWyboru()->whereKey($topicId)->value('id');

        // Tagi (D-021) — ta sama bramka co przy publikacji, rzuca
        // `BladDlaCzlowieka`, jeśli po rozwiązaniu zostaje więcej niż limit.
        $tags = $this->resolveTags->handle($tagNames);

        // Zmiana widoczności z publicznej na prywatną (i odwrotnie) nie
        // rusza `published_at` — `Post::isPublished()` patrzy tylko na status
        // i tę datę, nie na widoczność. Nie ma też żadnego powiadomienia
        // powiązanego z widocznością wpisu (jedyne dla wpisów to
        // `Notification::TYPE_FIRST_POST`, wysyłane wyłącznie przy
        // publikacji) — więc nie ma tu nic do wycofania ani do wysłania.
        $post->forceFill([
            'body' => $body,
            'visibility' => $visibility,
            'topic_id' => $topicId,
        ])->save();

        // Zastępujemy CAŁY zestaw tagów — to jest edycja, nie dopisywanie.
        // `sync()` samo liczy różnicę (dodaj/usuń), więc tag, który zostaje
        // na miejscu, nie traci i nie zyskuje niczego w pivotach bez potrzeby.
        $post->tags()->sync($this->pozycje($tags));

        return $post;
    }

    /**
     * @param  list<Tag>  $tags
     * @return array<string, array{position: int}>
     */
    private function pozycje(array $tags): array
    {
        $mapa = [];

        foreach ($tags as $position => $tag) {
            $mapa[$tag->getKey()] = ['position' => $position];
        }

        return $mapa;
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
