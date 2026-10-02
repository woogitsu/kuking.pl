<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Recipes\RecipeStatusTransitions;
use App\Http\Support\BlokadyWZadaniu;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;

class RecipePolicy
{
    public function view(?User $user, Recipe $recipe): bool
    {
        if (! $recipe->isPublished()) {
            if ($user === null) {
                return false;
            }

            if ($user->getKey() === $recipe->author_id) {
                return true;
            }

            // SZKIC JEST WYŁĄCZNIE AUTORA — także wobec moderatora (#1359,
            // audyt AUTHZ-01). Moderacja zagląda do treści, którą sama
            // ukryła albo zdjęła (`hidden`/`removed`), bo rozpatruje sprawę
            // albo odwołanie. Rodzinny przepis w warsztacie, którego autor
            // nikomu nie pokazał, nie jest żadną sprawą — ta sama granica co
            // `PostPolicy::view()` dla szkicu wpisu. Lista statusów jest
            // zamknięta: nowy stan nieopublikowany nie otworzy się moderacji
            // po cichu. Blokady ta gałąź celowo nie sprawdza — tak jak przed
            // #1359: czy blokada ma odcinać moderatora od sprawy, to otwarte
            // pytanie B-02 z `docs/AUDYT_BEZPIECZENSTWA_2026-09-15.md`,
            // a nie coś do rozstrzygnięcia przy okazji.
            return in_array($recipe->status, [Recipe::STATUS_HIDDEN, Recipe::STATUS_REMOVED], true)
                && $user->isModerator();
        }

        $isOwnerOrModerator = $user !== null
            && ($user->getKey() === $recipe->author_id || $user->isModerator());

        // Konto autora zbanowane albo oznaczone do usunięcia — ta sama granica
        // co `UserPolicy::viewProfile` (audyt A5). Bez tego przepis zostawał
        // dostępny pod bezpośrednim adresem, mimo że link „zobacz profil" pod
        // nim dawał 403 — obietnica bez pokrycia w drugą stronę. Zawieszenie
        // NIE wchodzi tutaj: to kara czasowa i tylko na publikowanie
        // („dostęp tylko do ODCZYTU" — `EnsureAccountIsActive`), więc treść
        // zawieszonej osoby zostaje widoczna tak jak jej profil.
        if (! $isOwnerOrModerator && ! $recipe->author->jestDostepnyJakoAutor()) {
            return false;
        }

        // Pamięć żądania: `view()` woła się kilka razy na stronę przepisu,
        // za każdym razem o tę samą parę (audyt wydajności, P3 W8).
        if ($user !== null && BlokadyWZadaniu::miedzy($user, $recipe->author)) {
            return false;
        }

        return match ($recipe->visibility) {
            'public' => true,
            'followers' => $user !== null
                && ($user->getKey() === $recipe->author_id || $user->isFollowing($recipe->author)),
            'private' => $user !== null && $user->getKey() === $recipe->author_id,
            default => false,
        };
    }

