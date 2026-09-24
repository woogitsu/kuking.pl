<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Feed\Actions\ZapiszTabliceDnia;
use App\Domain\Feed\DailyBoardCandidates;
use App\Http\Controllers\Controller;
use App\Models\DailyPick;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Wybór redakcyjny na tablicę „kuKINGi na dziś".
 *
 * Ekran ma zająć gospodarzowi minutę, nie pięć — dlatego jest to jedna strona
 * z listą świeżych wpisów i jednym polem wyboru przy każdym, a nie kreator.
 *
 * Świadomie NIE MA tu sortowania po popularności ani żadnej liczby, która
 * podpowiadałaby wybór. Gospodarz ma patrzeć na zdjęcia i na ludzi,
 * nie na słupki.
 */
class DailyBoardController extends Controller
{
    public function __construct(
        private readonly DailyBoardCandidates $candidates,
        private readonly ZapiszTabliceDnia $zapis,
    ) {}

    public function edit(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $picks = DailyPick::query()->forDate()->get();

        $selectedPosts = $picks->where('subject_type', DailyPick::TYPE_POST)->pluck('subject_id')->all();
        $selectedPeople = $picks->where('subject_type', DailyPick::TYPE_USER)->pluck('subject_id')->all();
        $restoring = $request->session()->hasOldInput('_board_form');
        $notes = $picks->pluck('note', 'subject_id')->filter()->all();
        if ($restoring) {
            $selectedPosts = $this->ids($request->old('wpisy', []));
            $selectedPeople = $this->ids($request->old('osoby', []));
            $oldNotes = $request->old('notatki', []);
            $notes = is_array($oldNotes) ? array_filter($oldNotes, fn ($note) => is_string($note) || $note === null) : [];
        }
        $searchInput = $restoring ? $request->old('szukaj', '') : $request->query('szukaj', '');
        $search = is_string($searchInput) ? mb_substr($searchInput, 0, 100) : '';
        $postsQuery = $this->candidates->posts($request->user())->with(['author.profile.avatar', 'media', 'recipe']);
        $peopleQuery = $this->candidates->people($request->user())->with('profile.avatar');
        // Wybrane pozycje mają pola także poza limitem kandydatów i wiekiem wpisu.
        $chosenPosts = (clone $postsQuery)->whereIn('id', $selectedPosts)->get()->keyBy('id');
        $chosenPeople = (clone $peopleQuery)->whereIn('id', $selectedPeople)->get()->keyBy('id');
        $posts = collect($selectedPosts)->map(fn ($id) => $chosenPosts->get($id))->filter()
            ->concat((clone $postsQuery)->where('published_at', '>=', now()->subDays(7))
                ->orderByDesc('published_at')->orderByDesc('id')->limit(40)->get())
            ->unique('id')->values();
        $people = collect($selectedPeople)->map(fn ($id) => $chosenPeople->get($id))->filter()
            ->concat($this->candidates->searchPeople($request->user(), $search)->with('profile.avatar')->limit(40)->get())
            ->unique('id')->values();

        return view('pages.admin.daily-board', [
            'wybraneWpisy' => $selectedPosts,
            'wybraneOsoby' => $selectedPeople,
            'notatki' => $notes,
            'szukaj' => $search,
            'wpisy' => $posts,
            'osoby' => $people,
            'niedostepne' => count($selectedPosts) + count($selectedPeople) - $chosenPosts->count() - $chosenPeople->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $request->merge(['_board_form' => '1']);
        $data = $request->validate([
            'wpisy' => ['nullable', 'array', 'max:6'],
            'wpisy.*' => ['uuid'],
            'osoby' => ['nullable', 'array', 'max:6'],
            'osoby.*' => ['uuid'],
            'notatki' => ['nullable', 'array'],
            'notatki.*' => ['nullable', 'string', 'max:300'],
            'szukaj' => ['nullable', 'string', 'max:100'],
        ], [
            'wpisy.max' => 'Wybierz najwyżej :max wpisów. Tablica ma być krótka.',
            'osoby.max' => 'Wybierz najwyżej :max osób. Tablica ma być krótka.',
            'notatki.*.max' => 'Skróć notatkę do :max znaków.',
            'notatki.*.string' => 'Wpisz notatkę jako tekst.',
        ]);

        if ($request->has('przegladaj')) {
            return redirect()->route('admin.daily-board')->withInput($request->except(['_token', 'przegladaj']));
        }

        $submittedPeople = count($data['osoby'] ?? []);
        $submittedPosts = count($data['wpisy'] ?? []);
        $data['osoby'] = array_values(array_unique($data['osoby'] ?? []));
        $data['wpisy'] = array_values(array_unique($data['wpisy'] ?? []));
        if ($this->candidates->people($request->user())->whereIn('id', $data['osoby'])->count() !== count($data['osoby'])) {
            throw ValidationException::withMessages(['osoby' => 'Wybierz ponownie osoby z dostępnej listy.']);
        }
        $wybraneWpisy = $this->candidates->posts($request->user())->whereIn('id', $data['wpisy'])->with('author.profile')->get();
        if ($wybraneWpisy->count() !== count($data['wpisy'])) {
            throw ValidationException::withMessages(['wpisy' => 'Wybierz ponownie publiczne wpisy z dostępnej listy.']);
        }
        // Najwyżej jedno danie od osoby także w części redakcyjnej (#1296) —
        // ta sama reguła, której automat pilnuje w `DailyBoard`. Bez niej
        // jedna aktywna osoba może zająć całą tablicę. Odrzucamy cały zapis,
        // zamiast po cichu odsiać drugie danie: gospodarz ma zdecydować, które
        // zostaje, a zaznaczenia wracają do formularza.
        $powtorzeni = $wybraneWpisy->groupBy('author_id')->filter(fn ($wpisy) => $wpisy->count() > 1);
        if ($powtorzeni->isNotEmpty()) {
            throw ValidationException::withMessages(['wpisy' => 'Na tablicy może stać najwyżej jedno danie od osoby. '
                .'Zostaw zaznaczone tylko jedno danie od: '.$powtorzeni->map(fn ($wpisy) => $wpisy->first()->author->displayName())->implode(', ').'.']);
        }

        $notatki = $data['notatki'] ?? [];

        // Blokada dnia, zastąpienie całego wyboru i wpis audytu w jednej
        // transakcji — uzasadnienie w `ZapiszTabliceDnia` (#1027, #1329).
        $saved = $this->zapis->zastap(
            gospodarz: $request->user(),
            osoby: $data['osoby'],
            wpisy: $data['wpisy'],
            notatki: is_array($notatki) ? $notatki : [],
            przeslaneOsoby: $submittedPeople,
            przeslaneWpisy: $submittedPosts,
            ip: $request->ip(),
        );

        $razem = array_sum($saved);

        return back()->with('status', $razem === 0
            ? 'Wyczyszczone. Tablica dobierze treści sama.'
            : 'Zapisaliśmy wyróżnienia. Pozostałe miejsca tablica uzupełni automatycznie.');
    }

    /** Usuwa dzisiejszy wybór — tablica wraca do trybu automatycznego. */
    public function destroy(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $this->zapis->wyczysc($request->user(), $request->ip());

        return back()->with('status', 'Wyczyszczone. Tablica dobierze treści sama.');
    }

    /** @return list<string> */
    private function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_unique(array_filter($value, fn ($id) => is_string($id) && Str::isUuid($id)))) : [];
    }
}
