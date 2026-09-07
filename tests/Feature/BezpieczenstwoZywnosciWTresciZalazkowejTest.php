<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Przegląd bezpieczeństwa żywności nie może się „odkleić” od treści
 * zalążkowej (`docs/decyzje/PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI.md`).
 *
 * DLACZEGO TO JEST TEST, A NIE UWAGA W DOKUMENTACJI
 * Bo zamówiony przegląd wskazał 28 poprawek i jedno wstrzymanie, a naniesione
 * zostały RĘCZNIE, W NASZYM GŁOSIE — czyli brzmieniem, które wolno zmieniać.
 * Gdyby ktoś kiedyś jeszcze raz „wygładził” te przepisy, zdanie o studzeniu
 * dałoby się skrócić do „szybko schowaj do lodówki” i zniknęłaby jedyna rzecz,
 * której zmieniać NIE WOLNO: liczba. „Dwie godziny” to nie ozdoba stylistyczna,
 * a „nie więcej niż 5°C” nie znaczy tego samego co „w lodówce”.
 *
 * Ten test nie ocenia brzmienia zdań i nie porównuje ich z propozycją
 * recenzenta słowo w słowo — o to właśnie chodziło, żeby brzmiały inaczej.
 * Pilnuje pięciu rzeczy:
 *
 *   1. p24 („Pomidory we własnym soku do słoików”, ocena BLOKUJE) NIE MA
 *      w treści startowej i NIC się do niego nie odwołuje;
 *   2. dla każdego z 28 przepisów POPRAW treść zawiera wszystkie LICZBY
 *      i JEDNOSTKI z proponowanego zdania recenzenta;
 *   3. dla każdego z tych 28 przepisów treść zawiera też WARUNEK słowny —
 *      bo osiem propozycji recenzenta nie ma w sobie ani jednej liczby
 *      (grzyby, ścięte jaja, zsiadłe mleko, surowe ciasto) i sama asercja
 *      liczbowa przechodziłaby dla nich na pusto;
 *   4. zdania, które recenzent kazał ZASTĄPIĆ, a nie uzupełnić, naprawdę
 *      zniknęły — dopisanie ostrzeżenia obok anegdoty normalizującej ryzyko
 *      nie usuwa anegdoty;
 *   5. dziewięć przepisów CZYSTY i dwa DO WIEDZY nadal są w pliku i mają
 *      niepustą treść.
 *
 * ASERCJA KONTROLNA (`test_kontrola_*`): przepisów jest dokładnie 39, a lista
 * `oceny` w pliku przeglądu ma 40 pozycji. Bez tego cały test mógłby
 * przechodzić na pusto — na przykład czytając plik, w którym nie ma już
 * żadnego przepisu, albo listę ocen, z której ktoś usunął p24, żeby „się
 * zgadzało”. Zgadzać ma się właśnie NIEZGODNOŚĆ tych dwóch liczb.
 *
 * Wzorem `DokumentyPrawneNieKlamiaTest`, który tak samo pilnuje, że każda
 * liczba dni w polityce prywatności ma za sobą konfigurację egzekwującą ją.
 */
class BezpieczenstwoZywnosciWTresciZalazkowejTest extends TestCase
{
    private const PLIK_TRESCI = __DIR__.'/../../database/seeders/dane/tresc-zalazkowa.json';

    private const PLIK_PRZEGLADU = __DIR__.'/../../database/seeders/dane/przeglad-bezpieczenstwa-zywnosci.json';

    /** Przepis wstrzymany decyzją właściciela — nie ma go w treści startowej. */
    private const REF_WSTRZYMANY = 'p24';

