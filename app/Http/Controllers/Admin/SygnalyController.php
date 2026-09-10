<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * KOLEJKA AUTOMATU — rzeczy, których NIKT nie zgłosił (D-052).
 *
 * DLACZEGO OSOBNY EKRAN, SKORO WIERSZE LEŻĄ W TEJ SAMEJ TABELI
 * Bo to jest inna praca i inna pilność. `/admin/zgloszenia` zawiera sprawy
 * od LUDZI: ktoś czeka na odpowiedź, biegną terminy z DSA art. 16 ust. 5,
 * a każda pozycja jest z definicji warta przeczytania. Tutaj leżą maszynowe
 * podejrzenia, z których większość okaże się niczym — i gdyby wpadały do
 * tamtej listy, przy setkach kont zasypałyby zgłoszenia od ludzi w tydzień.
 * Rozdzielenie ekranów jest jedynym sposobem, żeby narzędzie nie odebrało
 * moderatorowi czasu, który miało mu dać.
 *
 * Tabela zostaje jedna, bo koniec drogi jest ten sam: `ModerationAction`,
 * odwołanie, wspólna retencja. Nie duplikujemy kolejki, duplikujemy widok.
 *
 * GRUPOWANIE PO AUTORZE, NIE LISTA POZYCJI
 * Dziesięć wpisów tego samego konta to JEDNA rzecz do rozstrzygnięcia, a nie
 * dziesięć. Lista pozycja-po-pozycji zmusza człowieka do samodzielnego
 * zauważania, że dziesiąty raz patrzy na to samo konto — czyli do pracy,
 * którą baza wykona `GROUP BY`.
 *
 * KOLEJNOŚĆ = DECYZJA O TYM, CZEGO MODERATOR NIE ZDĄŻY PRZEJRZEĆ
 * Najpierw najcięższy sygnał w grupie (`Report::WAGA`), potem grupy
 * największe, potem najnowsze. Przy tysiącu kont nikt nie dochodzi do końca
 * listy i trzeba to założyć wprost, zamiast udawać, że kolejność jest
 * obojętna.
 */
class SygnalyController extends Controller
{
    /** Ile GRUP na stronę. Grupa bywa duża, więc mniej niż w kolejce zgłoszeń. */
    private const GRUP_NA_STRONE = 20;

    /** Ile pozycji rozwijamy w grupie; reszta zostaje policzona, ale nie wypisana. */
    private const POZYCJI_W_GRUPIE = 10;

    /** Powód w logu przy zamknięciu grupy — patrz `PodstawaDecyzji`: kod spoza listy nie dostaje numeru punktu i tak ma być. */
    public const POWOD_ODRZUCENIA = 'automat-falszywy-alarm';

    public function index(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $grupy = $this->grupy();

        $pozycje = $this->pozycje($grupy);

        return view('pages.admin.sygnaly', [
            'grupy' => $grupy,
            'pozycje' => $pozycje,
            'adresy' => $this->adresy($pozycje),
            'pozycjiWGrupie' => self::POZYCJI_W_GRUPIE,
            'otwartych' => $this->otwarte()->count(),
        ]);
    }

