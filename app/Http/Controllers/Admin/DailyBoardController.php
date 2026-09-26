<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Feed\DailyBoardCandidates;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\DailyPick;
use App\Models\Post;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly DailyBoardCandidates $candidates) {}

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
        // Kafel tablicy widzi gość, więc podgląd liczy przepis jak dla gościa
        // (issue #1377): wpis z własną treścią bez niedostępnego przepisu.
        Post::ukryjNiedostepnePrzepisy($posts, null);
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
        if ($this->candidates->posts($request->user())->whereIn('id', $data['wpisy'])->count() !== count($data['wpisy'])) {
            throw ValidationException::withMessages(['wpisy' => 'Wybierz ponownie publiczne wpisy z dostępnej listy.']);
        }

        $notatki = $data['notatki'] ?? [];
        $moderator = $request->user();

        // WYŚCIG PRZY PODWÓJNYM ZAPISIE (audyt zewnętrzny, "wyścig w
        // daily_board"): strona wolno się ładuje, gospodarz klika "Zapisz"
        // drugi raz z TĄ SAMĄ treścią formularza — dokładnie ten przypadek,
        // który AGENTS.md każe traktować jako normę w grupie 50+, nie jako
        // brzeg. Dwa niemal jednoczesne żądania mogą przeplatać się tak, że
        // oba wykonują DELETE (widząc jeszcze pustą tablicę), a potem oba
        // próbują wstawić TĘ SAMĄ pozycję (ta sama osoba/wpis, ten sam
        // dzień) — drugi INSERT zderza się z UNIQUE
        // (`daily_picks.unique(['shown_on','subject_type','subject_id'])`,
        // migracja `create_daily_picks_table`).
        //
        // CAŁOŚĆ W JEDNEJ TRANSAKCJI, A KAŻDY INSERT Z PRZECHWYCENIEM
        // ZDERZENIA — ten sam wzorzec co `SaveRecipeToCollection`/
        // `SavePostToCollection` (issue #43): dla człowieka, który kliknął
        // "Zapisz" dwa razy z tym samym wyborem, wynik ma być JEDNĄ pozycją
        // na tablicy, nie błędem 500. Transakcja pilnuje, że DELETE i INSERT-y
        // JEDNEGO zapisu widać razem albo wcale — bez niej przerwanie
        // w połowie zostawiałoby tablicę w stanie ani starym, ani nowym.
        $saved = DB::transaction(function () use ($data, $notatki, $moderator): array {
            // Wybór na dany dzień zastępujemy w całości — to jest prostsze
            // w obsłudze niż dokładanie i odejmowanie pozycji.
            DailyPick::query()->whereDate('shown_on', Czas::dzisiajData())->delete();

            $position = 0;

            foreach ($data['osoby'] ?? [] as $userId) {
                $this->utworzPozycje(
                    DailyPick::TYPE_USER,
                    $userId,
                    $position++,
                    $moderator,
                    $this->nullIfBlank($notatki[$userId] ?? null),
                );
            }

            $position = 0;

            foreach ($data['wpisy'] ?? [] as $postId) {
                $this->utworzPozycje(
                    DailyPick::TYPE_POST,
                    $postId,
                    $position++,
                    $moderator,
                    $this->nullIfBlank($notatki[$postId] ?? null),
                );
            }

            return DailyPick::query()->forDate()->get()->countBy('subject_type')->all();
        });

        AuditLogEntry::record(
            action: 'daily_board.updated',
            actor: $moderator,
            metadata: [
                'osoby' => $saved[DailyPick::TYPE_USER] ?? 0,
                'przeslane_osoby' => $submittedPeople,
                'wpisy' => $saved[DailyPick::TYPE_POST] ?? 0,
                'przeslane_wpisy' => $submittedPosts,
            ],
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

        DailyPick::query()->whereDate('shown_on', Czas::dzisiajData())->delete();

        return back()->with('status', 'Wyczyszczone. Tablica dobierze treści sama.');
    }

    /**
     * Jedna pozycja tablicy — z przechwyceniem zderzenia z UNIQUE.
     *
     * Zderzenie znaczy: konkurencyjne żądanie (drugie kliknięcie "Zapisz"
     * z tym samym wyborem) zdążyło wstawić DOKŁADNIE tę samą pozycję (ta
     * sama osoba/wpis, ten sam dzień) w tej samej szczelinie między naszym
     * DELETE-em a naszym INSERT-em. Dla człowieka to wciąż jest JEDEN zapis,
     * więc kończymy cicho — bez błędu 500 i bez drugiego wiersza, którego
     * UNIQUE i tak by nie przepuścił.
     */
    private function utworzPozycje(string $typ, string $subjectId, int $pozycja, User $moderator, ?string $notatka): void
    {
        try {
            // WŁASNA ZAGNIEŻDŻONA TRANSAKCJA, NIE SAM `try/catch`.
            //
            // W PostgreSQL zderzenie z UNIQUE nie tylko rzuca wyjątkiem —
            // oznacza CAŁĄ otaczającą transakcję jako nieużywalną
            // ("current transaction is aborted") aż do jej zakończenia.
            // Samo złapanie wyjątku w PHP nic by tu nie dało: kolejny
            // `INSERT` w tej samej transakcji i tak by już padł. Zagnieżdżone
            // `DB::transaction()` Laravel zamienia na `SAVEPOINT`, więc
            // wycofuje się TYLKO ten jeden `INSERT`, nie cały zapis tablicy —
            // dokładnie ten mechanizm, którego `Builder::createOrFirst()`
            // (`firstOrCreate()`) używa wewnętrznie dla tego samego problemu.
            DB::transaction(function () use ($typ, $subjectId, $pozycja, $moderator, $notatka): void {
                DailyPick::create([
                    'shown_on' => Czas::dzisiajData(),
                    'subject_type' => $typ,
                    'subject_id' => $subjectId,
                    'position' => $pozycja,
                    'curator_id' => $moderator->getKey(),
                    'note' => $notatka,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Nic do zrobienia — konkurencyjne żądanie już zapisało dokładnie
            // tę pozycję na dziś.
        }
    }

    /** @return list<string> */
    private function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_unique(array_filter($value, fn ($id) => is_string($id) && Str::isUuid($id)))) : [];
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
