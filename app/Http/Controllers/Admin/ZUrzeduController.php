<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\Actions\ZdejmijZUrzedu;
use App\Domain\Moderation\CelZgloszenia;
use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\PodstawaDecyzji;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Zdejmij z urzędu” — ekran i decyzja dla treści bez zgłoszenia (G31, D-251).
 *
 * Wejście: przycisk przy treści, widoczny tylko dla tego, komu Policy
 * pozwala (`removeExOfficio`). Trasa stoi w grupie `/admin`, więc przechodzi
 * `moderator` i `moderator.2fa` jak cały panel — a Policy pyta drugi raz,
 * bo UUID w adresie to nie autoryzacja (AGENTS.md §7).
 */
class ZUrzeduController extends Controller
{
    public function __construct(private readonly ZdejmijZUrzedu $zdejmij) {}

    public function create(string $typ, string $id): View
    {
        $cel = $this->cel($typ, $id);

        $this->authorize('removeExOfficio', $cel);

        return view('pages.admin.z-urzedu', [
            'typ' => $typ,
            'cel' => $cel,
            // Już zdjęta (także komentarz usunięty przez autora) — ekran mówi to od razu,
            // zamiast dawać formularz, który po wysłaniu i tak odmówi.
            'zdjeta' => ModeratedContent::jestZdjeta($cel),
            'opis' => CelZgloszenia::dla($cel),
            'powrot' => $this->adresTresci($cel),
        ]);
    }

    public function store(Request $request, string $typ, string $id): RedirectResponse
    {
        $cel = $this->cel($typ, $id);

        $this->authorize('removeExOfficio', $cel);

        $data = $request->validate([
            'reason_code' => ['required', 'string', 'in:'.implode(',', array_keys(PodstawaDecyzji::PODSTAWY))],
            'user_message' => ['required', 'string', 'min:10', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason_code.required' => 'Wybierz podstawę decyzji — autor treści zobaczy ją w powiadomieniu.',
            'reason_code.in' => 'Wybierz podstawę decyzji z listy.',
            'user_message.required' => 'Napisz autorowi, dlaczego zdejmujesz tę treść. Bez tego nie ma od czego się odwołać.',
            'user_message.min' => 'Uzasadnienie jest za krótkie. Napisz jednym, dwoma zdaniami, co konkretnie narusza zasady.',
            'user_message.max' => 'Uzasadnienie jest za długie. Zmieść się w 2000 znakach.',
            'note.max' => 'Notatka jest za długa. Zmieść się w 2000 znakach.',
        ]);

        try {
            $decyzja = $this->zdejmij->handle(
                moderator: $request->user(),
                target: $cel,
                reasonCode: $data['reason_code'],
                userMessage: $data['user_message'],
                note: $data['note'] ?? null,
                ip: $request->ip(),
            );
        } catch (BladDlaCzlowieka $blad) {
            return back()->withInput()->withErrors(['reason_code' => $blad->getMessage()]);
        }

        // Tam, gdzie decyzję widać: historia moderacji konta autora
        // (`UzytkownicyController::show()` czyta rejestr po `subject_user_id`).
        // Kolejka zgłoszeń jej nie pokazuje — zgłoszenia przecież nie ma.
        $status = 'Treść zdjęta z urzędu. Decyzja jest w historii konta autora, a autor dostał powiadomienie '
            .'z uzasadnieniem i może się odwołać.';

        return $decyzja->subject_user_id !== null
            ? redirect()->route('admin.users.show', ['user' => $decyzja->subject_user_id])->with('status', $status)
            : redirect()->route('admin.reports')->with('status', 'Treść zdjęta z urzędu. Decyzja jest w rejestrze.');
    }

    /**
     * Cel po typie i UUID — 404 dla typu spoza listy, dla treści miękko
     * usuniętej i dla treści, której nie widzi nikt poza autorem (szkic,
     * prywatna, ukryta). Tej ostatniej moderator z urzędu nie ogląda wcale:
     * po samym UUID nie dowie się nawet, że istnieje (D-251, zakres).
     */
    private function cel(string $typ, string $id): Post|Recipe|Comment
    {
        $klasa = ZdejmijZUrzedu::TYPY[$typ] ?? abort(404);

        $cel = ModeratedContent::znajdz($typ, $id);

        // Trzy klasy wypisane jawnie to te same trzy co w `ZdejmijZUrzedu::TYPY`
        // — `instanceof $klasa` sprawdza je w czasie wykonania, ale typu nie
        // zawęża, a `adresTresci()` woła `url()`, którego `Model` nie ma (#1731).
        if (! ($cel instanceof Post || $cel instanceof Recipe || $cel instanceof Comment)
            || ! $cel instanceof $klasa
            || ! ZdejmijZUrzedu::widocznaDlaInnych($cel)) {
            abort(404);
        }

        return $cel;
    }

    private function adresTresci(Post|Recipe|Comment $cel): ?string
    {
        return $cel instanceof Comment ? $cel->subject()?->url() : $cel->url();
    }
}
