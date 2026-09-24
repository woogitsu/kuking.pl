<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Post;
use App\Models\User;
use App\Support\Odmiana;
use Illuminate\Database\Eloquent\Builder;

/**
 * „Zapisało to N osób" pod wpisem (issue #275, decyzja właściciela D-081).
 *
 * PO CO TO JEST — DOCENIENIE, NIE WYNIK
 * Właściciel zdecydował wprost: „trzeba pokazać ile osób zapisało, żeby autor
 * wiedział i inni wiedzieli, i autor czuł się doceniony, nie chodzi
 * o rywalizację a docenienie". Cała trudność tej klasy siedzi w różnicy między
 * tymi dwiema rzeczami, bo `AGENTS.md` §12 zakazuje publicznych rankingów
 * użytkowników, a `docs/brand/COPY_STYLE.md` — komplementów za samą
 * publikację. Liczba, która DOCENIA, i liczba, która USTAWIA W SZEREGU, mają
 * ten sam kształt; różni je to, komu i od kiedy się ją pokazuje.
 *
 * TRZY WIDOKI NA TĘ SAMĄ LICZBĘ (D-081)
 * Autor swojego wpisu widzi liczbę OD PIERWSZEGO ZAPISU. Inny zalogowany
 * człowiek — dopiero od {@see self::PROG_DLA_OBCYCH}. Gość NIE WIDZI JEJ
 * WCALE (uzasadnienie przy `dolicz()`).
 *
 * Powód jest arytmetyczny, nie ideologiczny: serwis jest na starcie prawie
 * pusty (`docs/product/COLD_START.md`). Licznik od 1 pokazywałby pod
 * większością dań „zapisała to 1 osoba", a pod czyimś pierwszym daniem — nic.
 * „Zapisała to 1 osoba" docenia autora SŁABIEJ niż brak liczby, a zero obok
 * cudzej dziesiątki jest dokładnie tym, przed czym `COPY_STYLE.md` ostrzega
 * przy zakazie komplementów za publikację (ponad połowa osób 50+ w mediach
 * społecznościowych nigdy nic nie publikuje — `docs/research/AUDIENCE_50_PLUS.md`).
 * Autor ma za to prawo wiedzieć, że jego przepis komuś się przydał, i to jest
 * cała treść decyzji właściciela: reszta świata nie ma czego porównywać,
 * dopóki liczby są jednocyfrowe.
 *
 * DLACZEGO LICZBA, A NIE IMIONA
 * Rozważane było „zapisali to: Halina, Marek i jeszcze 3 osoby" — informacja
 * o LUDZIACH zamiast wyniku, lepiej pasująca do serwisu bez punktów.
 * Odpada, bo zapisanie do zeszytu NIE JEST dziś czynnością publiczną i nie
 * wolno jej taką zrobić bez osobnej decyzji właściciela. W kodzie:
 * `collections.visibility` ma DEFAULT `private` (migracja
 * 2026_09_05_000800_create_collections_tables), a komentarz tej migracji mówi
 * to wprost — „ktoś, kto zapisuje przepis »na potem«, nie ogłasza tego
 * światu". Imiona wyciągnęłyby na wierzch zawartość prywatnych zeszytów.
 * Sama liczba (i to od trzech dla obcych) niczyjego zeszytu nie zdradza.
 *
 * KTO SIĘ LICZY — GRANICA „PROMOCYJNA", NIE „POLITYCZNA"
 * `users.status = active`, czyli ta sama granica co
 * `Post::scopeTylkoOdAktywnychAutorow()`, `DiscoverFeed` i `SearchQuery`
 * (audyt A5). NIE `widocznyJakoOsoba()`, bo tamten zakres przepuszcza konta
 * ZAWIESZONE, a zapis od konta pod sankcją nie ma podbijać liczby pokazywanej
 * nieznajomym (to samo rozróżnienie opisuje komentarz w
 * `Comment::scopeWidoczneDla()`: granica polityczna wpuszcza zawieszonych,
 * promocyjna nie). Jednym warunkiem wypadają więc konta zbanowane,
 * zawieszone, w trakcie usuwania i usunięte.
 *
 * Blokada — bezwarunkowo i W OBIE STRONY, dokładnie jak w
 * `Comment::scopeWidoczneDla()` i `CookedEvent::scopeWidoczneDla()`. Liczba
 * jest więc policzona OCZAMI WIDZA: kto kogoś odciął, nie widzi jego śladu
 * ani w komentarzach, ani tutaj. Blokada działająca „w większości miejsc"
 * nie działa (AGENTS.md §4).
 *
 * WŁASNY ZAPIS AUTORA SIĘ NIE LICZY (`users.id <> posts.author_id`).
 * Odłożenie własnego dania do własnego zeszytu jest porządkowaniem, nie
 * docenieniem — a licznik, który autor może sobie sam podbić, nie jest
 * informacją o niczym.
 *
 * Kont zalążkowych (`users.is_seeded`, D-025) NIE wykluczamy i nie ma po co:
 * sprawdzone w `database/seeders` — żaden seeder nie zapisuje wpisów do
 * zeszytów, więc nie ma tu czego zawyżać. Gdyby kiedyś zaczął, właściwym
 * miejscem jest `CookEligibility::tylkoLiczeni()`, tak jak w
 * `LiczbaKukingow`, a nie kolejna kopia reguły tutaj.
 *
 * COUNT(DISTINCT), NIE COUNT(*)
 * Jedna osoba może mieć kilka zeszytów i wrzucić ten sam wpis do dwóch
 * (`CollectionController::savePost()` przyjmuje `collection_id`, a indeks
 * unikalny pilnuje pary zeszyt+wpis, nie osoba+wpis). Liczymy LUDZI, więc
 * `count(distinct users.id)` — `withCount()` umie tylko `count(*)` i dałby
 * dwa za tę samą osobę.
 *
 * PODZAPYTANIE SKORELOWANE, CZYLI ZERO N+1
 * `dolicz()` dokłada kolumnę do zapytania, które i tak się wykonuje — tak samo
 * jak `withCount(['comments' => …])` obok. Liczba zapytań na stronę feedu jest
 * więc stała, niezależna od liczby wpisów; pilnuje tego
 * `LicznikZapisowBezWachlarzaZapytanTest` wzorcem „mało vs dużo".
 */
