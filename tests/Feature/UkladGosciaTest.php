<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Układ strony dla niezalogowanych.
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Na desktopie (od 64rem) `.app-body` jest siatką o dwóch kolumnach:
 * nawigacja boczna i treść. Nawigacja renderuje się jednak tylko dla
 * zalogowanych. Dla gościa `<main>` był PIERWSZYM elementem siatki i lądował
 * w kolumnie zarezerwowanej na menu — `--container-sidenav`, czyli 15rem.
 *
 * Cała strona dla niezalogowanych ściskała się do 240 px na monitorze
 * o szerokości 1920 px.
 *
 * DLACZEGO TO MA TEST, A NIE TYLKO POPRAWKĘ
 * Ta usterka jest niewidoczna z trzech stron naraz: dla zalogowanego (menu
 * wypełnia pierwszą kolumnę), na telefonie (siatka jeszcze nie działa)
 * i w testach sprawdzających status 200 (strona przecież się otwiera).
 * Widzi ją wyłącznie nowa osoba na komputerze — czyli ktoś, kto ogląda
 * Kuking pierwszy raz i nie wróci powiedzieć, że wyglądało dziwnie.
 *
 * Test pilnuje NIEZMIENNIKA, nie konkretnej klasy: liczba kolumn siatki ma
 * odpowiadać temu, czy nawigacja boczna w ogóle istnieje w dokumencie.
 *
 * I DŁUGO TEGO NIE ROBIŁ, mimo że tak było napisane w tym akapicie.
 * Sprawdzał obecność literału `app-body-solo`. Gdy strona powitalna dostała
 * własny, szerszy układ jednokolumnowy (`app-body-powitalny`, 7 września),
 * niezmiennik był spełniony — jedna kolumna, żadnej rezerwacji na menu — a
 * test i tak się oblał, bo szukał nazwy, nie reguły. To jest dokładnie ta
 * klasa rozbieżności, którą ten projekt zbiera od tygodnia: obietnica
 * w komentarzu mocniejsza niż to, co sprawdza kod pod nią.
 *
 * Teraz klasy, które nie rezerwują kolumny na nawigację, są ODCZYTYWANE
 * Z ARKUSZA STYLÓW — tak samo, jak `CaddySpojnyZNaglowkamiLaravelaTest`
 * odczytuje nagłówki z pliku konfiguracyjnego Caddy. Dodanie jutro czwartego
 * układu bez nawigacji nie wymaga ruszania tego testu; dodanie klasy, która
 * kolumnę na nawigację rezerwuje, obleje go natychmiast.
 *
 * ZMIANA Z 11 WRZEŚNIA 2026 (D-122): NIEZMIENNIK BRZMIAŁ „JEDNA KOLUMNA",
 * A MIAŁ BRZMIEĆ „BEZ KOLUMNY NA NAWIGACJĘ".
 * Do dziś test zbierał z arkusza klasy, w których `grid-template-columns`
 * ZACZYNA SIĘ od `minmax(0, 1fr)`. Gdy gość dostał na ekranach z prawą szyną
 * układ DWUKOLUMNOWY (`minmax(0, 1fr) var(--container-rail)`), ten wzorzec
 * dalej się dopasowywał — czyli test uznawał dwie kolumny za jedną i nadal
 * mówił „solo". Nic by się nie oblało, a pilnowany niezmiennik przestałby
 * odpowiadać rzeczywistości: po raz drugi w tym pliku obietnica
 * w komentarzu byłaby mocniejsza niż kod pod nią.
 *
 * Sprawdzana jest więc ta rzecz, o którą naprawdę chodzi: czy pierwsza
 * kolumna siatki jest zarezerwowana na nawigację (`--container-sidenav`)
 * dokładnie wtedy, gdy ta nawigacja jest w dokumencie. Ile kolumn ma strona
 * poza nią, to osobna sprawa — pilnuje jej `SzynaGosciaTest`.
 */
class UkladGosciaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Klasy `app-body-*`, które w arkuszu stylów NIE rezerwują pierwszej
     * kolumny na nawigację boczną — czyli takie, po których treść gościa
     * zaczyna się przy lewej krawędzi siatki, a nie 15rem dalej.
     *
     * Czytane z pliku, nie wypisane tutaj: lista wypisana w teście rozjeżdża
     * się z arkuszem przy pierwszej zmianie i wtedy test zaczyna pilnować
     * swojej własnej kopii reguły zamiast reguły.
     *
     * ROZSTRZYGA BRAK `--container-sidenav`, NIE LICZBA KOLUMN (D-122).
     * `.app-body-solo-z-szyna` ma dwie kolumny (treść + szyna) i żadna z nich
     * nie jest kolumną menu, więc należy tutaj. Gdyby ten test dalej pytał
     * „czy jedna kolumna", musiałby albo zgłosić fałszywy błąd, albo — co
     * gorsze — cicho uznać dwie kolumny za jedną.
     *
     * @return list<string>
     */
    private function klasyBezKolumnyNawigacji(): array
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        preg_match_all('/\.(app-body-[a-z-]+)\s*\{([^}]*)\}/', $css, $trafienia, PREG_SET_ORDER);

        $klasy = [];

        foreach ($trafienia as $trafienie) {
            if (preg_match('/grid-template-columns:\s*([^;]+);/', $trafienie[2], $wartosc) !== 1) {
                continue;
            }

            if (! str_contains($wartosc[1], '--container-sidenav')) {
                $klasy[] = $trafienie[1];
            }
        }

        return array_values(array_unique($klasy));
    }

    /** @return array{solo: bool, maNawigacje: bool} */
    private function zbadajUklad(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new \DOMXPath($dom);

        $body = self::elementDom($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' app-body ')]")->item(0), 'W dokumencie nie ma elementu .app-body — układ strony się zmienił.');

        $klasy = ' '.preg_replace('/\s+/', ' ', (string) $body->getAttribute('class')).' ';

        $bezNawigacji = $this->klasyBezKolumnyNawigacji();

        $this->assertNotEmpty(
            $bezNawigacji,
            'W `resources/css/app.css` nie ma ani jednej klasy `app-body-*`, '
            .'która nie rezerwuje kolumny na nawigację boczną. Albo arkusz '
            .'zmienił kształt, albo ten test przestał cokolwiek sprawdzać — '
            .'jedno i drugie wymaga poprawki tutaj.',
        );

        $solo = false;

        foreach ($bezNawigacji as $klasa) {
            if (str_contains($klasy, ' '.$klasa.' ')) {
                $solo = true;

                break;
            }
        }

        return [
            'solo' => $solo,
            'maNawigacje' => $xpath->query("//nav[contains(concat(' ', normalize-space(@class), ' '), ' side-nav ')]")->length > 0,
        ];
    }

    public function test_goscia_nie_sciska_do_szerokosci_menu(): void
    {
        $uklad = $this->zbadajUklad($this->get(route('landing'))->assertOk()->getContent());

        $this->assertFalse(
            $uklad['maNawigacje'],
            'Gość nie powinien dostać nawigacji bocznej.',
        );

        $this->assertTrue(
            $uklad['solo'],
            'Strona dla gościa rezerwuje kolumnę na nawigację, której nie ma — '
            .'treść wpada w kolumnę szeroką na 15rem i cała strona powitalna '
            .'ściska się do 240 px na desktopie.',
        );
    }

    public function test_zalogowany_ma_dwie_kolumny(): void
    {
        $uklad = $this->zbadajUklad(
            $this->actingAs($this->user('basia'))->get(route('home'))->assertOk()->getContent(),
        );

        $this->assertTrue($uklad['maNawigacje'], 'Zalogowany traci nawigację boczną.');

        $this->assertFalse(
            $uklad['solo'],
            'Zalogowany dostał układ jednokolumnowy mimo obecnej nawigacji — '
            .'menu i treść nałożyłyby się na siebie.',
        );
    }

    /**
     * Niezmiennik, nie konkretna strona.
     *
     * Kolumna na nawigację ma być zarezerwowana dokładnie wtedy, gdy
     * nawigacja istnieje. Sprawdzamy to na wszystkich trasach, które gość
     * realnie odwiedza, zanim założy konto.
     */
    public function test_kazda_strona_goscia_trzyma_ten_niezmiennik(): void
    {
        // `kontakt` jest tu od D-122: to ekran gościa, który MA prawą szynę,
        // czyli dwie kolumny — a pierwszej z nich dalej nie rezerwuje na
        // nawigację, której nie ma. Niezmiennik obejmuje więc oba kształty
        // układu gościa, nie tylko jednokolumnowy.
        foreach (['landing', 'login', 'register', 'discover', 'kontakt'] as $trasa) {
            $odpowiedz = $this->get(route($trasa));

            $this->assertSame(200, $odpowiedz->getStatusCode(), "Trasa „{$trasa}” nie otwiera się dla gościa.");

            $uklad = $this->zbadajUklad($odpowiedz->getContent());

            $this->assertSame(
                ! $uklad['maNawigacje'],
                $uklad['solo'],
                "Trasa „{$trasa}”: rezerwacja kolumny na nawigację nie zgadza się z jej obecnością.",
            );
        }
    }
}
