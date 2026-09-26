<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\DailyPick;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * „kuKINGi na dziś" — kilka osób i kilka dań wartych zobaczenia dzisiaj.
 *
 * To NIE jest ranking i nigdy nim nie będzie. Kolejność decyzyjna:
 *
 *   1. wybór redakcyjny gospodarza na dzisiejszy dzień (tabela `daily_picks`),
 *   2. jeśli go nie ma — chronologicznie, maksymalnie JEDNA pozycja
 *      od tej samej osoby.
 *
 * Ograniczenie „jedna od osoby" jest tu najważniejsze. Bez niego jedna aktywna
 * osoba zasłania cały serwis, a nowy użytkownik odnosi wrażenie, że „tu jest
 * tylko ta pani" (docs/product/COLD_START.md).
 *
 * Świadomie NIE MA tu żadnej miary popularności: ani liczby obserwujących,
 * ani liczby komentarzy jako kryterium sortowania. Publiczne rankingi
 * natychmiast dzielą ludzi na dwie klasy i wyłączają publikowanie
 * u większości (AGENTS.md).
 */
final class DailyBoard
{
    /**
     * Ile osób i ile wpisów pokazujemy. Krótka lista, nie ściana kafelków.
     *
     * SZEŚĆ, NIE CZTERY (11.09.2026). Tablica układa się w dwie kolumny, więc
     * cztery pozycje to dwa pełne rzędy i połowa trzeciego pusta — dziura
     * widoczna na ekranie, nie w liczbach. Sufit sześciu daje trzy pełne rzędy
     * w układzie dwukolumnowym i dwa w trzykolumnowym, a jednocześnie
     * DOMYKA rozjazd, który stał tu od początku: panel gospodarza przyjmuje
     * do 6 osób i do 6 dań (`Admin\DailyBoardController::update()`,
     * „Wybierz najwyżej 6 osób"), więc automat miał sufit niższy od człowieka.
     *
     * CZEGO TA LICZBA NIE ZMIENIA — i to jest tu ważniejsze od niej samej:
     *
     *  1. **Liczby zapytań.** Limit jest narzucany w SQL (`->limit($limit)`
     *     w `peopleToFollow()`, `->limit($limit)` w podzapytaniu
     *     `automaticPosts()`), a relacje dociąga `with()`/`withCount()`,
     *     czyli stała liczba zapytań niezależna od liczby wierszy. ZMIERZONE
     *     (`test_podniesienie_sufitu_nie_doklada_ani_jednego_zapytania`):
     *       * `peopleToFollow()` — 4 zapytania przy limicie 4 i 4 przy 6,
     *       * całe `forViewer()` — 10 zapytań przy czterech pozycjach
     *         na tablicy i 10 przy sześciu.
     *     Tamten test pilnuje RÓWNOŚCI tych par, nie samych liczb: liczby
     *     zmienią się przy każdej nowej relacji w `with()`, a równość ma
     *     zostać.
     *  2. **Reguły „najwyżej jedna pozycja od osoby".** Trzyma ją
     *     `DISTINCT ON (posts.author_id)`, czyli struktura zapytania, a nie
     *     zapas nad limitem — i dlatego sufit wolno podnieść bez oglądania
     *     się na rozkład publikacji (patrz komentarz przy `automaticPosts()`).
     *  3. **Zakazu rankingu (AGENTS.md §12).** Dobór dalej idzie po tym,
     *     KIEDY ktoś ostatnio coś pokazał. Sufit zmienia, ILE pozycji widać,
     *     nie to, KTÓRE stoją wyżej.
     *
     * GDY W SERWISIE JEST MNIEJ NIŻ SZEŚĆ KONT ALBO WPISÓW, tablica pokazuje
     * tyle, ile jest, i ani jednego pustego miejsca: limit w SQL jest górną
     * granicą, a widok (`components/kuking-board.blade.php`) iteruje po
     * kolekcji — nie rysuje slotów. Cały ten stan jest normalny na starcie
     * serwisu (`docs/product/COLD_START.md`), więc nie jest wyjątkiem
     * do obsłużenia, tylko codziennością pierwszych tygodni.
     */
    private const PEOPLE = 6;

    private const POSTS = 6;