final class ZapisyWpisu
{
    /**
     * Od ilu zapisów liczbę widzi KTOŚ INNY niż autor.
     *
     * Trzy, nie dwa — i to jest wybór, nie zaokrąglenie. Właściciel w zgłoszeniu
     * #275 sam nazwał dwójkę liczbą, która wypada słabo: „ludzie widzieli że to
     * zapisało 10 osób a to tylko 2". Skoro „tylko 2" czyta się jak porażka,
     * to próg musi stać NAD dwójką, inaczej licznik pokazywałby obcym dokładnie
     * tę liczbę, która autorowi szkodzi. Przy trzech osobach zdanie mówi już
     * „przydało się kilku ludziom", a nie „prawie nikomu".
     *
     * Autora ten próg nie dotyczy — patrz komentarz klasy.
     */
    public const PROG_DLA_OBCYCH = 3;

    /**
     * Dokłada do zapytania o wpisy dwie kolumny liczone w TYM SAMYM SELECT-cie:
     *
     *  - `zapisow_count` — ile osób odłożyło ten wpis do zeszytu (patrz reguły
     *    w komentarzu klasy);
     *  - `czy_zapisany` — czy TEN widz ma już ten wpis u siebie; stąd bierze
     *    się widoczne potwierdzenie na karcie (część 1 issue #275).
     *
     * Kolumny są opcjonalne z premedytacją: karta wpisu pokazuje liczbę TYLKO
     * wtedy, gdy zapytanie ją doliczyło (`ZapisyWpisu::liczba()` zwraca `null`
     * w przeciwnym razie). Ten sam wzorzec co `relationLoaded('tags')` w
     * `post-card.blade.php` — ekran, który tego nie dolicza, nie odpala przez
     * przypadek zapytania na każdą kartę.
     *
     * @param  Builder<Post>  $query
     */
    public function dolicz(Builder $query, ?User $widz): void
    {
        // GOŚĆ NIE WIDZI LICZBY W OGÓLE — I TO JEST DECYZJA, NIE OSZCZĘDNOŚĆ
        // (D-081). Liczba mówi „przydało się ludziom Z TEJ SPOŁECZNOŚCI"
        // i jest adresowana do jej członków, nie do otwartego internetu.
        // Praktyczny powód dokłada się do zasady: strona powitalna układa
        // wpisy w SIATKĘ (`landing-wpisy`), a liczby jedna obok drugiej to
        // zestawienie — dokładnie to, czego ta decyzja zabrania. Wychodzi
        // z tego jedna reguła zamiast wyjątku na ekran: nie ma widza, nie ma
        // liczby, więc gość nie zobaczy jej ani na `/odkryj`, ani na stronie
        // powitalnej, ani w mapie strony.
        if ($widz === null) {
            return;
        }

        $query->addSelect(['zapisow_count' => $this->podzapytanieLiczby($widz)]);

        // `withExists`, nie osobne podzapytanie ręcznie: to jest gotowa,
        // przetestowana droga Eloquenta do „czy istnieje" w tym samym SELECT-cie.
        // Granica jest tu inna niż przy liczbie i tak ma być — pytanie brzmi
        // „czy JA to mam w zeszycie", a na to odpowiada wyłącznie właściciel
        // zeszytu; status ani blokada nie mają tu nic do rzeczy.
        $query->withExists(['collections as czy_zapisany' => fn ($q) => $q->where('collections.owner_id', $widz->getKey())]);
    }

