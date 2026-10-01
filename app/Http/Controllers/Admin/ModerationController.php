<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\Actions\RestoreContent;
use App\Domain\Moderation\Actions\RozstrzygnijZgloszenie;
use App\Domain\Moderation\CofniecieUkryciaWersji;
use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\PriorytetSprawy;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\DecyzjaModeracyjnaRequest;
use App\Models\ModerationAction;
use App\Models\RecipeHint;
use App\Models\RecipeVersion;
use App\Models\Report;
use App\Models\User;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kolejka moderacji dla 1-2 osobowego zespołu.
 *
 * Musi istnieć PRZED publicznym startem (docs/ROADMAP.md, punkt 9).
 * Każda decyzja zapisuje uzasadnienie i treść wysłaną użytkownikowi —
 * bez tego nie da się rozpatrzyć odwołania (DSA art. 17).
 */
class ModerationController extends Controller
{
    public function __construct(
        private readonly RozstrzygnijZgloszenie $rozstrzygnij,
        private readonly RestoreContent $przywroc,
    ) {}

    public function reports(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $status = $request->query('status', 'open');

        /*
         * DOMYŚLNIE: SPRAWY OD LUDZI (D-052).
         *
         * Oznaczenia automatu leżą w tej samej tabeli, bo kończą się tą samą
         * decyzją, tym samym wpisem w `moderation_actions` i tą samą ścieżką
         * odwołania — ale mają własny ekran (`/admin/sygnaly`) i własną
         * kolejność pracy. Ta lista jest kolejką spraw OD LUDZI: ktoś czeka
         * na odpowiedź, biegną terminy z DSA art. 16 ust. 5. Maszynowe
         * podejrzenia, których większość okaże się niczym, zasypałyby ją przy
         * pierwszej fali nowych kont — a wtedy narzędzie mające dać
         * moderatorowi czas odebrałoby mu ten, który miał.
         *
         * `?zrodlo=automat` odwraca ten filtr i jest jedynym miejscem, w
         * którym da się wydać PEŁNĄ decyzję o oznaczonej treści (ukryj, usuń,
         * zawieś). Ekran sygnałów prowadzi tu odnośnikiem „Rozpatrz
         * pojedynczo" — formularz decyzji jest jeden dla całego serwisu i tak
         * ma zostać: druga jego kopia rozjechałaby się przy pierwszej zmianie
         * w obowiązkach z DSA art. 17.
         */
        $zrodlo = $request->query('zrodlo') === Report::SOURCE_AUTOMAT ? Report::SOURCE_AUTOMAT : 'ludzie';

        // Priorytet TYLKO dla otwartych: archiwum P0 nie stoi w „Wszystkie"
        // nad dzisiejszym otwartym P2 (`PriorytetSprawy::wyrazenieSqlKolejki`).
        [$wyrazenieSql, $parametrySql] = PriorytetSprawy::wyrazenieSqlKolejki();

        $reports = Report::query()
            ->when(
                $zrodlo === Report::SOURCE_AUTOMAT,
                fn ($query) => $query->where('source', Report::SOURCE_AUTOMAT),
                fn ($query) => $query->where('source', '!=', Report::SOURCE_AUTOMAT),
            )
            ->when($status !== 'wszystkie', fn ($query) => $query->where('status', $status))
            ->with(['reporter.profile', 'resolver.profile'])
            // DRUGI WARUNEK PORZĄDKU TO NIE OZDOBA (audyt zewnętrzny, G10).
            //
            // Stało tu samo `latest()`, czyli `ORDER BY created_at DESC`.
            // Przy remisie na `created_at` PostgreSQL nie obiecuje ŻADNEJ
            // kolejności — oddaje wiersze w takim porządku, w jakim dotarły
            // do sortowania, czyli w porządku FIZYCZNYM w stercie. A ten
            // zmienia każdy UPDATE: zaktualizowany wiersz ląduje zwykle na
            // końcu tabeli.
            //
            // Kolejka jest stronicowana po 25, więc niestabilny porządek nie
            // znaczy „inna kolejność na ekranie", tylko INNY PODZIAŁ NA STRONY
            // między jednym kliknięciem a drugim: to samo zgłoszenie widziane
            // dwa razy na dwóch stronach, a inne — pominięte. Moderator nie ma
            // jak tego zauważyć, bo nie zna liczby, której szuka.
            //
            // Remis na sekundzie nie jest tu rzadki: fala spamu to kilkanaście
            // zgłoszeń w tej samej chwili, a rozpatrzenie zgłoszenia robi
            // UPDATE — czyli dokładnie ten ruch, który przestawia stertę.
            //
            // `id` jest UUID-em v7, więc rozstrzyga remis w tę samą stronę co
            // czas: nowsze na górze. Nie zmienia to kolejności ANI JEDNEJ pary
            // wierszy o różnym `created_at`.
            //
            // PRZED CZASEM STOI PRIORYTET (`PriorytetSprawy`) i to jest
            // ZMIANA WOBEC POPRZEDNIEJ GWARANCJI „najnowsze na górze".
            //
            // Sama data nie wystarczała: spam przychodzi falami, więc im
            // gorszy dzień, tym głębiej pod nim leży rzecz, która nie może
            // czekać. ZMIERZONE (`KolejkaModeracjiStawiaPilneNaGorzeTest`):
            // zgłoszenie „Dotyczy dziecka" sprzed dwóch dni leży pod
            // trzydziestoma zgłoszeniami spamu z ostatniej godziny, czyli na
            // DRUGIEJ stronie kolejki stronicowanej po 25.
            //
            // Wewnątrz jednego priorytetu porządek zostaje DOKŁADNIE taki,
            // jaki był — najnowsze na górze, remis po `id`. Zmieniamy jedną
            // rzecz naraz: kolejność MIĘDZY wagami. Odwrócenie kierunku
            // wewnątrz wagi (jak proponowała odrzucona gałąź) jest osobną
            // decyzją, bez dowodu i z własną ceną: góra kolejki przestałaby
            // się odświeżać, a moderator patrzyłby codziennie na te same
            // sprawy, których z jakiegoś powodu nie rozstrzygnął.
            ->orderByRaw($wyrazenieSql.' ASC', $parametrySql)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('pages.admin.reports', [
            'status' => $status,
            'zrodlo' => $zrodlo,
            'reports' => $reports,
            // Które zgłoszenia da się dziś cofnąć (issue #65) — i przy
            // których cofnąć może tylko ktoś inny, bo to treść patrzącego (#1479).
            'przywracalne' => $this->przywracalne($reports->getCollection()->all(), $request->user()),
            'wersje' => $this->wersjeZgloszen($reports->getCollection()->all()),
            'wskazowki' => $this->wskazowkiZgloszen($reports->getCollection()->all()),
            // Liczniki nad zakładkami liczą TO SAMO, co pokazuje lista pod
            // nimi — z tym samym warunkiem o źródle, także przy
            // `?zrodlo=automat` (issue #990). Zakładka niesie bieżące
            // `zrodlo`, więc jej liczba ma mówić, ile pozycji otworzy.
            // Liczone od ludzi przy widoku automatu dawały „Nowe (0)" nad
            // trzema oznaczeniami albo „Nowe (14)" nad czterema.
            'counts' => [
                'open' => $this->zeZrodla($zrodlo, Report::STATUS_OPEN),
                'reviewing' => $this->zeZrodla($zrodlo, Report::STATUS_REVIEWING),
                'resolved' => $this->zeZrodla($zrodlo, Report::STATUS_RESOLVED),
            ],
            // Ile czeka po drugiej stronie — odnośnik na ekranie zgłoszeń
            // ma powiedzieć, ile tam jest, zanim człowiek tam kliknie.
            'sygnalow' => Report::query()
                ->where('source', Report::SOURCE_AUTOMAT)
                ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
                ->count(),
        ]);
    }

