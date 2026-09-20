<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\Actions\ReportContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * Zgłaszanie treści (DSA art. 16).
 *
 * Przycisk w interfejsie ma NAPIS "Zgłoś", nie ikonkę flagi — osoba, która
 * chce zgłosić oszustwo, nie ma zgadywać, co znaczy trójkącik.
 *
 * `resolveTarget()` niżej rozstrzyga TYLKO, czy cel ISTNIEJE — nie, czy
 * zgłaszającemu wolno go zobaczyć. Widoczność sprawdza `ReportContent`
 * (`authorize()`/`handle()`), nie ten kontroler (audyt W7-05, AGENTS.md §4):
 * gdyby bramka siedziała tutaj, a nie w Domain, każdy kolejny endpoint
 * budujący cel zgłoszenia musiałby o niej pamiętać z osobna.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportContent $report) {}

    public function create(Request $request, string $type, string $id): View
    {
        $target = $this->resolveTarget($type, $id);

        // Ta sama bramka co w `store()` (przez `handle()`) — inaczej sam
        // formularz renderowałby się dla treści, której zgłaszający nie ma
        // prawa zobaczyć, i byłby to oracle istnienia sam w sobie.
        $this->report->authorize($request->user(), $target);

        return view('pages.report', [
            'targetType' => $type,
            'targetId' => $id,
            'target' => $target,
            'reasons' => Report::REASONS,
        ]);
    }

    public function store(Request $request, string $type, string $id): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string'],
            'details' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason.required' => 'Wybierz, co jest nie tak z tą treścią.',
            // Bez tego wypadał szablon ogólny: „Pole «szczegóły zgłoszenia»
            // jest za długie — może mieć najwyżej 2000 znaków." Na ekranie
            // nie ma niczego o nazwie „szczegóły zgłoszenia" — jest pytanie
            // „Chcesz coś dopisać?" — a zdanie nie mówiło, co zrobić.
            'details.max' => 'To jest za długie. Zmieść się w 2000 znakach — napisz samo to, co najważniejsze.',
        ]);

        try {
            $zgloszenie = $this->report->handle(
                reporter: $request->user(),
                target: $this->resolveTarget($type, $id),
                reason: $data['reason'],
                details: $data['details'] ?? null,
                ip: $request->ip(),
            );
        } catch (BladDlaCzlowieka $e) {
            // Stał tu wcześniej jawny `catch (ModelNotFoundException) { throw; }`,
            // bo poprzedni `catch (RuntimeException)` łapał także ją — a bramka
            // widoczności ma dawać 404 nieodróżnialne od celu, którego w ogóle
            // nie ma, nie zwykły błąd formularza. Znacznik `BladDlaCzlowieka`
            // rozwiązuje to u źródła: `ModelNotFoundException` nim nie jest
            // i przechodzi dalej sama.
            return back()->withInput()->withErrors(['reason' => $e->getMessage()]);
        }

        // POWRÓT DO SPRAWY, KTÓRA JUŻ ISTNIEJE, NIE JEST PRZYJĘCIEM NOWEJ
        // (issue #796). `wasRecentlyCreated` odróżnia świeży `INSERT` od
        // obu dróg powrotu w `ReportContent` — ciepłego `SELECT`-a i odbicia
        // się o indeks `reports_one_open_per_pair`.
        if (! $zgloszenie->wasRecentlyCreated) {
            return $this->powrotDoIstniejacejSprawy($zgloszenie, $data);
        }

        /*
         * ODESŁANIE NA KARTĘ SPRAWY, NIE NA STRONĘ GŁÓWNĄ (issue #10).
         *
         * Do tej zmiany zgłoszenie kończyło się na `/home` i jednym zdaniem
         * flash-em: człowiek nie widział numeru sprawy ANI RAZU, a po
         * odświeżeniu strony nie zostawało nic. Karta pokazuje numer, stan
         * i to, co się stanie dalej — czyli potwierdzenie, do którego da się
         * wrócić (DSA art. 16 ust. 4). Flash zostaje jako pierwsze zdanie,
         * bo to on mówi „przyjęliśmy" w chwili, w której człowiek na to
         * czeka.
         */
        return redirect()->route('reports.mine.show', $zgloszenie)->with('status',
            'Dziękujemy. Zgłoszenie trafiło do nas i sprawdzimy je najszybciej, jak się da. '
            .'Poniżej jest jego numer i stan — napiszemy tutaj, co postanowiliśmy. '
            .'Jeśli chcesz, możesz też zablokować tę osobę: wtedy nie zobaczycie już wzajemnie swoich treści.',
        );
    }

    /**
     * ODPOWIEDŹ NA PONOWNE ZGŁOSZENIE TEJ SAMEJ TREŚCI (issue #796).
     *
     * CO BYŁO PRZEDTEM
     * Deduplikacja jest celowa i zostaje — jedna otwarta sprawa na parę
     * (zgłaszający, treść). Ale odpowiedź była ta sama co przy przyjęciu
     * nowego zgłoszenia: „Dziękujemy. Zgłoszenie trafiło do nas…". Człowiek,
     * który wrócił z NOWYM wyjaśnieniem, miał pełne prawo sądzić, że jego
     * nowy tekst dotarł do moderatora. Nie dotarł: `ReportContent` oddaje
     * wcześniejszą sprawę bez uwzględnienia nowego `reason`/`details`.
     *
     * DWIE RÓŻNE SYTUACJE, DWIE RÓŻNE ODPOWIEDZI
     *  - nic nowego (podwójne kliknięcie, powrót „czy na pewno wysłałem")
     *    → karta sprawy i zdanie, że sprawa już u nas jest;
     *  - nowy powód albo nowy opis → POWRÓT DO FORMULARZA z zachowanym
     *    tekstem i jawnym zdaniem, czego NIE zapisaliśmy.
     *
     * TEKST CZŁOWIEKA NIE ZNIKA (AGENTS.md: poprawne dane nigdy nie giną).
     * `withInput()` odtwarza w formularzu i powód, i cały opis, więc da się
     * go skopiować albo poprawić — zamiast porzucać go bez słowa przy
     * przekierowaniu na kartę sprawy.
     *
     * CZEGO TA ZMIANA NIE ROBI
     * Nie dopisuje uzupełnień do sprawy — to osobna decyzja produktowa,
     * i naprawa nieprawdziwego potwierdzenia jej nie wymaga. Nie nadpisuje
     * też po cichu oryginalnego opisu: pierwszy zapis jest dowodem w sprawie.
     *
     * @param  array{reason: string, details?: string|null}  $dane
     */
    private function powrotDoIstniejacejSprawy(Report $zgloszenie, array $dane): RedirectResponse
    {
        $opis = trim((string) ($dane['details'] ?? ''));
        $wczesniejszy = trim((string) $zgloszenie->details);

        // UZUPEŁNIENIE, A NIE PODWÓJNE KLIKNIĘCIE: inny powód albo niepusty
        // opis różny od zapisanego. Pusty opis przy ponowieniu NIE jest
        // nową informacją — nikt niczego nie dopisał.
        $noweSzczegoly = $dane['reason'] !== $zgloszenie->reason
            || ($opis !== '' && $opis !== $wczesniejszy);

        if (! $noweSzczegoly) {
            return redirect()->route('reports.mine.show', $zgloszenie)->with('status',
                'To zgłoszenie już u nas jest — sprawa '.$zgloszenie->numer_sprawy
                .' czeka w kolejce. Nie musisz zgłaszać tej treści drugi raz; '
                .'napiszemy tutaj, co postanowiliśmy.',
            );
        }

        $komunikat = 'Tę treść już nam zgłosiłeś i sprawa '.$zgloszenie->numer_sprawy
            .' czeka w kolejce — nic jej nie ubyło. Nowego opisu NIE dopisaliśmy '
            .'do tej sprawy. Twój tekst został w polu niżej: skopiuj go i wyślij '
            .'na '.config('kuking.community.contact_email').', podając numer sprawy '
            .$zgloszenie->numer_sprawy.'.';

        return back()->withInput()->withErrors(['details' => $komunikat]);
    }

    /**
     * „Twoje zgłoszenia" — lista spraw TEJ osoby (DSA art. 16 ust. 4 i 5,
     * issue #10).
     *
     * DLACZEGO EKRAN, SKORO JEST POWIADOMIENIE
     * Bo powiadomienie żyje krócej niż sprawa. Retencja powiadomień to
     * domyślnie trzy miesiące (`kuking.notifications.retention_months`),
     * a zgłoszenia — trzydzieści sześć (`kuking.moderation.case_retention_months`).
     * Bez tego ekranu potwierdzenie odbioru byłoby „trwałe" tylko do
     * najbliższego sprzątania, a człowiek, który zgubił powiadomienie, nie
     * miałby jak sprawdzić numeru sprawy przed napisaniem do nas.
     */
    public function index(Request $request): View
    {
        $zgloszenia = Report::query()
            ->where('reporter_id', $request->user()->getKey())
            // Ten sam drugi warunek porządku co w kolejce moderatora: przy
            // remisie na `created_at` PostgreSQL nie obiecuje żadnej
            // kolejności, a lista jest stronicowana — niestabilny porządek
            // znaczy inny podział na strony przy każdym wejściu.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('pages.zgloszenia.lista', [
            'zgloszenia' => $zgloszenia,
            'decyzje' => $this->decyzjeDla($zgloszenia->getCollection()->all()),
        ]);
    }

    /**
     * Karta jednej sprawy.
     *
     * `authorize()` PRZED czymkolwiek innym — UUID w adresie nie jest
     * autoryzacją (`AGENTS.md` §7), rozstrzyga `ReportPolicy::view()`.
     */
    public function show(Report $report): View
    {
        $this->authorize('view', $report);

        return view('pages.zgloszenia.szczegoly', [
            'zgloszenie' => $report,
            // Jedna decyzja na zgłoszenie (`moderation_actions_one_per_report`),
            // więc to zapytanie trafia w co najwyżej jeden wiersz.
            'decyzja' => ModerationAction::query()->where('report_id', $report->getKey())->first(),
        ]);
    }

    /**
     * Decyzje dla wypisanej strony zgłoszeń — jednym zapytaniem, żeby lista
     * nie robiła N+1. `ModerationAction` nie ma relacji `hasOne` po stronie
     * `Report`, a dokładanie jej tylko dla tego ekranu przeciągałoby model
     * moderacji do warstwy, która ma go nie znać.
     *
     * @param  list<Report>  $zgloszenia
     * @return array<string, ModerationAction> klucz: id zgłoszenia
     */
    private function decyzjeDla(array $zgloszenia): array
    {
        if ($zgloszenia === []) {
            return [];
        }

        return ModerationAction::query()
            ->whereIn('report_id', array_map(fn (Report $r): string => (string) $r->getKey(), $zgloszenia))
            ->get()
            ->keyBy(fn (ModerationAction $decyzja): string => (string) $decyzja->report_id)
            ->all();
    }

    private function resolveTarget(string $type, string $id): Model
    {
        return match ($type) {
            'post' => Post::findOrFail($id),
            // NIE `where('slug', $id)->orWhere('id', $id)`.
            //
            // `recipes.id` jest kolumną `uuid`, więc Postgres musi rzutować
            // parametr na uuid, żeby w ogóle wykonać porównanie — niezależnie
            // od tego, czy pierwszy warunek pasuje. Slug rzutowania nie
            // przechodzi i całe zapytanie pada:
            //
            //   SQLSTATE[22P02]: invalid input syntax for type uuid: "rosol-babci"
            //
            // Skutek: przycisk „Zgłoś" pod KAŻDYM przepisem zwracał 500,
            // bo widok przekazuje tam slug (pages/recipes/show.blade.php).
            // Zgłaszanie treści to obowiązek z DSA art. 16, więc awaria
            // dotyczyła nie wygody, tylko rzeczy, którą musimy zapewnić.
            //
            // Rozstrzygamy typ w PHP, zanim dotkniemy bazy.
            'recipe' => Str::isUuid($id)
                ? Recipe::findOrFail($id)
                : Recipe::where('slug', $id)->firstOrFail(),
            'comment' => Comment::findOrFail($id),
            'cooked_event' => CookedEvent::findOrFail($id),
            'user' => Profile::where('username', $id)->firstOrFail()->user,
            default => abort(404),
        };
    }
}