    /**
     * KANDYDACI NIEZALEŻNI OD WIDZA, W CACHE (audyt B4 W1).
     *
     * `peopleToFollow()` i `automaticPosts()` agregowały CAŁE publiczne
     * `posts` (GROUP BY i DISTINCT ON) przy każdej odsłonie Startu, Odkrywaj,
     * Szukaj i strony powitalnej — także dla gości i robotów. `LIMIT` działał
     * dopiero po agregacji, więc koszt rósł z całą historią (zmierzone
     * lokalnie: ok. 225 ms przy 200 tys. wpisów).
     *
     * Wynik KOŃCOWY zależy od widza (blokady, obserwowani, wybór gospodarza),
     * ale KANDYDACI nie: „kto ostatnio coś publicznie pokazał" i „najnowszy
     * publiczny wpis każdego autora" są tacy sami dla wszystkich. Liczymy ich
     * więc raz na `KANDYDACI_SEKUND` dla widza anonimowego — najwęższego, bo
     * `zWidocznymPrzepisem(null)` przepuszcza tylko przepisy publiczne — a
     * wykluczenia konkretnego widza odsiewamy w PHP. Pełne modele dociąga
     * potem zapytanie po kluczach, z tymi samymi bramkami co zawsze (status
     * konta, widoczność wpisu i przepisu dla TEGO widza), więc treść schowana
     * po zapisaniu cache nie wraca na tablicę.
     *
     * Gdy po odsianiu zabraknie pozycji, a lista kandydatów była pełna (czyli
     * dalej mogą być następni), wracamy do pełnego zapytania — rezerwa dla
     * widza z wieloma blokadami albo obserwowanymi. Ceną jest świeżość:
     * nowa osoba albo nowe danie pojawia się na tablicy do pięciu minut
     * później.
     */
    private const KANDYDACI = 60;

    private const KANDYDACI_SEKUND = 300;

    private const KLUCZ_KANDYDACI_OSOB = 'tablica-dnia:kandydaci-osob';

    private const KLUCZ_KANDYDACI_DAN = 'tablica-dnia:kandydaci-dan';

    /**
     * @return array{people: Collection<int, User>, posts: Collection<int, Post>, curated: bool, notes: array<string, string>}
     */
    public function forViewer(?User $viewer): array
    {
        $picks = DailyPick::query()->forDate()->get();

        if ($picks->isEmpty()) {
            return [
                'people' => $this->peopleToFollow($viewer, self::PEOPLE),
                'posts' => $this->automaticPosts($viewer),
                'curated' => false,
                'notes' => [],
            ];
        }

        return $this->uzupelnijDoSufitu($this->fromCuratedPicks($picks, $viewer), $viewer);
    }

