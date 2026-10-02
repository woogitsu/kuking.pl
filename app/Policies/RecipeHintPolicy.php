<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CookedEvent;
use App\Models\RecipeHint;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Wskazówki od gotujących (#2352, D-333). Trzy zasady, każda z testem
 * ujemnym:
 *
 *  1. **Tylko autor przepisu proponuje** (`propose`), i tylko z wykonania
 *     cudzego, z niepustą uwagą. Moderator nie proponuje za autora.
 *  2. **Tylko kucharz decyduje** (`accept`, `decline`, `withdraw`) — autor,
 *     moderator i admin nie przyjmują, nie odrzucają i nie wycofują zgody
 *     za kogokolwiek. Identyfikator w adresie niczego nie otwiera.
 *  3. **Blokada między autorem a kucharzem = brak.** Nie powstaje prośba i
 *     nie da się jej przyjąć. Odrzucenie i wycofanie zgody zostają kucharzowi
 *     MIMO blokady: wycofanie zgody musi być zawsze możliwe (RODO art. 7
 *     ust. 3), a „Nie" niczego nie ujawnia ani nie udostępnia.
 *
 *  4. **Tylko autor anuluje własną czekającą prośbę** (`cancel`), i tylko
 *     dopóki kucharz nie odpowiedział, a prośba nie wygasła. Kucharz,
 *     moderator i admin nie anulują cudzych próśb. Anulowanie jest
 *     wycofaniem własnej prośby, więc zostaje autorowi także przy blokadzie.
 *
 * Czekająca prośba WYGASA po `kuking.wskazowki.prosba_wygasa_po_dniach`
 * od `created_at` (`RecipeHint::wygasla()`): wygasła nie przechodzi ani
 * przez `accept`, ani `decline`, ani `cancel`.
 *
 * 5. **Zgłosić wskazówkę (`report`) może ten, kto ją WIDZI w sekcji przy
 *    przepisie** (#2352, decyzja właściciela z 1.10.2026) — także gość przez
 *    logowanie, także autor przepisu. Zgłoszenie nie może zdradzić istnienia
 *    wskazówki, której nie widać: czekająca, odrzucona, wycofana i ukryta przez
 *    moderację są dla zgłaszającego 404.
 *
 * 6. **Przywrócić ukrytą wskazówkę (`restore`) może czynna moderacja**
 *    (#2352, decyzja właściciela z 1.10.2026), ale nie w sprawie, w której
 *    sama jest stroną: ani kucharz, ani autor przepisu. Stan (czy jest ukryta,
 *    kto ukrył) rozstrzyga akcja pod blokadą, z komunikatem po polsku.
 *
 * Zawieszenie odcina od PISANIA (`isActive()`), więc zawieszony autor nie
 * prosi, a zawieszony kucharz nie przyjmuje — ale może odrzucić i wycofać.
 */
class RecipeHintPolicy
{
    /**
     * Kto może zgłosić TĘ wskazówkę. Warunki odtwarzają dokładnie to, co
     * przepuszcza sekcja „Wskazówki od gotujących” (`RecipeController::show`):
     * przyjęta i nieukryta, przepis opublikowany i widoczny dla zgłaszającego,
     * kucharz dostępny jako autor treści, brak blokady zgłaszającego z kucharzem
     * i wykonanie, które zgłaszający może zobaczyć.
     *
     * Kucharz zgłasza własną wskazówkę tak samo jak każdą własną treść (bramka
     * nie rozróżnia autora), ale przycisk przy nim się nie pokazuje — on ma
     * „Wycofaj zgodę”. `?User`, bo bramkę woła `ReportContent::authorize()`
     * także dla zgłaszającego bez konta (gość i tak odpada na trasie za logowaniem).
     */
    public function report(?User $user, RecipeHint $hint): bool
    {
        if (! $hint->jestPokazywana()) {
            return false;
        }

        $recipe = $hint->recipe;
        $kucharz = $hint->cook;
        $wykonanie = $hint->cookedEvent;

        if ($recipe === null || $kucharz === null || $wykonanie === null || ! $recipe->isPublished()) {
            return false;
        }

        if (! $kucharz->jestDostepnyJakoAutor()) {
            return false;
        }

        if ($user !== null && $user->hasBlockRelationWith($kucharz)) {
            return false;
        }

        $bramka = Gate::forUser($user);

        return $bramka->allows('view', $recipe) && $bramka->allows('view', $wykonanie);
    }

    /**
     * Wejście dla trasy „Przywróć wskazówkę” w panelu moderacji. Moderator,
     * który jest kucharzem albo autorem przepisu tej wskazówki, jest jej
     * stroną — przywraca ją ktoś inny z zespołu (jak własną treść, #1479).
     */
    public function restore(User $user, RecipeHint $hint): bool
    {
        return $user->isModerator()
            && $user->getKey() !== $hint->cook_id
            && $user->getKey() !== $hint->author_id;
    }

    public function propose(User $user, CookedEvent $event): bool
    {
        $recipe = $event->recipe;

        if ($recipe === null || ! $recipe->isPublished()) {
            return false;
        }

        if ($user->getKey() !== $recipe->author_id || ! $user->isActive()) {
            return false;
        }

        $kucharz = $event->user;

        // Własnego wykonania nie ma po co „zgadzać" (CHECK w bazie mówi to samo).
        if ($user->getKey() === $event->user_id || $kucharz === null) {
            return false;
        }

        // Prośba musi mieć co pokazać: uwaga z wykonania (nie samo zdjęcie).
        if (trim((string) $event->note) === '') {
            return false;
        }

        // Kucharz ma móc prośbę przeczytać i ma być widoczny jako autor
        // treści (nie zbanowany, nie w karencji usunięcia).
        if (! $kucharz->mozeCzytac() || ! $kucharz->jestDostepnyJakoAutor()) {
            return false;
        }

        return ! $user->hasBlockRelationWith($kucharz);
    }

    /**
     * Wejście dla czterech tras odpowiedzi: to prośba/wskazówka TEJ osoby.
     * Stan (czeka, przyjęta…) rozstrzygają akcje domenowe pod blokadą, z
     * uczciwym komunikatem po polsku — ta metoda tylko odcina obcych (403),
     * żeby podwójne kliknięcie właściciela nie kończyło się ścianą.
     */
    public function answer(User $user, RecipeHint $hint): bool
    {
        return $user->getKey() === $hint->cook_id;
    }

    /**
     * Wejście dla trasy „Anuluj prośbę": to prośba TEGO autora. Stan
     * rozstrzyga akcja pod blokadą (`cancel`), z komunikatem po polsku.
     */
    public function own(User $user, RecipeHint $hint): bool
    {
        return $user->getKey() === $hint->author_id;
    }

    public function cancel(User $user, RecipeHint $hint): bool
    {
        return $user->getKey() === $hint->author_id && $hint->czekaNaOdpowiedz();
    }

    public function accept(User $user, RecipeHint $hint): bool
    {
        if ($user->getKey() !== $hint->cook_id || ! $hint->czekaNaOdpowiedz() || ! $user->isActive()) {
            return false;
        }

        $autor = $hint->author;
        $recipe = $hint->recipe;

        // Przepis skasowany (soft delete → relacja `null`) albo nieopublikowany:
        // nie ma przy czym pokazać wskazówki.
        if ($autor === null || $recipe === null || ! $recipe->isPublished()) {
            return false;
        }

        if (! $autor->jestDostepnyJakoAutor()) {
            return false;
        }

        return ! $user->hasBlockRelationWith($autor);
    }

    public function decline(User $user, RecipeHint $hint): bool
    {
        return $user->getKey() === $hint->cook_id && $hint->czekaNaOdpowiedz();
    }

    public function withdraw(User $user, RecipeHint $hint): bool
    {
        return $user->getKey() === $hint->cook_id && $hint->jestPrzyjeta();
    }
}
