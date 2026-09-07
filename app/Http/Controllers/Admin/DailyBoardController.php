<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
    public function edit(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $picks = DailyPick::query()->forDate()->get();

        return view('pages.admin.daily-board', [
            'wybraneWpisy' => $picks->where('subject_type', DailyPick::TYPE_POST)->pluck('subject_id')->all(),
            'wybraneOsoby' => $picks->where('subject_type', DailyPick::TYPE_USER)->pluck('subject_id')->all(),
            'notatki' => $picks->pluck('note', 'subject_id')->filter()->all(),

            // Świeże wpisy z ostatnich dni — z tego gospodarz wybiera.
            'wpisy' => Post::query()
                ->publiclyVisible()
                ->where('published_at', '>=', now()->subDays(7))
                ->with(['author.profile.avatar', 'media'])
                ->orderByDesc('published_at')
                ->limit(40)
                ->get(),

            // Osoby, które ostatnio coś pokazały.
            'osoby' => User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->whereHas('posts', fn ($query) => $query->publiclyVisible())
                ->with('profile.avatar')
                ->limit(40)
                ->get()
                ->sortBy(fn (User $user) => $user->displayName())
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $data = $request->validate([
            'wpisy' => ['nullable', 'array', 'max:6'],
            'wpisy.*' => ['uuid'],
            'osoby' => ['nullable', 'array', 'max:6'],
            'osoby.*' => ['uuid'],
            'notatki' => ['nullable', 'array'],
            'notatki.*' => ['nullable', 'string', 'max:300'],
        ], [
            'wpisy.max' => 'Wybierz najwyżej 6 dań. Tablica ma być krótka.',
            'osoby.max' => 'Wybierz najwyżej 6 osób. Tablica ma być krótka.',
        ]);

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
        DB::transaction(function () use ($data, $notatki, $moderator): void {
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
        });

        AuditLogEntry::record(
            action: 'daily_board.updated',
            actor: $moderator,
            metadata: [
                'osoby' => count($data['osoby'] ?? []),
                'wpisy' => count($data['wpisy'] ?? []),
            ],
            ip: $request->ip(),
        );

        $razem = count($data['osoby'] ?? []) + count($data['wpisy'] ?? []);

        return back()->with('status', $razem === 0
            ? 'Wyczyszczone. Tablica dobierze treści sama.'
            : "Zapisane. Na tablicy jest dziś {$razem} pozycji.");
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

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