    /**
     * Wybór redakcyjny UZUPEŁNIONY automatem do sufitu (decyzja właściciela,
     * 11.09.2026).
     *
     * DLACZEGO TO W OGÓLE POWSTAŁO. Do 11 września zaznaczenie w panelu choćby
     * JEDNEJ pozycji wyłączało automat całkowicie: tablica pokazywała dokładnie
     * tyle, ile zaznaczono, i ani rzeczy więcej. Właściciel zaznaczył cztery
     * pozycje i zobaczył na stronie powitalnej dwie osoby i dwa dania tam,
     * gdzie mieści się dwa razy tyle — przy 87 publicznych wpisach w bazie.
     * Wybór gospodarza to miało być WYRÓŻNIENIE kilku rzeczy, a nie zamknięcie
     * tablicy na resztę serwisu.
     *
     * KOLEJNOŚĆ JEST CZĘŚCIĄ DECYZJI: najpierw to, co wybrał człowiek, potem
     * dobór automatu. Inaczej wyróżnienie przestaje być wyróżnieniem.
     *
     * DZIURA PO POZYCJI SCHOWANEJ TEŻ SIĘ ZAPEŁNIA. `fromCuratedPicks`
     * odsiewa pozycje niedostępne dla tego widza (autor zablokowany, wpis
     * schowany przez moderację po wyborze). Liczymy więc brakujące miejsca
     * z tego, co NAPRAWDĘ zostało, a nie z liczby zaznaczeń w panelu — inaczej
     * widz z jedną blokadą dostawałby tablicę krótszą od cudzej, bez żadnego
     * powodu.
     *
     * TO NADAL NIE JEST RANKING (AGENTS.md §12). Automat dobiera po tym, KIEDY
     * ktoś ostatnio coś pokazał, i najwyżej jedną pozycję od osoby — żadna
     * miara popularności nie wchodzi tu ani w wybór, ani w kolejność.
     *
     * @param  array{people: Collection<int, User>, posts: Collection<int, Post>, curated: bool, notes: array<string, string>}  $tablica
     * @return array{people: Collection<int, User>, posts: Collection<int, Post>, curated: bool, notes: array<string, string>}
     */
    private function uzupelnijDoSufitu(array $tablica, ?User $viewer): array
    {
        $brakujeOsob = self::PEOPLE - $tablica['people']->count();

        if ($brakujeOsob > 0) {
            $tablica['people'] = $tablica['people']->concat(
                $this->peopleToFollow($viewer, $brakujeOsob, $tablica['people']->modelKeys()),
            );
        }

        $brakujeDan = self::POSTS - $tablica['posts']->count();

        if ($brakujeDan > 0) {
            // Pomijamy AUTORÓW wybranych dań, nie same dania. Reguła „najwyżej
            // jedna pozycja od osoby" jest w tej tablicy najważniejsza
            // (docs/product/COLD_START.md): bez niej jedna aktywna osoba
            // zasłania cały serwis, a dobór automatu mógłby dołożyć drugi wpis
            // dokładnie tej osoby, którą gospodarz właśnie wyróżnił.
            $tablica['posts'] = $tablica['posts']->concat(
                $this->automaticPosts($viewer, $brakujeDan, $tablica['posts']->pluck('author_id')->all()),
            );
        }

        return $tablica;
    }

    /**
     * Wybór redakcyjny. Pozycje niedostępne dla tego widza (blokada, treść
     * schowana w międzyczasie) po prostu wypadają — tablica nie może pokazać
     * pustej karty ani zdradzić, że coś tu było.
     *
     * @param  Collection<int, DailyPick>  $picks
     * @return array{people: Collection<int, User>, posts: Collection<int, Post>, curated: bool, notes: array<string, string>}
     */
    private function fromCuratedPicks(Collection $picks, ?User $viewer): array
    {
        $hidden = $this->hiddenAuthorIdsFor($viewer);
        $notes = [];

        foreach ($picks as $pick) {
            if ($pick->note !== null) {
                $notes[$pick->subject_id] = $pick->note;
            }
        }

        $people = User::query()
            ->whereIn('id', $picks->where('subject_type', DailyPick::TYPE_USER)->pluck('subject_id'))
            ->whereNotIn('id', $hidden)
            ->where('status', User::STATUS_ACTIVE)
            ->with(['profile.avatar', 'posts' => fn ($query) => $query->publiclyVisible()->zWidocznymPrzepisemAlboWlasnaTrescia($viewer)->latest('published_at')->limit(3)->with('media')])
            ->get();

        $posts = Post::query()
            ->whereIn('id', $picks->where('subject_type', DailyPick::TYPE_POST)->pluck('subject_id'))
            ->publiclyVisible()
            ->whereNotIn('author_id', $hidden)
            // Konto autora aktywne (audyt A5) — ta tablica żyje na tej samej
            // stronie /odkryj co reszta feedu i redakcja mogła wybrać wpis
            // wcześniej, zanim autora zawieszono albo zbanowano.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            // Przepis schowany, usunięty albo zawężony PO wyborze gospodarza
            // zabiera ze sobą wpis, który go wskazuje (issue #368) — tak samo
            // jak zabiera go ukrycie samego wpisu dwie linijki wyżej. Wpis
            // z własną treścią zostaje za swoją widocznością (issue #1377).
            ->zWidocznymPrzepisemAlboWlasnaTrescia($viewer)
            ->with([
                'author.profile.avatar',
                'media',
                // Wpis wskazujący przepis (issue #368) nie ma ani treści, ani
                // własnych zdjęć — kafelek tablicy bierze z relacji tytuł
                // przepisu i jego zdjęcie główne. Bez tych dwóch pozycji
                // pokazałby samo imię autora.
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
            ])
            ->withVisibleCommentCount($viewer)
            ->get()
            ->tap(fn (Collection $wpisy) => Post::ukryjNiedostepnePrzepisy($wpisy, $viewer));

        $peopleById = $people->keyBy('id');
        $people = new EloquentCollection(
            $picks->where('subject_type', DailyPick::TYPE_USER)
                ->map(fn (DailyPick $pick) => $peopleById->get($pick->subject_id))
                ->filter()
                ->values(),
        );

        $postsById = $posts->keyBy('id');
        $posts = new EloquentCollection(
            $picks->where('subject_type', DailyPick::TYPE_POST)
                ->map(fn (DailyPick $pick) => $postsById->get($pick->subject_id))
                ->filter()
                ->values(),
        );

        return [
            'people' => $people,
            'posts' => $posts,
            'curated' => true,
            'notes' => $notes,
        ];
    }