    /**
     * SŁOWNE FORMY LICZB dopuszczone jawnie, po obu stronach porównania.
     *
     * Recenzent pisze „w ciągu dwóch godzin”, „co najmniej pół godziny”,
     * „nie dłużej niż dwa dni”, „na dwie minuty” — słowami, nie cyfrą.
     * My piszemy tak samo, bo w opowieści przy garnku „2 godziny” wygląda
     * jak formularz. Gdyby ten słownik nie istniał, asercja liczbowa
     * przechodziłaby dla tych zdań na pusto (regex nie znalazłby żadnej
     * cyfry), więc jest tu wypisany wprost, a nie ukryty w wyrażeniu
     * regularnym. DOKŁADNIE te przypadki go potrzebują:
     *
     *   „pół godziny”              p2, p37
     *   „dwóch godzin”             p1, p2, p5, p11, p13, p14, p17, p22, p23,
     *                              p26, p29, p30, p32, p36, p37, p40
     *   „dwóch–trzech dniach”      p3
     *   „dwa dni” / „dwóch dni”    p10, p11
     *   „dwie minuty”              p32, p39
     *
     * Ćwierci ani „półtorej” w tych zdaniach nie ma i celowo nie dopisujemy
     * form, których nic nie używa — słownik ma pokrywać zmierzone przypadki,
     * a nie całą polską liczebnikowość.
     */
    private const LICZBY_SLOWNIE = [
        'pół' => '0,5',
        'dwóch' => '2',
        'dwie' => '2',
        'dwa' => '2',
        'dwoma' => '2',
        'trzech' => '3',
        'trzy' => '3',
    ];

    /**
     * Jednostki, których liczby pilnujemy. Zapisane RDZENIEM, bo polska
     * odmiana daje „stopni/stopnie”, „godzin/godzinach”, „dni/dniach”.
     */
    private const JEDNOSTKI = ['stopni', 'minut', 'sekund', 'godzin', 'dni'];

    /**
     * WARUNKI SŁOWNE — po jednym wierszu na każdy przepis POPRAW.
     *
     * Osiem propozycji recenzenta (p15, p16, p21, p27, p31, p33, p34, p38)
     * nie zawiera ani jednej liczby. Dla nich asercja liczbowa jest pusta
     * z definicji, a przecież właśnie tam siedzą najcięższe uwagi: pewna
     * identyfikacja grzybów, ścięte jajo, zagotowanie zupy po dolewce
     * zakwasu, osobna miska na farsz, zsiadłe mleko zamiast zepsutego.
     * Dlatego obok liczb sprawdzamy RDZENIE słów, które musi zawierać
     * każde uczciwe oddanie tego warunku — niezależnie od tego, jak
     * zostanie napisane.
     *
     * To jest tabela pisana ręcznie i tak ma być: automatyczne wyciąganie
     * „słów ważnych” ze zdania recenzenta łapałoby też „biorę”, „tylko”
     * i „potem”, czyli pilnowałoby brzmienia, którego wolno nam nie
     * zachować. Kontrola niżej sprawdza, że tabela ma dokładnie te refy,
     * które plik przeglądu ocenia na POPRAW — więc nowa poprawka nie może
     * wejść bez wiersza tutaj.
     *
     * @var array<string, list<string>>
     */
    private const WARUNKI = [
        'p1' => ['lodów'],
        'p2' => ['wrzeni|wrząc', 'zamraż|zamroż', 'lodów'],
        'p3' => ['lodów', 'pod zalew', 'zapas na zimę'],
        'p5' => ['wrząc|wrzątk', 'surowego ciasta', 'lodów'],
        'p9' => ['termometr', 'w środku'],
        'p10' => ['bez gotowania', 'lodów', 'opakowani'],
        'p11' => ['lodów', 'zamraż|zamroż', 'szafk'],
        'p13' => ['lodów', 'w środku'],
        'p14' => ['termometr', 'kości', 'lodów'],
        'p15' => ['pewnie rozpozna', 'jadaln', 'gatunk', 'uprawne'],
        'p16' => ['ścięt', 'płynn'],
        'p17' => ['lodów', 'w środku'],
        'p21' => ['wrzeni|zagotow', 'pleśń|pleśni'],
        'p22' => ['pewnie rozpozna', 'jadaln', 'lodów'],
        'p23' => ['lodów', 'całą noc'],
        'p26' => ['lodów', 'w środku'],
        'p27' => ['lodów', 'liczę od'],
        'p29' => ['lodów'],
        'p30' => ['lodów'],
        'p31' => ['pewnie rozpozna', 'jadaln', 'gatunk', 'namocz'],
        'p32' => ['lodów', 'w środku'],
        'p33' => ['osobn', 'czyst', 'surowego ciasta'],
        'p34' => ['zagotow', 'wnuk'],
        'p36' => ['lodów', 'najlepiej w godzinę', 'dob', 'tylko raz'],
        'p37' => ['wrząc|wrzątk', 'lodów'],
        'p38' => ['zsiadł|kefir', 'pasteryzowan', 'termin'],
        'p39' => ['termometr', 'w środku'],
        'p40' => ['pewnie rozpozna', 'jadaln', 'gatunk', 'lodów'],
    ];

