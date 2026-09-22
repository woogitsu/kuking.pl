<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Rezerwa nad przypiętym paskiem górnym (`scroll-padding-top`).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * `scripts/dostepnosc.mjs` meldował w linii podsumowania „focus częściowo
 * zasłonięty: 27". Dwadzieścia jeden z tych ostrzeżeń mówiło o `.topbar`:
 * przy dolnej belce stało `scroll-padding-bottom`, a przy górnym pasku nie
 * stało nic, więc `scroll-padding-top` miał wartość `auto` na każdej
 * szerokości i w każdej skali. Przewinięcie fokusu w widok, które
 * przeglądarka robi sama po Tab, liczy się wtedy do krawędzi okna — a na tej
 * krawędzi siedzi przypięty pasek. Dla osoby chodzącej po serwisie Tabem
 * (w grupie 50+ to nie rzadkość) znaczyło to: obwódka fokusu wjeżdża pod
 * pasek i nie widać, gdzie się jest.
 *
 * DLACZEGO TEN TEST ISTNIEJE OBOK POMIARU W PRZEGLĄDARCE
 * Dokładnie z tego samego powodu co `BelkaPrzyDuzymTekscieTest`: wysokości
 * paska nie da się stwierdzić z CSS-a, mierzy ją `scripts/dostepnosc.mjs`,
 * a ten pomiar chodzi w CI WARUNKOWO (job `dostepnosc` odpala się tylko przy
 * zmianie w `resources/`, `public/`, `scripts/dostepnosc.mjs` albo w plikach
 * npm). Ten plik chodzi w jobie `test`, czyli zawsze, i pilnuje tego, co
 * widać w źródle:
 *
 *   1. że rezerwa w ogóle jest i że `scroll-padding-top` z niej korzysta,
 *   2. że jest WIĄZANA ZE SKALĄ TEKSTU, a nie wpisana z palca w pikselach —
 *      pasek rośnie razem z tekstem i sztywna liczba naprawia jeden wariant,
 *      zostawiając dwa,
 *   3. że znika DOKŁADNIE TAM, gdzie pasek przestaje być przypięty.
 *
 * Punkt trzeci jest tu najważniejszy, bo to jedyna rzecz, która może się
 * rozjechać po cichu. Progi odpięcia paska (D-107) stoją w arkuszu w dwóch
 * miejscach oddalonych od siebie o ponad trzy tysiące linii. Gdyby ktoś
 * dołożył trzeci próg albo zmienił jeden z dwóch istniejących i nie ruszył
 * rezerwy, dostalibyśmy rezerwę nad paskiem, którego już nie ma: przy
 * czcionce przeglądarki 200% to 384 px, które razem z 480 px rezerwy dolnej
 * nie zostawiają z okna 740 px żadnego pasa na kontrolkę.
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Nie dowodzi, że liczba rezerwy jest DOBRA — na to trzeba przeglądarki.
 * Rezerwa ma sufit: gdy kontrolka nie mieści się w pasie między rezerwami,
 * przeglądarka równa ją górą, a wtedy każdy piksel rezerwy nad paskiem spycha
 * jej dół pod belkę dolną. Zmierzone przy 320 px i tekście 140%: sufit wynosi
 * 244,5 px, a rezerwa 14rem × skala (313,6 px) dokładała nowe ostrzeżenie
 * zamiast zbijać stare. Wyprowadzenie obu liczb stoi w komentarzu przy samej
 * regule w `app.css`.
 */
class RezerwaNadPaskiemTest extends TestCase
{
    private const TOKEN = '--rezerwa-nad-belka';

    /**
     * Arkusz bez komentarzy.
     *
     * KOMENTARZE PRECZ, ZANIM COKOLWIEK SPRAWDZIMY — tak samo jak
     * w `BelkaPrzyDuzymTekscieTest`. Komentarz przy tej regule cytuje
     * dosłownie odrzucone warianty („14rem × skala", „384 px"), więc asercja
     * szukająca ich w surowym pliku trafiałaby we własne uzasadnienie
     * (`docs/PULAPKI_TESTOW.md`, pułapka 1).
     */
    private function css(): string
    {
        $sciezka = resource_path('css/app.css');

        $this->assertFileExists($sciezka);

        $tresc = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($sciezka));

        $this->assertNotSame('', trim($tresc), 'Arkusz app.css jest pusty — test sprawdzałby pustkę.');

