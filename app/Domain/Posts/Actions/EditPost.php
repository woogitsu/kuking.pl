<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Models\Post;
use App\Models\Topic;
use RuntimeException;

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
    public function handle(
        Post $post,
        ?string $body,
        string $visibility,
        ?string $topicId = null,
    ): Post {
        $body = $this->cleanBody($body);

        // Ten sam twardy warunek co przy publikacji (PublishPost): wpis musi
        // mieć CO NAJMNIEJ zdjęcie ALBO tekst. Zdjęć ten ekran nie dotyka,
        // więc liczy się to, co wpis ma już przypięte.
        if ($body === null && $post->media()->count() === 0) {
            throw new RuntimeException('Wpis nie może być całkiem pusty. Napisz kilka słów.');
        }

        // Temat musi pochodzić z zamkniętej listy i być wciąż aktywny — ta
        // sama zasada co w PublishPost. `null` przy nieznanym/wycofanym
        // identyfikatorze: zapisanie poprawki nie może się nie udać z powodu
        // tematu, który redakcja właśnie wycofała.
        $topicId = $topicId === null ? null : Topic::doWyboru()->whereKey($topicId)->value('id');

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