    /**
     * ODCZYT PRZEZ UDOSTĘPNIENIE (#2650) — osobna zdolność, NIE gałąź `view()`.
     *
     * Dlaczego nie w `view()`: z `view()` korzystają `cook()`, `fork()`,
     * komentarze, zapis do zeszytu, historia wersji, tryb gotowania, karta QR,
     * API i zdjęcia. Rozszerzenie `view()` dałoby odbiorcy po cichu wszystkie
     * te drogi naraz (issue: „nie utożsamiać odczytu ze wszystkimi akcjami").
     * Udostępnienie daje JEDNĄ stronę — `recipes.shared.show` — i zdjęcia
     * przepisu (bez skanu kartki) w `DostepDoZdjecia`.
     *
     * Każdy warunek sprawdzany przy KAŻDYM żądaniu, z bazy, bez pamięci
     * podręcznej — odebranie dostępu działa od następnego żądania:
     *  - odbiorca zalogowany, nie autor (autor ma zwykłą stronę przepisu);
     *  - przepis opublikowany — szkic, ukryty i zdjęty przez moderację
     *    nie otwierają się nikomu przez udostępnienie;
     *  - OBA konta aktywne: zawieszenie którejkolwiek strony wstrzymuje
     *    dostęp (wiersz zostaje — po końcu kary wraca), ban, zamykanie
     *    i wymazanie konta też (wymazanie kasuje wiersz);
     *  - bez blokady w żadną stronę (blokada i tak kasuje wiersz);
     *  - wiersz `recipe_shares` dla tej pary istnieje.
     *
     * Moderator nie ma tu żadnej furtki: wgląd w treść z urzędu idzie przez
     * `view()` i dziennik wglądu.
     */
    public function readShared(?User $user, Recipe $recipe): bool
    {
        if ($user === null || $user->getKey() === $recipe->author_id) {
            return false;
        }

        if (! $recipe->isPublished() || ! self::czynne($user)) {
            return false;
        }

        $autor = $recipe->author;

        if ($autor === null || ! self::czynne($autor)) {
            return false;
        }

        if (BlokadyWZadaniu::miedzy($user, $autor)) {
            return false;
        }

        return RecipeShare::query()
            ->where('recipe_id', $recipe->getKey())
            ->where('recipient_id', $user->getKey())
            ->exists();
    }

    /**
     * Konto czynne — także zawieszone, którego kara już minęła, choć
     * `reinstate()` jeszcze nie zaszło (dzieje się przy pierwszym żądaniu
     * TEJ osoby; odbiorca nie może czekać, aż autor się zaloguje).
     */
    private static function czynne(User $konto): bool
    {
        return $konto->isActive() || $konto->punishmentHasExpired();
    }

    /**
     * Ekran „Komu pokazuję ten przepis" i odbieranie dostępu — wyłącznie
     * autor, w każdym stanie konta i przepisu: zawężanie dostępu wolno zawsze.
     */
    public function manageShares(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id;
    }

    /**
     * Nowe udostępnienie: autor z AKTYWNYM kontem (to rozszerza dostęp, więc
     * zawieszenie je zamyka), przepis opublikowany i NIE publiczny — przepis
     * „Wszyscy" widzi każdy, udostępniać nie ma czego.
     */
    public function share(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id
            && $user->isActive()
            && $recipe->isPublished()
            && $recipe->visibility !== 'public';
    }

    /**
     * Autorstwo to warunek konieczny, nie wystarczający (audyt A08).
     *
     * Sama odpowiedź „to Twój przepis" pozwalała autorowi wejść w edycję
     * przepisu UKRYTEGO przez moderatora i opublikować go z powrotem.
     * O tym, czy przepis w danym stanie wolno jeszcze zmieniać, decyduje
     * jawna macierz przejść, a nie kolejny warunek dopisany w tym miejscu.
     */
    public function update(User $user, Recipe $recipe): bool
    {
        if ($user->getKey() !== $recipe->author_id) {
            return false;
        }

        return RecipeStatusTransitions::authorMayEdit($recipe->status);
    }

    /**
     * „Zastosuj jako nową poprawkę” z historii (#2525): wyłącznie autor
     * własnego, OPUBLIKOWANEGO przepisu, z aktywnym kontem (zawieszenie
     * odcina od zmiany treści publicznej) i przy statusie, który dopuszcza
     * edycję przez autora (nie przepis zamrożony przez moderację).
     */
    public function applyVersion(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id
            && $user->isActive()
            && $recipe->isPublished()
            && RecipeStatusTransitions::authorMayEdit($recipe->status);
    }

    /**
     * „Zrób kopię” własnego szkicu do drugiego wariantu (#2507): wyłącznie AKTYWNY
     * autor własnego, nieopublikowanego szkicu. Przepis opublikowany, zamrożony
     * przez moderację i cudzy nie mają tej akcji — własny opublikowany przepis
     * poprawia się albo (cudzy) robi się z niego „Moją wersję” (`fork`, D-301).
     */
    public function copyDraft(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id
            && $user->isActive()
            && $recipe->status === Recipe::STATUS_DRAFT
            && $recipe->published_at === null;
    }

