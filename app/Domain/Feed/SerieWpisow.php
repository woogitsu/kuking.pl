<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Post;
use App\Support\Odmiana;

/**
 * Zwijanie serii w Obserwowanych (issue #1812, reguła z AGENTS.md §8 / D-275:
 * „dopuszczalne jest tylko zwinięcie serii wpisów jednej osoby, bez zmiany
 * kolejności").
 *
 * Więcej niż `WIDOCZNE_Z_SERII` kolejnych wpisów tej samej osoby — albo,
 * od połączenia tagów z osobami na Starcie (D-277), tego samego tagu — stoi
 * jako pierwsze dwa wpisy i `<details>` z resztą: „{nazwa}: jeszcze N wpisów".
 * Kolejność na stronie jest dokładnie ta, którą oddał `FollowingFeed`, i żaden
 * wpis nie znika: zwinięte są w HTML-u, otwierają się bez JavaScriptu.
 *
 * Działa w obrębie jednej strony — seria rozcięta przez „Pokaż więcej"
 * zaczyna się na następnej stronie od nowa. To świadome: grupowanie przez
 * granicę kursora wymagałoby pamiętania stanu poprzedniej strony, a strona
 * i tak pokazuje wszystko, co ma.
 */
final class SerieWpisow
{
    public const WIDOCZNE_Z_SERII = 2;

    /**
     * @param  iterable<Post>  $posty  w kolejności listy
     * @return list<array{widoczne: list<Post>, zwiniete: list<Post>, podpis: ?string}>
     */
    public static function grupuj(iterable $posty): array
    {
        $serie = [];
        $biezaca = [];
        $klucz = null;

        foreach ($posty as $post) {
            $kluczPostu = self::klucz($post);

            if ($biezaca !== [] && $kluczPostu !== $klucz) {
                $serie[] = self::zamknij($biezaca);
                $biezaca = [];
            }

            $biezaca[] = $post;
            $klucz = $kluczPostu;
        }

        if ($biezaca !== []) {
            $serie[] = self::zamknij($biezaca);
        }

        return $serie;
    }

    /**
     * Wpis z samego tagu (podpisany „Z tagu: …") należy do serii tagu, każdy
     * inny — do serii autora. Wpis obserwowanej osoby z obserwowanym tagiem
     * jest więc w serii OSOBY (przyszedł od osoby, D-277).
     */
    private static function klucz(Post $post): string
    {
        if ($post->relationLoaded('zrodloTematu') && $post->zrodloTematu !== null) {
            return 'tag:'.$post->zrodloTematu->getKey();
        }

        return 'autor:'.$post->author_id;
    }

    /**
     * @param  list<Post>  $seria
     * @return array{widoczne: list<Post>, zwiniete: list<Post>, podpis: ?string}
     */
    private static function zamknij(array $seria): array
    {
        if (count($seria) <= self::WIDOCZNE_Z_SERII) {
            return ['widoczne' => $seria, 'zwiniete' => [], 'podpis' => null];
        }

        $zwiniete = array_slice($seria, self::WIDOCZNE_Z_SERII);
        $ile = count($zwiniete);
        $pierwszy = $seria[0];

        // Nazwa dosłownie, przed dwukropkiem — bez odmiany (COPY_STYLE,
        // 11 września 2026). Tag po słowie „Tag", nie „temat" (JednoSlowoNaTagiTest).
        $nazwa = $pierwszy->relationLoaded('zrodloTematu') && $pierwszy->zrodloTematu !== null
            ? 'Tag '.$pierwszy->zrodloTematu->name
            : $pierwszy->author->displayName();

        return [
            'widoczne' => array_slice($seria, 0, self::WIDOCZNE_Z_SERII),
            'zwiniete' => $zwiniete,
            'podpis' => "{$nazwa}: jeszcze {$ile} ".Odmiana::rzeczownik($ile, 'wpis', 'wpisy', 'wpisów'),
        ];
    }
}
