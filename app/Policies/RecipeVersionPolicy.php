<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Recipes\Historia\HistoriaWersji;
use App\Models\RecipeVersion;
use App\Models\User;

/**
 * Kto może ukryć i przywrócić jedną wersję przepisu (issue #2270, D-333).
 *
 * Warunek wstępny dla obu: ta osoba widzi historię przepisu
 * (`HistoriaWersji::wolnoOgladac` — `RecipePolicy::view` + przepis
 * opublikowany). Kto nie widzi historii, nie ukrywa w niej niczego — i nie
 * dowiaduje się z odpowiedzi, że przepis istnieje.
 *
 *  - AUTOR przepisu ukrywa każdą swoją wersję i przywraca to, co ukrył SAM.
 *    Ukrycia moderacji nie cofa — inaczej ukrycie moderacji nic by nie
 *    znaczyło.
 *  - MODERACJA działa tą samą regułą co zdjęcie treści z urzędu
 *    (`UserPolicy::takeDownContentOf`): czynne konto obsługi, potwierdzone
 *    2FA i autor o niższej roli. Przywraca tylko to, co ukryła moderacja —
 *    wersji ukrytej przez autora nie odsłania, bo to jego decyzja
 *    o własnej treści.
 *
 * Stan (najnowsza wersja, już ukryta) rozstrzyga `UkrywanieWersji`, z
 * komunikatem po polsku, a nie 403.
 */
class RecipeVersionPolicy
{
    /**
     * Kto może ZGŁOSIĆ tę wersję (issue #2390, decyzja właściciela
     * z 1.10.2026). Osoba trzecia — także taka, która nie jest autorem — ale
     * tylko wersję, którą w ogóle widzi:
     *
     *  - widzi historię przepisu (`HistoriaWersji::wolnoOgladac`), a więc
     *    przepis jest widoczny i opublikowany;
     *  - wersja nie jest ukryta, chyba że patrzy autor albo czynna moderacja
     *    (`HistoriaWersji::widziUkryte`) — zgłoszenie nie może zdradzić
     *    istnienia wersji, której nie widać;
     *  - to NIE jest najnowsza wersja: ta jest treścią przepisu, więc zgłasza
     *    się przepis (najnowszej wersji nie da się też ukryć).
     *
     * `?User`, bo bramkę woła `ReportContent::authorize()` także dla zgłaszającego
     * bez konta; gość i tak odpada na trasie za logowaniem.
     */
    public function report(?User $user, RecipeVersion $wersja): bool
    {
        $recipe = $wersja->recipe;

        if ($recipe === null || ! HistoriaWersji::wolnoOgladac($user, $recipe)) {
            return false;
        }

        if ($wersja->czyUkryta() && ! HistoriaWersji::widziUkryte($user, $recipe)) {
            return false;
        }

        return HistoriaWersji::numerNajnowszej($recipe) !== $wersja->version_number;
    }

    public function hide(User $user, RecipeVersion $wersja): bool
    {
        $recipe = $wersja->recipe;

        if ($recipe === null || ! HistoriaWersji::wolnoOgladac($user, $recipe)) {
            return false;
        }

        return $user->getKey() === $recipe->author_id
            || app(UserPolicy::class)->takeDownContentOf($user, $recipe->author);
    }

    public function restore(User $user, RecipeVersion $wersja): bool
    {
        $recipe = $wersja->recipe;

        if ($recipe === null || ! HistoriaWersji::wolnoOgladac($user, $recipe)) {
            return false;
        }

        return match ($wersja->hidden_by_role) {
            RecipeVersion::UKRYL_AUTOR => $user->getKey() === $recipe->author_id,
            RecipeVersion::UKRYLA_MODERACJA => app(UserPolicy::class)->takeDownContentOf($user, $recipe->author),
            // Wersja nieukryta: te same drzwi co przy ukryciu, a
            // `UkrywanieWersji` powie „ta wersja nie jest ukryta".
            default => $this->hide($user, $wersja),
        };
    }
}