    /**
     * „Odłóż na później” / „Wróć do pracy” (#2550): wyłącznie autor własnego
     * szkicu. Przepis opublikowany albo zamrożony przez moderację (`hidden`,
     * `removed`) nie ma czego odkładać — oznaczenie nie zmienia widoczności.
     */
    public function postpone(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id
            && $recipe->status === Recipe::STATUS_DRAFT
            && $recipe->published_at === null;
    }

    /**
     * Zwykłe usunięcie (`DELETE` ze strony treści) — wyłącznie autor.
     *
     * Issue #932: moderator NIE usuwa tędy cudzej treści, nawet z 2FA.
     * Ta droga omija panel `/admin` (2FA — `moderator.2fa`), uzasadnienie,
     * wiersz w `moderation_actions`, powiadomienie i odwołanie (DSA art. 17
     * i 20). Cudzą treść zdejmuje się decyzją „Usuń" w `/admin/zgloszenia`.
     */
    public function delete(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id;
    }

    /**
     * Lista „Usunięte przepisy" i odzyskanie własnego, omyłkowo usuniętego
     * przepisu przed końcem retencji (#2620). Wyłącznie autor z AKTYWNYM
     * kontem: odzyskanie to pisanie, a zawieszenie odcina od pisania.
     * Moderator nie ma tu żadnej furtki — cudzy „kosz" jest prywatny, a
     * treść zdjętą moderacyjnie cofa się przez `RestoreContent` i odwołanie.
     * Czy konkretny przepis wolno odzyskać (termin, nagrobek, sprawa
     * moderacyjna), rozstrzyga `OdzyskajUsunietyPrzepis` pod blokadą.
     */
    public function odzyskaj(User $user, Recipe $recipe): bool
    {
        return $user->isActive() && $user->getKey() === $recipe->author_id;
    }

    public function odzyskajListe(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Zdjęcie przepisu Z URZĘDU, bez zgłoszenia, z panelu moderacji (G31, D-251).
     * Reguła: `UserPolicy::takeDownContentOf()` — 2FA i niższa rola autora.
     */
    public function removeExOfficio(User $user, Recipe $recipe): bool
    {
        return app(UserPolicy::class)->takeDownContentOf($user, $recipe->author);
    }

    /**
     * "Ugotowałem" można dodać do CUDZEGO przepisu.
     *
     * Do własnego też — bo ludzie realnie gotują swoje przepisy i chcą mieć
     * ślad, że robili to w tym roku. Nie odbieramy tego, ale w statystykach
     * jakości liczymy tylko wykonania cudzych przepisów.
     */
    public function cook(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe) && $user->isActive();
    }

    /**
     * „Zrób swoją wersję" — kopia CUDZEGO, opublikowanego przepisu, który
     * widz widzi, jako prywatny szkic (issue #23, D-301).
     *
     * - `view()` na końcu: blokada w którąkolwiek stronę, konto autora
     *   zbanowane albo w trakcie usuwania i każda widoczność, której widz nie
     *   ma — wszystko to odcina już tam, więc nie ma drugiej kopii tych reguł;
     * - konto AKTYWNE: wersja to pisanie, a zawieszenie odcina od pisania;
     * - nie własny przepis: własny się po prostu poprawia (`update`);
     * - każda widoczność, którą widz ma — także „dla obserwujących”. Decyzja
     *   właściciela z 26.09.2026: „nie ma co utrudniać, jak nie skopiują, to
     *   zrobią screena”. Oryginał niewidoczny dla odbiorcy wersji i tak
     *   zostaje w podpisie jako „oryginał jest niedostępny”, a do JSON-LD
     *   (`isBasedOn`) trafia tylko oryginał widoczny dla gości;
     * - wyłącznie opublikowany: szkicu i przepisu ukrytego przez moderację
     *   nie ma czego kopiować.
     */
    public function fork(User $user, Recipe $recipe): bool
    {
        return $user->isActive()
            && $user->getKey() !== $recipe->author_id
            && $recipe->isPublished()
            && $this->view($user, $recipe);
    }
}
