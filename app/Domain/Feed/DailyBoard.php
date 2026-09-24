<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\DailyPick;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
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
            ->with(['profile.avatar', 'posts' => fn ($query) => $query->publiclyVisible()->zWidocznymPrzepisem($viewer)->latest('published_at')->limit(3)->with('media')])
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
            // jak zabiera go ukrycie samego wpisu dwie linijki wyżej.
            ->zWidocznymPrzepisem($viewer)
            // Kontrakt kafelka (#1037): autor, zdjęcia, przepis z `heroMedia`
            // i licznik komentarzy — `Post::scopeDlaKarty()`. Wariant
            // `kafelek`, bo tablica nie pokazuje ani tematów, ani liczby zapisów.
            ->dlaKarty($viewer, kafelek: true)
            ->get();

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

        $excluded = array_values(array_unique($excluded));

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
        // DLACZEGO NIE CACHE
        // Bo ta lista zależy od widza: wyklucza osoby już obserwowane
        // i zablokowane. Cache musiałby być per widz, czyli byłby to nie tyle
        // cache, co osobna kopia danych dla każdego konta. Pomiar zmienił tu
        // decyzję — pierwotny pomysł z raportu (`cache()->remember` na 10 minut)
        // nie dałby się pogodzić z tym filtrem.
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
            ->with(['profile.avatar', 'posts' => fn ($query) => $query->publiclyVisible()->zWidocznymPrzepisem($viewer)->latest('published_at')->limit(3)->with('media')])
            ->limit($limit)
            ->get();
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
        $najnowszyKazdegoAutora = Post::query()
            ->selectRaw('DISTINCT ON (posts.author_id) posts.id, posts.published_at')
            ->publiclyVisible()
            ->when($hidden !== [], fn ($query) => $query->whereNotIn('author_id', $hidden))
            // Konto autora aktywne (audyt A5) — patrz uzasadnienie przy
            // DiscoverFeed::paginate(): to jest promowanie treści, więc próg
            // jest surowszy niż zwykłe wejście na adres wpisu.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            // Widoczność przepisu (issue #368) MUSI być w TYM podzapytaniu,
            // a nie w zapytaniu po pełne modele niżej: `DISTINCT ON` wybiera
            // jeden wpis na autora, więc wpis odsiany dopiero potem zabrałby
            // ze sobą całe miejsce tego autora na tablicy.
            ->zWidocznymPrzepisem($viewer)
            ->orderBy('posts.author_id')
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id');

        $wybrane = DB::query()
            ->fromSub($najnowszyKazdegoAutora, 'najnowsze')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');

        if ($wybrane->isEmpty()) {
            return new Collection;
        }

        // Drugie zapytanie po pełne modele z relacjami. Osobno, bo
        // `DISTINCT ON` nie znosi `with()`/`withCount()` w tym samym
        // przebiegu, a kolejność i tak trzeba narzucić na zewnątrz.
        return Post::query()
            ->whereIn('id', $wybrane)
            // Kontrakt kafelka (#1037): autor, zdjęcia, przepis z `heroMedia`
            // i licznik komentarzy — `Post::scopeDlaKarty()`. Wariant
            // `kafelek`, bo tablica nie pokazuje ani tematów, ani liczby zapisów.
            ->dlaKarty($viewer, kafelek: true)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
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
