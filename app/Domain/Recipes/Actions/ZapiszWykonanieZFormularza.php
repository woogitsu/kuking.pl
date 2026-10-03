<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;

/**
 * Zamienia zwalidowane pola formularza „Ugotowałem" na wywołanie
 * `RecordCookedEvent` — wyjęte z `CookedEventController::store()`
 * bez zmiany zachowania (issue #970). Powiadomienie autora i jego trzy
 * granice zostają w `RecordCookedEvent`.
 */
final class ZapiszWykonanieZFormularza
{
    public function __construct(private readonly RecordCookedEvent $record) {}

    /**
     * @param  array<string, mixed>  $dane  wynik `validated()` z pól formularza
     * @param  list<string>  $mediaIds
     *
     * @throws BladDlaCzlowieka
     */
    public function handle(
        User $cook,
        Recipe $recipe,
        array $dane,
        array $mediaIds,
        ?string $ip,
        ?string $kluczWyslania,
        ?string $wersjaPrzepisuId = null,
        bool $wersjaScisla = false,
    ): CookedEvent {
        // „Zrobisz to jeszcze raz?" ma TRZY stany, nie dwa (audyt A22).
        //
        // Wcześniej stało tu `$request->boolean(...) ?: null`. Formularz wysyła
        // value="0", więc świadome „Raczej nie powtórzę" wpadało w `?:`
        // i lądowało w bazie jako `null`, czyli „nie zaznaczono". Zapisywały
        // się wyłącznie pochwały, a gotowy render negatywnej odpowiedzi
        // w karcie wykonania był kodem nie do wywołania.
        //
        // „Ugotowałem" to najcenniejszy sygnał jakości przepisu (AGENTS.md §1).
        // Sygnał, w którym da się zapisać tylko „tak", nie jest sygnałem
        // jakości — jest licznikiem pochwał.
        $wouldMakeAgain = ($dane['would_make_again'] ?? null) === null || $dane['would_make_again'] === ''
            ? null
            : filter_var($dane['would_make_again'], FILTER_VALIDATE_BOOLEAN);

        $perceivedDifficulty = empty($dane['perceived_difficulty']) ? null : $dane['perceived_difficulty'];

        return $this->record->handle(
            cook: $cook,
            recipe: $recipe,
            note: $dane['note'] ?? null,
            mediaIds: $mediaIds,
            wouldMakeAgain: $wouldMakeAgain,
            perceivedDifficulty: $perceivedDifficulty,
            // Walidacja `integer` przepuszcza napis „90", a akcja przyjmuje
            // `?int` pod `strict_types` — bez rzutowania każdy wpisany
            // czas kończył się ekranem 500 (zmierzone przy #872).
            actualMinutes: isset($dane['actual_minutes']) ? (int) $dane['actual_minutes'] : null,
            changesNote: $dane['changes_note'] ?? null,
            ip: $ip,
            kluczWyslania: $kluczWyslania,
            wersjaPrzepisuId: $wersjaPrzepisuId,
            wersjaScisla: $wersjaScisla,
            dzienGotowania: isset($dane['dzien_gotowania']) && is_string($dane['dzien_gotowania']) ? $dane['dzien_gotowania'] : null,
            faktycznePorcje: isset($dane['faktyczne_porcje']) && is_string($dane['faktyczne_porcje']) ? $dane['faktyczne_porcje'] : null,
        );
    }
}
