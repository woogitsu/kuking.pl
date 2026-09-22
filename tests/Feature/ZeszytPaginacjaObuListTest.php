<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dwie listy zeszytu paginują się NIEZALEŻNIE (#646).
 *
 * Ekran zeszytu ma dwa paginatory na jednym adresie: przepisy pod `page`
 * i zapisane wpisy pod `wpisy`. Do tej pory każdy przycisk „Pokaż więcej"
 * budował adres wyłącznie ze swojego numeru strony — czyli przejście na
 * drugą stronę wpisów cofało przepisy na stronę pierwszą i odwrotnie.
 * Człowiek, który przewinął obie listy, tracił jedną z nich przy każdym
 * kliknięciu i nie miał jak wrócić inaczej niż ręcznym adresem.
 *
 * Ten plik stoi osobno od `ZeszytNiedostepneZapisyTest` (#567) celowo: tamten
 * pilnuje WIDOCZNOŚCI zapisów, ten — ADRESÓW obu paginatorów. Wspólny plik
 * miałby dwa różne tematy i jedną kontrolę ujemną na oba.
 */
final class ZeszytPaginacjaObuListTest extends TestCase
{
    use RefreshDatabase;

    /** Rozmiar strony przepisów jest wpisany wprost w `CollectionController::show()`. */
    private const PRZEPISOW_NA_STRONIE = 12;

    private const PRZYCISK_PRZEPISY = 'Pokaż więcej przepisów';

    private const PRZYCISK_WPISY = 'Pokaż więcej zapisanych wpisów';

    public function test_przejscie_do_kolejnych_wpisow_zachowuje_druga_strone_przepisow(): void
    {
        $this->sprawdzPrzejscie('page', 'wpisy', 'recipes', 'posts', self::PRZYCISK_WPISY);
    }

    public function test_przejscie_do_kolejnych_przepisow_zachowuje_druga_strone_wpisow(): void
    {
        $this->sprawdzPrzejscie('wpisy', 'page', 'posts', 'recipes', self::PRZYCISK_PRZEPISY);
    }

    public function test_bledne_numery_stron_i_obce_parametry_nie_trafiaja_do_odnosnikow(): void
    {
        [$owner, $author, $book] = $this->scene();
        $this->wypelnijObieListy($book, $author);

        // Laravel (`Illuminate\Pagination\PaginationState`) przepuszcza tylko
        // liczbę całkowitą >= 1; wszystko inne JEST stroną pierwszą — a strony
        // pierwszej nie ma po co doklejać do adresu. Ostatni przypadek jest
        // kontrolą dodatnią: przy poprawnej dwójce numer MA się pojawić.
        $przypadki = [
            'brak parametru' => null,
            'pusty' => '',
            'zero' => '0',
            'ujemny' => '-2',
            'tekst' => 'abc',
            'ułamek' => '1.5',
            'tablica' => ['2'],
            'przepełnienie' => '999999999999999999999999999999',
            'zero wiodące' => '01',
            'poprawna druga strona' => '2',
        ];

        foreach ([['page', 'wpisy', 'recipes', self::PRZYCISK_WPISY], ['wpisy', 'page', 'posts', self::PRZYCISK_PRZEPISY]] as [$staly, $ruchomy, $listaStala, $przycisk]) {
            foreach ($przypadki as $opis => $wartosc) {
                $gdzie = 'Parametr '.$staly.', przypadek: '.$opis.'.';
                $parametry = ['collection' => $book, 'redirect' => 'https://example.test/obcy', 'token' => 'nie-przenos'];

                if ($wartosc !== null) {
                    $parametry[$staly] = $wartosc;
                }

                $odpowiedz = $this->actingAs($owner)->get(route('collections.show', $parametry))->assertOk();
                $oczekiwanaStrona = $opis === 'poprawna druga strona' ? 2 : 1;
                $this->assertSame($oczekiwanaStrona, $odpowiedz->viewData($listaStala)->currentPage(), $gdzie);

                $linki = $this->przyciskiWiecej($odpowiedz->getContent());
                $this->assertArrayHasKey($przycisk, $linki, $gdzie);
                $this->assertSame(
                    $oczekiwanaStrona === 2 ? ['page' => '2', 'wpisy' => '2'] : [$ruchomy => '2'],
                    $this->parametry($linki[$przycisk]),
                    $gdzie.' Adres ma nieść wyłącznie numery stron obu list — ani obcych parametrów, ani strony pierwszej.',
                );
                $this->assertSame(
                    parse_url(route('collections.show', $book), PHP_URL_PATH),
                    parse_url($linki[$przycisk], PHP_URL_PATH),
                    $gdzie.' Przycisk ma zostać na adresie tego zeszytu.',
                );
            }
        }
    }

    public function test_zeszyt_bez_ponadlimitowej_drugiej_listy_nie_dokleja_strony_pierwszej(): void
    {
        [$owner, $author, $book] = $this->scene();

        $pusty = $this->actingAs($owner)->get(route('collections.show', $book))->assertOk();
        $this->assertSame([], $this->przyciskiWiecej($pusty->getContent()), 'Pusty zeszyt nie ma czego pokazywać więcej.');
        $this->assertStringContainsString('W tym zeszycie nic jeszcze nie ma', $this->sekcjaZeszytu($pusty->getContent()));

        for ($i = 0; $i < self::PRZEPISOW_NA_STRONIE + 1; $i++) {
            $this->recipe($book, $author);
        }
        $this->postItem($book, $author);

        $linki = $this->przyciskiWiecej($this->actingAs($owner)->get(route('collections.show', $book))->assertOk()->getContent());
        $this->assertSame([self::PRZYCISK_PRZEPISY], array_keys($linki), 'Jedna strona wpisów nie potrzebuje przycisku.');
        $this->assertSame(['page' => '2'], $this->parametry($linki[self::PRZYCISK_PRZEPISY]), 'Lista stojąca na stronie pierwszej nie ma czego zachowywać.');

        $drugi = Collection::create(['owner_id' => $owner->id, 'name' => 'Zeszyt tylko z wpisami', 'visibility' => 'private']);
        for ($i = 0; $i < $this->wpisowNaStronie() + 1; $i++) {
            $this->postItem($drugi, $author);
        }
        $this->recipe($drugi, $author);

        $linki = $this->przyciskiWiecej($this->actingAs($owner)->get(route('collections.show', $drugi))->assertOk()->getContent());
        $this->assertSame([self::PRZYCISK_WPISY], array_keys($linki), 'Jedna strona przepisów nie potrzebuje przycisku.');
        $this->assertSame(['wpisy' => '2'], $this->parametry($linki[self::PRZYCISK_WPISY]), 'Lista stojąca na stronie pierwszej nie ma czego zachowywać.');
    }

    public function test_obie_listy_na_drugiej_stronie_nie_pokazuja_tresci_prywatnych(): void
    {
        [$owner, $author, $book] = $this->scene();
        $this->wypelnijObieListy($book, $author);
        $prywatnyPrzepis = $this->recipe($book, $author, 'private');
        $prywatnyWpis = $this->postItem($book, $author, 'private');

        $pierwsza = $this->actingAs($owner)->get(route('collections.show', ['collection' => $book, 'page' => 2]))->assertOk();
        $href = $this->przyciskiWiecej($pierwsza->getContent())[self::PRZYCISK_WPISY] ?? null;
        $this->assertIsString($href, 'Bez przycisku wpisów nie ma jak dojść na drugą stronę obu list.');

        $druga = $this->actingAs($owner)->get($href)->assertOk();
        $this->assertSame(2, $druga->viewData('recipes')->currentPage(), 'Po kliknięciu przycisku wpisów przepisy cofnęły się ze strony 2 — issue #646.');
        $this->assertSame(2, $druga->viewData('posts')->currentPage(), 'Kliknięty przycisk ma przesunąć wpisy na stronę 2.');

        $dokument = $druga->getContent();
        // Asercje „czegoś nie ma" zostają na CAŁYM dokumencie (pułapka 1),
        // a poniżej stoi dla nich kontrola dodatnia na wycinku ekranu.
        $this->assertStringNotContainsString($prywatnyPrzepis->title, $dokument);
        $this->assertStringNotContainsString($prywatnyWpis->body, $dokument);

        $sekcja = $this->sekcjaZeszytu($dokument);
        $this->assertStringContainsString($druga->viewData('recipes')->getCollection()->first()->title, $sekcja);
        $this->assertStringContainsString($druga->viewData('posts')->getCollection()->first()->body, $sekcja);
    }

    /** Numer spoza zakresu wraca na istniejącą stronę przed budową linków (#908). */
    public function test_numer_strony_spoza_zakresu_nie_jest_przenoszony_do_odnosnika(): void
    {
        [$owner, $author, $book] = $this->scene();
        $this->wypelnijObieListy($book, $author);

        foreach ([['page', 'recipes', self::PRZYCISK_WPISY], ['wpisy', 'posts', self::PRZYCISK_PRZEPISY]] as [$parametr, $lista, $przycisk]) {
            $gdzie = 'Parametr '.$parametr.'=999.';
            $redirect = $this->actingAs($owner)->get(route('collections.show', ['collection' => $book, $parametr => 999]))->assertRedirect();
            $odpowiedz = $this->get($redirect->headers->get('Location'))->assertOk();

            // Kontrola: człowiek widzi ostatnią istniejącą stronę.
            $this->assertSame(2, $odpowiedz->viewData($lista)->currentPage(), $gdzie);
            $this->assertCount(1, $odpowiedz->viewData($lista), $gdzie);

            $linki = $this->przyciskiWiecej($odpowiedz->getContent());
            $this->assertSame([$przycisk], array_keys($linki), $gdzie.' Pusta lista nie ma przycisku „więcej".');
            $this->assertSame(
                ['page' => '2', 'wpisy' => '2'],
                $this->parametry($linki[$przycisk]),
                $gdzie.' Odnośnik ma nieść ostatnią stronę Z TREŚCIĄ, nie numer wpisany w adres.',
            );

            // I rzeczywiście da się tym przyciskiem wrócić do pozycji.
            $powrot = $this->actingAs($owner)->get($linki[$przycisk])->assertOk();
            $this->assertCount(1, $powrot->viewData('recipes'), $gdzie);
            $this->assertCount(1, $powrot->viewData('posts'), $gdzie);
        }
    }

    /**
     * TRZY STRONY, NIE DWIE — inaczej `currentPage()` i `lastPage()` są tym
     * samym i żaden test ich nie rozróżni.
     *
     * Wszystkie pozostałe sceny w tym pliku mają listy o dokładnie dwóch
     * stronach, więc doklejenie „ostatniej strony" zamiast „bieżącej"
     * przechodziłoby je bez jednej czerwieni — a na dłuższej liście byłby to
     * ten sam błąd co #646, tylko przesunięty o stronę: człowiek stojący na
     * stronie 2 z 3 przeskakiwałby na stronę 3.
     */
    public function test_dluzsza_lista_zachowuje_strone_biezaca_a_nie_ostatnia(): void
    {
        [$owner, $author, $book] = $this->scene();
        for ($i = 0; $i < self::PRZEPISOW_NA_STRONIE * 2 + 1; $i++) {
            $this->recipe($book, $author);
        }
        for ($i = 0; $i < $this->wpisowNaStronie() * 2 + 1; $i++) {
            $this->postItem($book, $author);
        }

        foreach ([['wpisy', 'posts', 'recipes', self::PRZYCISK_PRZEPISY], ['page', 'recipes', 'posts', self::PRZYCISK_WPISY]] as [$staly, $listaStala, $listaRuchoma, $przycisk]) {
            $gdzie = 'Lista „'.$listaStala.'" stoi na stronie 2 z 3.';
            $odpowiedz = $this->actingAs($owner)->get(route('collections.show', ['collection' => $book, $staly => 2]))->assertOk();

            // Kontrola dodatnia: scena naprawdę ma trzy strony, nie dwie.
            $this->assertSame(3, $odpowiedz->viewData($listaStala)->lastPage(), $gdzie.' Scena ma mieć trzy strony.');
            $this->assertSame(2, $odpowiedz->viewData($listaStala)->currentPage(), $gdzie);

            $linki = $this->przyciskiWiecej($odpowiedz->getContent());
            $this->assertArrayHasKey($przycisk, $linki, $gdzie.' Na ekranie ma stać przycisk „'.$przycisk.'".');
            $this->assertSame(
                ['page' => '2', 'wpisy' => '2'],
                $this->parametry($linki[$przycisk]),
                $gdzie.' Odnośnik ma nieść stronę BIEŻĄCĄ drugiej listy, nie jej ostatnią.',
            );

            $dalej = $this->actingAs($owner)->get($linki[$przycisk])->assertOk();
            $this->assertSame(2, $dalej->viewData($listaStala)->currentPage(), $gdzie.' Po kliknięciu lista przeskoczyła na inną stronę niż ta, na której stała.');
            $this->assertSame(2, $dalej->viewData($listaRuchoma)->currentPage(), $gdzie);
        }
    }

    /**
     * LISTY O RÓŻNEJ LICZBIE STRON — inaczej nie widać, który paginator jest
     * którego `min()`.
     *
     * Obie linie doklejające numer są prawie bliźniacze i pomylenie w nich
     * paginatorów (`min($posts->currentPage(), $recipes->lastPage())`) jest
     * NIEWIDOCZNE w każdej scenie, w której obie listy mają tyle samo stron —
     * a wszystkie pozostałe sceny w tym pliku takie właśnie są. Tutaj jedna
     * lista ma dwie strony, druga cztery, więc docięcie do cudzej ostatniej
     * strony cofa człowieka o stronę i test to widzi.
     */
    public function test_nierowne_listy_docinaja_sie_do_wlasnej_ostatniej_strony(): void
    {
        [$owner, $author, $book] = $this->scene();
        $dlugie = self::PRZEPISOW_NA_STRONIE * 3 + 1;

        // Zeszyt pierwszy: przepisy na dwie strony, wpisy na cztery.
        for ($i = 0; $i < self::PRZEPISOW_NA_STRONIE + 1; $i++) {
            $this->recipe($book, $author);
        }
        for ($i = 0; $i < $this->wpisowNaStronie() * 3 + 1; $i++) {
            $this->postItem($book, $author);
        }

        // Zeszyt drugi: odwrotnie.
        $odwrotny = Collection::create(['owner_id' => $owner->id, 'name' => 'Zeszyt odwrotny', 'visibility' => 'private']);
        for ($i = 0; $i < $dlugie; $i++) {
            $this->recipe($odwrotny, $author);
        }
        for ($i = 0; $i < $this->wpisowNaStronie() + 1; $i++) {
            $this->postItem($odwrotny, $author);
        }

        $przypadki = [
            [$book, 'wpisy', 3, 'posts', 'recipes', self::PRZYCISK_PRZEPISY, ['page' => '2', 'wpisy' => '3']],
            [$odwrotny, 'page', 3, 'recipes', 'posts', self::PRZYCISK_WPISY, ['page' => '3', 'wpisy' => '2']],
        ];

        foreach ($przypadki as [$zeszyt, $parametr, $strona, $dluga, $krotka, $przycisk, $oczekiwane]) {
            $gdzie = 'Lista „'.$dluga.'" stoi na stronie '.$strona.' z 4, druga ma tylko 2 strony.';
            $odpowiedz = $this->actingAs($owner)->get(route('collections.show', ['collection' => $zeszyt, $parametr => $strona]))->assertOk();

            // Kontrola dodatnia sceny: listy NAPRAWDĘ mają różną liczbę stron.
            $this->assertSame(4, $odpowiedz->viewData($dluga)->lastPage(), $gdzie.' Dłuższa lista ma mieć cztery strony.');
            $this->assertSame(2, $odpowiedz->viewData($krotka)->lastPage(), $gdzie.' Krótsza lista ma mieć dwie strony.');
            $this->assertSame($strona, $odpowiedz->viewData($dluga)->currentPage(), $gdzie);

            $linki = $this->przyciskiWiecej($odpowiedz->getContent());
            $this->assertArrayHasKey($przycisk, $linki, $gdzie.' Na ekranie ma stać przycisk „'.$przycisk.'".');
            $this->assertSame(
                $oczekiwane,
                $this->parametry($linki[$przycisk]),
                $gdzie.' Numer docięto do CUDZEJ ostatniej strony zamiast do własnej — to cofa człowieka o stronę.',
            );

            $dalej = $this->actingAs($owner)->get($linki[$przycisk])->assertOk();
            $this->assertSame($strona, $dalej->viewData($dluga)->currentPage(), $gdzie.' Po kliknięciu dłuższa lista przeskoczyła na inną stronę.');
            $this->assertSame(2, $dalej->viewData($krotka)->currentPage(), $gdzie);
        }
    }

    /**
     * Cudzy, publiczny zeszyt — inna gałąź `CollectionPolicy::view()`.
     *
     * Wszystkie pozostałe sceny oglądają zeszyt WŁASNY. Zeszyt gościa spoza
     * konta nie wchodzi w grę: trasa stoi za `auth`, więc niezalogowany dostaje
     * przekierowanie, a nie listę — i to też jest tu sprawdzone, żeby nikt nie
     * dopisywał testu na stan, którego nie ma.
     */
    public function test_cudzy_publiczny_zeszyt_tez_zachowuje_strone_drugiej_listy(): void
    {
        [$owner, $author, $book] = $this->scene();
        $book->update(['visibility' => 'public']);
        $this->wypelnijObieListy($book, $author);
        $widz = $this->user();

        $odpowiedz = $this->actingAs($widz)->get(route('collections.show', ['collection' => $book, 'wpisy' => 2]))->assertOk();
        $linki = $this->przyciskiWiecej($odpowiedz->getContent());
        $this->assertArrayHasKey(self::PRZYCISK_PRZEPISY, $linki, 'Cudzy publiczny zeszyt ma pokazać przycisk przepisów.');
        $this->assertSame(['page' => '2', 'wpisy' => '2'], $this->parametry($linki[self::PRZYCISK_PRZEPISY]), 'Cudzy zeszyt gubi stronę drugiej listy — issue #646.');

        $dalej = $this->actingAs($widz)->get($linki[self::PRZYCISK_PRZEPISY])->assertOk();
        $this->assertSame(2, $dalej->viewData('recipes')->currentPage(), 'Cudzy zeszyt: przepisy nie przeszły na stronę 2.');
        $this->assertSame(2, $dalej->viewData('posts')->currentPage(), 'Cudzy zeszyt: wpisy cofnęły się ze strony 2 — issue #646.');

        // Trasa stoi za `auth` — gość nie dostaje listy, tylko logowanie.
        $this->post(route('logout'));
        $this->get(route('collections.show', $book))->assertRedirect(route('login'));
    }

    private function sprawdzPrzejscie(string $staly, string $ruchomy, string $listaStala, string $listaRuchoma, string $przycisk): void
    {
        [$owner, $author, $book] = $this->scene();
        $this->wypelnijObieListy($book, $author);

        $pierwsza = $this->actingAs($owner)->get(route('collections.show', ['collection' => $book, $staly => 2]))->assertOk();
        $kluczeStalej = $pierwsza->viewData($listaStala)->getCollection()->modelKeys();
        $this->assertCount(1, $kluczeStalej, 'Druga strona listy „'.$listaStala.'" ma mieć dokładnie jedną pozycję.');
        $kluczeRuchomejPrzed = $pierwsza->viewData($listaRuchoma)->getCollection()->modelKeys();

        $linki = $this->przyciskiWiecej($pierwsza->getContent());
        $this->assertArrayHasKey($przycisk, $linki, 'Na ekranie ma stać przycisk „'.$przycisk.'".');
        $this->assertSame(
            ['page' => '2', 'wpisy' => '2'],
            $this->parametry($linki[$przycisk]),
            'Odnośnik „'.$przycisk.'" zgubił stronę drugiej listy ('.$staly.'=2) — issue #646.',
        );

        $druga = $this->actingAs($owner)->get($linki[$przycisk])->assertOk();
        $this->assertSame(2, $druga->viewData($listaStala)->currentPage(), 'Po kliknięciu „'.$przycisk.'" lista „'.$listaStala.'" cofnęła się ze strony 2 — issue #646.');
        $this->assertSame(2, $druga->viewData($listaRuchoma)->currentPage(), 'Kliknięty przycisk ma przesunąć listę „'.$listaRuchoma.'" na stronę 2.');
        $this->assertSame(
            $kluczeStalej,
            $druga->viewData($listaStala)->getCollection()->modelKeys(),
            'Po kliknięciu „'.$przycisk.'" lista „'.$listaStala.'" pokazuje inne pozycje niż przed kliknięciem — issue #646.',
        );

        $kluczeRuchomejPo = $druga->viewData($listaRuchoma)->getCollection()->modelKeys();
        $this->assertCount(1, $kluczeRuchomejPo);
        $this->assertSame([], array_intersect($kluczeRuchomejPrzed, $kluczeRuchomejPo), 'Kontrola dodatnia: lista „'.$listaRuchoma.'" naprawdę przeszła na kolejne pozycje.');

        $pozycja = $druga->viewData($listaStala)->getCollection()->first();
        $this->assertStringContainsString(
            $pozycja instanceof Recipe ? $pozycja->title : $pozycja->body,
            $this->sekcjaZeszytu($druga->getContent()),
            'Zachowana strona ma być widoczna na ekranie, nie tylko w danych widoku.',
        );
    }

    /** @return array{0: User, 1: User, 2: Collection} */
    private function scene(): array
    {
        $owner = $this->user();
        $author = $this->user();
        $book = Collection::create(['owner_id' => $owner->id, 'name' => 'Zeszyt paginacyjny', 'visibility' => 'private']);

        return [$owner, $author, $book];
    }

    /** Obie listy o jedną pozycję ponad limit strony — czyli dokładnie dwie strony każda. */
    private function wypelnijObieListy(Collection $book, User $author): void
    {
        for ($i = 0; $i < self::PRZEPISOW_NA_STRONIE + 1; $i++) {
            $this->recipe($book, $author);
        }

        for ($i = 0; $i < $this->wpisowNaStronie() + 1; $i++) {
            $this->postItem($book, $author);
        }
    }

    private function wpisowNaStronie(): int
    {
        return (int) config('kuking.collections.saved_posts_page_size');
    }

    private function recipe(Collection $book, User $author, string $visibility = 'public'): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'visibility' => $visibility, 'title' => 'Przepis kontrolny '.Str::uuid()]);
        $book->recipes()->attach($recipe->id);

        return $recipe;
    }

    private function postItem(Collection $book, User $author, string $visibility = 'public'): Post
    {
        $post = Post::factory()->create(['author_id' => $author->id, 'visibility' => $visibility, 'body' => 'Wpis kontrolny '.Str::uuid()]);
        $book->posts()->attach($post->id);

        return $post;
    }

    /**
     * Przyciski „Pokaż więcej" z SEKCJI ZESZYTU, nie z całego dokumentu.
     *
     * Pułapka 1 z `docs/PULAPKI_TESTOW.md`: belka, prawa szyna i stopka mają
     * własne odnośniki, a `<title>` własne teksty. Wycinamy `.marka-zeszyt`,
     * tak jak robi to `ZeszytNiedostepneZapisyTest::section()`.
     *
     * @return array<string, string> tekst przycisku => adres z atrybutu href
     */
    private function przyciskiWiecej(string $html): array
    {
        $xpath = new \DOMXPath($this->dokument($html));
        $sekcje = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " marka-zeszyt ")]');
        $this->assertSame(1, $sekcje->length, 'Ekran zeszytu ma dokładnie jedną sekcję `.marka-zeszyt`.');

        $linki = [];
        foreach ($xpath->query('.//a[starts-with(normalize-space(.), "Pokaż więcej")]', $sekcje->item(0)) as $link) {
            $linki[trim((string) preg_replace('/\s+/u', ' ', $link->textContent))] = $link->getAttribute('href');
        }

        return $linki;
    }

    private function sekcjaZeszytu(string $html): string
    {
        $sekcje = (new \DOMXPath($this->dokument($html)))->query('//*[contains(concat(" ", normalize-space(@class), " "), " marka-zeszyt ")]');
        $this->assertSame(1, $sekcje->length);

        return trim((string) preg_replace('/\s+/u', ' ', $sekcje->item(0)->textContent));
    }

    /** @return array<string, string> */
    private function parametry(string $href): array
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        ksort($query);

        return $query;
    }

    private function dokument(string $html): \DOMDocument
    {
        $dom = new \DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($poprzednie);
        }

        return $dom;
    }
}
