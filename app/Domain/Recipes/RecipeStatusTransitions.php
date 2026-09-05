<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Models\Recipe;

/**
 * Jawna macierz dozwolonych przejść statusu przepisu (audyt A08).
 *
 * DLACZEGO MACIERZ, A NIE KOLEJNY `if`
 * Regułę „co wolno zrobić z przepisem w tym stanie" trzeba było wcześniej
 * odtwarzać z dwóch rozsypanych warunków: `RecipePolicy::update()` pytała
 * wyłącznie o autorstwo, a `PublishRecipe` sprawdzała `! $recipe->isPublished()`.
 * Oba warunki były prawdziwe dla `hidden` — bo `hidden` NIE jest opublikowany —
 * więc przepis ukryty przez moderatora zachowywał się jak szkic i wracał do
 * sieci w dwóch kliknięciach, wykonanych przez osobę, której decyzja dotyczyła.
 *
 * Trzeci `if` naprawiłby ten jeden przypadek i zostawił następny status
 * (`removed`) tak samo niepilnowany. Macierz wymusza odpowiedź dla KAŻDEJ pary
 * stanów, a nowy status nie da się dodać po cichu — nie ma dla niego wiersza.
 *
 *   status obecny  | draft | published | hidden | removed
 *   ---------------|-------|-----------|--------|---------
 *   draft          |   ✓   |     ✓     |   ✗    |    ✗
 *   published      |   ✗   |     ✓     |   ✗    |    ✗
 *   hidden         |   ✗   |     ✗     |   ✗    |    ✗
 *   removed        |   ✗   |     ✗     |   ✗    |    ✗
 *
 * Czytanie wiersza: „autor przepisu, który jest w stanie X, może go zapisać
 * w stanie Y". Wiersz pusty znaczy, że przepisu nie wolno już zmieniać.
 *
 * Uzasadnienia poszczególnych „✗":
 *
 *  * `published → draft` — cofnięcia do szkicu nie ma w produkcie. Adres
 *    opublikowanego przepisu jest obietnicą: ludzie go zapisują i wysyłają
 *    rodzinie. Wycofanie treści z sieci to usunięcie, nie edycja.
 *  * `* → hidden` i `* → removed` — to są decyzje MODERACJI. Autor nie nadaje
 *    ich sobie sam i, co ważniejsze, sam ich nie zdejmuje.
 *  * cały wiersz `hidden` i `removed` — decyzja moderatora ma być trwała do
 *    czasu, aż moderator ją zmieni (`app/Http/Controllers/Admin/ModerationController.php`).
 *    Gdyby autor mógł tu cokolwiek, moderacja byłaby sugestią.
 *
 * Macierz opisuje wyłącznie uprawnienia AUTORA, bo tylko autor przechodzi
 * przez `PublishRecipe`. Moderator zmienia status własnym, nazwanym
 * narzędziem i celowo nie jest tu wpisany — inaczej ta klasa udawałaby, że
 * pilnuje czegoś, przez co moderacja w ogóle nie przechodzi.
 */
final class RecipeStatusTransitions
{
    /**
     * @var array<string, list<string>>
     */
    public const BY_AUTHOR = [
        Recipe::STATUS_DRAFT => [Recipe::STATUS_DRAFT, Recipe::STATUS_PUBLISHED],
        Recipe::STATUS_PUBLISHED => [Recipe::STATUS_PUBLISHED],
        Recipe::STATUS_HIDDEN => [],
        Recipe::STATUS_REMOVED => [],
    ];

    /** Czy autor może przenieść przepis ze stanu `$from` do stanu `$to`. */
    public static function authorMay(?string $from, string $to): bool
    {
        if ($from === null) {
            return false;
        }

        // Status spoza macierzy (np. dopisany w bazie ręcznie) domyślnie
        // ZAMYKA przepis, a nie otwiera. Brak wiersza to brak zgody.
        return in_array($to, self::BY_AUTHOR[$from] ?? [], true);
    }

    /**
     * Czy autor może w ogóle zapisywać zmiany w przepisie o tym statusie.
     *
     * Pusty wiersz macierzy = przepis jest dla autora zamrożony. Używane
     * przez `RecipePolicy::update()`, więc formularz edycji nie otwiera się
     * po to, żeby dopiero po wysłaniu powiedzieć „nie".
     */
    public static function authorMayEdit(?string $from): bool
    {
        if ($from === null) {
            return false;
        }

        return (self::BY_AUTHOR[$from] ?? []) !== [];
    }
}
