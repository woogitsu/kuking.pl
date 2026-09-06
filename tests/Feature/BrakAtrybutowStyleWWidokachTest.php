<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * W widokach nie ma ani jednego atrybutu `style="…"` (issue #107).
 *
 * PO CO TO JEST
 * `style-src` jest już wymuszany bez `unsafe-inline`. Atrybut `style` jest
 * jedyną rzeczą, która by go z powrotem wymusiła — nonce go NIE obejmuje,
 * działa na element `<style>`, nie na atrybut. Jeden taki atrybut w jednym
 * widoku znaczy więc: albo ten fragment strony renderuje się bez stylu, albo
 * ktoś otwiera `unsafe-inline` dla całego serwisu, żeby go uratować.
 *
 * DLACZEGO STRAŻNIK, A NIE „PRZECIEŻ POSPRZĄTALIŚMY"
 * Nowy widok powstaje przez skopiowanie istniejącego, a `style="margin:0"`
 * jest najkrótszą drogą do celu w każdym edytorze. Bez tego testu atrybuty
 * wróciłyby w ciągu kilku PR-ów, a zauważyłby to dopiero ktoś, kto zobaczyłby
 * rozjechaną stronę — nagłówki są niewidoczne, więc naruszenie nie boli od razu.
 *
 * Wcześniej ten test pilnował KIERUNKU (limit, który wolno tylko obniżać),
 * bo sprzątanie 355 atrybutów trwało dłużej niż jeden PR. Teraz pilnuje ZERA.
 *
 * DWA KATALOGI SĄ WYŁĄCZONE I ŻADEN Z NICH NIE JEST WYJĄTKIEM „NA SKRÓTY"
 *
 * `resources/views/mail/**` — klienty pocztowe nie czytają arkuszy stylów,
 * w e-mailu styl MUSI być inline. CSP nie dotyczy poczty w ogóle.
 *
 * `resources/views/exports/**` — paczka z danymi (RODO) to pliki HTML, które
 * człowiek otwiera Z DYSKU, we własnej przeglądarce, bez serwera. Nie ma tam
 * żadnych nagłówków, więc nie ma czego naruszać. Te widoki mają własny arkusz
 * (`exports/styles.blade.php`) i nie znają klas Tailwinda — zamiana stylu na
 * klasę zabrałaby im wygląd, nie poprawiając niczego.
 *
 * CZEGO NIE WYŁĄCZAMY, CHOĆ KUSI
 * `errors/_prosty.blade.php` (strony 500 i 503) ma style inline celowo — ma
 * działać, gdy arkusz się nie zbuduje. Ale ta strona idzie po HTTP, więc CSP
 * JĄ OBEJMUJE i po zdjęciu `unsafe-inline` wyrenderuje się bez stylów.
 * Rozwiązaniem będzie przeniesienie jej stylów do jednego bloku
 * `<style nonce="…">` w tym samym pliku — nadal bez zewnętrznego arkusza,
 * ale zgodnie z polityką. Dlatego zostaje policzona.
 */
class BrakAtrybutowStyleWWidokachTest extends TestCase
{
    public function test_w_widokach_nie_ma_ani_jednego_atrybutu_style(): void
    {
        $ile = 0;
        $najgorsze = [];

        foreach ($this->widoki() as $sciezka) {
            $tresc = (string) file_get_contents($sciezka);
            $wPliku = substr_count($tresc, ' style="');

            if ($wPliku > 0) {
                $ile += $wPliku;
                $najgorsze[$sciezka] = $wPliku;
            }
        }

        arsort($najgorsze);

        $this->assertSame(
            0,
            $ile,
            "W widokach jest {$ile} atrybutów style=\"…\", a ma być zero.\n\n"
            ."Każdy z nich wymusiłby `unsafe-inline` w style-src — czyli otworzyłby z powrotem\n"
            ."ostatnią furtkę polityki bezpieczeństwa dla CAŁEGO serwisu (issue #107).\n\n"
            ."Co robić zamiast: odstęp zapisz klasą Tailwinda (`mt-6`, `mb-5`), a zestaw\n"
            ."deklaracji — nazwaną klasą w `resources/css/app.css`, w sekcji na końcu pliku.\n"
            ."Wartość liczbowa z bazy (rozmiar, proporcje) idzie przez atrybut danych\n"
            ."(`data-rozmiar`) i regułę w arkuszu — tak robią `x-avatar` i `x-photo`.\n"
            ."Widok, który MUSI działać bez arkusza, dostaje jeden blok `<style nonce>` —\n"
            ."tak robi `errors/_prosty.blade.php`.\n\n"
            .'Znalezione w: '.implode(', ', array_map(
                static fn (string $p, int $n): string => basename($p)." ({$n})",
                array_keys($najgorsze),
                array_values($najgorsze),
            )),
        );
    }

    public function test_strona_awarii_ma_podpisany_blok_stylu(): void
    {
        // `errors/_prosty.blade.php` to jedyny widok, który nie może wziąć
        // stylów z arkusza: renderuje się przy awarii, w której `@vite` albo
        // baza mogą nie działać. Ma za to jeden blok `<style>` Z PODPISEM —
        // bez podpisu przeglądarka odrzuciłaby go tak samo jak atrybut,
        // a strona awarii wyszłaby szara.
        $tresc = (string) file_get_contents(resource_path('views/errors/_prosty.blade.php'));

        $this->assertStringContainsString('<style @if($nonce) nonce="{{ $nonce }}" @endif>', $tresc);
        $this->assertStringContainsString('Vite::cspNonce()', $tresc);

        // Nadal bez `@vite` i bez zapytań do bazy — to jest cały powód
        // istnienia tego pliku (issue #81).
        //
        // Komentarz na górze tego widoku CYTUJE `auth()->user()` w wyjaśnieniu,
        // czego tam nie ma. Asercja na surowej treści trafiała we własne
        // uzasadnienie i oblewała — ta sama pułapka, którą opisuje
        // `bezKomentarzy` w `LogotypIMarkaTest`.
        $kod = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $tresc);

        $this->assertStringNotContainsString('@vite', $kod);
        $this->assertStringNotContainsString('auth()', $kod);
    }

    /** @return list<string> */
    private function widoki(): array
    {
        $katalog = resource_path('views');
        $pliki = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($katalog));

        foreach ($iterator as $plik) {
            if (! $plik->isFile() || ! str_ends_with($plik->getFilename(), '.blade.php')) {
                continue;
            }

            $sciezka = str_replace('\\', '/', $plik->getPathname());

            // Poczta i paczka z danymi — patrz opis klasy.
            if (str_contains($sciezka, '/views/mail/') || str_contains($sciezka, '/views/exports/')) {
                continue;
            }

            $pliki[] = $sciezka;
        }

        return $pliki;
    }
}