    /**
     * Osoby, których widz jeszcze nie obserwuje, a które ostatnio coś pokazały.
     *
     * Jedyne miejsce w aplikacji, które odpowiada na pytanie „kogo pokazać
     * do zaobserwowania" — używa tego i tablica „kuKINGi na dziś",
     * i onboarding. Dwie różne odpowiedzi na to samo pytanie rozjechałyby się
     * przy pierwszej zmianie.
     *
     * @return Collection<int, User>
     */
    public function peopleToFollow(?User $viewer, int $limit = self::PEOPLE, array $pomin = []): Collection
    {
        $excluded = $this->wykluczeniOsob($viewer, $pomin);

        /** @var list<string> $kandydaci */
        $kandydaci = Cache::remember(
            self::KLUCZ_KANDYDACI_OSOB,
            self::KANDYDACI_SEKUND,
            fn (): array => $this->ostatnioAktywni([], self::KANDYDACI)->pluck('users.id')->all(),
        );

        $wybrani = array_values(array_diff($kandydaci, $excluded));

        if ($wybrani !== []) {
            // Kolejność kandydatów to kolejność z cache (ostatnia publikacja);
            // przechodzimy po niej, zamiast sortować od nowa.
            $poId = User::query()
                ->whereIn('id', $wybrani)
                ->where('status', User::STATUS_ACTIVE)
                ->with($this->relacjeOsoby($viewer))
                ->get()
                ->keyBy('id');
            $osoby = new EloquentCollection(
                collect($wybrani)->map(fn (string $id): ?User => $poId->get($id))->filter()->take($limit)->values()->all(),
            );
            $odrzuceni = count($wybrani) - $poId->count();
        } else {
            $osoby = new EloquentCollection;
            $odrzuceni = 0;
        }

        // Kandydat z cache, którego nie przepuściły bramki (konto zawieszone
        // albo usunięte po zapisaniu cache, baza postawiona od nowa), znaczy,
        // że lista w cache jest nieaktualna — niepełna lista przestaje wtedy
        // dowodzić, że dalej nikogo nie ma.
        $nieaktualni = $odrzuceni > 0;

        if ($osoby->count() >= $limit || (count($kandydaci) < self::KANDYDACI && ! $nieaktualni)) {
            return $osoby;
        }

        if ($nieaktualni) {
            Cache::forget(self::KLUCZ_KANDYDACI_OSOB);
        }

        // Rezerwa: kandydatów zabrakło po odsianiu, a mogą być następni.
        return $this->ostatnioAktywni($excluded, $limit)->with($this->relacjeOsoby($viewer))->get();
    }

    /** @return list<string> */
    private function wykluczeniOsob(?User $viewer, array $pomin): array
    {
        // `$pomin` — konta, które na tej tablicy już stoją z wyboru gospodarza.
        // Parametr, a nie odsiewanie po pobraniu: limit jest narzucany w SQL,
        // więc odsianie „po fakcie" zwracałoby MNIEJ pozycji niż proszono
        // i dziura zostawałaby otwarta.
        $excluded = [...$this->hiddenAuthorIdsFor($viewer), ...$pomin];

        if ($viewer !== null) {
            $excluded = [
                ...$excluded,
                $viewer->getKey(),
                ...$viewer->following()->pluck('users.id')->all(),
            ];
        }

        return array_values(array_unique($excluded));
    }