    /**
     * „To nic takiego" dla CAŁEJ grupy jednym kliknięciem.
     *
     * Jedna decyzja zamyka wszystkie otwarte oznaczenia jednego konta.
     * Każde z nich dostaje WŁASNY wiersz w `moderation_actions` — log ma
     * odpowiadać na pytanie „co się stało z TĄ treścią", a nie „co się stało
     * z tą grupą", której jutro już nie będzie.
     *
     * CZEGO TA DECYZJA NIE ROBI: nie powiadamia autora. `no_action` znaczy,
     * że tej osobie NIC się nie stało — powiadomienie powiedziałoby jej, że
     * była o coś podejrzewana, i to przez maszynę, której nikt nie zgłaszał
     * (`NotifyModerationDecision` sam odmawia wysyłki przy `no_action`).
     *
     * Odrzucone oznaczenie NIE WRACA: wiersz zostaje w tabeli, a indeks
     * `reports_jeden_automat_na_tresc` nie pozwoli automatowi postawić
     * drugiego dla tej samej treści.
     */
    public function odrzucGrupe(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $dane = $request->validate([
            'autor' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'autor.required' => 'Nie wiadomo, którą grupę zamknąć. Odśwież stronę i spróbuj jeszcze raz.',
        ]);

        $autorId = $dane['autor'] === 'brak' ? null : $dane['autor'];
        $moderator = $request->user();

        $ile = DB::transaction(function () use ($autorId, $moderator, $dane): int {
            $oznaczenia = $this->otwarte()
                ->when($autorId === null,
                    fn ($q) => $q->whereNull('autor_tresci_id'),
                    fn ($q) => $q->where('autor_tresci_id', $autorId),
                )
                // Blokada wiersza z tego samego powodu co w `decide()`:
                // dwie karty moderatora nie mogą wydać dwóch decyzji do
                // jednego oznaczenia (`moderation_actions` ma UNIQUE na
                // `report_id`).
                ->lockForUpdate()
                ->get();

            foreach ($oznaczenia as $oznaczenie) {
                ModerationAction::create([
                    'moderator_id' => $moderator->getKey(),
                    'report_id' => $oznaczenie->getKey(),
                    'target_type' => $oznaczenie->target_type,
                    'target_id' => $oznaczenie->target_id,
                    'subject_user_id' => $oznaczenie->autor_tresci_id,
                    'action' => ModerationAction::ACTION_NONE,
                    'reason_code' => self::POWOD_ODRZUCENIA,
                    'note' => $dane['note'] ?? 'Automat się pomylił — treść zostaje bez zmian.',
                ]);

                $oznaczenie->update([
                    'status' => Report::STATUS_REJECTED,
                    'resolution_note' => $dane['note'] ?? null,
                    'resolved_by' => $moderator->getKey(),
                    'resolved_at' => now(),
                ]);
            }

            return $oznaczenia->count();
        });

        if ($ile === 0) {
            return back()->withErrors([
                'autor' => 'Te oznaczenia zostały już zamknięte. Odśwież stronę, żeby zobaczyć aktualną listę.',
            ]);
        }

        AuditLogEntry::record(
            action: 'moderation.automat_dismissed',
            actor: $moderator,
            metadata: ['autor_tresci_id' => $autorId, 'ile' => $ile],
            ip: $request->ip(),
        );

        return back()->with('status', $ile === 1
            ? 'Zamknięte. Treść zostaje bez zmian, a automat już do niej nie wróci.'
            : 'Zamknięte — '.$ile.' oznaczenia tego konta. Treści zostają bez zmian, a automat już do nich nie wróci.');
    }

