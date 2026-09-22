<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * Kto może zobaczyć i zmienić wpis.
 *
 * UUID w adresie NIE JEST autoryzacją. Każde wejście na wpis przechodzi
 * przez tę politykę — inaczej mamy IDOR-a i wystarczy zgadnąć albo znaleźć
 * identyfikator, żeby czytać treści prywatne.
 */
class PostPolicy
{
    public function view(?User $user, Post $post): bool
    {
        if ($post->kind === Post::KIND_QUESTION && ! config('kuking.questions.enabled', false)) {
            return false;
        }

        if (! $post->isPublished()) {
            return $user !== null && $user->getKey() === $post->author_id;
        }

        $isOwnerOrModerator = $user !== null
            && ($user->getKey() === $post->author_id || $user->isModerator());

        // Konto autora zbanowane albo oznaczone do usunięcia — ta sama granica
        // co `UserPolicy::viewProfile` (audyt A5). Bez tego wpis zostawał
        // dostępny pod bezpośrednim adresem, mimo że link „zobacz profil" pod
        // nim dawał 403 — obietnica bez pokrycia w drugą stronę. Zawieszenie
        // NIE wchodzi tutaj: to kara czasowa i tylko na publikowanie
        // („dostęp tylko do ODCZYTU" — `EnsureAccountIsActive`), więc treść
        // zawieszonej osoby zostaje widoczna tak jak jej profil.
        if (! $isOwnerOrModerator && ! $post->author->jestDostepnyJakoAutor()) {
            return false;
        }

        // Blokada działa w obie strony i ma pierwszeństwo przed wszystkim innym.
        if ($user !== null && $user->hasBlockRelationWith($post->author)) {
            return false;
        }

        // WPIS WSKAZUJĄCY PRZEPIS: BRAMKĄ JEST PRZEPIS, NIE WPIS (#368).
        //
        // Taki wpis nie ma własnej treści — `body = null`, zero wierszy
        // w `post_media` — a tytuł, slug i zdjęcie bierze z relacji `recipe`.
        // Jego `visibility` to na stałe `public` i NIE jest to kopia
        // widoczności przepisu, tylko brak własnego zawężenia
        // (`WpisWskazujacyPrzepis::dopisz()`). Sam `match` niżej przepuszcza
        // więc taki wpis ZAWSZE i każdemu.
        //
        // ODTWORZONE POMIAREM. Przepis `followers`, wpis-zapowiedź, komentarz
        // od obcej osoby:
        //   [obca osoba]          status: 200  TYTUŁ: true  SLUG: true  ZDJĘCIE: true
        //   [gość niezalogowany]  status: 200  TYTUŁ: true  SLUG: true  ZDJĘCIE: true
        //
        // Dotąd ratowało to przekierowanie w `PostController::show()` — ale
        // tylko dopóki `Post::jestSamymPrzepisem()` zwraca `true`, a ono
        // zwraca `false`, gdy wpis ma choć jeden komentarz. Komentowanie
        // przechodzi przez `comment()`, czyli przez tę samą politykę, więc
        // obcy SAM WYTWARZAŁ warunek wyłączający zabezpieczenie: wystarczyło
        // skomentować cudzą zapowiedź. Zabezpieczenie, które znika, gdy ktoś
        // obcy wykona dozwoloną akcję, nie jest zabezpieczeniem — dlatego
        // odpowiedź musi paść TUTAJ, w polityce, a nie w kontrolerze.
        //
        // DROGA 1 („WIDOK/Policy" z `WidocznoscTestCase`). `Post::scopeZWidocznymPrzepisem()`
        // pilnuje dokładnie tej samej reguły na DRODZE 2 (listy, strumienie),
        // ale jest zakresem ZAPYTANIA — nie odpowiada na pytanie o jeden
        // obiekt, a właśnie takie pytanie zadaje `authorize('view', $post)`.
        //
        // Pytamy `RecipePolicy::view()`, a nie własnego `match` na
        // `$post->recipe->visibility`: to ta sama tabela prawdy, którą scope
        // czyta przez `Recipe::scopeWidoczneDla()`. Własna kopia byłaby
        // kolejnym miejscem do rozjechania się — i ominęłaby ban autora,
        // blokadę oraz przepis zdjęty przez moderację. Wzorzec jak
        // w `CommentPolicy::view()`, która tak samo deleguje do rodzica.
        //
        // AUTOR PRZECHODZI. `RecipePolicy::view()` wpuszcza właściciela do
        // przepisu `private` i do przepisu zdjętego przez moderację
        // (`! isPublished()` → autor albo moderator), więc autor nie traci
        // dostępu do własnej zapowiedzi ani do rozmowy pod nią.
        //
        // PRZEPIS USUNIĘTY (miękko) daje `$post->recipe === null`, bo relacja
        // prowadzi do modelu z `SoftDeletes`. Odmawiamy — tak samo jak
        // `scopeZWidocznymPrzepisem()`, którego `whereHas('recipe')` takiego
        // wpisu nie zwróci nikomu, łącznie z autorem.
        if ($post->recipe_id !== null) {
            $przepis = $post->recipe;

            if ($przepis === null || ! app(RecipePolicy::class)->view($user, $przepis)) {
                return false;
            }
        }

        return match ($post->visibility) {
            Post::VISIBILITY_PUBLIC => true,
            Post::VISIBILITY_FOLLOWERS => $user !== null
                && ($user->getKey() === $post->author_id || $user->isFollowing($post->author)),
            Post::VISIBILITY_PRIVATE => $user !== null && $user->getKey() === $post->author_id,
            default => false,
        };
    }

    public function update(User $user, Post $post): bool
    {
        return $user->getKey() === $post->author_id;
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->getKey() === $post->author_id || $user->isModerator();
    }

    public function comment(User $user, Post $post): bool
    {
        return $this->view($user, $post) && $user->isActive();
    }
}
