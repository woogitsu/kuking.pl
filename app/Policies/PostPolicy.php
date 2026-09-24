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
            if ($user === null) {
                return false;
            }

            if ($user->getKey() === $post->author_id) {
                return true;
            }

            // Wpis ukryty przez moderację otwiera jeszcze obsługa, która
            // rozpatruje sprawę albo odwołanie (#1018) — inaczej przywrócenie
            // szło „w ciemno", bez zdjęć, wątku i skutku edycji autora.
            // Ta sama bramka co panel `/admin` (`moderator` + `moderator.2fa`):
            // czynna rola ORAZ potwierdzone 2FA. Szkic zostaje wyłącznie
            // autora. Blokada działa w obie strony także tutaj (AGENTS.md §4).
            // `removed` nie wchodzi: jest miękko usunięty, więc wiązanie trasy
            // i tak go nie znajdzie — przywraca się go z panelu (#65).
            return $post->status === Post::STATUS_HIDDEN
                && self::obslugaZDwomaSkladnikami($user)
                && ! $user->hasBlockRelationWith($post->author);
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

        /*
         * ZAPOWIEDŹ PRZEPISU MA BRAMKĘ W PRZEPISIE, NIE W SOBIE (#368).
         *
         * `WpisWskazujacyPrzepis::dopisz()` zapisuje takiemu wpisowi
         * `visibility = 'public'` — i pisze wprost, że to NIE jest decyzja
         * o jawności, tylko brak własnego zawężenia, bo „jedyną bramką jest
         * przepis (`Post::scopeZWidocznymPrzepisem()`)". Ten zakres stał
         * dotąd wyłącznie w ZAPYTANIACH LIST. Wejście POD BEZPOŚREDNI ADRES
         * wpisu go omijało: `match` niżej widział `public` i przepuszczał
         * każdego, a strona wypisywała tytuł przepisu, jego slug w adresie
         * i zdjęcie główne — zmierzone dla gościa na przepisie „tylko dla
         * obserwujących".
         *
         * Przekierowanie na przepis (`PostController::show()`) tego nie
         * łatało z dwóch powodów naraz: samo wydaje slug, a przy wpisie
         * z choć jednym komentarzem w ogóle nie wchodzi
         * (`jestSamymPrzepisem()`).
         *
         * DLACZEGO TU, A NIE W KONTROLERZE. Bo to jest pytanie o prawo
         * wejścia, a „UUID w adresie to nie autoryzacja" — każde wejście ma
         * przechodzić przez Policy. Stąd korzysta z tego także
         * `comment()` niżej: pod zapowiedzią cudzego ukrytego przepisu nie
         * da się teraz dopisać komentarza (a to właśnie komentarz zdejmował
         * przekierowanie).
         *
         * ODMOWA, A NIE STRONA BEZ TYTUŁU — tylko dla ZAPOWIEDZI, czyli
         * wpisu bez własnej treści i bez własnych zdjęć. Taki wpis nie ma
         * nic, co dałoby się pokazać po zdjęciu przepisu: został by pusty
         * nagłówek. Wpis Z WŁASNĄ treścią zostaje dostępny i traci wyłącznie
         * odwołanie do przepisu — patrz `PostController::show()`.
         */
        if ($post->czyJestZapowiedziaPrzepisu()
            && ($post->recipe === null || ! app(RecipePolicy::class)->view($user, $post->recipe))) {
            return false;
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

    /**
     * Zwykłe usunięcie (`DELETE` ze strony treści) — wyłącznie autor.
     *
     * Issue #932: moderator NIE usuwa tędy cudzej treści, nawet z 2FA.
     * Ta droga omija panel `/admin` (2FA — `moderator.2fa`), uzasadnienie,
     * wiersz w `moderation_actions`, powiadomienie i odwołanie (DSA art. 17
     * i 20). Cudzą treść zdejmuje się decyzją „Usuń" w `/admin/zgloszenia`.
     */
    public function delete(User $user, Post $post): bool
    {
        return $user->getKey() === $post->author_id;
    }

    /**
     * Zdjęcie wpisu Z URZĘDU, bez zgłoszenia, z panelu moderacji (G31, D-251).
     * Reguła: `UserPolicy::takeDownContentOf()` — 2FA i niższa rola autora.
     */
    public function removeExOfficio(User $user, Post $post): bool
    {
        return app(UserPolicy::class)->takeDownContentOf($user, $post->author);
    }

    public function comment(User $user, Post $post): bool
    {
        // Podgląd ukrytego wpisu dla moderatora (#1018) to odczyt, nie
        // rozmowa: pod cudzym nieopublikowanym wpisem nie komentuje nikt.
        if (! $post->isPublished() && $user->getKey() !== $post->author_id) {
            return false;
        }

        return $this->view($user, $post) && $user->isActive();
    }

    /**
     * Czynny moderator albo administrator z potwierdzonym 2FA — ten sam
     * warunek, który stawia grupa `/admin` (`EnsureUserIsModerator`
     * + `EnsureModeratorHasTwoFactor`), tylko zadany tu, bo strona wpisu
     * leży poza tą grupą.
     */
    public static function obslugaZDwomaSkladnikami(User $user): bool
    {
        return $user->isModerator() && $user->hasTwoFactorConfirmed();
    }
}