    /**
     * ZDANIA USUNIĘTE — recenzent przy p33, p34 i p38 napisał wprost
     * ZASTĄPIĆ, a nie „dopisać”. Anegdota o zjedzeniu farszu z miski po
     * surowym cieście, próbowanie zupy po każdej dolewce surowego zakwasu
     * i „dobry sposób na kwaśne mleko, którego nikt już nie chce pić”
     * normalizują dokładnie to ryzyko, przed którym ostrzega dopisek —
     * więc muszą zniknąć, nie stać obok.
     *
     * Pozostałe wiersze to zdania, które po naniesieniu poprawki mówiłyby
     * coś przeciwnego niż ona: „zdejmuję patelnię trochę wcześniej” obok
     * „dopiero gdy masa jest ścięta”, „po dziesięciu minutach próbuję”
     * obok „co najmniej pół godziny we wrzeniu”, nieokreślone
     * „chłodniejsze miejsce” obok lodówki.
     *
     * @var array<string, list<string>>
     */
    private const USUNIETE = [
        'p3' => ['Potem przenoszę w chłodniejsze miejsce.'],
        'p11' => ['Gorące powidła przekładam do czystych słoików i zamykam.'],
        'p16' => ['zdejmuję patelnię trochę wcześniej'],
        'p21' => ['wlewam zakwas stopniowo i próbuję'],
        'p33' => [
            'Ostatnio chciałem oszczędzić naczynie i wymieszałem farsz w misce po cieście.',
            'Dało się zjeść, ale teraz jednak biorę drugą miskę.',
        ],
        'p34' => ['Od tej pory próbuję po każdej dolewce.'],
        'p37' => ['po dziesięciu minutach wyjmuję jedną i próbuję'],
        'p38' => ['To jest dobry sposób na kwaśne mleko, którego nikt już nie chce pić.'],
    ];

    // ---------------------------------------------------------------------
    //  Wczytanie obu plików
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function wczytajJson(string $sciezka): array
    {
        $surowe = file_get_contents($sciezka);

        if ($surowe === false) {
            throw new \RuntimeException("Nie da się wczytać {$sciezka}.");
        }

        $dane = json_decode($surowe, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($dane)) {
            throw new \RuntimeException("{$sciezka} nie jest obiektem JSON.");
        }

        return $dane;
    }

    /** @return list<array<string, mixed>> */
    private static function lista(string $sciezka, string $klucz): array
    {
        $dane = self::wczytajJson($sciezka);
        $lista = $dane[$klucz] ?? null;

        if (! is_array($lista)) {
            throw new \RuntimeException("Brak tablicy `{$klucz}` w {$sciezka}.");
        }

        $wynik = [];
        foreach ($lista as $element) {
            if (is_array($element)) {
                $wynik[] = $element;
            }
        }

        return $wynik;
    }

    /** @return array<string, string> ref przepisu => treść */
    private static function przepisy(): array
    {
        $wynik = [];
        foreach (self::lista(self::PLIK_TRESCI, 'przepisy') as $przepis) {
            $wynik[(string) ($przepis['ref'] ?? '')] = (string) ($przepis['tresc'] ?? '');
        }

        return $wynik;
    }

    /** @return list<array<string, mixed>> */
    private static function oceny(): array
    {
        return self::lista(self::PLIK_PRZEGLADU, 'oceny');
    }

    /** @return list<array<string, mixed>> */
    private static function ocenyO(string $ocena): array
    {
        $wynik = [];
        foreach (self::oceny() as $wpis) {
            if (($wpis['ocena'] ?? null) === $ocena) {
                $wynik[] = $wpis;
            }
        }

        return $wynik;
    }

    // ---------------------------------------------------------------------
    //  Normalizacja i porównanie liczb
    // ---------------------------------------------------------------------

