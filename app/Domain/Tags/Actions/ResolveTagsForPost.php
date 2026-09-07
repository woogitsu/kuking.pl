<?php

declare(strict_types=1);

namespace App\Domain\Tags\Actions;

use App\Domain\Tags\FiltrWulgaryzmow;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Support\LimityTagow;

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
 * „Dodaj"/„Usuń" w `PostController` — dokładnie tak, jak `PublishPost`
 * niezależnie re-weryfikuje `topic_id` mimo że formularz już go ograniczał
 * do zamkniętej listy.
 *
 * NIEPOPRAWNE NAZWY SĄ CICHO POMIJANE, NIE ODRZUCAJĄ CAŁEJ PUBLIKACJI.
 * Ten sam wybór co przy nieznanym/wycofanym `topic_id` w `PublishPost`:
 * „wpis bez tego tagu jest w pełni poprawny, więc lepiej opublikować bez
 * niego niż odmówić publikacji z powodu jednej złej nazwy wśród pięciu
 * poprawnych". WYJĄTEK: samą LICZBĘ tagów (>5) traktujemy twardo — to jest
 * nadużycie warte przerwania, nie literówka warta wybaczenia (SPEC/R1 §4:
 * limit ma być egzekwowany w warstwie domenowej, nie tylko podpowiedzią
 * w interfejsie).
 */
final class ResolveTagsForPost
{
    /**
     * @param  list<string>  $rawNames  to, co przyszło z hidden inputs `tag_names[]`
     * @return list<Tag> unikalne (po id), w kolejności pierwszego wystąpienia
     */
    public function handle(array $rawNames): array
    {
        $poprawne = [];

        foreach ($rawNames as $surowa) {
            if (! is_string($surowa)) {
                continue;
            }

            $znormalizowana = Tag::znormalizujNazwe($surowa);

            if ($znormalizowana === ''
                || ! LimityTagow::dlugoscOk($znormalizowana)
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

        // Limit liczony na unikalnych WPISACH na tym etapie — druga,
        // dokładniejsza kontrola (po unikalnych `tag_id`) jest niżej, bo
        // dwie różne pisownie mogą rozwiązać się do JEDNEGO kanonicznego
        // tagu przez alias (LimityTagow, R1 §4).
        if (count($poprawne) > LimityTagow::maksTagowNaWpis()) {
            throw new BladDlaCzlowieka(LimityTagow::komunikatZaDuzoTagow());
        }

        $tagi = [];

        foreach ($poprawne as $nazwa) {
            $tag = $this->znajdzAlboUtworz($nazwa);

            if ($tag !== null) {
                $tagi[$tag->getKey()] = $tag;
            }
        }

        if (count($tagi) > LimityTagow::maksTagowNaWpis()) {
            // Dwie różne pisownie ROZWIĄZAŁY SIĘ do tego samego kanonicznego
            // tagu (alias) — to zmniejsza liczbę, nigdy nie zwiększa, więc
            // to gałąź teoretyczna. Zostawiona jako siatka bezpieczeństwa,
            // nie usuwamy jej "bo i tak nigdy się nie wykona".
            throw new BladDlaCzlowieka(LimityTagow::komunikatZaDuzoTagow());
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
        if (FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($znormalizowana)) {
            return null;
        }

        // `firstOrCreate` po `normalized_name`: bezpieczne przy dwóch prawie
        // jednoczesnych żądaniach tworzących ten sam nowy tag (drugie już
        // go zastanie). Ten sam wzorzec co `Ingredient::findOrCreateByName()`.
        return Tag::query()->firstOrCreate(
            ['normalized_name' => $znormalizowana],
            ['name' => $nazwa, 'slug' => $this->wolnySlug(Tag::slugDlaNazwy($nazwa))],
        );
    }

    /** Wzorem `GenerateRecipeSlug` — sufiks numeryczny przy kolizji. */
    private function wolnySlug(string $baza): string
    {
        $slug = $baza;
        $sufiks = 2;

        while (Tag::query()->where('slug', $slug)->exists()) {
            $slug = $baza.'-'.$sufiks;
            $sufiks++;
        }

        return $slug;
    }
}
