<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Odstęp pod zdjęciem na karcie wpisu — zgłoszenie właściciela z 11 września:
 * „«z przepisu» i «bigos z cukinii» jest zbyt blisko zdjęcia".
 *
 * CO BYŁO ZMIERZONE (Chromium, `/home` po zalogowaniu, przerwa między TREŚCIĄ
 * bloków; `scripts/odstepy-karty-wpisu.mjs`, okna 1512 px i 390 px):
 *
 *     nagłówek → zdjęcie ...............................  16 px
 *     zdjęcie → pasek „Z przepisu · …" .................   0 px  ← zgłoszenie
 *     karuzela → „N osób zapisało to u siebie" .........   0 px  ← to samo
 *     pasek „Z przepisu" → tematy wpisu ................  40 px
 *
 * Cała karta trzyma 16 px i robi to DOLNYM wcięciem bloku wyżej. Zdjęcie
 * takiego wcięcia nie ma i mieć nie może (idzie od krawędzi do krawędzi), więc
 * para „zdjęcie → blok tekstu" była jedyną, w której odstępu nie deklarowała
 * żadna ze stron. Odstęp dostała strona DOLNA, jako `margin-top` — ten sam
 * wzorzec i to samo uzasadnienie co w rytmie strony przepisu (PR #400).
 *
 * CZEGO TEN TEST PILNUJE
 * Nie wyglądu — od tego jest pomiar w przeglądarce. Pilnuje dwóch rzeczy,
 * które przy następnym przestylowaniu zniknęłyby najciszej:
 *
 *  1. ODSTĘP JEST W ARKUSZU, jako `margin-top` i jako token `--spacing-*`
 *     (nie liczba z palca: ten odstęp oddziela bloki tekstu, więc ma rosnąć
 *     razem z pismem przy czcionce przeglądarki 200 % — druga strona
 *     D-082/D-107).
 *  2. SĄSIEDZTWO W HTML NAPRAWDĘ ISTNIEJE. Reguła wisi na `+`, czyli na tym,
 *     że blok tekstu jest NASTĘPNYM RODZEŃSTWEM bloku zdjęć. Wstawienie
 *     czegokolwiek pomiędzy albo owinięcie zdjęcia dodatkowym `<div>` wyłącza
 *     cały odstęp, nie ruszając ani jednej linii CSS-a — i nic by tego nie
 *     zauważyło. Dlatego test czyta WYRENDEROWANY dokument, a nie plik Blade.
 *
 * KOMENTARZE WYCINAMY, ZANIM COKOLWIEK DOPASUJEMY — nauka z PR #400: nad tą
 * regułą stoi kilkadziesiąt linii komentarza, w którym każdy z jej selektorów
 * pada z nazwy, a wzorzec „selektor, potem `{…}`" nie odróżnia reguły od nazwy
 * klasy WYMIENIONEJ W KOMENTARZU.
 */
class OdstepPodZdjeciemNaKarcieWpisuTest extends TestCase
{
    use RefreshDatabase;

    /** Klasy bloków zdjęć karty — trzy tryby wyświetlania z issue #92. */
    private const BLOKI_ZDJEC = ['photo-grid', 'karuzela', 'kolaz'];

    /**
     * Bloki tekstu, które stają zaraz pod zdjęciem i muszą mieć odstęp górny.
     *
     * Klucz jest opisem po polsku, bo to on pokazuje się w komunikacie błędu
     * i to on mówi, CO się zlepiło na ekranie.
     *
     * @var array<string, string>
     */
    private const BLOKI_POD_ZDJECIEM = [
        'pasek „Z przepisu · <tytuł>"' => 'post-card-recipe',
        'zdanie „N osób zapisało to u siebie w zeszycie"' => 'post-card-zapisy',
    ];

    /** Treść arkusza BEZ komentarzy — patrz opis klasy. */
    private function css(): string
    {
        $tresc = (string) file_get_contents(resource_path('css/app.css'));

        return (string) preg_replace('#/\*.*?\*/#s', '', $tresc);
    }

    /**
     * Deklaracje każdej reguły, której lista selektorów wspomina o `$klasa`
     * jako o bloku STOJĄCYM PO bloku zdjęć.
     *
     * Nie szukamy jednego, z góry umówionego selektora: reguła może być
     * napisana jako `:is(...)` z kilkoma klasami naraz albo rozbita na osobne
     * wiersze i jedno i drugie jest poprawne. Pytamy o to, co naprawdę
     * decyduje: czy w liście selektorów tej reguły stoi ta klasa po którymś
     * z bloków zdjęć, oddzielona `+`.
     *
     * @return list<string>
     */
    private function regulyOdstepuDla(string $klasa): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $this->css(), $reguly, PREG_SET_ORDER);

        $zdjecia = implode('|', array_map(
            static fn (string $blok): string => preg_quote($blok, '/'),
            self::BLOKI_ZDJEC,
        ));

        $znalezione = [];

        foreach ($reguly as $regula) {
            $selektory = (string) preg_replace('/\s+/', ' ', trim($regula[1]));

            // „…jakiś blok zdjęć… + …ta klasa…" — z dowolnym zapisem pomiędzy
            // (`:is(a, b)`, sam `.klasa`, dodatkowy przodek z przodu).
            if (preg_match('/(?:'.$zdjecia.')[^+{]*\+[^+{]*'.preg_quote($klasa, '/').'(?![\w-])/', $selektory) === 1) {
                $znalezione[] = $regula[2];
            }
        }

        return $znalezione;
    }

    public function test_blok_tekstu_pod_zdjeciem_ma_zadeklarowany_odstep_gorny(): void
    {
        foreach (self::BLOKI_POD_ZDJECIEM as $opis => $klasa) {
            $reguly = $this->regulyOdstepuDla($klasa);

            $this->assertNotEmpty(
                $reguly,
                "W resources/css/app.css nie ma reguły, która daje odstęp bloku `.{$klasa}` ".
                'stojącemu zaraz POD zdjęciem wpisu. Bez niej na karcie zlepiają się: zdjęcie '.
                "i {$opis} (zmierzone przed poprawką: 0 px na 1512 px i na 390 px). ".
                'Odstęp należy do JEDNEJ strony pary i jest `margin-top`, nie `margin-bottom` '.
                '(PR #400) — blok zdjęć nie może mieć dolnego wcięcia, bo idzie od krawędzi '.
                'do krawędzi karty.',
            );

            $maOdstep = false;

            foreach ($reguly as $deklaracje) {
                if (preg_match('/(?<![\w-])margin-top\s*:\s*var\((--spacing-\d+)\)/', $deklaracje, $trafienie) !== 1) {
                    continue;
                }

                $this->assertNotSame(
                    '--spacing-0',
                    $trafienie[1],
                    "Odstęp bloku `.{$klasa}` pod zdjęciem jest ustawiony na zero. To jest ".
                    "dokładnie ten stan, przez który zgłoszono usterkę: {$opis} przylegało ".
                    'do dolnej krawędzi zdjęcia.',
                );

                $maOdstep = true;
            }

            $this->assertTrue(
                $maOdstep,
                "Reguła dla `.{$klasa}` pod zdjęciem istnieje, ale nie ustawia ".
                '`margin-top` na token `var(--spacing-N)`. Token, a nie piksele: ten odstęp '.
                'oddziela BLOKI TEKSTU, więc ma rosnąć razem z pismem przy czcionce '.
                'przeglądarki 200 % (druga strona D-082/D-107). Zastane deklaracje: '.
                trim((string) preg_replace('/\s+/', ' ', implode(' | ', $reguly))),
            );
        }
    }

    public function test_pasek_z_przepisu_stoi_zaraz_po_bloku_zdjec_w_wyrenderowanym_html(): void
    {
        $autor = $this->user('autorka');
        $czytelniczka = $this->user('czytelniczka');
        $czytelniczka->following()->attach($autor->getKey(), ['created_at' => now()]);

        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Bigos z cukinii',
            'hero_media_id' => $zdjecie->getKey(),
        ]);

        // WPIS WSKAZUJĄCY PRZEPIS: bez `body` i bez własnych zdjęć (issue
        // #368) — dokładnie karta ze zrzutu od właściciela. Zdjęcie bierze się
        // z relacji do przepisu.
        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => null,
        ]);

        // STRUMIEŃ OBSERWOWANYCH, NIE STRONA WPISU — i to nie jest drobiazg
        // (D-099, D-106). `PostController::show()` doładowuje przepis bez
        // kolumny `hero_media_id`, więc na stronie samego wpisu zdjęcia
        // przepisu NIE MA i pasek „Z przepisu" stoi tam pod nagłówkiem.
        // Sprawdzana para bloków renderuje się w feedzie, który ładuje
        // `recipe.heroMedia` (`App\Domain\Feed\FollowingFeed`).
        $html = $this->actingAs($czytelniczka)
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        $this->assertSasiadujeZeZdjeciem($html, 'post-card-recipe', 'pasek „Z przepisu · Bigos z cukinii"');
    }

    public function test_zdanie_o_zapisach_stoi_zaraz_po_bloku_zdjec_w_wyrenderowanym_html(): void
    {
        $autorka = $this->user('gotujaca');
        $ktosInny = $this->user('zapisujaca');

        $zdjecie = Media::factory()->create(['owner_id' => $autorka->getKey()]);

        $wpis = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'body' => null,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        // LICZBĘ ZAPISÓW WIDZI AUTOR SWOJEGO WPISU OD PIERWSZEGO ZAPISU, a ktoś
        // inny dopiero od trzech (`ZapisyWpisu::PROG_DLA_OBCYCH`, D-081).
        // Dlatego oglądamy to jako autorka i wystarczy jeden cudzy zeszyt —
        // bez tego blok w ogóle się nie renderuje i test sprawdzałby kartę,
        // na której mierzonej pary NIE MA.
        $zeszyt = Collection::create([
            'owner_id' => $ktosInny->getKey(),
            'name' => 'Na potem',
            'visibility' => 'private',
            'is_default' => false,
        ]);
        $zeszyt->posts()->attach($wpis->getKey());

        $html = $this->actingAs($autorka)
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        $this->assertSasiadujeZeZdjeciem($html, 'post-card-zapisy', 'zdanie „N osób zapisało to u siebie w zeszycie"');
    }

    /**
     * Czy element o klasie `$klasa` jest NASTĘPNYM RODZEŃSTWEM bloku zdjęć.
     *
     * Czytamy dokument, nie tekst: `previousElementSibling` w XPath to
     * `preceding-sibling::*[1]`. Wzorzec na `</div>` obok `<p class=…>` łapałby
     * dowolny zamykany znacznik i przechodziłby także wtedy, gdyby zdjęcie
     * zostało owinięte czymś jeszcze — czyli dokładnie wtedy, gdy reguła
     * z `+` przestaje działać.
     */
    private function assertSasiadujeZeZdjeciem(string $html, string $klasa, string $opis): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $bloki = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$klasa} ')]");

        $this->assertInstanceOf(\DOMNodeList::class, $bloki);
        $this->assertGreaterThan(
            0,
            $bloki->length,
            "Na zmierzonym ekranie nie ma ani jednego bloku `.{$klasa}` ({$opis}). ".
            'Test nie sprawdziłby wtedy niczego — pusty ekran przechodzi każdą asercję. '.
            'Popraw przygotowanie danych, nie asercję.',
        );

        $blok = $bloki->item(0);
        $this->assertInstanceOf(\DOMElement::class, $blok);

        $poprzednie = $xpath->query('preceding-sibling::*[1]', $blok);
        $this->assertInstanceOf(\DOMNodeList::class, $poprzednie);

        $poprzednik = $poprzednie->item(0);
        $klasyPoprzednika = $poprzednik instanceof \DOMElement
            ? preg_split('/\s+/', trim($poprzednik->getAttribute('class'))) ?: []
            : [];

        $this->assertNotEmpty(
            array_intersect(self::BLOKI_ZDJEC, $klasyPoprzednika),
            "Blok `.{$klasa}` ({$opis}) nie stoi już zaraz po bloku zdjęć — poprzedza go ".
            ($poprzednik instanceof \DOMElement
                ? '<'.$poprzednik->tagName.' class="'.$poprzednik->getAttribute('class').'">'
                : 'nic').
            '. Odstęp pod zdjęciem daje reguła z `+` w resources/css/app.css, więc wstawienie '.
            'czegokolwiek pomiędzy albo owinięcie zdjęcia dodatkowym znacznikiem wyłącza ten '.
            'odstęp i pasek znów przykleja się do zdjęcia. Jeśli układ karty naprawdę ma się '.
            'zmienić, popraw NAJPIERW regułę w arkuszu, a potem ten test.',
        );
    }
}