    /**
     * Ujednolica zapis liczb i jednostek po OBU stronach porównania.
     *
     * Trzy pułapki formatu, każda zmierzona na tych plikach:
     *   - „5°C” u recenzenta i „5 stopni” w opowieści to ta sama liczba —
     *     stopień Celsjusza zamieniamy więc na słowo, ŻEBY JEDNOSTKA DALEJ
     *     BYŁA SPRAWDZANA. Gdybyśmy po prostu wycięli „°C”, zostałoby samo
     *     „5” i test przyjąłby „5 minut” za temperaturę;
     *   - „dwóch godzin” i „2 godziny” to ta sama liczba (słownik wyżej);
     *   - różne odstępy i twarde spacje.
     */
    private static function normalizuj(string $tekst): string
    {
        $tekst = mb_strtolower($tekst);
        $tekst = str_replace(['°c', "\u{00a0}"], [' stopni ', ' '], $tekst);

        foreach (self::LICZBY_SLOWNIE as $slowo => $cyfra) {
            $tekst = (string) preg_replace(
                '/(?<![\p{L}])'.preg_quote($slowo, '/').'(?![\p{L}])/u',
                $cyfra,
                $tekst,
            );
        }

        return trim((string) preg_replace('/\s+/u', ' ', $tekst));
    }

    /**
     * Wyciąga ze zdania pary (liczba, jednostka).
     *
     * Zakresy („dwóch–trzech dniach”, „dwa albo trzy dni”) dają DWIE pary,
     * bo obie liczby są treścią warunku — inaczej z „2–3 dni” pilnowalibyśmy
     * tylko trójki.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function liczby(string $zdanie): array
    {
        $tekst = self::normalizuj($zdanie);
        $jednostki = implode('|', self::JEDNOSTKI);
        $pary = [];

        $zakres = '/(\d+(?:,\d+)?)\s*(?:[–—-]|albo|lub|do)\s*(\d+(?:,\d+)?)\s*('.$jednostki.')/u';
        if (preg_match_all($zakres, $tekst, $trafienia, PREG_SET_ORDER) > 0) {
            foreach ($trafienia as $trafienie) {
                $pary[$trafienie[1].' '.$trafienie[3]] = [$trafienie[1], $trafienie[3]];
                $pary[$trafienie[2].' '.$trafienie[3]] = [$trafienie[2], $trafienie[3]];
            }
        }

        if (preg_match_all('/(\d+(?:,\d+)?)\s*('.$jednostki.')/u', $tekst, $trafienia, PREG_SET_ORDER) > 0) {
            foreach ($trafienia as $trafienie) {
                $pary[$trafienie[1].' '.$trafienie[2]] = [$trafienie[1], $trafienie[2]];
            }
        }

        return array_values($pary);
    }

    /**
     * Czy treść przepisu zawiera tę parę (liczba, jednostka).
     *
     * Między liczbą a jednostką wolno stać drugiej połowie zakresu
     * („2 albo 3 dni” zawiera zarówno „2 dni”, jak i „3 dni”) i niczemu
     * więcej — dopuszczenie dowolnych słów w środku zamieniłoby tę asercję
     * w wyszukiwanie samej cyfry gdziekolwiek w przepisie.
     *
     * @param  array{0: string, 1: string}  $para
     */
    private static function liczbaObecna(array $para, string $tresc): bool
    {
        $wzor = '/(?<![\d,])'.preg_quote($para[0], '/').'(?![\d,])'
            .'(?:\s*(?:[–—-]|albo|lub|do)\s*\d+(?:,\d+)?)?\s*'
            .preg_quote($para[1], '/').'/u';

        return preg_match($wzor, self::normalizuj($tresc)) === 1;
    }

    // ---------------------------------------------------------------------
    //  Dostawcy danych
    // ---------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> ref => [ref, proponowane zdanie] */
    public static function poprawki(): array
    {
        $wynik = [];
        foreach (self::ocenyO('POPRAW') as $wpis) {
            $ref = (string) ($wpis['ref'] ?? '');
            $wynik[$ref.' — '.(string) ($wpis['tytul'] ?? '')] = [$ref, (string) ($wpis['proponowane_zdanie'] ?? '')];
        }

        return $wynik;
    }

    /** @return array<string, array{0: string}> */
    public static function bezZmian(): array
    {
        $wynik = [];
        foreach (['CZYSTY', 'DO WIEDZY'] as $ocena) {
            foreach (self::ocenyO($ocena) as $wpis) {
                $ref = (string) ($wpis['ref'] ?? '');
                $wynik[$ocena.' '.$ref] = [$ref];
            }
        }

        return $wynik;
    }

    // ---------------------------------------------------------------------
    //  1. Kontrola — test naprawdę czyta oba pliki i widzi brak p24
    // ---------------------------------------------------------------------

