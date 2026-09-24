<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;

class CommentPolicy
{
    /**
     * Czy ten człowiek ma prawo zobaczyć TEN komentarz.
     *
     * TA METODA ODPOWIADAŁA TYLKO NA POŁOWĘ PYTANIA (audyt komentarzy).
     * Cała treść brzmiała „widoczność komentarza to widoczność jego rodzica"
     * i delegowała do `PostPolicy`/`RecipePolicy`/`CookedEventPolicy`. Rodzic
     * jest jednak tylko JEDNĄ z granic — `Comment::scopeWidoczneDla()` liczy
     * dwie kolejne (blokada widz↔autor komentarza, status konta autora), a
     * relacje `comments()` trzecią (status samego komentarza). Polityka nie
     * znała żadnej z nich.
     *
     * ZMIERZONY SKUTEK. Jedynym miejscem, które pyta tę metodę, jest bramka
     * zgłoszeń (`ReportContent::authorize()`, audyt W7-05) — i to wystarczało:
     * pod publicznym wpisem osoba, która KOGOŚ ZABLOKOWAŁA, dostawała na
     * `/zglos/comment/{uuid}` odpowiedź 200 na komentarz, którego na stronie
     * nie widzi, zamiast 404 nieodróżnialnego od „nie ma takiej treści".
     * To samo dla komentarza UKRYTEGO przez moderację. Formularz zgłoszenia
     * nie pokazuje treści, ale sam kod odpowiedzi jest oracle'em istnienia —
     * a dokładnie po to ta bramka powstała. Dodatkowo pozwalało to wciągnąć
     * do moderacji komentarz, którego zgłaszający nie ma prawa przeczytać.
     *
     * Kolejność warunków jest ta sama co w `Comment::scopeWidoczneDla()`, żeby
     * dało się je czytać obok siebie. Różnice są dwie i obie celowe: polityka
     * pilnuje wejścia na JEDNĄ treść, więc może mieć furtki dla autora i dla
     * moderatora (dokładnie tak jak `RecipePolicy::view()` przy nieopublikowanym
     * przepisie), a zakres buduje LISTĘ dla wielu autorów naraz i furtek
     * nie ma — tam pominięcie ich jest ostrzejsze, a więc bezpieczniejsze.
     */
    public function view(?User $user, Comment $comment): bool
    {
        $autorKomentarza = $comment->author;
        $jestAutorem = $user !== null && $user->getKey() === $comment->author_id;
        $jestAutoremLubModeratorem = $jestAutorem || ($user !== null && $user->isModerator());

        // 1. Blokada — pierwsza, bezwarunkowa, w obie strony (`AGENTS.md` §4).
        //    Ani moderator, ani autor treści nie obchodzą tej granicy: to nie
        //    jest kwestia uprawnień, tylko relacji dwóch osób.
        if ($user !== null && $autorKomentarza !== null && $user->hasBlockRelationWith($autorKomentarza)) {
            return false;
        }

        // 2. Konto autora komentarza zbanowane albo oznaczone do usunięcia —
        //    ta sama granica co `UserPolicy::viewProfile()` i `RecipePolicy::view()`.
        //    Zawieszenie NIE wchodzi: kara za pisanie nie kasuje napisanego.
        if ($autorKomentarza !== null && ! $autorKomentarza->jestDostepnyJakoAutor() && ! $jestAutoremLubModeratorem) {
            return false;
        }

        // 3. Komentarz ukryty przez moderację widzi jeszcze jego autor
        //    (potrzebuje tego, żeby się odwołać — DSA art. 20) i moderator.
        if ($comment->status !== Comment::STATUS_PUBLISHED && ! $jestAutoremLubModeratorem) {
            return false;
        }

        // 4. Rodzic. Dokładnie jeden z trzech (CHECK w bazie,
        //    `Comment::subject()`), więc `null` nie powinno się zdarzyć —
        //    a jeśli się zdarzy, odmawiamy, zamiast zgadywać.
        $subject = $comment->subject();

        return match (true) {
            $subject instanceof Post => app(PostPolicy::class)->view($user, $subject),
            $subject instanceof Recipe => app(RecipePolicy::class)->view($user, $subject),
            $subject instanceof CookedEvent => app(CookedEventPolicy::class)->view($user, $subject),
            default => false,
        };
    }

    public function update(User $user, Comment $comment): bool
    {
        // Edycja komentarza tylko przez 15 minut od publikacji. Krótkie okno
        // wystarcza na poprawienie literówki, a nie pozwala zmienić sensu
        // rozmowy po tym, jak ktoś już odpowiedział.
        //
        // Issue #937: komentarz ukryty albo zdjęty przez moderację nie jest
        // już edytowalny. Inaczej autor mógł w oknie 15 minut podmienić treść,
        // którą moderator właśnie ocenił — a przy odwołaniu (DSA art. 20)
        // moderator oglądałby inny tekst niż ten, o którym zdecydował.
        return $user->getKey() === $comment->author_id
            && $comment->status === Comment::STATUS_PUBLISHED
            && $comment->getAttribute('body_removed_at') === null
            && $comment->created_at?->diffInMinutes(now()) < 15;
    }

    /** Odzyskanie własnego tekstu nie otwiera ponownie okna edycji. */
    public function recoverExpiredEdit(User $user, Comment $comment): bool
    {
        return $user->isActive()
            && $user->getKey() === $comment->author_id
            && $comment->body_removed_at === null
            && $comment->status === Comment::STATUS_PUBLISHED
            && ! $comment->trashed()
            && $this->view($user, $comment)
            && $comment->created_at !== null
            && $comment->created_at->diffInMinutes(now()) >= 15;
    }

    /**
     * Zwykłe usunięcie komentarza — autor komentarza albo autor treści,
     * pod którą stoi (to jego kuchnia; `DeleteComment` powiadamia wtedy
     * autora komentarza).
     *
     * Issue #932: moderator NIE usuwa tędy cudzego komentarza, nawet z 2FA.
     * Cudzy komentarz zdejmuje się decyzją „Usuń" w `/admin/zgloszenia`
     * — z uzasadnieniem, wpisem w `moderation_actions` i odwołaniem.
     *
     * Komentarza ukrytego albo zdjętego przez moderację nie usuwa nikt
     * (issue #937) — autorowi zostaje odwołanie.
     */
    public function delete(User $user, Comment $comment): bool
    {
        // Issue #937: po decyzji moderatora komentarz jest zamrożony także dla
        // usunięcia. Przy odpowiedziach `DeleteComment` nadpisuje `body`
        // placeholderem, więc autor albo autor wpisu kasowałby treść, którą
        // moderator ocenił — a odwołanie (DSA art. 20) dotyczy właśnie jej.
        if ($comment->status !== Comment::STATUS_PUBLISHED) {
            return false;
        }

        if ($user->getKey() === $comment->author_id) {
            return true;
        }

        // Autor treści może usunąć komentarz pod swoim wpisem — to jego kuchnia.
        return $user->getKey() === $comment->notifiableUserId();
    }
}