        return $tresc;
    }

    /**
     * Wszystkie reguły arkusza, każda razem z otaczającymi ją zapytaniami
     * medialnymi.
     *
     * Płaska lista, bo pytania zadawane niżej brzmią „gdzie w arkuszu stoi
     * deklaracja X" — a nie „jak wygląda drzewo arkusza".
     *
     * @return list<array{media: list<string>, selektor: string, deklaracje: string}>
     */
    private function reguly(): array
    {
        $css = $this->css();
        $reguly = [];
        $stos = [];
        $bufor = '';

        for ($i = 0, $n = strlen($css); $i < $n; $i++) {
            $znak = $css[$i];

            if ($znak === '{') {
                $stos[] = trim((string) preg_replace('~\s+~', ' ', $bufor));
                $bufor = '';

                continue;
            }

            if ($znak === '}') {
                $prelude = array_pop($stos);

                if ($prelude === null) {
                    $this->fail('Arkusz app.css ma niezbilansowane nawiasy klamrowe.');
                }

                // Reguła stylu, nie `@media`/`@layer`: tylko ona ma deklaracje.
                if ($prelude !== '' && $prelude[0] !== '@') {
                    $media = array_values(array_filter(
                        $stos,
                        static fn (string $poziom): bool => str_starts_with($poziom, '@media'),
                    ));

                    $reguly[] = [
                        'media' => array_map(
                            static fn (string $m): string => trim(substr($m, strlen('@media'))),
                            $media,
                        ),
                        'selektor' => $prelude,
                        'deklaracje' => $bufor,
                    ];
                }

                $bufor = '';

                continue;
            }

            $bufor .= $znak;
        }

        $this->assertSame([], $stos, 'Arkusz app.css ma niedomknięty blok.');

        /*
         * ASERCJA NA LICZBĘ PRZESKANOWANYCH REGUŁ — bez niej cały ten plik
         * byłby zielony w chwili, gdy parser przestanie cokolwiek znajdować
         * (inny zapis, inna ścieżka, przeniesiony arkusz). Zero trafień jest
         * wtedy „sukcesem" (`docs/PULAPKI_TESTOW.md`, pułapka 2).
         */
        $this->assertGreaterThan(
            400,
            count($reguly),
            'Skan arkusza app.css znalazł mniej niż 400 reguł — to nie jest ten plik '
            .'albo parser go nie czyta. Test nie ma wtedy czego sprawdzać.',
        );

        return $reguly;
    }

    /**
     * Reguły, których selektor (po rozbiciu na listę) zawiera podany selektor
     * i których deklaracje pasują do wzorca.
     *
     * @return list<array{media: list<string>, selektor: string, deklaracje: string}>
     */
    private function regulyZ(string $selektor, string $wzorzec): array
    {
        return array_values(array_filter($this->reguly(), static function (array $r) use ($selektor, $wzorzec): bool {
            $selektory = array_map('trim', explode(',', $r['selektor']));

            return in_array($selektor, $selektory, true) && preg_match($wzorzec, $r['deklaracje']) === 1;
        }));
    }

    /** Zestaw warunków medialnych, uporządkowany i odchudzony z powtórzeń. */
    private function warunki(array $reguly): array
    {
        $warunki = array_map(
            static fn (array $r): string => implode(' i ', $r['media']),
            $reguly,
        );

        $warunki = array_values(array_unique($warunki));
        sort($warunki);

        return $warunki;
    }

    public function test_przewijanie_do_fokusu_ma_rezerwe_nad_przypietym_paskiem(): void
    {
        $reguly = $this->regulyZ(':root', '~(?<![-\w])scroll-padding-top\s*:~');

        $this->assertCount(
            1,
            $reguly,
            'W arkuszu nie ma dokładnie jednej reguły `:root` ze `scroll-padding-top`. '
            .'Bez niej przewinięcie fokusu po Tab liczy się do krawędzi okna, a na tej '
            .'krawędzi siedzi przypięty pasek górny — obwódka fokusu wjeżdża pod niego.',
        );

        $this->assertSame(
            [],
            $reguly[0]['media'],
            'Rezerwa nad paskiem jest podpięta tylko w zapytaniu medialnym, czyli poza nim '
            .'jej nie ma. Wartość ma być jedna, a różnicować ją mają progi przy samym tokenie.',
        );

        preg_match('~(?<![-\w])scroll-padding-top\s*:\s*([^;}]+)~', $reguly[0]['deklaracje'], $trafienie);

        $this->assertStringContainsString(
            'var('.self::TOKEN,
            trim($trafienie[1]),
            '`scroll-padding-top` nie bierze już wartości z `'.self::TOKEN.'`. '
            .'Wpisana na sztywno liczba naprawia jeden wariant powiększenia i zostawia dwa.',
        );
    }

    public function test_rezerwa_nad_paskiem_rosnie_razem_z_tekstem(): void
    {
        $bazowe = array_values(array_filter(
            $this->regulyZ(':root', '~'.preg_quote(self::TOKEN, '~').'\s*:~'),
            static fn (array $r): bool => $r['media'] === [],
        ));

        $this->assertCount(
            1,
            $bazowe,
            'Poza zapytaniami medialnymi nie ma dokładnie jednej deklaracji `'.self::TOKEN.'`. '
            .'Wartość bazowa jest tą, którą dostaje zwykły telefon.',
        );

        preg_match(
            '~'.preg_quote(self::TOKEN, '~').'\s*:\s*([^;}]+)~',
            $bazowe[0]['deklaracje'],
            $trafienie,
        );

        $wartosc = trim($trafienie[1]);

        $this->assertStringContainsString(
            'var(--user-text-scale',
            $wartosc,
            'Rezerwa nad paskiem nie mnoży się już przez `--user-text-scale` (jest: `'.$wartosc.'`). '
            .'Pasek górny rośnie razem z tekstem — zmierzone 170,7 px bez powiększania '
            .'i 194,6 px przy tekście 140% na tym samym telefonie 320 px — a `rem` stoi '
            .'w miejscu. Liczba wpisana z palca naprawia jeden wariant i zostawia dwa.',
        );

        $this->assertMatchesRegularExpression(
            '~(?<![-\w.])[1-9][\d.]*rem~',
            $wartosc,
            'Rezerwa nad paskiem nie ma już części stałej w `rem` (jest: `'.$wartosc.'`). '
            .'Wysokość paska to w większości minima przycisków i wypełnienia, które idą za '
            .'korzeniem, a nie za skalą tekstu — sam mnożnik musiałby przekroczyć sufit '
            .'244,5 px przy tekście 140%.',
        );
    }

    public function test_rezerwa_znika_dokladnie_tam_gdzie_pasek_przestaje_byc_przypiety(): void
    {
        $odpiecia = $this->regulyZ('.topbar', '~(?<![-\w])position\s*:\s*relative~');

        /*
         * Kontrola dodatnia wpisana w pomiar (`docs/PULAPKI_TESTOW.md`,
         * pułapka 2 i 4): bez tej asercji zmiana zapisu selektora dawałaby
         * zero odpięć, zero zer rezerwy i zielony test na obu zerach.
         */
        $this->assertGreaterThanOrEqual(
            2,
            count($odpiecia),
            'W arkuszu nie ma już dwóch progów odpinających `.topbar` (D-107). '
            .'Albo zmienił się zapis reguły, albo progi zniknęły — a ten test '
            .'porównuje z nimi rezerwę i bez nich nie ma czego porównywać.',
        );

        foreach ($odpiecia as $regula) {
            $this->assertNotSame(
                [],
                $regula['media'],
                'Pasek górny jest odpięty bezwarunkowo — rezerwa nad nim nie ma wtedy sensu nigdzie.',
            );
        }

        $zera = array_values(array_filter(
            $this->regulyZ(':root', '~'.preg_quote(self::TOKEN, '~').'\s*:\s*0~'),
            static fn (array $r): bool => $r['media'] !== [],
        ));

        $this->assertSame(
            $this->warunki($odpiecia),
            $this->warunki($zera),
            'Progi, na których rezerwa nad paskiem schodzi do zera, rozjechały się z progami, '
            .'na których pasek przestaje być przypięty (D-107). Rezerwa nad paskiem, który '
            .'wyjeżdża razem z treścią, to czysta strata ekranu: przy czcionce przeglądarki '
            .'200% to 384 px, które razem z 480 px rezerwy dolnej nie zostawiają z okna '
            .'740 px żadnego pasa na kontrolkę. W drugą stronę — brak zera tam, gdzie pasek '
            .'jest przypięty — chowa fokus pod paskiem, czyli przywraca usterkę.',
        );
    }
}
