<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Api\V1\Concerns\PrzyjmujeZdjecia;
use App\Http\Controllers\Controller;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/przepisy/{uuid}/ugotowalem` — „Ugotowałem" (D-273).
 *
 * Najważniejsza akcja w produkcie idzie przez `RecordCookedEvent`, tę samą
 * co formularz WWW — a to ona woła `NotifyUser`. Dzięki temu obietnica
 * „Ugotowałem ZAWSZE powiadamia autora" obowiązuje także w aplikacji,
 * z tymi samymi trzema granicami (AGENTS.md §1): własny przepis, konto autora
 * zamknięte, blokada. Kto może gotować, rozstrzyga `RecipePolicy::cook`.
 */
class UgotowalemController extends Controller
{
    use PrzyjmujeZdjecia;

    public function store(Request $request, string $przepis, StoreUploadedImage $zapisZdjecia, RecordCookedEvent $zapisz): JsonResponse
    {
        $model = Recipe::query()->findOrFail($przepis);
        $this->authorize('cook', $model);

        $dane = $request->validate([
            ...$this->regulyZdjec(),
            'note' => ['nullable', 'string', 'max:2000'],
            'changes_note' => ['nullable', 'string', 'max:1000'],
            'would_make_again' => ['nullable', 'boolean'],
            'perceived_difficulty' => ['nullable', 'in:easy,medium,hard'],
            'actual_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
        ], [
            ...$this->komunikatyZdjec(),
            'note.max' => 'Ta uwaga jest za długa. Zmieść się w 2000 znakach.',
            'changes_note.max' => 'To jest za długie. Zmieść się w 1000 znakach.',
            'perceived_difficulty.in' => 'Wybierz, jak trudny był ten przepis: łatwy, średni albo trudny.',
            'would_make_again.boolean' => 'Zaznacz jedną z odpowiedzi: „Tak, zrobię ponownie” albo „Raczej nie powtórzę”.',
            'actual_minutes.integer' => 'Wpisz sam czas w minutach, samymi cyframi — na przykład 90.',
            'actual_minutes.min' => 'Czas nie może być ujemny. Wpisz liczbę minut, na przykład 90.',
            'actual_minutes.max' => 'Ten czas jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
        ]);

        $kucharz = $request->user();
        $zdjecia = $this->zapiszZdjecia($request, $kucharz, $zapisZdjecia);

        try {
            $wykonanie = $zapisz->handle(
                cook: $kucharz,
                recipe: $model,
                note: $dane['note'] ?? null,
                mediaIds: $zdjecia,
                wouldMakeAgain: array_key_exists('would_make_again', $dane) && $dane['would_make_again'] !== null
                    ? $request->boolean('would_make_again')
                    : null,
                perceivedDifficulty: $dane['perceived_difficulty'] ?? null,
                actualMinutes: isset($dane['actual_minutes']) ? (int) $dane['actual_minutes'] : null,
                changesNote: $dane['changes_note'] ?? null,
                ip: $request->ip(),
                kluczWyslania: $this->kluczWyslania($request),
            );
        } catch (BladDlaCzlowieka $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        }

        return new JsonResponse([
            'data' => [
                'id' => (string) $wykonanie->getKey(),
                'recipe_id' => (string) $model->getKey(),
                'note' => $wykonanie->note,
                'cooked_at' => $wykonanie->cooked_at?->toIso8601String(),
            ],
            'message' => $wykonanie->wasRecentlyCreated
                ? 'Wykonanie zapisane.'
                : 'To wykonanie już zapisaliśmy.',
        ], $wykonanie->wasRecentlyCreated ? 201 : 200, [], JSON_UNESCAPED_UNICODE);
    }
}