    /**
     * Ekrany zgłoszonych wersji przepisu (#2390) — jednym zapytaniem, żeby
     * kolejka mogła odesłać moderatora do wersji, o którą chodzi (kolejka
     * pokazuje samo `target_type · target_id`). Moderacja widzi też wersje
     * ukryte, a adres ma numer, który moderator zna z historii zmian.
     *
     * @param  list<Report>  $reports
     * @return array<string, array{adres: string, numer: int, tytul: string}> klucz: id wersji
     */
    private function wersjeZgloszen(array $reports): array
    {
        $ids = [];
        foreach ($reports as $report) {
            if ($report->target_type === CofniecieUkryciaWersji::TYP && $report->target_id !== null) {
                $ids[] = (string) $report->target_id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $wynik = [];
        foreach (RecipeVersion::query()->with('recipe:id,slug,title')->whereIn('id', $ids)->get(['id', 'recipe_id', 'version_number']) as $wersja) {
            if ($wersja->recipe === null) {
                continue;
            }
            $wynik[(string) $wersja->getKey()] = [
                'adres' => route('recipes.history.version', [$wersja->recipe->slug, $wersja->version_number]),
                'numer' => $wersja->version_number,
                'tytul' => (string) $wersja->recipe->title,
            ];
        }

        return $wynik;
    }

    /**
     * Zgłoszone wskazówki od gotujących (#2352) — jednym zapytaniem. Kolejka
     * pokazuje samo `target_type · target_id`, a moderator ma zobaczyć TEKST,
     * o który chodzi (uwaga z wykonania), przepis, przy którym stoi, i czy
     * dalej jest pokazywana. Moderacja czyta tu cudzą uwagę z urzędu, jak przy
     * każdej zgłoszonej treści; wykonanie zostaje nietknięte.
     *
     * @param  list<Report>  $reports
     * @return array<string, array{adres: string, tytul: string, tekst: string, kucharz: string, pokazywana: bool}> klucz: id wskazówki
     */
    private function wskazowkiZgloszen(array $reports): array
    {
        $ids = [];
        foreach ($reports as $report) {
            if ($report->target_type === 'recipe_hint' && $report->target_id !== null) {
                $ids[] = (string) $report->target_id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $wynik = [];
        foreach (RecipeHint::query()->with(['recipe:id,slug,title', 'cookedEvent:id,note', 'cook.profile'])->whereIn('id', $ids)->get() as $wskazowka) {
            if ($wskazowka->recipe === null) {
                continue;
            }
            $wynik[(string) $wskazowka->getKey()] = [
                'adres' => route('recipes.show', $wskazowka->recipe).'#wskazowki-gotujacych',
                'tytul' => (string) $wskazowka->recipe->title,
                'tekst' => (string) $wskazowka->cookedEvent?->note,
                'kucharz' => (string) $wskazowka->cook?->displayName(),
                'pokazywana' => $wskazowka->jestPokazywana(),
            ];
        }

        return $wynik;
    }

    /** Sprawy z bieżącego źródła (ludzie albo automat) w danym stanie — ten sam warunek, co lista wyżej. */
    private function zeZrodla(string $zrodlo, string $status): int
    {
        return Report::query()
            ->when(
                $zrodlo === Report::SOURCE_AUTOMAT,
                fn ($query) => $query->where('source', Report::SOURCE_AUTOMAT),
                fn ($query) => $query->where('source', '!=', Report::SOURCE_AUTOMAT),
            )
            ->where('status', $status)
            ->count();
    }

    /**
     * Decyzja w sprawie otwartego zgłoszenia.
     *
     * Wejście (rola, własna sprawa, sprawa już rozstrzygnięta, reguły pól)
     * sprawdza `DecyzjaModeracyjnaRequest`, zanim ruszy ta metoda. Transakcję,
     * sankcję, powiadomienia i dziennik wykonuje `RozstrzygnijZgloszenie`
     * (issue #970, krok 2). Tu zostaje tylko wybór odpowiedzi.
     */
    public function decide(DecyzjaModeracyjnaRequest $request, Report $report): RedirectResponse
    {
        // Rolę sprawdza już `DecyzjaModeracyjnaRequest::authorize()` — przed
        // walidacją, jak dotąd. Zostaje też tutaj, żeby wejście było widać
        // w kontrolerze i żeby przeniesienie walidacji go nie zgubiło.
        $this->authorize('moderate', User::class);

        $wynik = $this->rozstrzygnij->handle(
            moderator: $request->user(),
            report: $report,
            data: $request->validated(),
            ip: $request->ip(),
        );

        if ($wynik === null) {
            // Drugie żądanie doszło tu po tym, jak pierwsze już zapisało
            // decyzję. Ten sam komunikat co przy wstępnym sprawdzeniu — dla
            // człowieka to jest ta sama sytuacja.
            return back()->withErrors([
                'action' => DecyzjaModeracyjnaRequest::JUZ_ROZSTRZYGNIETE,
            ]);
        }

        return back()->with(Komunikat::sukces('Decyzja zapisana.'));
    }

    /**
     * Cofnięcie ukrycia albo usunięcia treści (issue #65).
     *
     * DLACZEGO OSOBNY ENDPOINT, A NIE KOLEJNA POZYCJA W `DOZWOLONE`
     * Formularz decyzji obsługuje zgłoszenie OTWARTE i słusznie odmawia
     * drugiej decyzji dla zgłoszenia rozstrzygniętego (inaczej log przestaje
     * być jednoznaczny). Przywrócenie z definicji przychodzi PÓŹNIEJ — po
     * poprawieniu treści przez autora albo po odwołaniu — więc musiało dostać
     * własne wejście, własny przycisk i własną nazwę.
     *
     * Powód decyzji jest obowiązkowy tak samo jak przy ukrywaniu: cofnięcie
     * kary bez uzasadnienia wygląda w logu jak przypadek.
     */
    public function restore(Request $request, Report $report): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:80'],
            'user_message' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason_code.required' => 'Podaj powód przywrócenia — w logu musi zostać ślad, dlaczego zdjęto ukrycie.',
        ]);

        // „Przywróć” cofa decyzję podjętą PRZY TYM zgłoszeniu (audyt B2-01).
        // Bez tego warunku przycisk z dowolnego zgłoszenia — także
        // odrzuconego — przywracał treść, którą schował ktoś inny.
        $decyzja = ModerationAction::query()
            ->where('report_id', $report->getKey())
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE])
            ->first();

