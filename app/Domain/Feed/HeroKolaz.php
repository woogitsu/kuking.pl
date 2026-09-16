<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\HeroPick;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Prawdziwe publiczne zdjęcia na powitanie: wybór gospodarza, potem automat.
 * Każdy odczyt ponownie sprawdza widoczność, aktywność autora i stan ready.
 * Pokazujemy od jednego do czterech dostępnych zdjęć; brak pełnej czwórki
 * nie usuwa całego kolażu (#632). Widok dopasowuje siatkę do liczby kafli.
 */
final class HeroKolaz
{
    /**
     * Maksymalna liczba kafli; CSS obsługuje również zestawy 1–3.
     */
    public const SLOTOW = 4;

    /**
     * Ile najnowszych wpisów oglądamy przy doborze automatycznym.
     *
     * Zapas, nie limit wyniku: z tych wpisów bierzemy najwyżej dwa zdjęcia
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
     * renderować kolażu w ogóle. Kolekcja niepusta ma od jednej do
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

        return $kafle->values();
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
     * od tej samej osoby — i tylko drugie. Dzięki temu automat może
     * uzupełnić kolaż przy niewielkiej liczbie aktywnych autorów.
     *
     * Twardy limit dwóch zostaje. Przy jednej aktywnej osobie pokażemy
     * mniejszy kolaż, podpisany jej nazwą, zamiast ukrywać dostępne zdjęcia.
     *
     * @param  list<string>  $pomijaniAutorzy
     * @param  list<string>  $pomijaneZdjecia
     * @return Collection<int, array{media: Media, autor: User, wybrane: bool}>
     */
    private function dobraneAutomatycznie(int $ile, array $pomijaniAutorzy, array $pomijaneZdjecia): Collection
    {
        $wpisy = $this->wpisyDoPokazania()
            ->whereHas('media', fn ($query) => $query->where('status', Media::STATUS_READY))
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
     * @return Builder<Post>
     */
    private function wpisyDoPokazania(): Builder
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
