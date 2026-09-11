<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\HeroPick;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Kolaż zdjęć w hero strony powitalnej — cztery zdjęcia, które gość widzi,
 * zanim cokolwiek kliknie.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  JEDNA KLASA, BO TO JEDNO PYTANIE
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Trzy miejsca pytają tu o to samo, tylko z różnych stron:
 *
 *   `doKolazu()`         — co pokazać gościowi TERAZ,
 *   `kandydaci()`        — z czego gospodarz może wybierać w panelu,
 *   `dopuszczZdjecia()`  — co wolno zapisać jako wybór.
 *
 * Wszystkie trzy muszą odpowiadać na jedno pytanie „czy to zdjęcie wolno
 * pokazać nieznajomemu" DOKŁADNIE TAK SAMO. Trzy kopie tego filtru rozjadą
 * się przy pierwszej zmianie i rozjadą się po cichu — a tutaj rozjazd znaczy
 * cudze prywatne zdjęcie na stronie powitalnej. Stąd jedna prywatna metoda
 * `wpisyDoPokazania()` i trzy publiczne wejścia do niej.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  FILTR WIDOCZNOŚCI DZIAŁA PRZY KAŻDYM WYŚWIETLENIU, NIE PRZY ZAPISIE
 * ─────────────────────────────────────────────────────────────────────────
 *
 * `hero_picks` trzyma wskazanie, nie zgodę. Między wskazaniem a wyświetleniem
 * może się wydarzyć wszystko: autor przełącza wpis na „tylko dla
 * obserwujących" albo „prywatny", moderator go chowa, konto zostaje
 * zawieszone, zablokowane albo zgłoszone do usunięcia, zdjęcie idzie do
 * skasowania. Żadne z tych zdarzeń nie kasuje wiersza w `hero_picks` i żadne
 * nie ma takiego obowiązku — bo filtr i tak liczy się od nowa przy każdym
 * wejściu na stronę.
 *
 * `publiclyVisible()` + `tylkoOdAktywnychAutorow()` to ta sama para, której
 * używa `DailyBoard` i `DiscoverFeed` — czyli wszędzie tam, gdzie serwis SAM
 * PODSUWA komuś cudzą treść. Próg jest tam surowszy niż w politykach (te
 * dopuszczają jeszcze konto zawieszone), bo to jest promowanie, a nie wejście
 * na adres wpisu. Kolaż w hero jest z tej rodziny i najdalej w niej wysunięty.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  STAN ZAPASOWY: DOBÓR AUTOMATYCZNY, A W OSTATECZNOŚCI BRAK KOLAŻU
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Kolaż ma zawsze DOKŁADNIE `SLOTOW` zdjęć albo nie ma go wcale. Trzeciej
 * możliwości nie ma i to jest tu decyzja, nie uproszczenie: układ kolażu
 * (dwa wysokie kafle zazębione z dwoma niskimi) jest ZAZĘBIONY — wyjęcie
 * z niego jednego kafla nie zostawia mniejszego kolażu, tylko dziurę
 * w kształcie tego kafla. Ekran, na którym widać dziurę, mówi gościowi
 * „coś się tu zepsuło" w pierwszej sekundzie kontaktu z serwisem.
 *
 * Kolejność decyzyjna jest więc taka:
 *
 *   1. zdjęcia wskazane przez gospodarza, które WCIĄŻ wolno pokazać,
 *   2. uzupełnienie do czterech najnowszymi publicznymi zdjęciami —
 *      najpierw po jednym od osoby, a gdy to nie starcza, po dwa
 *      (uzasadnienie przy `dobraneAutomatycznie()`),
 *   3. jeśli i tak nie ma czterech — kolaż nie renderuje się wcale,
 *      a hero wraca do jednej kolumny tekstu, czyli do układu sprzed
 *      tej zmiany.
 *
 * DLACZEGO UZUPEŁNIAMY, A NIE POKAZUJEMY „TYLE, ILE GOSPODARZ WSKAZAŁ"
 * `DailyBoard` robi inaczej — tam wybór redakcyjny WYŁĄCZA dobór
 * automatyczny, bo tablica składa się z osobnych kart i trzy karty zamiast
 * czterech nie są usterką. Kolaż nie ma tej własności (patrz akapit wyżej),
 * a przede wszystkim: uzupełnianie jest jedyną odpowiedzią na „wybrane
 * zdjęcia zniknęły", która nie wymaga od nikogo zauważenia, że zniknęły.
 * Usunięte konto, wpis schowany przez moderację i zdjęcie skasowane przez
 * autora to zdarzenia, o których gospodarz dowiaduje się najwcześniej
 * następnego dnia — a strona powitalna działa cały czas.
 *
 * Panel mówi to gospodarzowi wprost, jednym zdaniem, zamiast zostawiać go
 * ze zdziwieniem „wskazałem dwa, a widzę cztery".
 *
 * DLACZEGO TRZECI STOPIEŃ TO BRAK KOLAŻU, A NIE ZDJĘCIA ZASTĘPCZE
 * Bo cały argument tej strony brzmi „tu są prawdziwe zdjęcia prawdziwych
 * ludzi". Grafika poglądowa w tym miejscu nie jest ozdobą — jest
 * zaprzeczeniem zdania, które stoi obok niej. Serwis, w którym nie ma
 * jeszcze czterech publicznych zdjęć, ma powiedzieć prawdę: pustą prawą
 * stronę hero, czyli dokładnie to, co strona ma dzisiaj.
 */
