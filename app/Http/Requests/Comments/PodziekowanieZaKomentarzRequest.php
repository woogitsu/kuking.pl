<?php

declare(strict_types=1);

namespace App\Http\Requests\Comments;

use App\Models\Comment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Wejście „Dziękuję" pod komentarzem (`comments.thank`, issue #2355).
 *
 * UUID w adresie to nie autoryzacja. Komentarz, którego widz w ogóle nie
 * może zobaczyć (blokada, ukryty, treść nadrzędna niedostępna), daje 404
 * — nieodróżnialne od „nie ma takiego komentarza", jak bramka zgłoszeń.
 * Komentarz widoczny, ale taki, za który ten człowiek nie może dziękować
 * (cudzy wpis, własny komentarz), daje 403. Pól formularza nie ma.
 */
final class PodziekowanieZaKomentarzRequest extends FormRequest
{
    public function authorize(): bool
    {
        $komentarz = $this->route('comment');

        if (! $komentarz instanceof Comment) {
            abort(404);
        }

        $user = $this->user();

        if ($user === null || Gate::forUser($user)->denies('view', $komentarz)) {
            abort(404);
        }

        Gate::forUser($user)->inspect('thank', $komentarz)->authorize();

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
