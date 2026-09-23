<?php

declare(strict_types=1);

namespace App\Poczta;

/**
 * Ślady śledzenia otwarć w liście — obce obrazki wstawione w treść, po których
 * dostawca poznaje moment otwarcia, adres IP i klienta pocztowego odbiorcy.
 *
 * PO CO TO ISTNIEJE (issue #204)
 * 9 września 2026 w PRAWDZIWYM liście doręczonym na o2.pl (potwierdzenie
 * adresu) siedziały dwa znaczniki śledzące otwarcie — `<img>` o rozmiarze
 * 1×1 i zapasowy `<div>` z tym samym adresem w `background:url()`, na wypadek
 * klienta, który blokuje obrazki. Ani jednego z nich nie wstawia nasz kod:
 * dokłada je dostawca PO stronie naszego żądania. Nasze szablony nie mają
 * obrazków w ogóle (D-057).
 *
 * DLACZEGO POMIAR, A NIE ZAŁOŻENIE
 * Wyłączenie śledzenia otwarć jest ustawieniem konta wysyłkowego w panelu
 * EmailLabs — nagłówkiem `X-TRACKING-OFF` gasi się wyłącznie śledzenie
 * ODNOŚNIKÓW, co specyfikacja API mówi dosłownie („Link tracking"). Czyli
 * żadna zmiana w tym repozytorium nie usunie piksela i żaden test na treść,
 * którą składamy sami, tego nie zauważy. Jedyne miejsce, w którym piksel
 * naprawdę JEST, to DORĘCZONY list — i tę klasę można nastawić właśnie na
 * niego (`kuking:sprawdz-piksel`), a nie na nasze własne wyobrażenie o nim.
 *
 * PUŁAPKA, KTÓRA TU MIESZKA: `click.kuking.pl` WYGLĄDA JAK NASZE
 * Domena śledząca dostawcy jest PODDOMENĄ naszej domeny
 * (`click.kuking.pl CNAME tracking.mf-settings.com`), więc porównywanie
 * końcówki adresu („czy host kończy się na kuking.pl") uznałoby piksel za
 * własny obrazek i przepuściło go bez słowa. Dlatego lista własnych hostów
 * jest sprawdzana NA DOKŁADNE RÓWNANIE, nigdy na przyrostek.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie mówi nic o USTAWIENIU u dostawcy — mówi o jednym konkretnym liście,
 * który ktoś jej podał. Brak śladów w jednym liście nie jest dowodem, że
 * przełącznik w panelu jest wyłączony; jest dowodem, że TEN list wyszedł
 * czysty. Odwrotnie jest mocniej: jeden ślad wystarcza, żeby wiedzieć, że
 * śledzenie otwarć wciąż działa.
 */
final class SladySledzeniaOtwarc
{
    /**
     * Hosty, które są nasze niezależnie od konfiguracji.
     *
     * `config('app.url')` na maszynie deweloperskiej i w testach wskazuje na
     * `localhost`, a doręczony list zawsze prowadzi na domenę produkcyjną —
     * bez tych dwóch wpisów badanie prawdziwego listu na laptopie uznałoby
     * własne odnośniki za obce.
     *
     * @var list<string>
     */
    private const NASZE_HOSTY_ZAWSZE = ['kuking.pl', 'www.kuking.pl'];

    /**
     * Znaki, po których w ścieżce adresu rozpoznajemy licznik OTWARĆ.
     *
     * Służą wyłącznie do nazwania śladu w komunikacie („to jest licznik
     * otwarć, nie zwykły obrazek"), a nie do rozstrzygnięcia, czy ślad
     * jest — o tym decyduje host. Wzorzec oparty na samej ścieżce byłby
     * kruchy: dostawca może ją zmienić jednym wdrożeniem u siebie.
     *
     * @var list<string>
     */
    private const SCIEZKI_LICZNIKA_OTWARC = ['/track/o/', '/track/open', '/open/', '/o/1/'];

