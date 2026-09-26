<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Tag;
use App\Models\TagHighlight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plan „tagu tygodnia" (issue #18) — na tym samym ekranie co tagi promowane
 * i za tą samą bramką (`Gate` `moderate`). Za flagą
 * `kuking.tag_tygodnia.wlaczony`: przy wyłączonej trasy zwracają 404.
 *
 * Gospodarz wybiera ISTNIEJĄCY, aktywny tag i dni od–do. Ten ekran nie tworzy
 * tagów i nie wyróżnia niczego sam z siebie — żadnego awansu z popularności.
 */
class TagHighlightController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);
        abort_unless(config('kuking.tag_tygodnia.wlaczony', false), 404);

        $dane = $request->validate([
            'tag_tygodnia' => ['required', 'string', 'max:200'],
            'od_dnia' => ['required', 'date_format:Y-m-d'],
            'do_dnia' => ['required', 'date_format:Y-m-d', 'after_or_equal:od_dnia'],
            'notatka_tygodnia' => ['nullable', 'string', 'max:200'],
        ], [
            'tag_tygodnia.required' => 'Wpisz nazwę tagu, który ma być tagiem tygodnia.',
            'od_dnia.required' => 'Wybierz pierwszy dzień wyróżnienia.',
            'od_dnia.date_format' => 'Wybierz pierwszy dzień z kalendarza albo wpisz go jako rok-miesiąc-dzień.',
            'do_dnia.required' => 'Wybierz ostatni dzień wyróżnienia.',
            'do_dnia.date_format' => 'Wybierz ostatni dzień z kalendarza albo wpisz go jako rok-miesiąc-dzień.',
            'do_dnia.after_or_equal' => 'Ostatni dzień nie może być wcześniej niż pierwszy. Popraw jedną z dat.',
            'notatka_tygodnia.max' => 'Notatka może mieć najwyżej 200 znaków. Skróć ją do jednego zdania.',
        ]);

        $tag = Tag::query()
            ->where('normalized_name', Tag::znormalizujNazwe($dane['tag_tygodnia']))
            ->where('status', Tag::STATUS_ACTIVE)
            ->first();

        if ($tag === null) {
            return back()->withInput()->withErrors([
                'tag_tygodnia' => 'Nie znaleziono aktywnego tagu o tej nazwie. Sprawdź pisownię — tag musi już istnieć.',
            ]);
        }

        $notatka = trim((string) ($dane['notatka_tygodnia'] ?? ''));

        try {
            // Własna transakcja (w transakcji zewnętrznej — punkt zapisu), żeby
            // odrzucenie przez `EXCLUDE` nie zostawiło połączenia w stanie
            // przerwanej transakcji.
            DB::transaction(fn () => TagHighlight::create([
                'tag_id' => $tag->getKey(),
                'starts_on' => $dane['od_dnia'],
                'ends_on' => $dane['do_dnia'],
                'note' => $notatka === '' ? null : $notatka,
            ]));
        } catch (QueryException $e) {
            // `tag_highlights_no_overlap` — jedyny powód, który tu przewidujemy.
            // Inny błąd bazy nie jest sprawą formularza i idzie dalej.
            if (! str_contains($e->getMessage(), 'tag_highlights_no_overlap')) {
                throw $e;
            }

            return back()->withInput()->withErrors([
                'od_dnia' => 'W tych dniach jest już inny tag tygodnia. Wybierz dni po jego zakończeniu albo najpierw usuń tamto wyróżnienie.',
            ]);
        }

        AuditLogEntry::record(
            action: 'tag_highlight.added',
            actor: $request->user(),
            subject: $tag,
            ip: $request->ip(),
        );

        return redirect()->route('admin.tag-promotions')->with('status', "Tag „{$tag->name}” zaplanowany jako tag tygodnia.");
    }

    public function destroy(Request $request, TagHighlight $wyroznienie): RedirectResponse
    {
        $this->authorize('moderate', User::class);
        abort_unless(config('kuking.tag_tygodnia.wlaczony', false), 404);

        // Usuwa sam plan wyróżnienia. Tag, jego strona i wpisy zostają.
        $wyroznienie->delete();

        AuditLogEntry::record(
            action: 'tag_highlight.removed',
            actor: $request->user(),
            subject: $wyroznienie->tag,
            ip: $request->ip(),
        );

        return redirect()->route('admin.tag-promotions')->with('status', 'Wyróżnienie usunięte. Tag i jego wpisy zostały.');
    }
}