    /**
     * ASERCJA KONTROLNA. Przepisów w treści startowej jest 39, a ocen
     * w przeglądzie 40. Ta jedna różnica jest całym sensem tego zadania:
     * recenzent ocenił czterdzieści przepisów, właściciel zdecydował
     * o usunięciu jednego. Gdyby oba pliki podawały tę samą liczbę,
     * znaczyłoby to, że albo p24 wrócił, albo ktoś „poprawił” plik
     * przeglądu, żeby przestał być dowodem.
     */
    public function test_kontrola_39_przepisow_wobec_40_ocen(): void
    {
        $przepisy = self::przepisy();
        $oceny = self::oceny();

        $this->assertCount(40, $oceny, 'Plik przeglądu przestał zawierać 40 ocen — to jest dowód, co powiedział recenzent, i nie wolno go dostrajać do naszej redakcji.');
        $this->assertCount(39, $przepisy, 'Treść startowa ma zawierać 39 przepisów: 40 ocenionych minus wstrzymany p24.');

        // Rozkład ocen z raportu: 1 BLOKUJE, 28 POPRAW, 2 DO WIEDZY, 9 CZYSTY.
        $this->assertCount(1, self::ocenyO('BLOKUJE'));
        $this->assertCount(28, self::ocenyO('POPRAW'));
        $this->assertCount(2, self::ocenyO('DO WIEDZY'));
        $this->assertCount(9, self::ocenyO('CZYSTY'));

        // Kontrola samej mechaniki porównania: normalizacja naprawdę zbliża
        // do siebie oba zapisy, a nie po prostu wszystko przepuszcza.
        $this->assertTrue(self::liczbaObecna(['5', 'stopni'], 'w lodówce jest nie więcej niż 5°C'));
        $this->assertTrue(self::liczbaObecna(['2', 'godzin'], 'w ciągu dwóch godzin wstawiam do lodówki'));
        $this->assertTrue(self::liczbaObecna(['2', 'dni'], 'przez pierwsze dwa albo trzy dni'));
        $this->assertTrue(self::liczbaObecna(['3', 'dni'], 'przez pierwsze dwa albo trzy dni'));
        $this->assertFalse(self::liczbaObecna(['5', 'stopni'], 'wkładam go do lodówki i tyle'));
        $this->assertFalse(self::liczbaObecna(['2', 'godzin'], 'chowam do lodówki niedługo po obiedzie'));
        $this->assertFalse(self::liczbaObecna(['0,5', 'godzin'], 'gotuję ją kilka minut'));
    }

    /**
     * Tabela warunków słownych pokrywa DOKŁADNIE te refy, które przegląd
     * ocenia na POPRAW. Bez tej kontroli nowa poprawka mogłaby wejść
     * do pliku przeglądu, a test przemilczałby ją.
     */
    public function test_kontrola_tabela_warunkow_pokrywa_wszystkie_poprawki(): void
    {
        $zPrzegladu = [];
        foreach (self::ocenyO('POPRAW') as $wpis) {
            $zPrzegladu[] = (string) ($wpis['ref'] ?? '');
        }

        sort($zPrzegladu);
        $zTabeli = array_keys(self::WARUNKI);
        sort($zTabeli);

        $this->assertSame($zPrzegladu, $zTabeli, 'Tabela WARUNKI musi mieć wiersz dla każdego przepisu POPRAW i dla żadnego innego.');
        $this->assertCount(28, $zTabeli);

        // Trzy przepisy, przy których recenzent napisał ZASTĄPIĆ, muszą mieć
        // wiersz w tabeli zdań usuniętych — inaczej sam dopisek by wystarczył.
        foreach (['p33', 'p34', 'p38'] as $ref) {
            $this->assertArrayHasKey($ref, self::USUNIETE, "Przy {$ref} recenzent kazał ZASTĄPIĆ zdanie, więc test musi pilnować, że stare zniknęło.");
        }
    }

    // ---------------------------------------------------------------------
    //  2. p24 nie ma i nic do niego nie prowadzi
    // ---------------------------------------------------------------------