    /**
     * To samo dla JEDNEGO, już wczytanego wpisu — ekran pojedynczego wpisu
     * dostaje model z wiązania trasy, więc nie ma zapytania, do którego dałoby
     * się dołożyć kolumnę.
     *
     * Jedno dodatkowe zapytanie na stronę, stałe (jeden wpis = jeden ekran),
     * więc nie jest to N+1. Nie dublujemy tu reguł — idą tym samym
     * `dolicz()`, żeby ekran wpisu i karta w feedzie nie mogły policzyć
     * czegoś innego.
     */
    public function doliczDoWpisu(Post $post, ?User $widz): void
    {
        // Dla gościa nie ma czego liczyć (patrz `dolicz()`), więc nie robimy
        // też tego jednego zapytania. Ekran wpisu jest publiczny i chodzi po
        // nim także Google — nie ma powodu, żeby liczył coś, czego nikt tam
        // nie zobaczy.
        if ($widz === null) {
            return;
        }

        $dane = Post::query()
            ->whereKey($post->getKey())
            ->tap(fn (Builder $q) => $this->dolicz($q, $widz))
            ->first();

        if ($dane === null) {
            return;
        }

        $post->setAttribute('zapisow_count', $dane->getAttribute('zapisow_count'));
        $post->setAttribute('czy_zapisany', $dane->getAttribute('czy_zapisany'));
    }

    /**
     * Ile osób zapisało — albo `null`, gdy ten ekran liczby nie doliczył.
     *
     * `null` i `0` znaczą tu dwie różne rzeczy i widok nie ma prawa ich
     * pomieszać: „nie wiadomo" kontra „nikt jeszcze".
     */
    public function liczba(Post $post): ?int
    {
        $wartosc = $post->getAttribute('zapisow_count');

        return $wartosc === null ? null : (int) $wartosc;
    }

    /** Czy widz ma ten wpis w swoim zeszycie (potwierdzenie na karcie). */
    public function czyZapisany(Post $post): bool
    {
        return (bool) $post->getAttribute('czy_zapisany');
    }

