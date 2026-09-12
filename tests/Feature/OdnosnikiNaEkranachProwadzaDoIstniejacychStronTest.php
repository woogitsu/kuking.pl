<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * D-053: żaden przycisk i żaden odnośnik nie ma prawa prowadzić donikąd.
 *
 * DLACZEGO TEN TEST POWSTAŁ
 * 12 września 2026 pierwszy przebieg `scripts/martwe-przyciski.mjs` — audytu,
 * który po raz pierwszy obszedł CAŁY serwis — znalazł w zasadach społeczności
 * odnośnik „polityka prywatności" prowadzący na `/polityka-prywatnosci`.
 * Takiej trasy nie ma i nigdy nie było: dokument nazywa się
 * `resources/legal/polityka-prywatnosci.md`, ale jego adres to `/prywatnosc`.
 * Autor tekstu wziął adres z nazwy pliku.
 *
 * Kliknięcie kończyło się ekranem „Nie znaleźliśmy tej strony" — w punkcie 12
 * zasad, czyli dokładnie tam, gdzie człowiek chce sprawdzić, co serwis wysyła
 * zewnętrznemu modelowi na temat jego zdjęć. Stało to na żywej stronie,
 * widoczne dla KAŻDEGO, i nie łapał tego ani jeden test: dokumenty prawne miały
 * testy pilnujące ich TREŚCI (`DokumentyPrawneNieKlamiaTest`) i żadnego,
 * który by w ich odnośniki WSZEDŁ.
 *
 * CZEGO TEN TEST PILNUJE
 * Bierze stronę tak, jak dostaje ją człowiek, wyjmuje z niej każdy odnośnik
 * wewnętrzny i wchodzi w niego. Odnośnik kończący się 404 albo 500 oblewa test
 * z nazwy własnej i z nazwą ekranu, na którym stoi.
 *
 * DLACZEGO CAŁY DOKUMENT, A NIE SAM `<main>` (pułapka 1 odwrotnie)
 * Pułapka 1 każe zwężać asercje „widać X", bo belka i stopka powtarzają te
 * same słowa. Tu jest odwrotnie: nie pytamy, czy coś widać, tylko czy KAŻDY
 * odnośnik dokądś prowadzi — a odnośnik w stopce jest przyciskiem tak samo jak
 * odnośnik w treści. Zwężenie do `<main>` zmniejszyłoby zasięg testu, nie
 * zwiększyło jego celność.
 *
 * ASERCJA NA LICZBĘ ZNALEZIONYCH ODNOŚNIKÓW (pułapka 2)
 * Bez niej ten test przechodzi także wtedy, gdy wyrażenie przestaje cokolwiek
 * łapać — a wtedy zero odnośników jest dla niego sukcesem.
 */