final class HeroKolaz
{
    /**
     * Ile kafli ma kolaż. Zmiana tej liczby wymaga zmiany układu w CSS
     * (`.hero-kolaz` w `resources/css/strony-publiczne.css`) — obszary
     * siatki są tam nazwane po kolei i jest ich dokładnie tyle.
     */
    public const SLOTOW = 4;

    /**
     * Ile najnowszych wpisów oglądamy przy doborze automatycznym.
     *
     * Zapas, nie limit wyniku: z tych wpisów bierzemy najwyżej jedno zdjęcie
     * od osoby i najwyżej cztery razem. Zapas jest potrzebny, bo jeden bardzo
     * aktywny autor potrafi zająć cały początek listy — a `COLD_START.md` §4.2
     * każe gospodarzowi publikować codziennie, więc to jest wzorzec wpisany
     * w plan startu, nie anomalia.
     */
    private const ZAPAS_AUTOMATU = 40;

    /**
     * Zdjęcia do kolażu — gotowe do wyrenderowania albo pusta kolekcja.
     *
     * Pusta kolekcja znaczy „nie ma czego pokazać" i widok ma wtedy NIE
     * renderować kolażu w ogóle. Kolekcja niepusta ma zawsze dokładnie
     * `SLOTOW` pozycji.
     *
     * @return Collection<int, array{media: Media, autor: User, wybrane: bool}>
     */
    public function doKolazu(): Collection
    {
        $kafle = $this->wskazaneRecznie();

        $uzyciAutorzy = $kafle->pluck('autor.id')->all();
        $uzyteZdjecia = $kafle->pluck('media.id')->all();

        if ($kafle->count() < self::SLOTOW) {
            $kafle = $kafle->concat(
                $this->dobraneAutomatycznie(
                    self::SLOTOW - $kafle->count(),
                    $uzyciAutorzy,
                    $uzyteZdjecia,
                ),
            );
        }

        return $kafle->count() === self::SLOTOW
            ? $kafle->values()
            : new Collection;
    }

    /**
     * Wpisy, z których gospodarz może wybierać w panelu — najnowsze
     * publiczne, od aktywnych kont, z co najmniej jednym gotowym zdjęciem.
     *
     * @return Collection<int, Post>
     */
    public function kandydaci(int $limit = 60): Collection
    {
        return $this->wpisyDoPokazania()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->filter(fn (Post $post) => $this->gotoweZdjecia($post)->isNotEmpty())
            ->values();
    }

