<?php

declare(strict_types=1);

namespace App\Domain\Digest;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buduje treść podsumowań dla CAŁEJ PACZKI odbiorców naraz (issue #11).
 *
 * DLACZEGO PACZKA, A NIE „ZBUDUJ LIST DLA JEDNEJ OSOBY W PĘTLI"
 * To jest jedyna nietrywialna decyzja w tym pliku i warto ją nazwać wprost,
 * bo wersja z pętlą jest krótsza i wygląda czyściej.
 *
 * Wysyłka dotyka do 120 osób na dobę (`config('kuking.digest.dzienny_limit')`).
 * Naiwna wersja — trzy zapytania na osobę plus doładowania relacji — to
 * kilkaset zapytań w jednym przebiegu harmonogramu, który w tym repozytorium
 * chodzi W TYM SAMYM PROCESIE PHP co serwer WWW (`routes/console.php`
 * używa `Schedule::call()`, bo `proc_open` jest wyłączone w `docker/php.ini`).
 * Każde z tych zapytań blokuje więc pętlę harmonogramu, a przy jednej
 * replice i jednym połączeniu do bazy — także obsługę zwykłych żądań.
 * To nie jest hipoteza o skali: to jest ten sam wzorzec, który audyt N03
 * łapał już na miniaturach w feedzie (`MiniaturyBezWachlarzaZapytanTest`).
 *
 * Dlatego liczba zapytań tej klasy jest **stała** i nie zależy od tego, czy
 * odbiorców jest pięciu, czy pięćdziesięciu. Pilnuje tego
 * `PodsumowanieBezWachlarzaZapytanTest` — tym samym sposobem co N03: mierzy
 * ten sam kod przy małym i dużym zestawie i wymaga tej samej liczby.
 *
 * PRYWATNOŚĆ: NIC, CZEGO ADRESAT NIE ZOBACZYŁBY NA STRONIE
 * Każde z trzech zapytań niżej niesie tę samą granicę, którą ma odpowiedni
 * ekran serwisu, i bierze ją z tego samego miejsca:
 *
 *  - wykonania — blokada w obie strony i status kucharza, dokładnie jak
 *    `CookedEvent::scopeWidoczneDla()` (galeria „Komu wyszło");
 *  - nowi obserwujący — `User::scopeWidocznyJakoOsoba()`, dokładnie jak
 *    lista obserwujących na profilu (D-022);
 *  - wpisy obserwowanych — `Post::scopeTylkoOdAktywnychAutorow()`, widoczność
 *    `public`/`followers` ORAZ osobna bramka widoczności PRZEPISU, tak jak
 *    w `FollowingFeed`. Te trzy warunki to nie jest jedno i to samo: przez
 *    długi czas stało tu, że wystarczą dwa pierwsze i że jest to „dokładnie
 *    jak `FollowingFeed`", a bramki przepisu nie było wcale (#368).
 *
 * Jedyne miejsce, w którym warunek jest ZAPISANY tu, a nie wywołany
 * z modelu, to blokada przy wykonaniach — bo `scopeWidoczneDla()` przyjmuje
 * JEDNEGO widza, a tutaj widzem każdego wiersza jest autor jego przepisu,
 * inny w każdym wierszu. Warunek jest przez to skorelowany
 * (`recipes.author_id`), nie sparametryzowany, i dlatego nie da się go
 * wywołać z tamtego zakresu. Ta jedna kopia jest przypięta osobnym testem
 * (`PodsumowanieSzanujePrywatnoscTest`).
 *
 * DLACZEGO BLOKADA NIE JEST SPRAWDZANA PRZY DWÓCH POZOSTAŁYCH SEKCJACH
 * Bo zablokowanie kogoś KASUJE obserwowanie w obie strony
 * (`App\Domain\Social\Actions\BlockUser`), więc para „obserwujący
 * i zablokowany jednocześnie" nie istnieje w bazie. Sprawdzanie jej byłoby
 * warunkiem, który nigdy nic nie odrzuca — a taki warunek z czasem przestaje
 * być testowany i zaczyna kłamać. Pilnuje tego osobny przypadek testowy:
 * blokada usuwa osobę z obu tych sekcji, właśnie przez zerwane obserwowanie.
 */
final class ZbierzTresciDigestu
{
    /**
     * @param  Collection<int, User>  $odbiorcy
     * @return array<string, TrescDigestu> klucz = identyfikator odbiorcy
     */
    public function dla(Collection $odbiorcy, ?Carbon $od = null): array
    {
        if ($odbiorcy->isEmpty()) {
            return [];
        }

        $od ??= now()->subDays((int) config('kuking.digest.okno_dni'));
        $limit = max(1, (int) config('kuking.digest.max_pozycji'));

        /** @var list<string> $identyfikatory */
        $identyfikatory = $odbiorcy->map(fn (User $u): string => (string) $u->getKey())->values()->all();

        $wykonania = $this->wykonania($identyfikatory, $od, $limit);
        [$nowiObserwujacy, $ileObserwujacych] = $this->nowiObserwujacy($identyfikatory, $od, $limit);
        $wpisy = $this->wpisyObserwowanych($identyfikatory, $od, $limit);

        $pytanie = $this->pytanieGospodarza();

        $tresci = [];

        foreach ($odbiorcy as $odbiorca) {
            $id = (string) $odbiorca->getKey();

            $tresci[$id] = new TrescDigestu(
                odbiorca: $odbiorca,
                wykonania: $wykonania[$id] ?? [],
                nowiObserwujacy: $nowiObserwujacy[$id] ?? [],
                ileNowychObserwujacych: $ileObserwujacych[$id] ?? 0,
                wpisyObserwowanych: $wpisy[$id] ?? [],
                pytanieGospodarza: $pytanie,
            );
        }

        return $tresci;
    }

    /** Wygodne wejście dla jednej osoby — podgląd i testy. */
    public function dlaJednej(User $odbiorca, ?Carbon $od = null): TrescDigestu
    {
        $tresci = $this->dla(collect([$odbiorca]), $od);

        return $tresci[(string) $odbiorca->getKey()] ?? TrescDigestu::pusta($odbiorca);
    }

    /**
     * Cudze „Ugotowałem" z przepisów adresata, najnowsze pierwsze.
     *
     * `whereColumn(... '!=' ...)` wycina gotowanie autora z własnego przepisu:
     * jest to normalne i częste (`AGENTS.md` §6 wprost dopuszcza dziesiątki
     * wykonań tego samego przepisu przez tę samą osobę), ale nie jest
     * powodem, żeby napisać do kogoś list o tym, co sam zrobił.
     *
     * `whereNull('recipes.deleted_at')` — `Recipe` ma soft delete, a złączenie
     * ręczne omija globalny zakres Eloquenta. Bez tego list pisałby o wykonaniu
     * przepisu, który autor tydzień temu usunął.
     *
     * @param  list<string>  $identyfikatory
     * @return array<string, list<CookedEvent>>
     */
    private function wykonania(array $identyfikatory, Carbon $od, int $limit): array
    {
        $ranking = CookedEvent::query()
            ->select('cooked_events.*')
            ->addSelect('recipes.author_id as digest_odbiorca_id')
            ->selectRaw('row_number() over (partition by recipes.author_id order by cooked_events.cooked_at desc, cooked_events.id desc) as digest_row_number')
            ->join('recipes', 'recipes.id', '=', 'cooked_events.recipe_id')
            ->whereNull('recipes.deleted_at')
            ->whereIn('recipes.author_id', $identyfikatory)
            ->where('cooked_events.cooked_at', '>=', $od)
            ->whereColumn('cooked_events.user_id', '!=', 'recipes.author_id')
            ->whereHas('user', fn ($kucharz) => $kucharz->dostepnyJakoAutor())
            // Blokada w OBIE strony, skorelowana z autorem przepisu — patrz
            // komentarz klasy, dlaczego akurat tu warunek jest zapisany
            // wprost, a nie wzięty z `CookedEvent::scopeWidoczneDla()`.
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('blocks')
                    ->where(function ($w): void {
                        $w->whereColumn('blocks.blocker_id', 'recipes.author_id')
                            ->whereColumn('blocks.blocked_id', 'cooked_events.user_id');
                    })
                    ->orWhere(function ($w): void {
                        $w->whereColumn('blocks.blocker_id', 'cooked_events.user_id')
                            ->whereColumn('blocks.blocked_id', 'recipes.author_id');
                    });
            })
            ->orderByDesc('cooked_events.cooked_at')
            ->orderByDesc('cooked_events.id');

        $wiersze = CookedEvent::query()
            ->fromSub($ranking, 'cooked_events')
            ->where('digest_row_number', '<=', $limit)
            ->with(['user.profile', 'recipe:id,title,slug,author_id'])
            ->orderBy('digest_odbiorca_id')
            ->orderBy('digest_row_number')
            ->get();

        $pogrupowane = [];

        foreach ($wiersze as $wiersz) {
            $pogrupowane[(string) $wiersz->getAttribute('digest_odbiorca_id')][] = $wiersz;
        }

        return $pogrupowane;
    }

    /**
     * Nowe obserwacje adresata: lista osób i PEŁNA liczba.
     *
     * Liczba jest osobno, bo lista jest przycięta do limitu, a zdanie „i
     * jeszcze cztery inne osoby" wymaga prawdziwej liczby — nie długości
     * przyciętej listy.
     *
     * @param  list<string>  $identyfikatory
     * @return array{0: array<string, list<User>>, 1: array<string, int>}
     */
    private function nowiObserwujacy(array $identyfikatory, Carbon $od, int $limit): array
    {
        $ranking = DB::table('follows')
            ->join('users', 'users.id', '=', 'follows.follower_id')
            ->whereIn('follows.followed_id', $identyfikatory)
            ->where('follows.created_at', '>=', $od)
            // Ta sama granica co lista obserwujących na profilu (D-022):
            // konto zamknięte nie jest pokazywane JAKO OSOBA.
            ->whereNotIn('users.status', User::STATUSY_ZAMKNIETEGO_KONTA)
            ->select(['follows.followed_id', 'follows.follower_id'])
            ->selectRaw('row_number() over (partition by follows.followed_id order by follows.created_at desc, follows.follower_id desc) as digest_row_number')
            ->selectRaw('count(*) over (partition by follows.followed_id) as digest_total');

        $wiersze = DB::query()
            ->fromSub($ranking, 'digest_follows')
            ->where('digest_row_number', '<=', $limit)
            ->orderBy('followed_id')
            ->orderBy('digest_row_number')
            ->get();

        if ($wiersze->isEmpty()) {
            return [[], []];
        }

        $osoby = User::query()
            ->whereIn('id', $wiersze->pluck('follower_id')->unique()->all())
            ->with('profile')
            ->get()
            ->keyBy(fn (User $u): string => (string) $u->getKey());

        $lista = [];
        $ile = [];

        foreach ($wiersze as $wiersz) {
            $odbiorca = (string) $wiersz->followed_id;
            $ile[$odbiorca] = (int) $wiersz->digest_total;

            $osoba = $osoby->get((string) $wiersz->follower_id);

            if ($osoba instanceof User) {
                $lista[$odbiorca][] = $osoba;
            }
        }

        return [$lista, $ile];
    }

    /**
     * Co pokazali ludzie, których adresat obserwuje — chronologicznie.
     *
     * Złączenie z `follows` jest celowe: pozwala PostgreSQL policzyć trzy
     * najnowsze wpisy OSOBNO dla każdego odbiorcy. Ten sam wpis może pojawić
     * się dla wielu odbiorców, ale `row_number()` odcina każdą partycję
     * PRZED hydratacją. W pamięci jest więc najwyżej `liczba odbiorców ×
     * limit`, zamiast całej historii wspólnego gospodarza.
     *
     * BRAMKA PRZEPISU W ZAPYTANIU WSPÓLNYM DLA WSZYSTKICH ODBIORCÓW (#368).
     * To jest jedyna trudność tej metody i warto ją nazwać wprost, bo
     * oczywiste rozwiązanie jest tu ZŁE. `zWidocznymPrzepisem($widz)` —
     * zakres, którym bramkują się wszystkie strumienie — przyjmuje JEDNEGO
     * widza. Tutaj widzów jest tylu, ilu odbiorców paczki, a zapytanie jest
     * jedno. Wywołanie zakresu w pętli po odbiorcach dałoby zapytanie na
     * osobę i wywróciłoby jedyną twardą własność tej klasy: stałą liczbę
     * zapytań niezależną od wielkości paczki (`PodsumowanieBezWachlarzaZapytanTest`).
     *
     * Bramka zostaje więc JEDNA i policzona BEZ widza — wolno tak, bo widz
     * jest tu zdeterminowany przez sam wiersz. Wpis trafia do odbiorcy
     * WYŁĄCZNIE przez `$relacje`, czyli wyłącznie wtedy, gdy odbiorca
     * obserwuje jego autora. Rozpisując `Recipe::scopeWidoczneDla($widz)`
     * przy tym założeniu:
     *
     *  - blokada odpada — zablokowanie KASUJE obserwowanie w obie strony
     *    (`BlockUser`, akapit na górze tej klasy), więc wiersza by tu nie było;
     *  - „własny przepis widza" odpada — nikt nie obserwuje sam siebie,
     *    a gdyby zaczął, to i tak jest to jego własna treść;
     *  - `public` wolno — każdemu;
     *  - `followers` wolno — bo odbiorca autora OBSERWUJE, i to jest ten sam
     *    argument, który stoi zdanie niżej przy widoczności WPISU;
     *  - `private` i wszystko nieopublikowane (szkic, zdjęte przez moderację)
     *    nie wolno NIKOMU poza autorem.
     *
     * Zostaje warunek bez parametru: przepis opublikowany i `public` albo
     * `followers`. `whereColumn('recipes.author_id', 'posts.author_id')` NIE
     * JEST OZDOBĄ — to on zamienia powyższe rozumowanie w warunek, bo całe
     * ono wisi na tym, że obserwowany autor wpisu jest zarazem autorem
     * przepisu. Dziś inaczej być nie może (`WpisWskazujacyPrzepis::dopisz()`
     * przepisuje `author_id` z przepisu), ale wpis wskazujący CUDZY przepis
     * przestałby spełniać założenie, nie łamiąc ani jednego testu. Z tym
     * warunkiem taki wiersz po prostu wypada z listu — zamknięcie w złą
     * stronę, czyli we właściwą.
     *
     * @param  list<string>  $identyfikatory
     * @return array<string, list<Post>>
     */
    private function wpisyObserwowanych(array $identyfikatory, Carbon $od, int $limit): array
    {
        $ranking = Post::query()
            ->select('posts.*')
            ->addSelect('follows.follower_id as digest_odbiorca_id')
            ->selectRaw('row_number() over (partition by follows.follower_id order by posts.published_at desc, posts.id desc) as digest_row_number')
            ->join('follows', 'follows.followed_id', '=', 'posts.author_id')
            ->whereIn('follows.follower_id', $identyfikatory)
            ->published()
            // Widoczność WPISU: „tylko dla obserwujących" wolno pokazać, bo
            // adresat OBSERWUJE autora — prywatne nie, nigdy i nikomu poza
            // autorem.
            //
            // STAŁO TU „ta sama para warunków co `FollowingFeed`" I BYŁO TO
            // NIEPRAWDĄ — a że brzmiało jak sprawdzone, przez to nikt tu nie
            // zaglądał. `FollowingFeed` ma OBOK tej pary jeszcze
            // `zWidocznymPrzepisem($widz)` (dwa razy), i to ona, a nie ta
            // para, trzyma bramkę przepisu. Tutaj jej nie było; para na
            // `posts.visibility` nie zatrzymuje zapowiedzi przepisu, bo
            // zapowiedź jest z założenia trwale `public` (#368).
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
            // Widoczność PRZEPISU — osobna bramka, `EXISTS` na `recipes`,
            // policzona bez widza. Dlaczego bez widza i dlaczego wolno:
            // długi akapit w opisie tej metody.
            ->where(function (Builder $w): void {
                $w->whereNull('posts.recipe_id')
                    ->orWhereHas('recipe', function (Builder $przepis): void {
                        $przepis->published()
                            ->whereIn('recipes.visibility', ['public', 'followers'])
                            ->whereColumn('recipes.author_id', 'posts.author_id');
                    });
            })
            ->tylkoOdAktywnychAutorow()
            ->where('published_at', '>=', $od)
            ->orderByDesc('published_at')
            ->orderByDesc('posts.id');

        $wpisy = Post::query()
            ->fromSub($ranking, 'posts')
            ->where('digest_row_number', '<=', $limit)
            ->with(['author.profile', 'recipe:id,title,slug'])
            ->orderBy('digest_odbiorca_id')
            ->orderBy('digest_row_number')
            ->get();

        if ($wpisy->isEmpty()) {
            return [];
        }

        /** @var array<string, list<Post>> $wynik */
        $wynik = [];

        foreach ($wpisy as $wpis) {
            $wynik[(string) $wpis->getAttribute('digest_odbiorca_id')][] = $wpis;
        }

        return $wynik;
    }

    private function pytanieGospodarza(): ?string
    {
        $pytanie = trim((string) config('kuking.digest.pytanie'));

        return $pytanie === '' ? null : $pytanie;
    }
}