    /**
     * @param  list<string>  $slady  opisy po polsku, gotowe do pokazania człowiekowi
     * @param  list<string>  $obceHosty  same hosty, bez ścieżek — do podsumowania
     */
    private function __construct(
        public readonly array $slady,
        public readonly array $obceHosty,
        public readonly int $adresow,
        public readonly int $znakowPoDekodowaniu,
    ) {}

    /**
     * Badanie SUROWEGO ŹRÓDŁA doręczonej wiadomości (to, co daje „Pokaż
     * oryginał" w Gmailu albo „Pokaż szczegóły" w o2 i WP).
     *
     * Źródło jest zakodowane transportowo i to jest tu najważniejsza część
     * roboty: w `quoted-printable` adres piksela bywa PRZEŁAMANY w środku
     * miękkim zawinięciem (`=\r\n`), a w `base64` nie ma go w ogóle jako
     * tekstu. Szukanie łańcucha w nieodkodowanym źródle przechodziłoby więc
     * na liście, w którym piksel stoi — czyli byłoby atrapą pomiaru.
     *
     * @param  list<string>|null  $naszeHosty  do podmiany w testach
     */
    public static function wSurowymZrodle(string $zrodlo, ?array $naszeHosty = null): self
    {
        return self::zbadaj(self::rozkoduj($zrodlo), $naszeHosty);
    }

    /**
     * Badanie gotowego HTML-a, którego niczym nie kodowaliśmy — treści, którą
     * składamy sami, zanim wyjdzie do dostawcy.
     *
     * @param  list<string>|null  $naszeHosty  do podmiany w testach
     */
    public static function wHtml(string $html, ?array $naszeHosty = null): self
    {
        return self::zbadaj($html, $naszeHosty);
    }

    /** Czy w zbadanej treści nie ma ani jednego śladu śledzenia otwarć. */
    public function czysto(): bool
    {
        return $this->slady === [];
    }

    /**
     * Czy w zbadanej treści nie było ANI JEDNEGO adresu http(s).
     *
     * To nie jest to samo co „czysto" i dlatego jest osobną metodą: zero
     * adresów znaczy, że plik nie jest listem, albo że dekodowanie nic nie
     * dało. Każdy list z Kuking niesie co najmniej jeden odnośnik (przycisk
     * albo adres pod nim), więc zero adresów to „NIE WIEMY", a nie „czysto"
     * (`docs/PULAPKI_TESTOW.md` §2 i §5).
     */
    public function nicNieZmierzono(): bool
    {
        return $this->adresow === 0;
    }

    /**
     * Hosty, których obrazki uznajemy za własne.
     *
     * @return list<string>
     */
    public static function naszeHosty(): array
    {
        $hosty = self::NASZE_HOSTY_ZAWSZE;

        $wlasny = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($wlasny) && $wlasny !== '') {
            $hosty[] = mb_strtolower($wlasny);
        }