    /**
     * Czy TEMU widzowi pokazujemy liczbę — patrz „TRZY WIDOKI NA TĘ SAMĄ
     * LICZBĘ" w komentarzu klasy.
     */
    public function widocznaDla(Post $post, ?User $widz): bool
    {
        $liczba = $this->liczba($post);

        if ($liczba === null || $liczba < 1) {
            return false;
        }

        if ($widz !== null && $widz->getKey() === $post->author_id) {
            return true;
        }

        return $liczba >= self::PROG_DLA_OBCYCH;
    }

    /**
     * Zdanie po polsku, z odmienionym CZASOWNIKIEM, nie tylko rzeczownikiem.
     *
     * „3 osoby zapisało" byłoby błędem gramatycznym, a `Odmiana::rzeczownik()`
     * jest funkcją ogólną i odmienia tak samo czasownik: 1 → „osoba zapisała",
     * 2-4 → „osoby zapisały", 5+ i nastki → „osób zapisało".
     *
     * Zdanie mówi o CZYNNOŚCI („zapisały to u siebie w zeszycie"), nie
     * o wyniku („liczba zapisów: 3"). To ta sama różnica, którą trzyma cały
     * produkt: „Ugotowałem" zamiast licznika serduszek (AGENTS.md §1).
     */
    public function zdanie(Post $post): string
    {
        $liczba = (int) $this->liczba($post);

        $rzeczownik = Odmiana::rzeczownik($liczba, 'osoba', 'osoby', 'osób');
        $czasownik = Odmiana::rzeczownik($liczba, 'zapisała', 'zapisały', 'zapisało');

        return "{$liczba} {$rzeczownik} {$czasownik} to u siebie w zeszycie";
    }

    /**
     * `select count(distinct users.id) …` skorelowane z `posts.id` z zapytania
     * nadrzędnego.
     *
     * Warunki są POKWALIFIKOWANE nazwą tabeli z wyliczonego powodu: `posts`
     * ma własną kolumnę `status` (migracja 2026_09_05_000500). Gołe `status`
     * Postgres rozwiązałby tu i tak do `users.status` (najpierw szuka we
     * własnym FROM), ale zapis, którego poprawność zależy od kolejności
     * rozwiązywania nazw, jest zaproszeniem do pomyłki przy następnej zmianie.
     *
     * @return Builder<User>
     */
    private function podzapytanieLiczby(?User $widz): Builder
    {
        return User::query()
            ->selectRaw('count(distinct users.id)')
            ->join('collections', 'collections.owner_id', '=', 'users.id')
            ->join('collection_items', 'collection_items.collection_id', '=', 'collections.id')
            ->whereColumn('collection_items.post_id', 'posts.id')
            ->whereColumn('users.id', '!=', 'posts.author_id')
            ->where('users.status', User::STATUS_ACTIVE)
            ->tap(fn (Builder $q) => $this->pomijajZablokowanych($q, $widz));
    }

    /**
     * Blokada w obie strony — kopia wzorca z `SearchQuery::pomijajZablokowanych()`
     * i `Comment::scopeWidoczneDla()`, bo dotyczy innej tabeli (`collections.owner_id`)
     * i nie da się jej wprost wywołać z tamtych miejsc.
     *
     * Zapas na `null`: blokada jest relacją między dwoma kontami, więc dla
     * gościa nie ma czego filtrować. W praktyce ta gałąź jest martwa, bo
     * `dolicz()` dla gościa nie liczy niczego — zostaje, żeby ta metoda była
     * poprawna sama z siebie, a nie tylko przy jednym wywołującym.
     *
     * @param  Builder<User>  $query
     */
    private function pomijajZablokowanych(Builder $query, ?User $widz): void
    {
        if ($widz === null) {
            return;
        }

        $widzId = $widz->getKey();

        $query->whereNotExists(function ($sub) use ($widzId): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function ($w) use ($widzId): void {
                    $w->where('blocks.blocker_id', $widzId)
                        ->whereColumn('blocks.blocked_id', 'users.id');
                })
                ->orWhere(function ($w) use ($widzId): void {
                    $w->whereColumn('blocks.blocker_id', 'users.id')
                        ->where('blocks.blocked_id', $widzId);
                });
        });
    }
}
