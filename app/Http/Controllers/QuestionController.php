<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Questions\PytaniaBezOdpowiedzi;
use App\Domain\Questions\QuestionList;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class QuestionController extends Controller
{
    public function index(Request $request, QuestionList $list, PytaniaBezOdpowiedzi $licznik): View
    {
        abort_unless(config('kuking.questions.enabled'), 404);
        $filters = $request->validate([
            'filtr' => ['sometimes', Rule::in(['najnowsze', 'bez-odpowiedzi'])],
            'tag' => ['sometimes', 'nullable', 'string', 'max:180'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);
        $filter = $filters['filtr'] ?? 'najnowsze';
        $tag = ! empty($filters['tag'])
            ? Tag::query()->aktywne()->where('slug', $filters['tag'])->firstOrFail()
            : null;
        $questions = $list->query($request->user(), $filter === 'bez-odpowiedzi', $tag?->slug)
            ->cursorPaginate((int) config('kuking.feed.page_size'))
            ->withQueryString();
        // Liczony w tle + dokładna poprawka widza (blokady, obserwowani) —
        // `PytaniaBezOdpowiedzi`; pełny COUNT na żądanie kosztował 80–140 ms (#372).
        $unansweredCount = $licznik->dla($request->user(), $tag?->slug);

        return view('pages.questions.index', compact('questions', 'filter', 'tag', 'unansweredCount'));
    }
}