    /**
     * BRAMKA ZAPISU: które z podanych zdjęć wolno w ogóle wskazać i przy
     * którym wpisie każde z nich wisi.
     *
     * Zwraca mapę `media_id => post_id` wyłącznie dla zdjęć, które w tej
     * chwili przechodzą filtr widoczności. Wszystko inne — zdjęcie
     * z prywatnego wpisu, z wpisu schowanego przez moderację, z konta
     * zawieszonego, zdjęcie jeszcze nieprzetworzone albo przejęte do
     * skasowania, wreszcie identyfikator wzięty z sufitu — po prostu nie
     * trafia do wyniku.
     *
     * DLACZEGO TO JEST TUTAJ, A NIE W REGULE WALIDACJI
     * `exists:media,id` odpowiedziałoby na pytanie „czy taki wiersz jest",
     * a pytanie brzmi „czy TEN widz ma prawo to zobaczyć" — i odpowiedź na
     * nie zmienia się w czasie. Reguła walidacji byłaby tu drugą, słabszą
     * kopią tego samego filtru, czyli dokładnie tym, przed czym ostrzega
     * docblock klasy.
     *
     * @param  list<string>  $mediaIds
     * @return array<string, string>
     */
    public function dopuszczZdjecia(array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $wpisy = $this->wpisyDoPokazania()
            ->whereHas('media', fn ($query) => $query->whereIn('media.id', $mediaIds))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        $dopuszczone = [];

        foreach ($wpisy as $wpis) {
            foreach ($this->gotoweZdjecia($wpis) as $zdjecie) {
                $id = (string) $zdjecie->getKey();

                // `in_array`, nie `isset`: to samo zdjęcie bywa przypięte do
                // kilku wpisów, a wystarczy JEDEN publiczny, żeby wolno je
                // było pokazać. Pierwszy znaleziony wygrywa i to on zostaje
                // zapisany jako kontekst widoczności.
                if (in_array($id, $mediaIds, true) && ! isset($dopuszczone[$id])) {
                    $dopuszczone[$id] = (string) $wpis->getKey();
                }
            }
        }

        return $dopuszczone;
    }

    /**
     * Zdjęcia wskazane ręcznie, w kolejności z panelu, po odsianiu tych,
     * których już nie wolno pokazać.
     *
     * @return Collection<int, array{media: Media, autor: User, wybrane: bool}>
     */
    private function wskazaneRecznie(): Collection
    {
        /** @var Collection<int, HeroPick> $picks */
        $picks = HeroPick::query()->orderBy('position')->orderBy('id')->get();

        if ($picks->isEmpty()) {
            return new Collection;
        }

        $wpisy = $this->wpisyDoPokazania()
            ->whereIn('id', $picks->pluck('post_id')->unique()->all())
            ->with(['author.profile', 'media'])
            ->get()
            ->keyBy(fn (Post $post) => (string) $post->getKey());

        $kafle = new Collection;

        foreach ($picks as $pick) {
            if ($kafle->count() >= self::SLOTOW) {
                break;
            }

            $wpis = $wpisy->get((string) $pick->post_id);

            if ($wpis === null) {
                continue;
            }

            $zdjecie = $this->gotoweZdjecia($wpis)
                ->first(fn (Media $media) => (string) $media->getKey() === (string) $pick->media_id);

            if ($zdjecie === null) {
                continue;
            }

            $kafle->push(['media' => $zdjecie, 'autor' => $wpis->author, 'wybrane' => true]);
        }

        return $kafle;
    }