    /**
     * p24 („Pomidory we własnym soku do słoików”) jest jedyną oceną
     * BLOKUJE: tekst obiecywał przechowanie do zimy po około dwudziestu
     * minutach pasteryzacji, bez zweryfikowanej kwasowości i wielkości
     * słoika, a recenzent świadomie NIE podał zastępczego czasu. Właściciel
     * wybrał usunięcie, nie przepisanie.
     */
    public function test_przepis_wstrzymany_nie_ma_go_w_tresci_startowej(): void
    {
        $blokujace = self::ocenyO('BLOKUJE');
        $this->assertSame(self::REF_WSTRZYMANY, (string) ($blokujace[0]['ref'] ?? ''), 'Kontrola: to p24 jest przepisem wstrzymanym w przeglądzie.');

        $this->assertArrayNotHasKey(self::REF_WSTRZYMANY, self::przepisy(), 'Przepis p24 jest nadal w treści startowej.');

        // Tytuł też, bo przepis dałoby się wnieść z powrotem pod innym `ref`.
        $tytul = (string) ($blokujace[0]['tytul'] ?? '');
        $this->assertNotSame('', $tytul);

        foreach (self::lista(self::PLIK_TRESCI, 'przepisy') as $przepis) {
            $this->assertNotSame($tytul, (string) ($przepis['tytul'] ?? ''), "Przepis „{$tytul}” wrócił do treści startowej pod innym numerem.");
        }
    }

    /**
     * Cichy wiersz wskazujący w próżnię jest gorszy niż brak przepisu:
     * `TrescZalazkowaSeeder` pominąłby taki komentarz i zgłosił to
     * ostrzeżeniem, którego nikt nie czyta, a plik zostałby niespójny.
     */
    public function test_nic_nie_odwoluje_sie_do_przepisu_wstrzymanego(): void
    {
        $refy = [];
        foreach (['przepisy', 'wpisy'] as $lista) {
            foreach (self::lista(self::PLIK_TRESCI, $lista) as $element) {
                $refy[(string) ($element['ref'] ?? '')] = true;
            }
        }

        $this->assertArrayNotHasKey(self::REF_WSTRZYMANY, $refy);

        $komentarze = self::lista(self::PLIK_TRESCI, 'komentarze');
        $this->assertNotEmpty($komentarze, 'Kontrola: lista komentarzy nie może być pusta, inaczej pętla niżej nie sprawdza niczego.');

        foreach ($komentarze as $indeks => $komentarz) {
            $cel = (string) ($komentarz['do'] ?? '');

            $this->assertNotSame(self::REF_WSTRZYMANY, $cel, "Komentarz na pozycji {$indeks} nadal wskazuje na usunięty przepis p24.");
            $this->assertArrayHasKey($cel, $refy, "Komentarz na pozycji {$indeks} wskazuje na nieistniejący „{$cel}”.");
        }

        // Tagi promowane wskazują nazwy tagów, nie przepisy — sprawdzamy, czy
        // przez pomyłkę nie trafił tam `ref`.
        foreach (self::lista(self::PLIK_TRESCI, 'tagi_promowane') as $tag) {
            $this->assertNotSame(self::REF_WSTRZYMANY, (string) ($tag['tag'] ?? ''));
        }
    }

    // ---------------------------------------------------------------------
    //  3. Poprawki POPRAW — liczby, warunki, zdania usunięte
    // ---------------------------------------------------------------------

    /**
     * WŁAŚCIWY POMIAR. Każda liczba i jednostka ze zdania recenzenta jest
     * w treści przepisu.
     *
     * Test NIE porównuje zdań — brzmienie zostało celowo przepisane w głos
     * autora. Porównuje fakt: „dwie godziny” nie mogło zrobić się „szybko”,
     * a „nie więcej niż 5°C” nie mogło zrobić się „w lodówce”.
     */
    #[DataProvider('poprawki')]
    public function test_liczby_z_poprawki_sa_w_tresci_przepisu(string $ref, string $zdanieRecenzenta): void
    {
        $przepisy = self::przepisy();
        $this->assertArrayHasKey($ref, $przepisy, "Przepis {$ref} zniknął z treści startowej, a przegląd kazał go tylko poprawić.");

        $tresc = $przepisy[$ref];
        $this->assertNotSame('', trim($tresc), "Przepis {$ref} ma pustą treść.");

        foreach (self::liczby($zdanieRecenzenta) as $para) {
            $this->assertTrue(
                self::liczbaObecna($para, $tresc),
                "Przepis {$ref} nie zawiera warunku „{$para[0]} {$para[1]}” ze zdania recenzenta: {$zdanieRecenzenta}",
            );
        }
    }

