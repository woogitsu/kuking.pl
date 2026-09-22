<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Konta użytkowników — panel moderacji.
 *
 * PO CO TO POWSTAŁO
 * Pytanie właściciela brzmiało wprost: „gdzie będę mógł zarządzać
 * użytkownikami (lista użytkowników, data rejestracji itp.)". Do tej pory
 * jedyną drogą do odpowiedzi na „kim jest ta osoba, od kiedy tu jest i czy
 * już coś z nią było" był `psql`. Moderator, który przy zgłoszeniu musi
 * wiedzieć, czy pisze do kogoś, kto założył konto wczoraj, czy do kogoś, kto
 * gotuje z nami od pół roku, nie ma jak tego sprawdzić z przeglądarki.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  SKALA: TYSIĄCE KONT, NIE DWADZIEŚCIA
 * ══════════════════════════════════════════════════════════════════════════
 *
 * D-012 („zamknięta alfa, ~20 osób") przy TYM ekranie już nie obowiązuje:
 * właściciel zapowiada przejście grupy użytkowniczek z Garnek.pl, czyli setki,
 * a potem tysiące kont. To zmienia trzy rzeczy i wszystkie są tu widoczne:
 *
 *  1. STRONICOWANIE I WYSZUKIWANIE SĄ WARUNKIEM DZIAŁANIA, nie wygodą.
 *     Nic tu nie ładuje całej tabeli — ani lista, ani liczniki przy filtrach.
 *
 *  2. ZERO N+1. Profil idzie przez `with()`, liczba wpisów przez `withCount()`
 *     (podzapytanie w tym samym `SELECT`), liczniki filtrów jednym `GROUP BY`.
 *     Liczba zapytań tej strony NIE ROŚNIE z liczbą kont i pilnuje tego test
 *     (`PanelUzytkownicyTest::test_liczba_zapytan_nie_rosnie_z_liczba_kont`) —
 *     nie zdanie w opisie PR-a.
 *
 *  3. INDEKSY POD TO, PO CZYM NAPRAWDĘ FILTRUJEMY I SORTUJEMY. Migracja
 *     `2026_09_09_400000_add_moderation_list_indexes_to_users` dokłada
 *     `users_created_at_idx` (data rejestracji) i `users_email_trgm_idx`
 *     (szukanie po adresie). Nazwy szukamy po istniejących kolumnach
 *     `profiles.username_search` / `display_name_search`, które mają
 *     indeksy trigramowe od issue #116 — dlatego zapytanie pyta o te kolumny,
 *     a nie o `kuking_normalize(username)` liczone od nowa.
 *
 * FALA REJESTRACJI Z JEDNEGO ŁĄCZA TO NIE JEST SYGNAŁ OSTRZEGAWCZY.
 * Dziesięć kont założonych tego samego popołudnia to zwykle koło gospodyń,
 * biblioteka albo jedna rodzina przy jednym Wi-Fi — czyli dokładnie ci ludzie,
 * dla których ten serwis powstał. Ekran ma to POKAZAĆ (filtr po dacie
 * rejestracji: „kto przyszedł dzisiaj"), żeby dało się takie osoby powitać,
 * i nie ma prawa tego OZNACZAĆ. Dlatego nie ma tu ani kolumny z adresem IP,
 * ani żadnego „podobne konta" — sygnały pasywne z decyzji 3.6 mają prowadzić
 * do przeglądu treści, nie do listy podejrzanych ludzi.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  TEN EKRAN JEST DO PATRZENIA. NIE MA NA NIM ANI JEDNEGO PRZYCISKU
 *  ZMIENIAJĄCEGO KONTO — I TO JEST DECYZJA, NIE BRAK CZASU.
 * ══════════════════════════════════════════════════════════════════════════
 *
 *  * ROLA. Nadaje ją wyłącznie `kuking:nadaj-role` z powłoki (D-039).
 *    Uzasadnienie stoi w nagłówku `App\Console\Commands\NadajRole`: ekran
 *    w przeglądarce znaczyłby, że przejęcie JEDNEGO konta administratora
 *    wystarcza, żeby zrobić administratorów z kolejnych. Nie dokładamy tu
 *    drugiej, słabszej drogi do tej samej rzeczy — `role` nie jest
 *    w `$fillable` (AGENTS.md §7) i ten ekran tego nie omija, bo w ogóle
 *    niczego nie zapisuje.
 *
 *  * ZAWIESZENIE, BLOKADA, PRZYWRÓCENIE. To już istnieje i ma swoje miejsce:
 *    `/admin/zgloszenia` (`ModerationController::decide()` →
 *    `ModerationAction::ACTION_SUSPEND` / `ACTION_BAN`) oraz przywracanie
 *    treści (`ModerationController::restore()`). Tamta droga wymaga POWODU
 *    i zostawia wiersz w `moderation_actions`, od którego przysługuje
 *    odwołanie (DSA art. 20). Przycisk „zawieś" wystawiony obok listy kont
 *    byłby drugą drogą do tej samej kary — tyle że bez sprawy, bez powodu
 *    i bez czegokolwiek, od czego dałoby się odwołać. Stąd na karcie konta
 *    jest ODNOŚNIK do kolejki zgłoszeń, a nie własny formularz.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CO TRAFIA DO `audit_log`, A CO NIE (docs/INSPIRATION_DECISIONS.md poz. 3.2)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Decyzja 3.2 („wpisy przy OGLĄDANIU danych, nie tylko przy zmianie") jest
 * przyjęta, więc pytanie nie brzmi „czy logować", tylko „co". Granicę
 * stawiamy między LISTĄ a KARTĄ:
 *
 *  * KARTA POJEDYNCZEGO KONTA (`show()`) — LOGUJEMY, każde wejście
 *    (`admin.user_viewed`, `subject_id` = oglądane konto). To jest moment,
 *    w którym ktoś czyta dane JEDNEJ, wskazanej osoby w komplecie: pełny
 *    adres e-mail, całą historię decyzji moderacyjnych, kiedy ostatnio tu
 *    była. Na pytanie „kto oglądał moje dane" odpowiada wyłącznie ten wpis,
 *    bo tylko on wskazuje na konkretnego człowieka.
 *
 *  * LISTA (`index()`) — NIE LOGUJEMY, i przy tysiącach kont ta decyzja waży
 *    więcej, nie mniej. Lista jest przeglądaniem, nie czytaniem: adres e-mail
 *    pokazuje w masce (pierwsza litera i domena), a moderator wchodzi na nią kilkanaście
 *    razy dziennie po drodze do czegoś innego. Wpis z niej nie odpowiedziałby
 *    na żadne pytanie — „ktoś obejrzał dwudziestu pięciu ludzi naraz" nie jest
 *    wiedzą o niczyich danych — a przy tej skali zalałby tabelę tak, że
 *    prawdziwe wejścia utonęłyby w szumie. To jest dokładnie to, przed czym
 *    ostrzega sama decyzja 3.2: dziennik ma zostać czytelny, bo dziennik,
 *    w którym nic nie widać, odpowiada „nie wiemy" tak samo jak jego brak.
 *
 * ŚWIADOMIE NIE LOGUJEMY TREŚCI WYSZUKIWANIA. `App\Models\AuditLogEntry`
 * zapisuje FAKT i AKTORA, nigdy treści — a szukana fraza to zwykle imię,
 * nazwisko albo adres e-mail konkretnej osoby, czyli dane osobowe kogoś,
 * kto o tym wpisie nigdy się nie dowie. Dziennik, który sam produkuje nowy
 * zbiór danych osobowych, jest lekarstwem gorszym od choroby.
 *
 * ŚWIADOMIE NIE ZWIJAMY WPISÓW W OKNO CZASOWE („nie loguj drugi raz w ciągu
 * kwadransa"). Kartę otwiera się celowo, po jednej sprawie, więc powodzi z niej
 * nie ma; ryzyko zalania siedziało w liście i zostało zamknięte tym, że listy
 * nie logujemy wcale. Zwijanie kosztowałoby dokładnie tę informację, której
 * ten wpis ma bronić — ile razy i kiedy ktoś do czyjegoś konta wracał.
 *
 * Retencja: zwykła, `config('kuking.audit_log.retention_months')`. Ten wpis
 * nie należy do `AuditLogEntry::NIGDY_NIE_KASUJ` — tamta lista jest zamknięta
 * i obejmuje wyłącznie zdarzenia będące JEDYNYM dowodem wykonania żądania
 * z RODO art. 17. Wgląd moderatora nim nie jest.
 */
class UzytkownicyController extends Controller
{
    /**
     * Ile kont na stronie.
     *
     * Ta sama liczba co w kolejce odwołań i wiadomości — jeden rytm
     * stronicowania w całym panelu. Przy 25 wierszach tabela mieści się
     * na ekranie razem z nagłówkiem, a osoba z powiększonym tekstem nie
     * przewija pół dnia do stronicowania na dole.
     */
    private const NA_STRONIE = 25;

    /**
     * Po czym wolno sortować — BIAŁA LISTA, nie nazwa kolumny z adresu.
     *
     * `?sortuj=` trafia wprost do `ORDER BY`, więc ta lista jest jedyną
     * rzeczą, która dzieli ten ekran od wstrzyknięcia SQL. Klucze są po
     * polsku, bo widać je w pasku adresu.
     *
     * @var array<string, string>
     */
    private const SORTOWANIA = [
        'rejestracja' => 'users.created_at',
        'aktywnosc' => 'users.ostatnio_widziany_at',
        'wpisy' => 'wpisow_count',
    ];

    /**
     * DOMYŚLNIE: NAJNOWSZE KONTA NA GÓRZE. Nigdy „najwięcej wpisów".
     *
     * AGENTS.md §12 zakazuje publicznych rankingów użytkowników. Lista
     * posortowana domyślnie po liczbie wpisów malejąco JEST takim rankingiem
     * — wystarczy zrzut ekranu, żeby wyszła z niej „czołówka najaktywniejszych"
     * pokazana komukolwiek poza moderatorem. Sortowanie po wpisach zostaje
     * dostępne, bo bywa potrzebne przy koncie zakładanym pod spam, ale trzeba
     * je włączyć świadomie, klikając w nagłówek kolumny.
     *
     * Data rejestracji malejąco odpowiada na pytanie, które przy tej liście
     * pada najczęściej, a przy fali z Garnek.pl będzie padać codziennie:
     * „kto przyszedł dzisiaj".
     */
    private const SORTOWANIE_DOMYSLNE = 'rejestracja';

    public function index(Request $request): View
    {
        // Middleware `moderator` pilnuje wejścia do całej grupy `/admin`,
        // ale bramka na politykę zostaje TUTAJ — tak samo jak w
        // `AppealController::index()`. Adres nie jest autoryzacją
        // (AGENTS.md §7), a trasa może kiedyś trafić do innej grupy.
        $this->authorize('moderate', User::class);

        $filtry = $this->filtry($request);
        [$sortuj, $kierunek] = $this->sortowanie($request);

        return view('pages.admin.uzytkownicy', [
            'uzytkownicy' => $this->lista($filtry, $sortuj, $kierunek),
            'filtry' => $filtry,
            'sortuj' => $sortuj,
            'kierunek' => $kierunek,
            'liczniki' => $this->liczniki(),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        $this->authorize('moderate', User::class);

        /*
         * WPIS DO DZIENNIKA PRZED ZŁOŻENIEM WIDOKU, nie po.
         *
         * Gdyby renderowanie padło (brak profilu, błąd w Blade), a wpis
         * powstawał na końcu, ślad wglądu zginąłby razem z żądaniem — mimo
         * że dane zostały już z bazy odczytane. Dziennik ma odpowiadać na
         * pytanie „kto to otworzył", a nie „komu się to wyświetliło".
         *
         * Metadanych nie ma żadnych i to jest celowe: `subject_id` już mówi,
         * czyje dane obejrzano, a cokolwiek więcej byłoby przepisywaniem
         * cudzych danych osobowych do drugiej tabeli.
         */
        AuditLogEntry::record(
            action: 'admin.user_viewed',
            actor: $request->user(),
            subject: $user,
            ip: $request->ip(),
        );

        $user->loadCount([
            'posts as wpisow_count',
            'recipes as przepisow_count',
            'comments as komentarzy_count',
            'cookedEvents as ugotowan_count',
        ]);

        return view('pages.admin.uzytkownik', [
            'uzytkownik' => $user->load('profile'),
            'decyzje' => $this->historiaDecyzji($user),
        ]);
    }

    /**
     * @param  array{status: string, od: ?CarbonImmutable, do: ?CarbonImmutable, bez_wpisow: bool, szukaj: string, fraza: string}  $filtry
     * @return LengthAwarePaginator<int, User>
     */
    private function lista(array $filtry, string $sortuj, string $kierunek): LengthAwarePaginator
    {
        $zapytanie = User::query()
            /*
             * PROFIL PRZEZ `with()`, NIE W PĘTLI. Bez tego każdy z 25 wierszy
             * dokładałby własne zapytanie o nazwę — czyli 26 zamiast 2.
             *
             * AWATARA ŚWIADOMIE TU NIE MA (`profile.avatar` nie jest
             * doładowywane). To jest tabela do czytania, nie galeria: dodanie
             * zdjęć dołożyłoby trzecie zapytanie i wariantów pliku na każdy
             * wiersz, a tożsamość na tym ekranie niesie nazwa z `@username`.
             * Awatar jest tam, gdzie ma znaczenie — na karcie konta.
             */
            ->with('profile')
            /*
             * `withCount` = PODZAPYTANIE W TYM SAMYM `SELECT`, zero dodatkowych
             * zapytań niezależnie od liczby wierszy. Idzie po istniejącym
             * indeksie `posts_author_published_idx (author_id, …) WHERE
             * deleted_at IS NULL`.
             *
             * Liczy WPISY, nie „aktywność" w ogóle — moderatorowi chodzi o to,
             * czy konto w ogóle czegokolwiek tu dodało. Soft delete sprawia,
             * że usunięte wpisy się nie liczą, i tak ma być: pytamy o to, co
             * dziś stoi w serwisie.
             */
            ->withCount(['posts as wpisow_count']);

        if ($filtry['status'] !== 'wszystkie') {
            $zapytanie->where('users.status', $filtry['status']);
        }

        if ($filtry['od'] instanceof CarbonImmutable) {
            $zapytanie->where('users.created_at', '>=', $filtry['od']);
        }

        if ($filtry['do'] instanceof CarbonImmutable) {
            $zapytanie->where('users.created_at', '<', $filtry['do']);
        }

        if ($filtry['bez_wpisow']) {
            // `NOT EXISTS` po tym samym indeksie co `withCount` wyżej —
            // nie liczy wpisów, tylko sprawdza, czy jest choć jeden.
            $zapytanie->whereDoesntHave('posts');
        }

        if ($filtry['fraza'] !== '') {
            $wzorzec = '%'.$this->doLike($filtry['fraza']).'%';

            $zapytanie->where(function ($szukaj) use ($wzorzec): void {
                // Adres e-mail leży w bazie już małymi literami (mutator
                // `User::email`), ale przepuszczamy go przez tę samą funkcję
                // co nazwy — inaczej „Michał@…" wpisane w pole szukania nie
                // znalazłoby niczego, bo fraza jest znormalizowana, a kolumna
                // nie. Indeks `users_email_trgm_idx` stoi na dokładnie tym
                // wyrażeniu.
                $szukaj->whereRaw('public.kuking_normalize(users.email) LIKE ?', [$wzorzec])
                    ->orWhereHas('profile', function ($profil) use ($wzorzec): void {
                        // KOLUMNY `*_search`, nie `kuking_normalize(kolumna)`.
                        // To są kolumny generowane z issue #116, z gotowymi
                        // indeksami trigramowymi — liczenie normalizacji od
                        // nowa przy każdym wierszu było dokładnie tym kosztem,
                        // który tamta migracja usunęła.
                        $profil->where('profiles.username_search', 'like', $wzorzec)
                            ->orWhere('profiles.display_name_search', 'like', $wzorzec);
                    });
            });
        }

        return $zapytanie
            ->orderByRaw($this->klauzulaSortowania($sortuj, $kierunek))
            /*
             * DRUGI WARUNEK ROZSTRZYGA REMIS — ten sam powód co w
             * `AppealController::index()`. Bez niego PostgreSQL oddaje wiersze
             * o równej wartości w porządku fizycznym, a ten przestawia każdy
             * UPDATE (choćby zapis `ostatnio_widziany_at`). Przy stronicowaniu
             * po 25 znaczy to inny podział na strony między jednym kliknięciem
             * a drugim: konto pokazane dwa razy albo pominięte. Przy sortowaniu
             * po liczbie wpisów remisy są regułą, nie wyjątkiem — zero wpisów
             * ma większość kont, a przy tysiącach kont to są całe strony
             * nierozróżnialnych wierszy.
             *
             * `id` jest UUID-em v7, więc rozstrzyga tak samo jak czas założenia
             * konta: starsze niżej przy DESC.
             */
            ->orderByDesc('users.id')
            ->paginate(self::NA_STRONIE)
            ->withQueryString();
    }

    /**
     * Historia decyzji moderacyjnych DOTYCZĄCYCH tego konta.
     *
     * Po `subject_user_id`, nie po `moderator_id` — pytamy „co się z tą osobą
     * działo", nie „co ta osoba rozstrzygnęła". Idzie po istniejącym indeksie
     * `moderation_actions_subject_idx (subject_user_id, created_at DESC)`,
     * więc nie potrzeba tu żadnej zmiany schematu.
     *
     * Bez stronicowania, za to z twardym limitem: to jest kontekst do sprawy,
     * a nie druga kolejka do pracy. Konto z pięćdziesięcioma decyzjami jest
     * i tak rozstrzygnięte na pierwszy rzut oka.
     *
     * @return EloquentCollection<int, ModerationAction>
     */
    private function historiaDecyzji(User $user): EloquentCollection
    {
        return ModerationAction::query()
            ->where('subject_user_id', $user->getKey())
            ->with('moderator.profile')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Ile kont w każdym stanie — liczby przy zakładkach filtra.
     *
     * JEDNO zapytanie grupujące, nie pięć `count()`. To nie jest panel
     * statystyk: te liczby istnieją po to, żeby moderator wiedział, czy
     * zakładka „Zawieszone" jest pusta, ZANIM w nią kliknie — przy tysiącach
     * kont wejście na pustą listę to strata, której da się uniknąć.
     *
     * @return array<string, int>
     */
    private function liczniki(): array
    {
        $wiersze = User::query()
            ->selectRaw('status, count(*) as ile')
            ->groupBy('status')
            ->pluck('ile', 'status');

        $liczniki = ['wszystkie' => 0];

        foreach (array_keys(User::ETYKIETY_STATUSU) as $status) {
            $liczniki[$status] = (int) ($wiersze[$status] ?? 0);
            $liczniki['wszystkie'] += $liczniki[$status];
        }

        return $liczniki;
    }

    /**
     * Filtry z adresu, sprowadzone do wartości, których nie da się podrobić.
     *
     * CZTERY, NIE OSIEM. Każdy odpowiada na pytanie, które moderator naprawdę
     * zadaje przy tysiącu kont:
     *
     *  * `szukaj`     — „mam adres e-mail ze zgłoszenia, czyje to konto";
     *  * `status`     — „pokaż zawieszone" (zakładki z licznikami);
     *  * `od`/`do`    — „kto przyszedł dzisiaj / w zeszłym tygodniu";
     *  * `bez_wpisow` — „kto założył konto i nic nie napisał", czyli lista
     *    osób do powitania (issue #6: pierwsza reakcja od człowieka jest
     *    ważniejsza niż którakolwiek funkcja z MVP).
     *
     * ŚWIADOMIE NIE MA FILTRA „konta z zawieszeniem w historii". Zakładka
     * „Zawieszone" odpowiada na to pytanie dla stanu BIEŻĄCEGO, a pełną
     * historię widać na karcie konta. Osobna lista „ludzie, którzy kiedyś
     * dostali karę" jest tym samym, czym publiczny ranking najaktywniejszych
     * (AGENTS.md §12), tylko z odwróconym znakiem — a decyzja 3.3
     * (docs/INSPIRATION_DECISIONS.md) mówi wprost, że log samych kar wygląda
     * jak akt oskarżenia.
     *
     * @return array{status: string, od: ?CarbonImmutable, do: ?CarbonImmutable, bez_wpisow: bool, szukaj: string, fraza: string}
     */
    private function filtry(Request $request): array
    {
        $wpisane = trim((string) $request->query('szukaj', ''));
        $wpisane = mb_substr($wpisane, 0, 120);

        $status = (string) $request->query('status', 'wszystkie');

        return [
            'status' => array_key_exists($status, User::ETYKIETY_STATUSU) ? $status : 'wszystkie',
            'od' => $this->dzien($request, 'od'),
            // Górna granica jest WŁĄCZAJĄCA dla całego wskazanego dnia:
            // porównanie idzie do początku dnia następnego (`<`). Bez tego
            // „do 9 września" gubiłoby wszystkie konta założone 9 września
            // po północy, czyli praktycznie wszystkie z tego dnia.
            'do' => $this->dzien($request, 'do')?->addDay(),
            'bez_wpisow' => $request->query('bez_wpisow') === '1',
            // Surowa fraza wraca do pola formularza (człowiek ma widzieć to,
            // co wpisał), znormalizowana idzie do zapytania.
            'szukaj' => $wpisane,
            'fraza' => $wpisane === '' ? '' : $this->normalizuj($wpisane),
        ];
    }

    /**
     * Data z adresu jako początek dnia w strefie CZŁOWIEKA, nie w UTC.
     *
     * `users.created_at` jest w UTC, a moderator wpisuje „9 września" myśląc
     * o polskim dniu. Bez `App\Support\Czas::strefa()` filtr „od dzisiaj"
     * gubiłby konta założone między północą a drugą w nocy czasu polskiego —
     * czyli te, które na liście widać z datą dzisiejszą.
     */
    private function dzien(Request $request, string $parametr): ?CarbonImmutable
    {
        $wartosc = trim((string) $request->query($parametr, ''));

        if ($wartosc === '') {
            return null;
        }

        try {
            $dzien = CarbonImmutable::createFromFormat('Y-m-d', $wartosc, Czas::strefa());

            return $dzien instanceof CarbonImmutable ? $dzien->startOfDay()->utc() : null;
        } catch (\Throwable) {
            // Data nie do odczytania = brak filtra. Świadomie bez błędu
            // walidacji: to jest parametr z adresu, nie pole, które człowiek
            // wypełnił źle — a ekran ma się otworzyć, nie nakrzyczeć.
            return null;
        }
    }

    /**
     * Fraza znormalizowana tak samo jak kolumna po stronie bazy.
     *
     * Ta sama normalizacja co w `App\Domain\Search\SearchQuery`: `Str::ascii`
     * odpowiada temu, co `unaccent` robi z polskimi znakami, więc „Żaneta"
     * znajduje „zaneta".
     */
    private function normalizuj(string $fraza): string
    {
        return mb_strtolower(Str::ascii($fraza));
    }

    /**
     * Fraza bezpieczna do wstawienia w `LIKE`.
     *
     * `%` i `_` są w `LIKE` znakami wieloznacznymi. Bez tego kroku wpisanie
     * samego „%" oddawałoby WSZYSTKIE konta w serwisie jednym znakiem —
     * a „_" cicho psułby szukanie nazw z podkreśleniem (`profiles.username`
     * dopuszcza je wprost, CHECK `^[a-zA-Z0-9_]{3,40}$`). Backslash uciekamy
     * jako pierwszy, inaczej uciekalibyśmy własne ucieczki.
     */
    private function doLike(string $fraza): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $fraza);
    }

    /**
     * Wybrane sortowanie sprowadzone do pary, której nie da się podrobić
     * z adresu.
     *
     * @return array{string, string}
     */
    private function sortowanie(Request $request): array
    {
        $sortuj = (string) $request->query('sortuj', self::SORTOWANIE_DOMYSLNE);
        $kierunek = (string) $request->query('kierunek', 'desc');

        return [
            array_key_exists($sortuj, self::SORTOWANIA) ? $sortuj : self::SORTOWANIE_DOMYSLNE,
            $kierunek === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * Klauzula `ORDER BY` — obie części wyłącznie z białej listy wyżej,
     * żadnego fragmentu z żądania.
     *
     * `NULLS LAST` W OBIE STRONY I TO NIE JEST KOSMETYKA. PostgreSQL domyślnie
     * stawia NULL-e na końcu przy `ASC` i na POCZĄTKU przy `DESC`. Kolumna
     * `ostatnio_widziany_at` jest pusta u kont, które nigdy nie weszły
     * (i u zanonimizowanych — `EraseAccountData` ją zeruje), więc sortowanie
     * „ostatnio widziani, najnowsi na górze" zaczynałoby się od kilkudziesięciu
     * wierszy z pustym polem. Pierwsza strona pokazywałaby dokładnie te konta,
     * o które nikt nie pytał.
     */
    private function klauzulaSortowania(string $sortuj, string $kierunek): string
    {
        return self::SORTOWANIA[$sortuj].' '.strtoupper($kierunek).' NULLS LAST';
    }
}
