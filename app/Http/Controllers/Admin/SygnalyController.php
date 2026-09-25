<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

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

    /** Odmowa, gdy od wyświetlenia strony grupa urosła (#1059, decyzja właściciela). */
    public const GRUPA_UROSLA = 'Doszły nowe zgłoszenia — odśwież listę i sprawdź je. W tej grupie nic nie zamknęliśmy.';

    /** Formularz bez znacznika stanu (np. karta otwarta przed tą zmianą). */
    public const NIEZNANY_STAN = 'Nie wiadomo, które oznaczenia zamknąć. Odśwież stronę i spróbuj jeszcze raz.';

    public function index(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $grupy = $this->grupy();

        $pozycje = $this->pozycje($grupy);

        return view('pages.admin.sygnaly', [
            'grupy' => $grupy,
            'pozycje' => $pozycje,
            'podglady' => $this->podglady($pozycje),
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
     *
     * ZAKRES TO ZBIÓR Z EKRANU, NIE „WSZYSTKO, CO JEST OTWARTE TERAZ" (#1059)
     * Przycisk mówi „zamknij wszystkie 3", bo moderator widział trzy. Gdy
     * automat dopisze czwarte między odczytem strony a kliknięciem, stare
     * zapytanie „wszystkie otwarte tego autora" zamykało także je — sprawę,
     * której nikt nie oglądał, z decyzją człowieka w logu. A `juzOgladane()`
     * nie pozwala automatowi postawić jej drugi raz, więc znikała na dobre.
     *
     * Formularz niesie więc ZNACZNIK STANU grupy z chwili wyświetlenia
     * (decyzja właściciela do #1059, wariant b): liczbę otwartych oznaczeń
     * (`stan_ile`) i identyfikator najnowszego z nich (`stan_najnowsze`,
     * kolejność `created_at DESC, id DESC`). Nie listę identyfikatorów —
     * widok rozwija najwyżej `POZYCJI_W_GRUPIE` pozycji (#1060), a grupa
     * bywa liczona w setkach.
     *
     * Serwer i tak wybiera wiersze SAM — źródło, autor, otwarty status, pod
     * blokadą — a znacznik jedynie sprawdza, czy grupa nie urosła: nic
     * nowszego od najnowszego z ekranu i nie więcej niż `stan_ile`. Znacznik
     * spoza tej grupy (inny autor, nie automat, nie istnieje) to formularz
     * nieaktualny albo podrobiony — też odmowa. Gdy grupa urosła, nie
     * zamykamy niczego — grupa to jedna decyzja, a połowa decyzji podjęta
     * za kogoś nie jest decyzją. Oznaczenie, które zamknął w międzyczasie
     * ktoś inny, po prostu nie wraca z zapytania i nie dostaje drugiej
     * decyzji; reszta grupy się zamyka.
     */
    public function odrzucGrupe(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $dane = $request->validate([
            'autor' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:2000'],
            'stan_ile' => ['required', 'integer', 'min:1'],
            'stan_najnowsze' => ['required', 'uuid'],
        ], [
            'autor.required' => 'Nie wiadomo, którą grupę zamknąć. Odśwież stronę i spróbuj jeszcze raz.',
            'stan_ile.required' => self::NIEZNANY_STAN,
            'stan_ile.integer' => self::NIEZNANY_STAN,
            'stan_ile.min' => self::NIEZNANY_STAN,
            'stan_najnowsze.required' => self::NIEZNANY_STAN,
            'stan_najnowsze.uuid' => self::NIEZNANY_STAN,
        ]);

        $stanIle = (int) $dane['stan_ile'];
        $stanNajnowsze = (string) $dane['stan_najnowsze'];

        $autorId = $dane['autor'] === 'brak' ? null : $dane['autor'];
        $moderator = $request->user();

        try {
            [$ile, $urosla] = DB::transaction(function () use ($autorId, $moderator, $dane, $request, $stanIle, $stanNajnowsze): array {
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

                // Dopisane po odczycie strony (#1059) — patrz komentarz
                // metody. Nic jeszcze nie zapisaliśmy, więc wyjście tutaj
                // zostawia grupę dokładnie taką, jaka była.
                if ($this->grupaUrosla($oznaczenia, $autorId, $stanIle, $stanNajnowsze)) {
                    return [0, true];
                }

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

                $ile = $oznaczenia->count();

                // Wpis zbiorczy jest CZĘŚCIĄ tej decyzji, więc stoi w jej
                // transakcji, jak `moderation.decided` w `ModerationController`
                // (D-249, #1343). Awaria dziennika cofa decyzje i statusy
                // razem z nim, a ponowienie daje jeden komplet — zamiast
                // zamkniętej grupy bez wpisu, której ponowienie już nie
                // znajdzie. Zero zamkniętych to zero decyzji: nie ma czego
                // zapisywać.
                if ($ile > 0) {
                    AuditLogEntry::record(
                        action: 'moderation.automat_dismissed',
                        actor: $moderator,
                        metadata: ['autor_tresci_id' => $autorId, 'ile' => $ile],
                        ip: $request->ip(),
                    );
                }

                return [$ile, false];
            });
        } catch (Throwable $awaria) {
            // Transakcja jest wycofana w całości — grupa zostaje otwarta,
            // więc moderator dostaje prawdę i drogę dalej, a operator
            // przyczynę. Nie połykamy: `report()` idzie do monitoringu.
            report($awaria);

            return back()->withErrors([
                'autor' => 'Nie udało się zamknąć tej grupy i nic się w niej nie zmieniło. Spróbuj jeszcze raz za chwilę.',
            ]);
        }

        if ($urosla) {
            return back()->withErrors(['autor' => self::GRUPA_UROSLA]);
        }

        if ($ile === 0) {
            return back()->withErrors([
                'autor' => 'Te oznaczenia zostały już zamknięte. Odśwież stronę, żeby zobaczyć aktualną listę.',
            ]);
        }

        return back()->with('status', $ile === 1
            ? 'Zamknięte. Treść zostaje bez zmian, a automat już do niej nie wróci.'
            : 'Zamknięte — '.$ile.' oznaczenia tego konta. Treści zostają bez zmian, a automat już do nich nie wróci.');
    }

    /**
     * Czy grupa jest inna niż ta, którą moderator widział (#1059).
     *
     * Najnowsze z ekranu musi być oznaczeniem automatu z TEJ grupy. Nowsze od
     * niego (po `created_at`, a przy tej samej sekundzie po identyfikatorze —
     * UUIDv7 rośnie z czasem) oznacza, że automat coś dopisał. Liczba ponad
     * `stan_ile` łapie dopisanie, którego kolejność nie odróżni (ta sama
     * chwila, losowa część UUID). Mniej niż `stan_ile` jest w porządku — to
     * oznaczenia zamknięte w międzyczasie przez kogoś innego.
     *
     * @param  EloquentCollection<int, Report>  $oznaczenia  otwarte oznaczenia grupy, pod blokadą
     */
    private function grupaUrosla(EloquentCollection $oznaczenia, ?string $autorId, int $stanIle, string $stanNajnowsze): bool
    {
        $znacznik = Report::query()
            ->whereKey($stanNajnowsze)
            ->where('source', Report::SOURCE_AUTOMAT)
            ->when($autorId === null,
                fn ($q) => $q->whereNull('autor_tresci_id'),
                fn ($q) => $q->where('autor_tresci_id', $autorId),
            )
            ->first(['id', 'created_at']);

        $granica = $znacznik?->created_at;

        if ($znacznik === null || $granica === null || $oznaczenia->count() > $stanIle) {
            return true;
        }

        $najnowszeId = (string) $znacznik->getKey();

        return $oznaczenia->contains(static fn (Report $r): bool => $r->created_at === null
            || $r->created_at->greaterThan($granica)
            || ($r->created_at->equalTo($granica) && strcmp((string) $r->getKey(), $najnowszeId) > 0));
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
            // `najnowsze` — znacznik stanu grupy dla formularza zamknięcia
            // (#1059); ta sama kolejność co w `grupaUrosla()`.
            ->selectRaw('autor_tresci_id, COUNT(*) AS ile, MAX('.$this->wagaCase().') AS waga, MAX(created_at) AS ostatnie, '
                .'(ARRAY_AGG(id::text ORDER BY created_at DESC, id DESC))[1] AS najnowsze')
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
     * pytamy dokładnie o konta, które i tak wyświetlamy, i najwyżej
     * `POZYCJI_W_GRUPIE` pozycji z każdego z nich.
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

        // Kolejność w grupie — ta sama w oknie i na zewnątrz, żeby pierwsze
        // dziesięć z `ROW_NUMBER()` było dokładnie tymi dziesięcioma, które
        // widok wypisze.
        $kolejnosc = $this->wagaCase().' DESC, created_at DESC, id DESC';

        // Limit NA GRUPĘ w SQL (issue #1060): grupa bywa liczona w setkach,
        // a widok rozwija `POZYCJI_W_GRUPIE`. Reszta grupy nie wychodzi
        // z bazy — ile jej jest, mówi już `COUNT(*)` z `grupy()`.
        // `PARTITION BY` traktuje NULL jak jedną wartość, więc pozycje bez
        // autora dostają jedną wspólną grupę, tak jak w `GROUP BY` wyżej.
        $ponumerowane = $this->otwarte()
            ->where(function ($query) use ($autorzy, $bezAutora): void {
                if ($autorzy !== []) {
                    $query->whereIn('autor_tresci_id', $autorzy);
                }

                if ($bezAutora) {
                    $query->orWhereNull('autor_tresci_id');
                }
            })
            ->select('reports.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY autor_tresci_id ORDER BY '.$kolejnosc.') AS nr_w_grupie');

        return Report::query()
            ->fromSub($ponumerowane, 'reports')
            ->where('nr_w_grupie', '<=', self::POZYCJI_W_GRUPIE)
            ->orderByRaw($kolejnosc)
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
     * PO CO WIĘCEJ NIŻ ADRES
     * Pierwsza wersja dawała tylko odnośnik „Otwórz treść i przeczytaj ją".
     * Przy jednym oznaczeniu dziennie to wystarczało; przy kilkudziesięciu
     * moderator musiałby otworzyć kilkadziesiąt kart, żeby dowiedzieć się
     * rzeczy, którą widać w pół sekundy — czy to zdjęcie obiadu, czy coś,
     * czego nie chce zobaczyć jego rodzina. Zgłoszenie właściciela po
     * pierwszym prawdziwym trafieniu modelu: „trzeba dać jakąś miniaturkę
     * i mniejszym tekstem treść, a nie klikać otwierać treść".
     *
     * Odnośnik ZOSTAJE. Podgląd nie zastępuje przeczytania całości przed
     * decyzją — pozwala odsiać oczywiste przypadki bez otwierania.
     *
     * @param  Collection<string, Collection<int, Report>>  $pozycje
     *                                                                `odnosnik` i `pusto` są w tej mapie, a nie w widoku, bo zależą od
     *                                                                RODZAJU oznaczonej treści: „Otwórz treść i przeczytaj ją" jest zdaniem
     *                                                                bez sensu przy zdjęciu profilowym, przy którym nie ma ani jednego słowa
     *                                                                do przeczytania. Widok ma pokazywać, nie zgadywać.
     * @return array<string, array{adres: string, tekst: ?string, miniatura: ?string, odnosnik: string, pusto: string}>
     */
    private function podglady(Collection $pozycje): array
    {
        /** @var Collection<int, Report> $wszystkie */
        $wszystkie = $pozycje->flatten();

        $wpisy = $wszystkie->where('target_type', 'post')->pluck('target_id')->filter()->unique()->all();
        $komentarze = $wszystkie->where('target_type', 'comment')->pluck('target_id')->filter()->unique()->all();
        $zdjecia = $wszystkie->where('target_type', 'media')->pluck('target_id')->filter()->unique()->all();

        $podglady = [];

        // `with('media')`, NIE zapytanie na wpis: przy dwudziestu oznaczeniach
        // z jednego konta to jest różnica między dwoma zapytaniami a dwudziestoma
        // jednym. Pilnuje tego
        // `KolejkiModeracjiBezWachlarzaZapytanTest::test_kolejka_sygnalow_automatu_nie_ma_wachlarza_zapytan`.
        //
        // NAZWA TEGO TESTU BYŁA TU WCZEŚNIEJ ZMYŚLONA. Stało
        // „Pilnuje tego `KolejkaSygnalowBezWachlarzaZapytanTest`" — klasy
        // o tej nazwie nie było w repozytorium ani jednego dnia. Zdanie
        // o teście, którego nie ma, czyta się jak pomiar, a jest deklaracją
        // zamiaru; po nim nikt już tego ekranu nie mierzy, bo „przecież jest
        // test". Odkąd to piszemy: nazwa testu w komentarzu obowiązuje tak
        // samo jak liczba w pomiarze.
        foreach (Post::query()->with('media')->whereIn('id', $wpisy)->get() as $wpis) {
            $podglady['post:'.$wpis->getKey()] = [
                'adres' => $wpis->url(),
                'tekst' => $this->poczatek($wpis->body),
                'miniatura' => $this->miniatura($wpis),
                'odnosnik' => 'Otwórz treść i przeczytaj ją',
                'pusto' => 'Treść bez tekstu — samo zdjęcie.',
            ];
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
                $podglady['comment:'.$komentarz->getKey()] = [
                    'adres' => $rodzic->url().'#komentarz-'.$komentarz->getKey(),
                    'tekst' => $this->poczatek($komentarz->body),
                    // Komentarz nie ma własnego zdjęcia. Miniatury treści,
                    // POD KTÓRĄ stoi, świadomie tu nie pokazuję: oznaczony
                    // jest komentarz, a cudze zdjęcie obok niego sugerowałoby
                    // moderatorowi, że to ono jest przedmiotem sprawy.
                    'miniatura' => null,
                    'odnosnik' => 'Otwórz treść i przeczytaj ją',
                    'pusto' => 'Komentarz bez tekstu.',
                ];
            }
        }

        // ZDJĘCIE PROFILOWE (issue #237) — tu miniatura jest CAŁĄ sprawą,
        // nie dodatkiem: oznaczony jest obraz i nie ma przy nim ani jednego
        // słowa do przeczytania. Odnośnik prowadzi na profil, bo tam to
        // zdjęcie widzą ludzie i tam widać je w kontekście, w jakim działa.
        //
        // `with('owner.profile')`, bo adres profilu bierze się z loginu
        // właściciela — bez tego przy dwudziestu oznaczeniach byłoby
        // czterdzieści zapytań.
        foreach (Media::query()->with('owner.profile')->whereIn('id', $zdjecia)->get() as $zdjecie) {
            // Login mieszka w `profiles`, nie w `users` (dane publiczne są
            // w profilu), więc adres bierzemy stamtąd. Konto bez profilu nie
            // ma publicznej strony — wtedy zostaje sama miniatura.
            $login = $zdjecie->owner?->profile?->username;

            $podglady['media:'.$zdjecie->getKey()] = [
                'adres' => $login !== null ? route('profile.show', $login) : '',
                'tekst' => null,
                'miniatura' => $zdjecie->wariantDoSerwowania('thumb') === null ? null : $zdjecie->url('thumb'),
                'odnosnik' => 'Otwórz profil i zobacz to zdjęcie',
                'pusto' => 'Zdjęcie profilowe — przy nim nie ma żadnego tekstu.',
            ];
        }

        return $podglady;
    }

    /**
     * Początek treści — tyle, żeby rozpoznać, o co chodzi, i nie więcej.
     *
     * 240 znaków, bo kolejka ma się dać przejrzeć wzrokiem. Kto potrzebuje
     * całości, klika „Otwórz treść" — i przed decyzją MUSI to zrobić.
     */
    private function poczatek(?string $tresc): ?string
    {
        $tresc = trim((string) preg_replace('/\s+/u', ' ', (string) $tresc));

        if ($tresc === '') {
            return null;
        }

        return mb_strimwidth($tresc, 0, 240, '…');
    }

    /**
     * Adres miniatury pierwszego zdjęcia wpisu albo `null`.
     *
     * Wariant `thumb`, ten sam co w awatarze i na tablicy dnia — najmniejszy,
     * jaki generujemy. Zdjęcie bez gotowych wariantów (`status` inny niż
     * gotowy) nie ma czego pokazać, więc wtedy też `null`: pusty prostokąt
     * albo ikona zastępcza mówiłyby moderatorowi „nie ma zdjęcia", a to
     * nieprawda — jest, tylko jeszcze się przetwarza.
     */
    private function miniatura(Post $wpis): ?string
    {
        $zdjecie = $wpis->media->first();

        if ($zdjecie === null || $zdjecie->wariantDoSerwowania('thumb') === null) {
            return null;
        }

        return $zdjecie->url('thumb');
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
