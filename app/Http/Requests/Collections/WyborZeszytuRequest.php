<?php

declare(strict_types=1);

namespace App\Http\Requests\Collections;

use App\Models\Collection;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;

/**
 * JEDNA REGUŁA WŁASNEGO ZESZYTU DLA ZAPISU I DLA WYJĘCIA (issue #775),
 * wyjęta z `CollectionController::regulyWlasnegoZeszytu()` (issue #970,
 * krok 5). Podklasy różnią się WYŁĄCZNIE zdaniami w błędach — reguła jest
 * ta sama i ma być ta sama: format UUID przed zapytaniem (`bail`) i zakres
 * przypięty do zeszytów, do których ta osoba ma ważny dostęp. Wyjęcie
 * z cudzego zeszytu jest dokładnie tą granicą, której pilnuje AGENTS.md §7.
 *
 * `rules()` jest świadomie puste (jak w `EdycjaWpisuRequest`): kontroler
 * najpierw rozstrzyga przepis lub wpis (404, Policy), a dopiero potem
 * wybór zeszytu. Automatyczna walidacja przed kontrolerem zamieniłaby
 * 403/404 na błąd pola. Wybór waliduje `idWybranegoZeszytu()`, wołany po
 * tych krokach; rzuca `ValidationException` jak `->validate()` (powrót
 * z błędem i `old()`).
 */
abstract class WyborZeszytuRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autoryzację robi kontroler (Policy przepisu/wpisu, zakres akcji).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Zdanie błędu dla formatu UUID i dla zeszytu spoza zakresu — jedno,
     * bo dla człowieka to ta sama sytuacja (issue #473).
     */
    abstract protected function zdanieBleduWyboru(): string;

    /**
     * @return array<string, list<mixed>>
     */
    public function regulyWyboru(): array
    {
        $osoba = $this->user();

        return [
            'collection_id' => [
                'bail', 'nullable', 'uuid',
                // Własne zeszyty ORAZ wspólne, do których ta osoba ma ważny
                // dostęp (#1743, D-302) — jeden zakres dla listy wyboru,
                // walidacji i akcji. Cudzy zeszyt bez dostępu dalej daje ten
                // sam komunikat co nieistniejący (#473).
                Rule::exists('collections', 'id')->where(fn ($q) => $q->whereIn('id', Collection::query()->dostepneDoZapisuDla($osoba)->select('collections.id'))),
            ],
        ];
    }

    /**
     * Walidator bez rzucania — dla testów i wywołań, które same decydują,
     * co zrobić z błędami.
     */
    public function walidatorWyboru(): Validator
    {
        return ValidatorFactory::make($this->all(), $this->regulyWyboru(), [
            'collection_id.uuid' => $this->zdanieBleduWyboru(),
            'collection_id.exists' => $this->zdanieBleduWyboru(),
        ]);
    }

    /**
     * Zwalidowany identyfikator zeszytu albo `null` („brak wyboru").
     */
    protected function idWybranegoZeszytu(): ?string
    {
        $dane = $this->walidatorWyboru()->validate();

        return isset($dane['collection_id']) ? (string) $dane['collection_id'] : null;
    }
}