        return array_values(array_unique($hosty));
    }

    /** @param list<string>|null $naszeHosty */
    private static function zbadaj(string $tresc, ?array $naszeHosty): self
    {
        $nasze = $naszeHosty ?? self::naszeHosty();
        $slady = [];
        $obce = [];

        // 1. OBRAZKI. Każdy `<img>` osobno, żeby dało się przy nim zobaczyć
        // rozmiar — piksel śledzący to prawie zawsze 1×1.
        preg_match_all('/<img\b[^>]*>/i', $tresc, $obrazki);

        foreach ($obrazki[0] as $obrazek) {
            $adres = self::atrybut($obrazek, 'src');
            $host = self::host($adres);
            $jedenNaJeden = self::jedenNaJeden($obrazek);

            if ($host !== null && ! self::nasz($host, $nasze)) {
                $obce[] = $host;
                $slady[] = 'obcy obrazek w treści listu: <img src="'.self::skrocony($adres)
                    .'">'.($jedenNaJeden ? ' o rozmiarze 1×1' : '')
                    .self::czyLicznikOtwarc($adres);

                continue;
            }

            if ($jedenNaJeden) {
                // Obrazek 1×1 z NASZEGO hosta też nie ma po co istnieć —
                // nasze szablony nie mają obrazków w ogóle (D-057), więc
                // taki obrazek jest albo licznikiem, albo przemyconym
                // odwołaniem, które trzeba zobaczyć.
                $slady[] = 'obrazek o rozmiarze 1×1 w treści listu: <img src="'.self::skrocony($adres).'">';
            }
        }

        // 2. TŁO W CSS. Zapasowa droga licznika otwarć dla klientów, które
        // wycinają `<img>` — dokładnie ta, która stała w liście z 9 września.
        preg_match_all('/url\(\s*[\'"]?(https?:\/\/[^\'")\s]+)/i', $tresc, $tla);

        foreach ($tla[1] as $adres) {
            $host = self::host($adres);

            if ($host === null || self::nasz($host, $nasze)) {
                continue;
            }

            $obce[] = $host;
            $slady[] = 'obcy obrazek w tle (CSS `background`): url('.self::skrocony($adres).')'
                .self::czyLicznikOtwarc($adres);
        }

        // 3. ODNOŚNIKI. Śledzenie KLIKNIĘĆ jest wyłączone nagłówkiem
        // `X-TRACKING-OFF` i pilnowane osobnym testem, ale nagłówek dotyczy
        // tego, co wysyłamy — nie tego, co dostawca zrobił z listem po
        // drodze. Skoro i tak czytamy doręczony list, to jest jedyne miejsce,
        // w którym podmieniony odnośnik naprawdę widać.
        preg_match_all('/<a\b[^>]*>/i', $tresc, $odnosniki);

        foreach ($odnosniki[0] as $odnosnik) {
            $adres = self::atrybut($odnosnik, 'href');
            $host = self::host($adres);

            if ($host === null || self::nasz($host, $nasze)) {
                continue;
            }

            $obce[] = $host;
            $slady[] = 'odnośnik przepisany na obcą domenę (śledzenie kliknięć): '.self::skrocony($adres);
        }

        return new self(
            array_values(array_unique($slady)),
            array_values(array_unique($obce)),
            preg_match_all('/https?:\/\//i', $tresc),
            mb_strlen($tresc),
        );
    }

    /**
     * Surowe źródło listu → tekst, w którym da się szukać adresów.
     *
     * Dwa kodowania transportowe, oba spotkane w liście z 9 września:
     * `quoted-printable` (miękkie zawinięcia `=\r\n` w środku adresu)
     * i `base64` (adresu nie ma wtedy w źródle jako tekstu w ogóle).
     * Odkodowane części DOKLEJAMY do źródła, zamiast je podmieniać — tak
     * badanie widzi i jedno, i drugie, bez rozstrzygania, która część
     * wiadomości jest którą.
     */
    private static function rozkoduj(string $zrodlo): string
    {
        $bezZawiniec = (string) preg_replace("/=\r?\n/", '', $zrodlo);

        return $zrodlo."\n".quoted_printable_decode($bezZawiniec)."\n".self::rozkodowaneBlokiBase64($zrodlo);
    }

    /**
     * Bloki base64 ze źródła, rozkodowane i sklejone.
     *
     * Rozpoznanie po kształcie linii, a nie po nagłówku
     * `Content-Transfer-Encoding`: nagłówek stoi w innej części wiadomości
     * niż blok, a przy wiadomości wielocześciowej trzeba by rozbierać całą
     * strukturę MIME. Kształt wystarcza, bo pomyłka jest tu tania — blok,
     * który nie jest base64, nie rozkoduje się na nic, co zawiera adres.
     */
    private static function rozkodowaneBlokiBase64(string $zrodlo): string
    {
        $wynik = '';
        $blok = '';

        foreach (preg_split('/\r?\n/', $zrodlo) ?: [] as $linia) {
            $linia = trim($linia);
            $ksztaltBase64 = $linia !== '' && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $linia) === 1;

            // Blok ZACZYNA się od długiej linii (krótka mogłaby być zwykłym
            // słowem), ale KOŃCZY się linią dowolnej długości — ostatnia
            // linia bloku base64 jest krótsza od pozostałych i właśnie w niej
            // siedzi koniec treści listu, czyli miejsce, w którym dostawca
            // dokleja piksel. Odrzucenie jej przepuściłoby piksel bez słowa.
            if ($ksztaltBase64 && ($blok !== '' || mb_strlen($linia) >= 40)) {
                $blok .= $linia;

                continue;
            }

            $wynik .= self::rozkodowany($blok);
            $blok = '';
        }

        return $wynik.self::rozkodowany($blok);
    }

    private static function rozkodowany(string $blok): string
    {
        if (mb_strlen($blok) < 60) {
            return '';
        }

        $tekst = base64_decode($blok, true);

        return is_string($tekst) ? $tekst."\n" : '';
    }

    private static function atrybut(string $znacznik, string $nazwa): string
    {
        if (preg_match('/\b'.$nazwa.'\s*=\s*["\']([^"\']*)["\']/i', $znacznik, $trafienie) === 1) {
            return trim($trafienie[1]);
        }

        // Wartość bez cudzysłowów — w doręczonym liście zdarza się po
        // przepisaniu treści przez dostawcę.
        if (preg_match('/\b'.$nazwa.'\s*=\s*([^\s>]+)/i', $znacznik, $trafienie) === 1) {
            return trim($trafienie[1]);
        }

        return '';
    }

    private static function host(string $adres): ?string
    {
        if (preg_match('/^https?:\/\//i', $adres) !== 1) {
            // Adres względny, `mailto:`, `cid:` (załącznik w tym samym
            // liście) i `data:` nie wychodzą do obcego serwera, więc nie
            // mówią nikomu, że list został otwarty.
            return null;
        }

        $host = parse_url($adres, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return rtrim(mb_strtolower($host), '.');
    }

    /**
     * @param  list<string>  $nasze
     */
    private static function nasz(string $host, array $nasze): bool
    {
        // RÓWNANIE, NIE PRZYROSTEK — patrz komentarz klasy o `click.kuking.pl`.
        return in_array($host, $nasze, true);
    }

    private static function jedenNaJeden(string $obrazek): bool
    {
        return self::atrybut($obrazek, 'width') === '1' && self::atrybut($obrazek, 'height') === '1';
    }

    private static function czyLicznikOtwarc(string $adres): string
    {
        foreach (self::SCIEZKI_LICZNIKA_OTWARC as $sciezka) {
            if (mb_stripos($adres, $sciezka) !== false) {
                return ' — ścieżka wygląda na licznik OTWARĆ';
            }
        }

        return '';
    }

    /**
     * Adres skrócony do hosta i dwóch pierwszych członów ścieżki.
     *
     * W adresie piksela stoi identyfikator wiadomości, a ten wskazuje na
     * konkretnego odbiorcę — czyli jest daną osobową i nie ma po co trafiać
     * do wyniku komendy ani do komunikatu oblanego testu (AGENTS.md §7).
     */
    private static function skrocony(string $adres): string
    {
        $host = parse_url($adres, PHP_URL_HOST);
        $sciezka = parse_url($adres, PHP_URL_PATH);

        if (! is_string($host) || $host === '') {
            return mb_substr($adres, 0, 40);
        }

        $czlony = array_values(array_filter(explode('/', is_string($sciezka) ? $sciezka : '')));
        $skrot = 'https://'.$host.($czlony === [] ? '' : '/'.implode('/', array_slice($czlony, 0, 2)));

        return $skrot.(count($czlony) > 2 ? '/…' : '');
    }
}