    /**
     * Uzupełnienie do pełnego kolażu — najnowsze publiczne zdjęcia.
     *
     * DWA PRZEBIEGI, BO „JEDNO OD OSOBY" JEST PREFERENCJĄ, NIE ŚCIANĄ.
     *
     * Pierwszy przebieg bierze najwyżej JEDNO zdjęcie od osoby — z tego
     * samego powodu co tablica dnia: bez tego jedna aktywna osoba zajmuje
     * cały kolaż i gość odnosi wrażenie, że „tu jest tylko ta pani"
     * (docs/product/COLD_START.md).
     *
     * Drugi przebieg dokłada brakujące kafle, dopuszczając DRUGIE zdjęcie
     * od tej samej osoby — i tylko drugie. Bez niego serwis z trzema
     * aktywnymi osobami nie miałby kolażu W OGÓLE, a dokładnie tak wygląda
     * Kuking w pierwszych tygodniach: `COLD_START.md` zakłada garstkę osób
     * i gospodarza publikującego codziennie. Reguła „jedno od osoby",
     * potraktowana jako ściana, wyłączałaby kolaż dokładnie wtedy, kiedy
     * jest najbardziej potrzebny — na starcie.
     *
     * Twardy limit dwóch zostaje: przy jednej aktywnej osobie kolaż się nie
     * zbierze i to jest właściwa odpowiedź. Cztery zdjęcia jednej osoby
     * pokazane jako „zobacz, co tu się gotuje" byłyby nieprawdą o serwisie.
     *
     * @param  list<string>  $pomijaniAutorzy
     * @param  list<string>  $pomijaneZdjecia
     * @return Collection<int, array{media: Media, autor: User, wybrane: bool}>
     */
    private function dobraneAutomatycznie(int $ile, array $pomijaniAutorzy, array $pomijaneZdjecia): Collection
    {
        $wpisy = $this->wpisyDoPokazania()
            ->when(
                $pomijaniAutorzy !== [],
                fn ($query) => $query->whereNotIn('author_id', $pomijaniAutorzy),
            )
            ->with(['author.profile', 'media'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::ZAPAS_AUTOMATU)
            ->get();

        $kafle = new Collection;

        // Ile zdjęć wzięliśmy już od każdej osoby — licznik wspólny dla obu
        // przebiegów, więc drugi widzi wynik pierwszego.
        $odOsoby = [];

        // Zdjęcia już użyte: wskazane ręcznie (parametr) plus wzięte
        // w pierwszym przebiegu. Bez tej drugiej części drugi przebieg
        // potrafiłby wstawić to samo zdjęcie dwa razy.
        $uzyte = $pomijaneZdjecia;

        foreach ([1, 2] as $limitNaOsobe) {
            foreach ($wpisy as $wpis) {
                if ($kafle->count() >= $ile) {
                    break 2;
                }

                $autor = (string) $wpis->author_id;

                if (($odOsoby[$autor] ?? 0) >= $limitNaOsobe) {
                    continue;
                }

                $zdjecie = $this->gotoweZdjecia($wpis)
                    ->first(fn (Media $media) => ! in_array((string) $media->getKey(), $uzyte, true));

                if ($zdjecie === null) {
                    continue;
                }

                $odOsoby[$autor] = ($odOsoby[$autor] ?? 0) + 1;
                $uzyte[] = (string) $zdjecie->getKey();
                $kafle->push(['media' => $zdjecie, 'autor' => $wpis->author, 'wybrane' => false]);
            }
        }

        return $kafle;
    }

    /**
     * JEDYNA definicja „wpis, którego zdjęcie wolno pokazać nieznajomemu".
     *
     * @return \Illuminate\Database\Eloquent\Builder<Post>
     */
    private function wpisyDoPokazania(): \Illuminate\Database\Eloquent\Builder
    {
        return Post::query()
            ->publiclyVisible()
            ->tylkoOdAktywnychAutorow()
            ->with(['author.profile', 'media']);
    }

    /**
     * Zdjęcia wpisu, które wolno serwować — wyłącznie `ready`.
     *
     * `Media::isReady()`, nie warunek w zapytaniu: ta sama reguła obowiązuje
     * w widokach (AGENTS.md §7) i ma tu brzmieć tak samo. Zdjęcie `pending`
     * albo `processing` nie ma jeszcze zdjętego EXIF-u, a `deleted` jest już
     * przejęte do skasowania.
     *
     * @return Collection<int, Media>
     */
    private function gotoweZdjecia(Post $post): Collection
    {
        return $post->media->filter(fn (Media $media) => $media->isReady())->values();
    }
}
