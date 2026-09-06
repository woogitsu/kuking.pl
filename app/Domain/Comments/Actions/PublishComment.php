<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use RuntimeException;

/**
 * Dodanie komentarza pod wpisem, przepisem albo "Ugotowałem".
 *
 * Świadoma decyzja: odpowiedzi są jednopoziomowe. `parent_id` wskazuje na
 * komentarz główny, a odpowiedź na odpowiedź jest "podnoszona" do tego samego
 * wątku. Głębokie drzewa są nieczytelne przy powiększonym tekście i na
 * telefonie — a to nasi główni użytkownicy.
 */
final class PublishComment
{
    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(
        User $author,
        Post|Recipe|CookedEvent $subject,
        string $body,
        ?Comment $parent = null,
    ): Comment {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Napisz coś, zanim wyślesz komentarz.');
        }

        $subjectOwner = $this->ownerOf($subject);

        if ($author->hasBlockRelationWith($subjectOwner)) {
            throw new RuntimeException('Nie można tu komentować.');
        }

        /*
         * BLOKADA OBOWIĄZUJE TAKŻE PRZY ODPOWIADANIU (audyt W7-06).
         *
         * Blokada działała dotąd na trzech powierzchniach: renderowanie
         * (`Comment::scopeWidoczneDla`), powiadomienia (`NotifyUser`) i tu —
         * ale tylko wobec WŁAŚCICIELA treści. Wobec autora komentarza,
         * pod którym się odpowiada, nie działała nigdzie.
         *
         * Scenariusz: A i B są w relacji blokady, ale oboje mogą komentować
         * u C. B pisze komentarz X. A go nie widzi, bo filtr go ukrywa —
         * ale znając UUID komentarza X (ze wspólnego znajomego, ze zrzutu
         * ekranu, sprzed blokady) A mógł wysłać `parent_id = X` i utworzyć
         * odpowiedź STRUKTURALNIE podpiętą pod wątek B.
         *
         * Powiadomienie do B i tak nie szło, bo warstwa powiadomień
         * sprawdza blokadę osobno — więc nękania z tego nie było. Ale zapis
         * przekraczał granicę, o której interfejs mówi, że jej nie da się
         * przekroczyć, a wątek B rósł o cudzą odpowiedź.
         *
         * Sprawdzenie stoi TUTAJ, a nie tylko w kontrolerze, bo kontrolery
         * są trzy (wpis, przepis, „Ugotowałem") i czwarty by o tym zapomniał.
         */
        if ($parent !== null) {
            if (! $this->naleziDo($parent, $subject)) {
                throw new RuntimeException('Nie można tu komentować.');
            }

            $autorRodzica = $parent->author;

            if ($autorRodzica !== null && $author->hasBlockRelationWith($autorRodzica)) {
                // Ten sam komunikat co przy blokadzie z autorem treści —
                // celowo. Osobny tekst („ta osoba Cię zablokowała")
                // potwierdzałby, kto kogo zablokował, komuś, kto właśnie
                // próbuje to obejść.
                throw new RuntimeException('Nie można tu komentować.');
            }
        }

        // Spłaszczamy wątki: odpowiedź na odpowiedź trafia do korzenia wątku.
        $parentId = $parent?->parent_id ?? $parent?->getKey();

        $comment = Comment::create([
            'author_id' => $author->getKey(),
            'post_id' => $subject instanceof Post ? $subject->getKey() : null,
            'recipe_id' => $subject instanceof Recipe ? $subject->getKey() : null,
            'cooked_event_id' => $subject instanceof CookedEvent ? $subject->getKey() : null,
            'parent_id' => $parentId,
            'body' => $body,
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->notify->handle(
            recipient: $subjectOwner,
            type: $parentId === null ? Notification::TYPE_COMMENT : Notification::TYPE_REPLY,
            actor: $author,
            data: [
                'comment_id' => $comment->getKey(),
                'excerpt' => mb_substr($body, 0, 120),
                'url' => $this->urlFor($subject),
            ],
        );

        // Jeśli odpowiadamy komuś innemu niż autor treści, ta osoba też
        // powinna się dowiedzieć — inaczej rozmowa się nie kleji.
        if ($parent !== null && $parent->author_id !== $subjectOwner->getKey()) {
            $this->notify->handle(
                recipient: $parent->author,
                type: Notification::TYPE_REPLY,
                actor: $author,
                data: [
                    'comment_id' => $comment->getKey(),
                    'excerpt' => mb_substr($body, 0, 120),
                    'url' => $this->urlFor($subject),
                ],
            );
        }

        return $comment;
    }

    /**
     * Czy ten komentarz naprawdę stoi pod tą treścią.
     *
     * Kontrolery szukają rodzica przez relację treści, więc same z siebie
     * tego nie przepuszczą. Powtarzamy to tutaj, bo akcja domenowa nie może
     * zakładać, że każdy przyszły wywołujący zrobi to samo — a odpowiedź
     * podpięta pod komentarz z INNEJ strony rozjeżdża wątek w obie strony:
     * u siebie jej nie widać, a w cudzym wątku wisi.
     */
    private function naleziDo(Comment $parent, Post|Recipe|CookedEvent $subject): bool
    {
        return match (true) {
            $subject instanceof Post => $parent->post_id === $subject->getKey(),
            $subject instanceof Recipe => $parent->recipe_id === $subject->getKey(),
            $subject instanceof CookedEvent => $parent->cooked_event_id === $subject->getKey(),
        };
    }

    private function ownerOf(Post|Recipe|CookedEvent $subject): User
    {
        return $subject instanceof CookedEvent ? $subject->user : $subject->author;
    }

    private function urlFor(Post|Recipe|CookedEvent $subject): string
    {
        return $subject->url();
    }
}
