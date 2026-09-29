<?php

declare(strict_types=1);

namespace App\Http\Requests\Posts;

use App\Models\Post;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;

/**
 * Wejście formularza edycji wpisu i pytania (`posts.update`) — wyjęte
 * z `PostController::update()` bez zmiany zachowania (issue #970, krok 4).
 *
 * `rules()` jest świadomie puste: walidacja treści NIE może pójść przed
 * kontrolerem. Kontroler najpierw rozstrzyga wpis pod decyzją moderacji
 * (403 z tekstem do skopiowania), Policy, 404 wyłączonych pytań i przyciski
 * tagów („Dodaj"/„Usuń" wracają bez walidacji treści). Gdyby FormRequest
 * sprawdzał treść automatycznie, pusta widoczność przy „Dodaj tag"
 * kończyłaby się błędem pola zamiast dodaniem tagu.
 */
final class EdycjaWpisuRequest extends FormRequest
{
    use WalidujeTrescWpisu;

    public function authorize(): bool
    {
        // Autoryzację robi kontroler (Policy, wpis pod decyzją moderacji).
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
     * Zapisuje w wejściu, którego formularza dotyczy `old('_tag_form_post_id')`.
     *
     * Wpisujemy to w OBA obiekty: w ten FormRequest (stąd czyta kontroler
     * i walidator) oraz w żądanie z kontenera. Przy błędzie walidacji
     * `old()` powstaje z tego drugiego — FormRequest jest jego kopią, więc
     * samo `$this->merge()` zgubiłoby marker, a razem z nim listę tagów po
     * błędzie (regresja z testów `TagiInlinePochodzenieTest`).
     */
    public function oznaczFormularzWpisu(Post $post): void
    {
        $marker = ['_tag_form_post_id' => (string) $post->getKey()];

        $this->merge($marker);
        app('request')->merge($marker);
    }

    /**
     * Treść, widoczność i (dla pytania) tytuł — woła kontroler po obsłudze
     * przycisków tagów. Rzuca `ValidationException` jak `->validate()`:
     * powrót na poprzednią stronę z `old()` i błędami po polsku.
     *
     * @return array<string, mixed>
     */
    public function trescWpisu(Post $post): array
    {
        return $this->walidatorTresci($post)->validate();
    }

    /**
     * Walidator bez rzucania — dla testów i wywołań, które same decydują,
     * co zrobić z błędami.
     */
    public function walidatorTresci(Post $post): Validator
    {
        return ValidatorFactory::make(
            $this->all(),
            self::regulyTresci($post->kind === Post::KIND_QUESTION, true),
            self::komunikatyTresci(),
        );
    }
}
