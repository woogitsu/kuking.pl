<?php

declare(strict_types=1);

namespace App\Domain\Tags\Actions;

use App\Domain\Tags\FiltrWulgaryzmow;
use App\Domain\Tags\TagMutationLock;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Support\LimityTagow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Zamienia to, co ktoś WPISAŁ jako tagi (wolny tekst z formularza), na
 * prawdziwe wiersze `Tag` — tworząc nowe, jeśli trzeba.
 *
 * DLACZEGO TO JEST OSOBNA AKCJA, A NIE KOD W KONTROLERZE
 * `PostController` (formularz bez JS), `PublishPost` i `EditPost` wszystkie
 * potrzebują TEGO SAMEGO: nazwa → tag. Napisanie tego trzy razy byłoby
 * dokładnie tym rodzajem rozjazdu, przed którym ostrzega `LimityZdjec`
 * (ta sama liczba/reguła przepisana ręcznie w kilku miejscach rozjeżdża
 * się osobno w każdym).
 *
 * UUID W UKRYTYM POLU FORMULARZA TO NIE AUTORYZACJA — A TU NAWET NIE MA UUID.
 * `tag_names[]` przychodzi od klienta jako WOLNY TEKST, więc ta klasa jest
 * jedyną bramką: bez niej dałoby się opublikować wpis z tagiem o dowolnej
 * treści, długości czy znakach sterujących, bo formularz bez JavaScriptu
 * nie ma jak wymusić niczego po swojej stronie. Każda reguła (długość,
 * dozwolone znaki, wulgaryzmy, limit 5) jest więc sprawdzana TUTAJ, drugi
 * raz, niezależnie od tego, co pokazał wcześniej interaktywny krok
 * „Dodaj"/„Usuń" w `PostController` — ten sam rodzaj bramki, jaką dawniej
 * (issue #31, usunięte w D-021) `PublishPost` stawiał niezależnie przed
 * `topic_id`, mimo że formularz już go ograniczał do zamkniętej listy.
 *
 * NIEPOPRAWNE NAZWY SĄ CICHO POMIJANE, NIE ODRZUCAJĄ CAŁEJ PUBLIKACJI.
 * Ten sam wybór, jaki dawniej działał dla nieznanego/wycofanego `topic_id`:
 * „wpis bez tego tagu jest w pełni poprawny, więc lepiej opublikować bez
 * niego niż odmówić publikacji z powodu jednej złej nazwy wśród pięciu
 * poprawnych". WYJĄTEK: samą LICZBĘ tagów (>5) traktujemy twardo — to jest
 * nadużycie warte przerwania, nie literówka warta wybaczenia (SPEC/R1 §4:
 * limit ma być egzekwowany w warstwie domenowej, nie tylko podpowiedzią
 * w interfejsie).
 */
final class ResolveTagsForPost
{
    /** Ile razy najwyżej próbujemy zapisać nowy tag, zanim oddamy błąd kolizji slugu. */
    public const MAKSYMALNA_LICZBA_PROB = 5;

    /** Nazwa indeksu `UNIQUE` na `tags.slug` (migracja `create_tags_tables`). */
    public const INDEKS_SLUGU = 'tags_slug_unique';

    /**
     * @param  list<string>  $rawNames  to, co przyszło z hidden inputs `tag_names[]`
     * @return list<Tag> unikalne (po id), w kolejności pierwszego wystąpienia
     */
    public function handle(array $rawNames, array $preservedHiddenIds = []): array
    {
        return DB::transaction(function () use ($rawNames, $preservedHiddenIds): array {
            TagMutationLock::forPost();

            return $this->resolve($rawNames, $preservedHiddenIds, false);
        });
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $preservedHiddenIds  wyłącznie istniejące powiązania wpisu z DB
     * @return list<Tag>
     */
    public function handleTokens(array $tokens, array $preservedHiddenIds = []): array
    {
        return DB::transaction(function () use ($tokens, $preservedHiddenIds): array {
            TagMutationLock::forPost();

            return $this->resolve($tokens, $preservedHiddenIds, true);
        });
    }

    /** @return list<Tag> */
    private function resolve(array $rawNames, array $preservedHiddenIds, bool $tokens): array
    {
        $poprawne = [];

        foreach ($rawNames as $surowa) {
            if (! is_string($surowa)) {
                continue;
            }

            $znormalizowana = Tag::znormalizujNazwe($surowa);

            if ($znormalizowana === ''
                || ($tokens ? mb_strlen($znormalizowana) > 40 : ! LimityTagow::dlugoscOk($znormalizowana))
                || ! LimityTagow::pasujeDoWzorca($znormalizowana)
            ) {
                continue;
            }

            // Dedup po znormalizowanej nazwie NA TYM ETAPIE — dwa wpisane
            // warianty pisowni tej samej rzeczy ("Sernik" i "sernik") nie
            // mają liczyć się jako dwa tagi, zanim jeszcze cokolwiek
            // trafi do bazy.
            $poprawne[$znormalizowana] = trim($surowa);
        }

        $tagi = [];

        foreach ($poprawne as $nazwa) {
            $tag = $tokens ? Tag::query()->where('slug', Tag::znormalizujNazwe($nazwa))->first() : null;
            $tag = $tag === null ? $this->znajdzAlboUtworz($nazwa) : $tag->tagKanoniczny();

            if ($tag !== null && ($tag->isActive() || in_array((string) $tag->getKey(), $preservedHiddenIds, true))) {
                $tagi[$tag->getKey()] = $tag;
            }
            // Dopiero kanoniczne ID liczą się do limitu. Sześć aliasów
            // jednego taga jest jednym tagiem. Transakcja cofa nowe nazwy.
            if (count($tagi) > LimityTagow::maksTagowNaWpis()) {
                throw new BladDlaCzlowieka(LimityTagow::komunikatZaDuzoTagow());
            }
        }

        return array_values($tagi);
    }

    private function znajdzAlboUtworz(string $nazwa): ?Tag
    {
        $znormalizowana = Tag::znormalizujNazwe($nazwa);

        $tag = Tag::query()->where('normalized_name', $znormalizowana)->first();

        if ($tag !== null) {
            return $tag->tagKanoniczny();
        }

        // Dokładny alias — SPEC §1.8 krok 3: "jeśli znajduje dokładny alias,
        // używa istniejącego tagu kanonicznego i nie tworzy nowego".
        $alias = TagAlias::query()->where('normalized_alias', $znormalizowana)->first();

        if ($alias !== null) {
            return $alias->tag?->tagKanoniczny();
        }

        // Nowy tag — ale nie każda nazwa ma prawo powstać (R1 §8: tag jest
        // publiczną etykietą indeksowaną przez wyszukiwarkę, wyższa ekspozycja
        // niż wolny tekst wpisu). Cicho pomijamy, nie rzucamy — patrz
        // komentarz klasy.
        if (! LimityTagow::dlugoscOk($znormalizowana) || FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($znormalizowana)) {
            return null;
        }

        $created = $this->zapiszNowyTag($nazwa, $znormalizowana);

        // Status ma DEFAULT w bazie, więc nowo utworzony model bez tego
        // atrybutu nie może udawać ukrytego taga przy kontroli isActive().
        return $created->refresh()->tagKanoniczny();
    }

    /**
     * Tworzy tag w SAVEPOINT-cie i rozstrzyga dwa rodzaje kolizji z równoległego
     * wpisu (audyt DB-004, ten sam wyścig co w `GenerateRecipeSlug::zapisz()`):
     *
     * - TA SAMA nazwa (`normalized_name UNIQUE`) — ktoś właśnie założył ten sam
     *   tag, więc bierzemy jego wiersz: ta sama nazwa to ZAWSZE ten sam tag,
     *   nigdy drugi z sufiksem;
     * - RÓŻNA nazwa o tym samym slugu (`tags_slug_unique`, np. „żurek" i „zurek")
     *   — to dwa tagi, drugi dostaje następny slug z numerem; limit prób jest
     *   twardy.
     *
     * Savepoint (zagnieżdżony `DB::transaction`) cofa tylko nieudany insert,
     * więc nadrzędny zapis wpisu żyje dalej; bez niego błąd 23505 psuje całą
     * transakcję PostgreSQL.
     */
    private function zapiszNowyTag(string $nazwa, string $znormalizowana): Tag
    {
        $zajete = [];

        for ($proba = 1; ; $proba++) {
            $slug = $this->wolnySlug(Tag::slugDlaNazwy($nazwa), $zajete);

            try {
                return DB::transaction(static fn (): Tag => Tag::query()->create([
                    'normalized_name' => $znormalizowana,
                    'name' => $nazwa,
                    'slug' => $slug,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                $istniejacy = Tag::query()->useWritePdo()->where('normalized_name', $znormalizowana)->first();

                if ($istniejacy !== null) {
                    return $istniejacy;
                }

                if ($proba >= self::MAKSYMALNA_LICZBA_PROB || ! str_contains($e->getMessage(), self::INDEKS_SLUGU)) {
                    throw $e;
                }

                $zajete[] = $slug;
            }
        }
    }

    /**
     * Wzorem `GenerateRecipeSlug` — sufiks numeryczny przy kolizji.
     *
     * @param  list<string>  $pomijane  slugi, które już odbiły się o `UNIQUE` w tej próbie
     */
    private function wolnySlug(string $baza, array $pomijane = []): string
    {
        // CHECK `^[a-z0-9-]{1,40}$`: baza z sufiksem „-N" musi się zmieścić
        // w 40 znakach, więc przycinamy ją pod długość sufiksu (bez końcowego „-").
        $slug = $this->przytnij($baza, 40);
        $sufiks = 2;

        while (in_array($slug, $pomijane, true) || Tag::query()->where('slug', $slug)->exists()) {
            $dopisek = '-'.$sufiks;
            $slug = $this->przytnij($baza, 40 - strlen($dopisek)).$dopisek;
            $sufiks++;
        }

        return $slug;
    }

    private function przytnij(string $baza, int $dlugosc): string
    {
        $przyciety = rtrim(substr($baza, 0, $dlugosc), '-');

        return $przyciety === '' ? 'tag' : $przyciety;
    }
}