    /** Otwarte oznaczenia automatu — jedno miejsce, w którym rozstrzyga się „co jeszcze czeka". */
    private function otwarte(): Builder
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING]);
    }

    /**
     * Grupy do pokazania na tej stronie — jedno zapytanie agregujące.
     *
     * Agregat idzie po indeksie częściowym `reports_automat_autor_idx`, więc
     * jego koszt nie rośnie z tabelą `reports`, tylko z liczbą OTWARTYCH
     * oznaczeń. Zamknięte wypadają z indeksu razem ze zmianą statusu — czyli
     * kolejka, którą moderator opróżnia, naprawdę staje się tańsza.
     *
     * @return LengthAwarePaginator<int, Report>
     */
    private function grupy(): LengthAwarePaginator
    {
        return $this->otwarte()
            ->selectRaw('autor_tresci_id, COUNT(*) AS ile, MAX('.$this->wagaCase().') AS waga, MAX(created_at) AS ostatnie')
            ->with('autorTresci.profile')
            ->groupBy('autor_tresci_id')
            ->orderByDesc('waga')
            ->orderByDesc('ile')
            ->orderByDesc('ostatnie')
            ->paginate(self::GRUP_NA_STRONE)
            ->withQueryString();
    }

    /**
     * Pozycje należące do grup z TEJ strony — jedno zapytanie na całą stronę.
     *
     * Nie `N+1` po grupach i nie pobranie wszystkich otwartych oznaczeń:
     * pytamy dokładnie o konta, które i tak wyświetlamy.
     *
     * @param  LengthAwarePaginator<int, Report>  $grupy
     * @return Collection<string, Collection<int, Report>>
     */
    private function pozycje(LengthAwarePaginator $grupy): Collection
    {
        /** @var list<string> $autorzy */
        $autorzy = collect($grupy->items())
            ->pluck('autor_tresci_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        $bezAutora = collect($grupy->items())->contains(
            static fn (Report $grupa): bool => $grupa->autor_tresci_id === null,
        );

        if ($autorzy === [] && ! $bezAutora) {
            return collect();
        }

        return $this->otwarte()
            ->where(function ($query) use ($autorzy, $bezAutora): void {
                if ($autorzy !== []) {
                    $query->whereIn('autor_tresci_id', $autorzy);
                }

                if ($bezAutora) {
                    $query->orWhereNull('autor_tresci_id');
                }
            })
            ->orderByRaw($this->wagaCase().' DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(static fn (Report $r): string => (string) ($r->autor_tresci_id ?? 'brak'));
    }

    /**
     * Adresy oznaczonych treści — DWA zapytania na całą stronę, nie jedno na wiersz.
     *
     * Moderator musi móc kliknąć i zobaczyć treść własnymi oczami, inaczej
     * kolejka jest listą zarzutów bez dowodów. Naiwne `->url()` w widoku dla
     * dwustu pozycji to dwieście zapytań plus tyle samo na rodziców
     * komentarzy — przy fali nowych kont ten ekran przestałby się otwierać
     * dokładnie wtedy, kiedy jest potrzebny.
     *
     * Klucz mapy to `typ:id`, bo identyfikatory z różnych tabel mogą się
     * teoretycznie powtórzyć, a cicho pomylony adres wysłałby moderatora na
     * cudzą treść.
     *
     * @param  Collection<string, Collection<int, Report>>  $pozycje
     * @return array<string, string>
     */
    private function adresy(Collection $pozycje): array
    {
        /** @var Collection<int, Report> $wszystkie */
        $wszystkie = $pozycje->flatten();

        $wpisy = $wszystkie->where('target_type', 'post')->pluck('target_id')->filter()->unique()->all();
        $komentarze = $wszystkie->where('target_type', 'comment')->pluck('target_id')->filter()->unique()->all();

        $adresy = [];

        foreach (Post::query()->whereIn('id', $wpisy)->get() as $wpis) {
            $adresy['post:'.$wpis->getKey()] = $wpis->url();
        }

        // Komentarz nie ma własnego adresu — otwiera się razem z treścią,
        // pod którą stoi. Rodzice idą przez `with()`, żeby nie zrobić z tego
        // trzech zapytań na komentarz.
        $zRodzicami = Comment::query()
            ->with(['post', 'recipe', 'cookedEvent'])
            ->whereIn('id', $komentarze)
            ->get();

        foreach ($zRodzicami as $komentarz) {
            $rodzic = $komentarz->post ?? $komentarz->recipe ?? $komentarz->cookedEvent;

            if ($rodzic !== null) {
                $adresy['comment:'.$komentarz->getKey()] = $rodzic->url().'#komentarz-'.$komentarz->getKey();
            }
        }

        return $adresy;
    }

    /**
     * Waga sygnału jako wyrażenie SQL.
     *
     * Budowane z `Report::WAGA`, a nie wpisane w zapytanie z ręki: gdyby
     * kolejność w kodzie i kolejność w bazie były dwoma niezależnymi
     * listami, rozjechałyby się przy pierwszej zmianie — a rozjazd byłby
     * niewidoczny, bo obie wersje dają POPRAWNIE WYGLĄDAJĄCĄ kolejkę.
     *
     * Wartości pochodzą wyłącznie ze stałej w kodzie (klucze przez
     * `addslashes` i tak, bo `selectRaw` nie przyjmuje wiązań w `GROUP BY`
     * dla wszystkich sterowników) — nic tu nie pochodzi z żądania.
     */
    private function wagaCase(): string
    {
        $galezie = '';

        foreach (Report::WAGA as $kod => $waga) {
            $galezie .= " WHEN '".addslashes((string) $kod)."' THEN ".(int) $waga;
        }

        return '(CASE reason'.$galezie.' ELSE 0 END)';
    }
}