class OdnosnikiNaEkranachProwadzaDoIstniejacychStronTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ekrany, na których 404 pod odnośnikiem kosztuje najwięcej: trzy
     * dokumenty prawne (człowiek wchodzi tam po konkretną odpowiedź i nie
     * wraca szukać jej drugą drogą), pomoc i strona powitalna.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function ekrany(): array
    {
        return [
            'zasady społeczności' => ['/zasady', 5],
            'regulamin' => ['/regulamin', 5],
            'polityka prywatności' => ['/prywatnosc', 5],
            'pomoc' => ['/pomoc', 5],
            'strona powitalna' => ['/', 5],
        ];
    }

    #[DataProvider('ekrany')]
    public function test_kazdy_odnosnik_na_ekranie_prowadzi_do_istniejacej_strony(string $adres, int $minimum): void
    {
        $ekran = $this->get($adres);

        $ekran->assertOk();

        $odnosniki = self::odnosnikiWewnetrzne($ekran->getContent() ?: '');

        // Pułapka 2: skan bez trafień jest dla skanu sukcesem.
        $this->assertGreaterThanOrEqual(
            $minimum,
            count($odnosniki),
            "Na ekranie {$adres} znalazłem tylko ".count($odnosniki).' odnośników wewnętrznych. '
            .'Skan ich nie czyta — zmienił się szablon albo wyrażenie przestało łapać.',
        );

        foreach ($odnosniki as $cel) {
            $kod = $this->get($cel)->getStatusCode();

            $this->assertLessThan(
                400,
                $kod,
                "Odnośnik na ekranie {$adres} prowadzi na {$cel}, a serwis odpowiada tam {$kod}. "
                .'To jest martwy przycisk (AGENTS.md §5, D-053): albo popraw adres, albo usuń odnośnik.',
            );
        }
    }

    /**
     * ŹRÓDŁA MARKDOWN OSOBNO — bo dokument może zawierać odnośnik, którego
     * renderowany ekran akurat nie pokazuje (sekcja za warunkiem, fragment
     * cytowany gdzie indziej), a plik i tak jest wtedy nieprawdziwy.
     *
     * To także jedyne miejsce w serwisie, gdzie adres wpisuje się z ręki:
     * Blade składa odnośniki przez `route()`, więc literówki tam nie ma jak
     * zrobić. W markdownie jest — i właśnie tak powstała usterka z 12 września.
     */
    public function test_zrodla_markdown_dokumentow_prawnych_nie_maja_odnosnika_donikad(): void
    {
        $pliki = glob(resource_path('legal/*.md')) ?: [];

        $this->assertGreaterThanOrEqual(
            3,
            count($pliki),
            'Skan nie znajduje dokumentów prawnych w resources/legal — zmieniła się ścieżka?',
        );

        $znalezione = 0;

        foreach ($pliki as $plik) {
            $tresc = (string) file_get_contents($plik);
            $nazwa = basename($plik);

            preg_match_all('/\]\((\/[^)\s]*)\)/', $tresc, $trafienia);

            foreach ($trafienia[1] as $cel) {
                $znalezione++;

                $kod = $this->get($cel)->getStatusCode();

                $this->assertLessThan(
                    400,
                    $kod,
                    "Dokument {$nazwa} linkuje na {$cel}, a serwis odpowiada tam {$kod}. "
                    .'Najczęstszy powód: adres wzięty z NAZWY PLIKU zamiast z trasy — '
                    .'`polityka-prywatnosci.md` jest serwowana pod `/prywatnosc`.',
                );
            }
        }

        // Pułapka 2, drugi raz: gdyby wyrażenie przestało łapać odnośniki
        // Markdown, pętla wyżej nie wykonałaby się ani razu i test byłby zielony.
        $this->assertGreaterThanOrEqual(
            1,
            $znalezione,
            'Skan nie znalazł w dokumentach prawnych ANI JEDNEGO odnośnika Markdown. '
            .'Albo wyrażenie przestało działać, albo ktoś usunął wszystkie odnośniki — '
            .'w obu przypadkach ten test przestał czegokolwiek pilnować.',
        );
    }

    /**
     * Wewnętrzne cele `<a href>` z dokumentu, bez kotwic, poczty i adresów
     * zewnętrznych. Wynik jest odchudzony o powtórzenia, bo ten sam odnośnik
     * belki stoi na każdym ekranie i sprawdzanie go po raz dziesiąty niczego
     * nie dowodzi.
     *
     * DWA KSZTAŁTY ADRESU, I OBA TRZEBA UMIEĆ PRZECZYTAĆ. Blade składa
     * odnośniki przez `route()`, a ta funkcja zwraca adres BEZWZGLĘDNY
     * (`http://localhost/pomoc`). Odnośniki wpisane z ręki w markdownie są
     * względne (`/prywatnosc`). Pierwsza wersja tej metody brała tylko drugi
     * kształt i na `/regulamin` znalazła ZERO odnośników — czyli dokładnie
     * tyle, ile znajduje zepsuty skan. Złapała to asercja na minimalną liczbę
     * trafień, i po to ona jest (pułapka 2).
     *
     * @return list<string>
     */
    private static function odnosnikiWewnetrzne(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/i', $html, $trafienia);

        $naszHost = (string) parse_url(url('/'), PHP_URL_HOST);
        $cele = [];

        foreach ($trafienia[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
                $cele[strtok($href, '#')] = true;

                continue;
            }

            // Kotwica, poczta, telefon i adres na cudzym serwerze to nie są
            // trasy tego serwisu — 404 pod nimi jest cudzą sprawą albo nie
            // istnieje.
            $czesci = parse_url($href);

            if (! is_array($czesci) || ($czesci['host'] ?? null) !== $naszHost) {
                continue;
            }

            $cele[($czesci['path'] ?? '/').(isset($czesci['query']) ? '?'.$czesci['query'] : '')] = true;
        }

        return array_values(array_filter(array_keys($cele), static fn (string $c): bool => $c !== ''));
    }
}