        if ($decyzja === null) {
            return back()->withErrors([
                'reason_code' => 'Przy tym zgłoszeniu moderacja niczego nie ukryła ani nie usunęła, więc nie ma czego przywracać.',
            ]);
        }

        $cel = ModeratedContent::znajdz($decyzja->target_type, $decyzja->target_id, zUsunietymi: true);

        if ($cel === null) {
            return back()->withErrors([
                'reason_code' => 'Tej treści już nie ma w bazie — nie da się jej przywrócić.',
            ]);
        }

        try {
            $this->przywroc->handle(
                moderator: $request->user(),
                target: $cel,
                reasonCode: $data['reason_code'],
                note: 'Przywrócone przy zgłoszeniu '.$report->getKey().'.',
                userMessage: $data['user_message'] ?? null,
                ip: $request->ip(),
            );
        } catch (BladDlaCzlowieka $blad) {
            return back()->withErrors(['reason_code' => $blad->getMessage()]);
        }

        return back()->with(Komunikat::sukces('Treść przywrócona. Wróciła do stanu sprzed ukrycia, a autor dostał powiadomienie.'));
    }

    /**
     * Zgłoszenia, przy których jest dziś co przywracać.
     *
     * Zapytanie na cel idzie osobno dla każdego zgłoszenia z decyzją
     * `hide`/`remove` — świadomie, bo cele leżą w czterech różnych tabelach,
     * a strona kolejki ma 25 pozycji obsługiwanych przez jedną osobę.
     * Optymalizacja tego miejsca kosztowałaby więcej czytelności niż daje.
     *
     * Treść, której autorem jest patrzący moderator, dostaje `wlasna`
     * zamiast `przywroc` (#1479): `RestoreContent` i tak by odmówił, więc
     * przycisk byłby martwy (AGENTS.md §5). Autora bierzemy tą samą drogą
     * co reguła w akcji — `ModeratedContent::osoba()`.
     *
     * Treść, którą ukrył administrator, dostaje u zwykłego moderatora
     * `tylko_admin` (#1748): regułę rangi B2-01 czytamy z
     * `RestoreContent::wolnoCofnac()`, nie z kopii. Kolejność jak w akcji —
     * najpierw własna treść, potem ranga — więc informacja w widoku mówi to
     * samo, co powiedziałaby odmowa.
     *
     * @param  list<Report>  $reports
     * @return array<string, 'przywroc'|'wlasna'|'tylko_admin'> klucz: id zgłoszenia
     */
    private function przywracalne(array $reports, User $moderator): array
    {
        if ($reports === []) {
            return [];
        }

        $decyzje = ModerationAction::query()
            ->whereIn('report_id', array_map(fn (Report $r): string => (string) $r->getKey(), $reports))
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE])
            ->orderBy('created_at')
            ->get();

        $wynik = [];

        foreach ($decyzje as $decyzja) {
            $cel = ModeratedContent::znajdz($decyzja->target_type, $decyzja->target_id, zUsunietymi: true);

            if ($cel === null || ! ModeratedContent::daSieUkryc($cel)) {
                continue;
            }

            $usunieta = method_exists($cel, 'trashed') && $cel->trashed();
            $schowana = ModeratedContent::jestUkryta($cel) || $usunieta;

            if (! $schowana) {
                continue;
            }

            // Treść schowana przez autora albo właściciela wpisu nie dostaje
            // przycisku — `RestoreContent` i tak by odmówił (B2-01).
            $zdjecie = RestoreContent::zdjeciePrzezModeracje($decyzja->target_type, (string) $decyzja->target_id, $usunieta);

            if ($zdjecie === null) {
                continue;
            }

            $wynik[(string) $decyzja->report_id] = match (true) {
                ModeratedContent::osoba($cel)?->getKey() === $moderator->getKey() => 'wlasna',
                ! RestoreContent::wolnoCofnac($moderator, $zdjecie) => 'tylko_admin',
                default => 'przywroc',
            };
        }

        return $wynik;
    }
}
