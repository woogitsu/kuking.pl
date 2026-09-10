<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\Actions\NotifyModerationDecision;
use App\Domain\Moderation\Actions\NotifyReporterDecision;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Domain\Moderation\DlugoscZawieszenia;
use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\PodstawaDecyzji;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as Walidator;
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
        private readonly NotifyModerationDecision $powiadom,
        private readonly NotifyReporterDecision $powiadomZglaszajacego,
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
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('pages.admin.reports', [
            'status' => $status,
            'zrodlo' => $zrodlo,
            'reports' => $reports,
            // Które zgłoszenia da się dziś cofnąć (issue #65).
            'przywracalne' => $this->przywracalne($reports->getCollection()->all()),
            // Liczniki nad zakładkami liczą TO SAMO, co pokazuje lista pod
            // nimi. Bez tego samego warunku o źródle „Nowe (14)" oznaczałoby
            // czternaście spraw, z których widać cztery — a moderator nie ma
            // jak się dowiedzieć, że reszta jest na innym ekranie.
            'counts' => [
                'open' => $this->odLudzi(Report::STATUS_OPEN),
                'reviewing' => $this->odLudzi(Report::STATUS_REVIEWING),
                'resolved' => $this->odLudzi(Report::STATUS_RESOLVED),
            ],
            // Ile czeka po drugiej stronie — odnośnik na ekranie zgłoszeń
            // ma powiedzieć, ile tam jest, zanim człowiek tam kliknie.
            'sygnalow' => Report::query()
                ->where('source', Report::SOURCE_AUTOMAT)
                ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
                ->count(),
        ]);
    }

    /** Zgłoszenia OD LUDZI w danym stanie — bez oznaczeń automatu, tak jak lista wyżej. */
    private function odLudzi(string $status): int
    {
        return Report::query()
            ->where('source', '!=', Report::SOURCE_AUTOMAT)
            ->where('status', $status)
            ->count();
    }

    public function decide(Request $request, Report $report): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        // Wstępne sprawdzenie — tanie i daje sensowny komunikat bez wchodzenia
        // w transakcję. NIE JEST GWARANCJĄ: prawdziwe rozstrzygnięcie stoi
        // niżej, pod blokadą wiersza.
        if ($report->status !== Report::STATUS_OPEN) {
            return back()->withErrors([
                'action' => 'To zgłoszenie zostało już rozstrzygnięte. Odśwież stronę, żeby zobaczyć decyzję.',
            ]);
        }

        // Lista dozwolonych decyzji zależy od TYPU zgłoszenia — patrz
        // ModerationAction::DOZWOLONE. Kombinacja spoza listy jest błędem,
        // a nie cichym „zrób coś innego".
        $dozwolone = array_keys(ModerationAction::dozwoloneDla($report->target_type));

        /*
         * CZY LICZBA DNI Z POLA „WŁASNY TERMIN" ZOSTANIE W OGÓLE UŻYTA.
         *
         * Od tego zależy, czy pilnujemy jej zakresu — i to jest różnica
         * zamierzona, nie oszczędność. Moderator, który zaznaczył „własny
         * termin", wpisał 14, a potem zmienił zdanie i wybrał „Na 7 dni",
         * ma dostać zapisaną decyzję, nie wykład o polu, którego nie użył.
         * Liczba jest wtedy po cichu ignorowana (`terminKary`).
         */
        $wlasnyTermin = $request->input('action') === ModerationAction::ACTION_SUSPEND
            && $request->input('suspend_days') === DlugoscZawieszenia::WLASNY;

        /*
         * PODSTAWA DECYZJI IDZIE DO CZŁOWIEKA (DSA art. 17 ust. 3 lit. d i e).
         *
         * `reason_code` przestał być „kodem wewnętrznym": formularz oferuje
         * teraz wyłącznie zamkniętą listę `PodstawaDecyzji`, a każdy jej
         * element wskazuje konkretny punkt `resources/legal/zasady.md`, który
         * autor treści przeczyta w powiadomieniu.
         *
         * REGUŁA ZOSTAJE ŚWIADOMIE MIĘKKA (`string`, nie `in:`). W bazie leżą
         * decyzje sprzed tej zmiany, a `RestoreContent` z odwołania zapisuje
         * `appeal_overturned` — twarda lista unieważniłaby jedno i drugie.
         * Kod spoza listy po prostu nie dostaje numeru punktu:
         * `PodstawaDecyzji::zdanie()` mówi wtedy prawdę ogólną, zamiast
         * wymyślać numer, którego nie zna.
         */
        $walidator = Validator::make($request->all(), [
            'action' => ['required', 'in:'.implode(',', $dozwolone)],
            'reason_code' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:2000'],
            /*
             * WIADOMOŚĆ OBOWIĄZKOWA PRZY PODSTAWIE PRAWNEJ.
             *
             * Przy „treść niezgodna z prawem" uzasadnienie mówi autorowi:
             * „Wyjaśnienie masz w wiadomości od moderacji powyżej"
             * (`PodstawaDecyzji::zdanie()`). Nie ma dziś kolumny na konkretny
             * przepis — `reason_code` mieści 80 znaków i trzyma sam rodzaj
             * podstawy — więc to jedyne miejsce, w którym człowiek dowie się,
             * CO uznaliśmy za niezgodne z prawem. Puste pole zamieniłoby
             * tamto zdanie w odesłanie w próżnię.
             */
            'user_message' => ['nullable', 'string', 'max:2000', 'required_if:reason_code,'.PodstawaDecyzji::NIEZGODNE_Z_PRAWEM],
            /*
             * DŁUGOŚĆ ZAWIESZENIA — LISTA Z `DlugoscZawieszenia`, NIE Z PALCA.
             *
             * Doszły dwie pozycje: `brak` („Bez zawieszenia", pierwsza
             * i domyślnie zaznaczona) oraz `wlasny` (liczba dni z pola
             * `suspend_days_custom`). Powód obu — i powód, dla którego
             * BRAK WYBORU przestał znaczyć „bezterminowo" — stoi w tamtej
             * klasie.
             *
             * Reguła zostaje `nullable`: żądanie bez tego pola jest wciąż
             * poprawne przy decyzji innej niż zawieszenie. Przy „Zawieś
             * konto" pilnuje tego `after()` niżej, bo `in:` nie odróżni
             * „nie wybrałem" od „wybrałem nie zawieszać", a różnica między
             * nimi jest tu żadna: obie znaczą, że kary nie ma.
             */
            'suspend_days' => ['nullable', 'in:'.implode(',', DlugoscZawieszenia::wartosci())],
            /*
             * WŁASNY TERMIN W DNIACH — REGUŁY TYLKO WTEDY, GDY LICZBA JEST
             * UŻYWANA.
             *
             * Przy wyborze „własny termin" liczba jest obowiązkowa i musi
             * mieścić się w zakresie 1-365 (uzasadnienie zakresu:
             * `DlugoscZawieszenia`) — bo `status_expires_at` przyjmie
             * dowolną datę, a w polu można wpisać cokolwiek.
             *
             * Przy każdym innym wyborze zostaje samo `nullable`: liczba
             * leżąca w polu po zmianie zdania nie ma prawa zatrzymać
             * decyzji. Nie krzyczymy na człowieka za pole, którego nie użył.
             */
            'suspend_days_custom' => $wlasnyTermin
                ? ['required', 'integer', 'min:'.DlugoscZawieszenia::MIN_DNI, 'max:'.DlugoscZawieszenia::MAX_DNI]
                : ['nullable'],
        ], [
            'action.required' => 'Wybierz decyzję.',
            'action.in' => 'Ta decyzja nie ma zastosowania do tego zgłoszenia. Wybierz jedną z pokazanych.',
            'reason_code.required' => 'Wybierz podstawę decyzji — autor treści zobaczy ją w powiadomieniu.',
            'user_message.required_if' => 'Przy podstawie „treść niezgodna z prawem" napisz autorowi, '
                .'co dokładnie uznaliśmy za niezgodne z prawem. Bez tego uzasadnienie odsyła w próżnię.',
            'suspend_days.in' => 'Wybierz długość zawieszenia z listy.',
            'suspend_days_custom.required' => 'Przy „Własnym terminie" wpisz liczbę dni od '
                .DlugoscZawieszenia::MIN_DNI.' do '.DlugoscZawieszenia::MAX_DNI
                .'. Albo zaznacz jeden z gotowych terminów wyżej.',
            'suspend_days_custom.integer' => 'Wpisz własny termin jako liczbę dni, na przykład 14.',
            'suspend_days_custom.min' => 'Najkrótsze zawieszenie to '.DlugoscZawieszenia::MIN_DNI.' dzień. '
                .'Jeśli chcesz tylko zwrócić uwagę, wybierz decyzję „Ostrzeżenie".',
            'suspend_days_custom.max' => 'Najdłuższe zawieszenie z terminem to '.DlugoscZawieszenia::MAX_DNI.' dni. '
                .'Jeśli kara ma trwać dłużej, zaznacz „Bezterminowo, do mojej decyzji".',
        ]);

        /*
         * „ZAWIEŚ KONTO" BEZ WYBRANEGO TERMINU TO POMYŁKA, NIE BEZTERMINOWOŚĆ.
         *
         * Reguła stoi tu, a nie w tablicy wyżej, bo dotyczy DWÓCH pól naraz
         * i bo `in:` nie odróżni „nie wybrałem" od „wybrałem nie zawieszać".
         * Ta różnica jest tu żadna — obie odpowiedzi znaczą, że kary nie ma.
         *
         * Wcześniej brak wyboru dawał karę BEZ TERMINU, czyli najsurowszą
         * z możliwych, a podpis pod grupą mówił o tym wprost, jakby to było
         * w porządku. Teraz brakujący termin zatrzymuje decyzję i mówi,
         * czego brakuje. Cicho wykonać jej nie wolno w ŻADNĄ stronę:
         * bezterminowo byłoby karą, której nikt nie wybrał, a pominięcie
         * kary zostawiłoby w logu moderacji „zawieszono" przy koncie, które
         * działa dalej.
         */
        $walidator->after(function (Walidator $sprawdzenie) use ($request): void {
            if ($request->input('action') !== ModerationAction::ACTION_SUSPEND) {
                return;
            }

            $wybor = $request->input('suspend_days');

            if (! DlugoscZawieszenia::zawiesza(is_string($wybor) ? $wybor : null)) {
                $sprawdzenie->errors()->add(
                    'suspend_days',
                    'Przy decyzji „Zawieś konto" zaznacz jeszcze, na jak długo. '
                    .'„Bez zawieszenia" znaczy, że kary nie ma.',
                );
            }
        });

        /*
         * `validate()` przy błędzie rzuca `ValidationException`, a ta wraca
         * na kolejkę z BŁĘDAMI I Z WPISANYMI DANYMI. To jest sedno
         * zgłoszenia, od którego zaczęła się ta zmiana: moderator nie ma
         * przepisywać uzasadnienia drugi raz tylko dlatego, że pomylił się
         * w jednym polu.
         */
        $data = $walidator->validate();

        $moderator = $request->user();

        $termin = $this->terminKary($data);

        /*
         * WSZYSTKO PONIŻEJ W JEDNEJ TRANSAKCJI, POD BLOKADĄ WIERSZA
         * (audyt W3-09, W6-01).
         *
         * Sprawdzenie statusu wyżej stało samo i miało komentarz mówiący, że
         * chroni przed podwójną decyzją. Nie chroniło: między odczytem
         * a zapisem jest okno, w którym drugie żądanie widzi jeszcze `open`.
         * Dwie karty moderatora wykonywały więc dwie kary, tworzyły dwa wpisy
         * w `moderation_actions` i wysyłały dwa powiadomienia — a przy
         * odwołaniu (DSA art. 17) log przestawał być jednoznaczny. Baza też
         * tego nie łapała: `moderation_actions.report_id` nie ma ograniczenia
         * unikalności.
         *
         * To był komentarz pewniejszy niż kod — ten sam wzorzec, który audyty
         * wskazują jako powtarzalny w tym repozytorium.
         *
         * `lockForUpdate()` wstrzymuje drugie żądanie do końca pierwszej
         * transakcji, a ponowne sprawdzenie statusu JUŻ POD BLOKADĄ rozstrzyga
         * je jednoznacznie. Powiadomienie zostaje w środku świadomie: ma nie
         * wyjść, jeśli zapis się nie powiedzie.
         */
        $wynik = DB::transaction(function () use ($request, $report, $data, $moderator, $termin) {
            $zablokowane = Report::query()->whereKey($report->getKey())->lockForUpdate()->first();

            if ($zablokowane === null || $zablokowane->status !== Report::STATUS_OPEN) {
                return null;
            }

            // Cel i osobę wyznaczamy PRZED zapisaniem decyzji i przed jej
            // wykonaniem. Powodów są teraz dwa:
            //  - `remove` kasuje cel, a wtedy nie ma już kogo zapytać o autora;
            //  - do logu wchodzi STAN SPRZED decyzji (`previous_status`), więc
            //    trzeba go odczytać, zanim cokolwiek się zmieni. Bez tego
            //    ukrycia nie da się później cofnąć do właściwego stanu (#65).
            $cel = ModeratedContent::znajdz($report->target_type, $report->target_id);
            $osoba = $cel === null ? null : ModeratedContent::osoba($cel);

            $akcja = ModerationAction::create([
                'moderator_id' => $moderator->getKey(),
                'report_id' => $report->getKey(),
                'target_type' => $report->target_type,
                'target_id' => $report->target_id,
                'subject_user_id' => $osoba?->getKey(),
                'action' => $data['action'],
                'previous_status' => $cel === null ? null : ($cel->status ?? null),
                'reason_code' => $data['reason_code'],
                'note' => $data['note'] ?? null,
                'user_message' => $data['user_message'] ?? null,
            ]);

            $this->applyAction($cel, $osoba, $data['action'], $termin);

            // Powiadomienie o decyzji. Dopóki go nie było, `user_message` lądowała
            // wyłącznie w logu moderacji: dokumentacja twierdziła, że autora
            // poinformowano, a autor nie dostawał niczego (audyt A16).
            //
            // Dotyczyło to WSZYSTKICH decyzji zapisujących `user_message`, nie
            // tylko `warn` — `hide`, `remove`, `suspend` i `ban` milczały tak samo.
            if ($osoba !== null) {
                $this->powiadom->handle(
                    osoba: $osoba,
                    decyzja: $data['action'],
                    wiadomoscModeratora: $data['user_message'] ?? null,
                    do: $termin,
                    // Bez tego powiadomienie mówi „możesz się odwołać" i nie ma
                    // gdzie kliknąć — a formularz odwołania musi wiedzieć,
                    // KTÓREJ decyzji dotyczy (#10).
                    decyzjaModeracyjna: $akcja,
                );
            }

            // POWIADOMIENIE ZGŁASZAJĄCEGO (DSA art. 16 ust. 5) — audyt W5-02.
            //
            // Osobny obowiązek od tego wyżej: tamto idzie do AUTORA treści
            // (art. 17), to do osoby, która zgłosiła. Do tej pory zgłaszający
            // nie dowiadywał się niczego, nawet tego, że sprawa jest zamknięta,
            // a ekran obiecywał „odpiszemy Ci, co zrobiliśmy".
            //
            // DWA KANAŁY, BO SĄ DWIE DROGI ZGŁOSZENIA — i to jest cała
            // różnica między nimi, nie dwa różne obowiązki (issue #10):
            //
            //  - zgłoszenie prawne (`legal_notice`) przychodzi od osoby, która
            //    może nie mieć konta (art. 16 ust. 2 lit. c), więc jedynym
            //    kanałem jest adres e-mail, jeśli go podała;
            //  - zgłoszenie społecznościowe przychodzi zawsze od osoby
            //    zalogowanej (`reports.create` stoi w grupie `auth`), więc
            //    kanałem jest powiadomienie w serwisie — czytelne także
            //    wtedy, gdy serwis nie ma jeszcze SMTP.
            //
            // Warunki wykluczają się nawzajem: `maAdresDoOdpowiedzi()` wymaga
            // `source = legal_notice`, a `reporter_id` ustawia wyłącznie droga
            // społecznościowa (`ReportContent`). Nikt nie dostanie odpowiedzi
            // dwa razy.
            if ($zablokowane->maAdresDoOdpowiedzi()) {
                Notification::route('mail', $zablokowane->notifier_email)
                    ->notify(new DecyzjaWSprawieZgloszenia($zablokowane, $akcja));

                $zablokowane->forceFill(['decision_sent_at' => now()])->save();
            }

            $this->powiadomZglaszajacego->handle($zablokowane, $akcja);

            $zablokowane->update([
                'status' => $data['action'] === ModerationAction::ACTION_NONE
                    ? Report::STATUS_REJECTED
                    : Report::STATUS_RESOLVED,
                'resolution_note' => $data['note'] ?? null,
                'resolved_by' => $moderator->getKey(),
                'resolved_at' => now(),
            ]);

            AuditLogEntry::record(
                action: 'moderation.decided',
                actor: $moderator,
                subject: $zablokowane,
                metadata: [
                    'decision' => $data['action'],
                    'reason_code' => $data['reason_code'],
                    'suspend_days' => $data['suspend_days'] ?? null,
                    // Sam wybór z listy przestał wystarczać, odkąd jedną z
                    // pozycji jest „własny termin": `wlasny` bez daty nie
                    // mówi w logu nic. Zapisujemy więc TERMIN, który
                    // naprawdę trafił na konto — to jest ta liczba, o którą
                    // pyta się przy odwołaniu (DSA art. 20).
                    'suspend_until' => $termin?->toIso8601String(),
                ],
                ip: $request->ip(),
            );

            return $akcja;
        });

        if ($wynik === null) {
            // Drugie żądanie doszło tu po tym, jak pierwsze już zapisało
            // decyzję. Ten sam komunikat co przy wstępnym sprawdzeniu — dla
            // człowieka to jest ta sama sytuacja.
            return back()->withErrors([
                'action' => 'To zgłoszenie zostało już rozstrzygnięte. Odśwież stronę, żeby zobaczyć decyzję.',
            ]);
        }

        return back()->with('status', 'Decyzja zapisana.');
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

        $cel = ModeratedContent::znajdz($report->target_type, $report->target_id, zUsunietymi: true);

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

        return back()->with('status', 'Treść przywrócona. Wróciła do stanu sprzed ukrycia, a autor dostał powiadomienie.');
    }

    /**
     * Zgłoszenia, przy których jest dziś co przywracać.
     *
     * Zapytanie na cel idzie osobno dla każdego zgłoszenia z decyzją
     * `hide`/`remove` — świadomie, bo cele leżą w czterech różnych tabelach,
     * a strona kolejki ma 25 pozycji obsługiwanych przez jedną osobę.
     * Optymalizacja tego miejsca kosztowałaby więcej czytelności niż daje.
     *
     * @param  list<Report>  $reports
     * @return array<string, true> klucz: id zgłoszenia
     */
    private function przywracalne(array $reports): array
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

            $schowana = ModeratedContent::jestUkryta($cel)
                || (method_exists($cel, 'trashed') && $cel->trashed());

            if ($schowana) {
                $wynik[(string) $decyzja->report_id] = true;
            }
        }

        return $wynik;
    }

    /**
     * Zamiana wyboru z formularza na konkretną datę.
     *
     * `null` znaczy „bezterminowo, do decyzji człowieka" — i tak ma zostać
     * przy `bezterminowo` oraz przy każdej decyzji innej niż zawieszenie.
     *
     * TU JEST MIEJSCE, W KTÓRYM LICZBA DNI JEST IGNOROWANA po cichu: przy
     * decyzji innej niż „Zawieś konto" wychodzimy od razu i nie zaglądamy ani
     * do wyboru, ani do liczby. Moderator, który zaznaczył termin, a potem
     * zmienił decyzję na ostrzeżenie, nie dostaje za to błędu.
     *
     * @param  array<string, mixed>  $data
     */
    private function terminKary(array $data): ?CarbonInterface
    {
        if (($data['action'] ?? null) !== ModerationAction::ACTION_SUSPEND) {
            return null;
        }

        $wybor = $data['suspend_days'] ?? null;
        $dni = $data['suspend_days_custom'] ?? null;

        return DlugoscZawieszenia::termin(
            is_string($wybor) ? $wybor : null,
            is_numeric($dni) ? (int) $dni : null,
        );
    }

    /**
     * Wykonanie decyzji na obiekcie.
     *
     * `hide` ukrywa treść (da się przywrócić), `remove` usuwa miękko.
     * Automat nigdy nie banuje sam — ban jest zawsze decyzją człowieka.
     *
     * @param  ?User  $osoba  autor zgłoszonej treści albo zgłoszona osoba.
     *                        Kara dotyczy CZŁOWIEKA, więc przy zgłoszonej treści
     *                        sięgamy po jej autora — wcześniej „Zawieś konto" na
     *                        zgłoszonym wpisie tylko ukrywało wpis, a konto
     *                        zostawało aktywne.
     */
    private function applyAction(?object $target, ?User $osoba, string $action, ?CarbonInterface $do = null): void
    {
        if ($action === ModerationAction::ACTION_NONE || $target === null) {
            return;
        }

        match ($action) {
            ModerationAction::ACTION_HIDE => $this->hide($target),

            // `remove` nie jest dozwolone dla celu `user` (macierz
            // ModerationAction::DOZWOLONE), więc tu nigdy nie trafi konto.
            // Wcześniej trafiało i kasowało je bezpowrotnie.
            ModerationAction::ACTION_REMOVE => $target->delete(),

            ModerationAction::ACTION_SUSPEND => $osoba?->suspend($do),
            ModerationAction::ACTION_BAN => $osoba?->ban(),

            // `warn` nie robi nic z treścią ani z kontem — całą jego siłą jest
            // wiadomość do autora. Wysyła ją `NotifyModerationDecision`
            // w `decide()`, wspólnie dla wszystkich decyzji.
            default => null,
        };
    }

    /**
     * Ukrycie treści.
     *
     * Status bierzemy z `ModeratedContent::UKRYTY`, a nie z łańcucha
     * `instanceof`. Poprzednia wersja miała gałęzie dla trzech modeli i cicho
     * przepuszczała czwarty („Ugotowałem"): moderator klikał „Ukryj treść",
     * zgłoszenie robiło się rozstrzygnięte, autor dostawał powiadomienie
     * „ukryliśmy Twoją treść" — a treść stała dalej. Teraz `cooked_event`
     * w ogóle nie ma tej decyzji na liście (`ModerationAction::DOZWOLONE`),
     * a gdyby ktoś ją tam dopisał, ten warunek nadal nie da się oszukać.
     */
    private function hide(object $target): void
    {
        if (! ModeratedContent::daSieUkryc($target)) {
            return;
        }

        $target->forceFill(['status' => ModeratedContent::UKRYTY[$target::class]])->save();
    }
}
