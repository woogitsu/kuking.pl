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
 * Teraz klasy zwijające siatkę do jednej kolumny są ODCZYTYWANE Z ARKUSZA
 * STYLÓW — tak samo, jak `CaddySpojnyZNaglowkamiLaravelaTest` odczytuje
 * nagłówki z pliku konfiguracyjnego Caddy. Dodanie jutro trzeciego układu
 * jednokolumnowego nie wymaga ruszania tego testu; dodanie klasy, która
 * siatki NIE zwija, obleje go natychmiast.
 */
class UkladGosciaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Klasy `app-body-*`, które w arkuszu stylów zwijają siatkę do JEDNEJ
     * kolumny — czyli nie rezerwują miejsca na nawigację boczną.
     *
     * Czytane z pliku, nie wypisane tutaj: lista wypisana w teście rozjeżdża
     * się z arkuszem przy pierwszej zmianie i wtedy test zaczyna pilnować
     * swojej własnej kopii reguły zamiast reguły.
     *
     * @return list<string>
     */
    private function klasyJednokolumnowe(): array
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        preg_match_all('/\.(app-body-[a-z-]+)\s*\{([^}]*)\}/', $css, $trafienia, PREG_SET_ORDER);

        $klasy = [];

        foreach ($trafienia as $trafienie) {
            if (preg_match('/grid-template-columns:\s*minmax\(\s*0\s*,\s*1fr\s*\)/', $trafienie[2]) === 1) {
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

        $body = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' app-body ')]")->item(0);

        $this->assertNotNull($body, 'W dokumencie nie ma elementu .app-body — układ strony się zmienił.');

        $klasy = ' '.preg_replace('/\s+/', ' ', (string) $body->getAttribute('class')).' ';

        $jednokolumnowe = $this->klasyJednokolumnowe();

        $this->assertNotEmpty(
            $jednokolumnowe,
            'W `resources/css/app.css` nie ma ani jednej klasy `app-body-*` '
            .'zwijającej siatkę do jednej kolumny. Albo arkusz zmienił kształt, '
            .'albo ten test przestał cokolwiek sprawdzać — jedno i drugie '
            .'wymaga poprawki tutaj.',
        );

        $solo = false;

        foreach ($jednokolumnowe as $klasa) {
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
        foreach (['landing', 'login', 'register', 'discover'] as $trasa) {
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