    /**
     * WŁAŚCIWY POMIAR, DRUGA POŁOWA. Warunek, którego nie da się wyrazić
     * liczbą, też musi być w treści.
     *
     * Osiem z 28 propozycji recenzenta nie ma w sobie żadnej liczby, więc
     * dla nich test wyżej przechodzi, nie sprawdzając niczego. Uzasadnienie
     * tabeli i jej ręcznego charakteru: komentarz przy `WARUNKI`.
     */
    #[DataProvider('poprawki')]
    public function test_warunek_slowny_z_poprawki_jest_w_tresci_przepisu(string $ref, string $zdanieRecenzenta): void
    {
        $przepisy = self::przepisy();
        $this->assertArrayHasKey($ref, $przepisy);

        $tresc = mb_strtolower($przepisy[$ref]);
        $wymagane = self::WARUNKI[$ref] ?? [];

        $this->assertNotEmpty($wymagane, "Brak wiersza w tabeli WARUNKI dla {$ref}.");

        foreach ($wymagane as $rdzen) {
            $this->assertSame(
                1,
                preg_match('/'.$rdzen.'/u', $tresc),
                "Przepis {$ref} nie zawiera warunku „{$rdzen}” z uwagi recenzenta: {$zdanieRecenzenta}",
            );
        }
    }

    /**
     * Zdania, które miały zniknąć, zniknęły.
     *
     * Recenzent napisał to wprost przy p33, p34 i p38: dopisanie ostrzeżenia
     * po anegdocie, która normalizuje ryzyko, nie usuwa anegdoty. Pozostałe
     * wiersze tabeli to zdania sprzeczne z naniesioną poprawką.
     */
    public function test_zdania_ktore_mialy_zniknac_zniknely(): void
    {
        $przepisy = self::przepisy();

        foreach (self::USUNIETE as $ref => $zdania) {
            $this->assertArrayHasKey($ref, $przepisy);

            foreach ($zdania as $zdanie) {
                $this->assertStringNotContainsString(
                    $zdanie,
                    $przepisy[$ref],
                    "Przepis {$ref} nadal zawiera zdanie, które miało zostać zastąpione: „{$zdanie}”",
                );
            }
        }
    }

    // ---------------------------------------------------------------------
    //  4. CZYSTY i DO WIEDZY — zostają nietknięte i nie giną po drodze
    // ---------------------------------------------------------------------

    /**
     * Dziewięć przepisów CZYSTY i dwa DO WIEDZY (p6 i p28 — recenzent pisze
     * wprost, że nie trzeba nic zmieniać) muszą nadal być w pliku i mieć
     * niepustą treść. Ta asercja nie pilnuje brzmienia: chodzi o to, żeby
     * poprawianie dwudziestu ośmiu przepisów nie zgubiło jedenastu
     * pozostałych.
     */
    #[DataProvider('bezZmian')]
    public function test_przepisy_bez_uwag_zostaja_w_pliku(string $ref): void
    {
        $przepisy = self::przepisy();

        $this->assertArrayHasKey($ref, $przepisy, "Przepis {$ref} nie miał uwag bezpieczeństwa, a zniknął z treści startowej.");
        $this->assertNotSame('', trim($przepisy[$ref]), "Przepis {$ref} ma pustą treść.");
    }

    /**
     * Nowe zdania są częścią opowieści, nie ostrzeżeniem na opakowaniu
     * (`docs/brand/COPY_STYLE.md`). Plik był zweryfikowany maszynowo pod tym
     * kątem przed przeglądem (`database/seeders/dane/README.md`: zero
     * wykrzykników, zero emotikon) i redakcja bezpieczeństwa nie mogła tego
     * zepsuć — najłatwiejszy sposób dopisania ostrzeżenia to właśnie
     * „UWAGA!” i „pamiętaj, że”.
     */
    public function test_poprawki_nie_wniosly_tonu_ostrzezenia(): void
    {
        foreach (self::przepisy() as $ref => $tresc) {
            $this->assertStringNotContainsString('!', $tresc, "Przepis {$ref} zawiera wykrzyknik.");

            $this->assertSame(
                0,
                preg_match('/\bUWAGA\b|\bpamiętaj\b|\bmusisz\b|\bnie wolno ci\b/iu', $tresc, $trafienie),
                "Przepis {$ref} brzmi jak ostrzeżenie, nie jak opowieść: ".($trafienie[0] ?? ''),
            );

            $this->assertSame(
                0,
                preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $tresc),
                "Przepis {$ref} zawiera emotikonę.",
            );
        }
    }
}
