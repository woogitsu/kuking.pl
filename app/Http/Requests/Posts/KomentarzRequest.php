<?php

declare(strict_types=1);

namespace App\Http\Requests\Posts;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Wejście formularza komentarza pod wpisem (`posts.comment`) — wyjęte
 * z `PostController::comment()` bez zmiany zachowania (issue #970, krok 4).
 *
 * Kolejność zostaje ta sama co w kontrolerze: NAJPIERW Policy, potem
 * walidacja. `authorize()` woła `Gate::inspect()->authorize()`, czyli ten
 * sam wyjątek 403 z tym samym komunikatem co `$this->authorize()`; dzięki
 * temu ktoś bez prawa komentowania nie dowiaduje się z błędu pola, co
 * wysłał źle.
 */
final class KomentarzRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('post');

        // Trasa `posts.comment` zawsze wiąże `Post`; inny typ to nie ten
        // adres, więc 404, a nie ciche przepuszczenie (fail-open).
        if (! $post instanceof Post) {
            abort(404);
        }

        Gate::inspect('comment', $post)->authorize();

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000'],
            'parent_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Napisz coś, zanim wyślesz komentarz.',
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ];
    }
}