    /**
     * Konta aktywne, posortowane po ostatniej publicznej publikacji.
     *
     * @param  list<string>  $excluded
     * @return Builder<User>
     */
    private function ostatnioAktywni(array $excluded, int $limit): Builder
    {
        // JEDNA AGREGACJA NA CAŁE `posts`, A NIE JEDNA NA KAŻDE KONTO.
        //
        // Wcześniej sortowanie szło skorelowanym podzapytaniem: baza liczyła
        // `max(published_at)` OSOBNO dla każdego konta, które przeszło
        // `whereHas`, sortowała całość i dopiero potem brała pozycje z limitu.
        // A `forViewer` chodzi na trzech ekranach, w tym na publicznym
        // landingu — czyli także dla każdego robota indeksującego.
        //
        // ZMIERZONE na syntetycznych 5000 kont i 20 000 wpisów (AGENTS.md §3
        // wymaga pomiaru przed optymalizacją, więc nie jest to domysł):
        //   * skorelowane podzapytanie — mediana 28,6 ms
        //   * złączenie z agregatem    — mediana 12,9 ms
        // Obie rosną z liczbą wpisów, ale tylko pierwsza rośnie także
        // z liczbą KONT.
        //
        // TEN POMIAR ZROBIONO PRZY SUFICIE 4 I ZOSTAJE WAŻNY PRZY 6.
        // Obie mierzone wersje płacą za agregację i sortowanie CAŁOŚCI —
        // `LIMIT` obcina dopiero posortowany wynik, więc koszt rośnie
        // z liczbą kont i wpisów, a nie z sufitem tablicy. Dwie pozycje
        // więcej to dwa wiersze więcej w ostatnim kroku i tyle samo
        // zapytań co przedtem (patrz komentarz przy stałych wyżej).
        // Gdyby ktoś kiedyś wrócił do skorelowanego podzapytania, ta różnica
        // przestałaby być obojętna — dlatego stoi tu, a nie w opisie zmiany.
        //
        // CACHE — ALE KANDYDATÓW, NIE WYNIKU
        // Wynik zależy od widza (obserwowani, blokady), więc cache całej
        // listy musiałby być per widz. Od audytu B4 W1 cache trzyma
        // kandydatów liczonych bez widza, a wykluczenia widza odsiewa PHP
        // (komentarz przy `KANDYDACI`). To zapytanie chodzi raz na pięć
        // minut i w rezerwie, gdy odsianie zostawi za mało pozycji.
        $ostatniePublikacje = Post::query()
            ->selectRaw('author_id, max(published_at) as ostatnia_publikacja')
            ->publiclyVisible()
            ->groupBy('author_id');

        /*
         * TU ŚWIADOMIE NIE MA `zWidocznymPrzepisem()` (issue #368), choć
         * podgląd zdjęć niżej i cała reszta tej klasy go ma.
         *
         * Powód jest zmierzony, nie estetyczny. `zWidocznymPrzepisem()` to
         * `EXISTS` na `recipes`, a ten agregat jest tu po to, żeby NIE
         * liczyć niczego per konto — pilnuje tego
         * `PropozycjeOsobDoObserwowaniaTest::test_zapytanie_nie_liczy_agregatu_dla_kazdego_konta_osobno`,
         * czytając plan zapytania i oblewając na `SubPlan`. Dołożenie tu
         * warunku wstawia do planu `hashed SubPlan` i ten test oblewa.
         *
         * Cena jest mała i policzona: ten agregat decyduje wyłącznie
         * o KOLEJNOŚCI propozycji („kto ostatnio coś pokazał"), a nie o tym,
         * co widać. Osoba, której jedyną publikacją jest wpis do przepisu
         * schowanego przez moderację, może więc stanąć na liście propozycji
         * z pustym paskiem podglądu — bo podgląd (`with('posts')` niżej) tę
         * bramkę MA. Pokazuje to o jedno konto za dużo, nigdy o jedną treść
         * za dużo.
         */

        return User::query()
            ->select('users.*')
            ->where('users.status', User::STATUS_ACTIVE)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('users.id', $excluded))
            // Złączenie wewnętrzne zastępuje `whereHas`: konto bez ani jednego
            // publicznego wpisu po prostu nie ma z czym się złączyć.
            ->joinSub($ostatniePublikacje, 'ostatnie', fn ($join) => $join->on('ostatnie.author_id', '=', 'users.id'))
            // Sortujemy po tym, KIEDY ktoś ostatnio coś pokazał, nie po tym,
            // ile ma obserwujących. Obserwowanie osoby, która nic nie wrzuca,
            // nie zapełnia feedu.
            ->orderByDesc('ostatnie.ostatnia_publikacja')
            ->limit($limit);
    }

    /** @return array<int|string, mixed> */
    private function relacjeOsoby(?User $viewer): array
    {
        return ['profile.avatar', 'posts' => fn ($query) => $query->publiclyVisible()->zWidocznymPrzepisemAlboWlasnaTrescia($viewer)->latest('published_at')->limit(3)->with('media')];
    }

    /**
     * Świeże wpisy, maksymalnie jeden od osoby.
     *
     * @return Collection<int, Post>
     */
    private function automaticPosts(?User $viewer, int $limit = self::POSTS, array $pominAutorow = []): Collection
    {
        // `$pominAutorow` — autorzy, których danie już stoi na tablicy
        // z wyboru gospodarza. Wykluczamy AUTORA, nie sam wpis, bo reguła
        // „najwyżej jedno danie od osoby" obowiązuje w całej tablicy, a nie
        // osobno w części redakcyjnej i osobno w dobranej.
        $hidden = array_values(array_unique([...$this->hiddenAuthorIdsFor($viewer), ...$pominAutorow]));

        // DISTINCT ON (author_id), NIE „pobierz z zapasem i odsiej".
        //
        // Wcześniej ta metoda brała 24 najnowsze wpisy (`POSTS * 6`, przy
        // ówczesnym `POSTS = 4`) i dopiero potem odsiewała powtórzonych
        // autorów przez `unique('author_id')`. Komentarz nazywał to świadomym
        // kompromisem, ale zapas 6× był ZGADYWANY, nie gwarantowany —
        // i zmierzone: gdy jedna osoba opublikowała 30 najnowszych wpisów,
        // odsiew zostawiał z nich JEDEN i tablica pokazywała jedną kartę
        // zamiast czterech, mimo że trzech innych autorów miało dostępne
        // (tylko starsze) treści.
        //
        // To nie był przypadek teoretyczny: `COLD_START.md` §4.2 każe
        // gospodarzowi publikować codziennie, więc jeden bardzo aktywny
        // autor jest wzorcem wpisanym w plan startu, nie anomalią.
        //
        // Postgresowy `DISTINCT ON` daje NAJNOWSZY wpis KAŻDEGO autora
        // niezależnie od tego, ilu wpisów dodał — a dopiero z tego zbioru
        // bierzemy tyle najnowszych, ile mówi limit. Liczba autorów
        // na tablicy nie zależy już od rozkładu publikacji.
        //
        // DLATEGO WŁAŚNIE SUFIT WOLNO BYŁO PODNIEŚĆ Z 4 NA 6. Przy starym
        // rozwiązaniu „pobierz z zapasem i odsiej" każda zmiana sufitu
        // wymagałaby przeliczenia zapasu i była zgadywaniem na nowo: zapas
        // 6× nad czterema to co innego niż 6× nad sześcioma, a gwarancji
        // nie dawał żaden z nich. `DISTINCT ON` nie ma czego przeliczać —
        // jeden wpis na autora jest własnością zapytania, nie skutkiem
        // dobranej z góry liczby.
        //
        // `ORDER BY` w podzapytaniu MUSI zaczynać się od `author_id` —
        // tego wymaga Postgres od `DISTINCT ON`. Dalsze kolumny wybierają,
        // KTÓRY wpis danego autora wygrywa: najnowszy, a przy równej
        // sekundzie większe `id`.
        $ukryci = array_flip($hidden);

        /** @var list<array{0: string, 1: string}> $kandydaci */
        $kandydaci = Cache::remember(
            self::KLUCZ_KANDYDACI_DAN,
            self::KANDYDACI_SEKUND,
            fn (): array => $this->najnowszyKazdegoAutora(null, [], self::KANDYDACI)
                ->map(fn (object $wiersz): array => [(string) $wiersz->id, (string) $wiersz->author_id])
                ->all(),
        );

        $wybrane = [];
        foreach ($kandydaci as [$wpis, $autor]) {
            if (! isset($ukryci[$autor])) {
                $wybrane[] = $wpis;
            }
        }

        $wpisy = $wybrane === [] ? new Collection : $this->pelneWpisy($viewer, $wybrane, $limit);

        // Jak przy osobach: kandydat odrzucony przez bramki (wpis schowany,
        // konto zawieszone, baza postawiona od nowa) znaczy nieaktualny cache.
        $nieaktualne = $wpisy->count() < min($limit, count($wybrane));

        if ($wpisy->count() >= $limit || (count($kandydaci) < self::KANDYDACI && ! $nieaktualne)) {
            return $wpisy;
        }

        if ($nieaktualne) {
            Cache::forget(self::KLUCZ_KANDYDACI_DAN);
        }

        // Rezerwa: kandydatów zabrakło po odsianiu, a mogą być następni.
        $wybrane = $this->najnowszyKazdegoAutora($viewer, $hidden, $limit)->pluck('id')->all();

        return $wybrane === [] ? new Collection : $this->pelneWpisy($viewer, $wybrane, $limit);
    }

    /**
     * Najnowszy publiczny wpis każdego autora, najświeższe najpierw.
     *
     * @param  list<string>  $hidden
     * @return Collection<int, object{id: string, author_id: string, published_at: string}>
     */
    private function najnowszyKazdegoAutora(?User $viewer, array $hidden, int $limit): Collection
    {
        $najnowszyKazdegoAutora = Post::query()
            ->selectRaw('DISTINCT ON (posts.author_id) posts.id, posts.author_id, posts.published_at')
            ->publiclyVisible()
            ->when($hidden !== [], fn ($query) => $query->whereNotIn('posts.author_id', $hidden))
            // Konto autora aktywne (audyt A5) — patrz uzasadnienie przy
            // DiscoverFeed::paginate(): to jest promowanie treści, więc próg
            // jest surowszy niż zwykłe wejście na adres wpisu.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            // Widoczność przepisu (issue #368) MUSI być w TYM podzapytaniu,
            // a nie w zapytaniu po pełne modele niżej: `DISTINCT ON` wybiera
            // jeden wpis na autora, więc wpis odsiany dopiero potem zabrałby
            // ze sobą całe miejsce tego autora na tablicy. Wpis z własną
            // treścią zostaje za swoją widocznością (issue #1377).
            ->zWidocznymPrzepisemAlboWlasnaTrescia($viewer)
            ->orderBy('posts.author_id')
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id');

        return DB::query()
            ->fromSub($najnowszyKazdegoAutora, 'najnowsze')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<string>  $wybrane
     * @return Collection<int, Post>
     */
    private function pelneWpisy(?User $viewer, array $wybrane, int $limit): Collection
    {
        // Drugie zapytanie po pełne modele z relacjami. Osobno, bo
        // `DISTINCT ON` nie znosi `with()`/`withCount()` w tym samym
        // przebiegu, a kolejność i tak trzeba narzucić na zewnątrz.
        return Post::query()
            ->whereIn('id', $wybrane)
            // Te same bramki co przy wyborze kandydatów, liczone dla TEGO
            // widza: kandydaci z cache mogli zostać schowani po zapisaniu.
            ->publiclyVisible()
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            // Wpis z własną treścią zostaje za swoją widocznością (issue #1377);
            // przepis zdejmuje z kafelka `Post::ukryjNiedostepnePrzepisy()`.
            ->zWidocznymPrzepisemAlboWlasnaTrescia($viewer)
            ->with([
                'author.profile.avatar',
                'media',
                // Wpis wskazujący przepis (issue #368) nie ma ani treści, ani
                // własnych zdjęć — kafelek tablicy bierze z relacji tytuł
                // przepisu i jego zdjęcie główne. Bez tych dwóch pozycji
                // pokazałby samo imię autora.
                'recipe:id,title,slug,visibility,hero_media_id',
                'recipe.heroMedia',
            ])
            ->withVisibleCommentCount($viewer)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->tap(fn (Collection $wpisy) => Post::ukryjNiedostepnePrzepisy($wpisy, $viewer));
    }

    /** @return list<string> */
    private function hiddenAuthorIdsFor(?User $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        return array_values(array_unique([
            ...$viewer->blocking()->pluck('users.id')->all(),
            ...$viewer->blockedBy()->pluck('users.id')->all(),
        ]));
    }
}
