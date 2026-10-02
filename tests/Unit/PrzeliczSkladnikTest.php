<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\Porcje\IloscKuchenna;
use App\Domain\Recipes\Porcje\JednostkaKuchenna;
use App\Domain\Recipes\Porcje\PrzeliczSkladnik;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Przeliczanie JEDNEGO wiersza składnika na inną liczbę porcji (D-284, V2).
 *
 * Macierz zapisów, które ludzie naprawdę wpisują w jedno pole tekstowe
 * (D-017): liczba z przodu, jednostka słowem i skrótem, ułamki, „pół”,
 * zakres, „mąka – 500 g”, „szklanka mąki” bez liczby. Druga macierz — to,
 * czego przeliczać NIE WOLNO.
 *
 * KONTROLA UJEMNA (wykonana ręcznie przy pisaniu):
 *  - szczyptę chronią DWA zamki — słowo w `PrzeliczSkladnik::BEZ_PRZELICZANIA`
 *    i rodzaj `NIEPOLICZALNA` w `JednostkaKuchenna`. Zdjęcie jednego test
 *    przepuszcza (drugi trzyma), zdjęcie obu oblewa „szczypta soli”
 *    i „2 szczypty pieprzu”;
 *  - krok 1 g zamiast 10 g dla setek gramów oblewa „175 g” × 1,5
 *    (wychodzi „263 g cukru” zamiast „260 g cukru”).
 *  - #2455: kontrola CI usuwa gałąź liczby grupowanej; test musi oblać
 *    dla zapisu „1 000 g”, także po myślniku i z NBSP.
 */
final class PrzeliczSkladnikTest extends TestCase
{
    /** @return array<string, array{0: string, 1: float, 2: string}> */
    public static function przeliczane(): array
    {
        return [
            'sztuki bez jednostki' => ['2 jajka', 1.5, '3 jajka'],
            'gramy skrótem' => ['200 g mąki', 1.5, '300 g mąki'],
            'gramy sklejone z liczbą' => ['200g mąki', 2.0, '400g mąki'],
            'gramy do kroku 10' => ['175 g cukru', 1.5, '260 g cukru'],
            'drożdże — krok 5 poniżej setki' => ['25 g drożdży', 1.5, '40 g drożdży'],
            'łyżka odmienia się w ułamku' => ['1 łyżka masła', 1.5, '1½ łyżki masła'],
            'łyżki w liczbie 2–4' => ['2 łyżki cukru', 1.5, '3 łyżki cukru'],
            'łyżek w liczbie 5+' => ['2 łyżki cukru', 2.5, '5 łyżek cukru'],
            'pół kostki' => ['pół kostki drożdży', 2.0, '1 kostka drożdży'],
            'półtorej szklanki' => ['półtorej szklanki mleka', 2.0, '3 szklanki mleka'],
            'ułamek zwykły' => ['1/3 szklanki oleju', 1.5, '½ szklanki oleju'],
            'liczba mieszana' => ['1 1/2 szklanki mąki', 2.0, '3 szklanki mąki'],
            'znak ułamka' => ['1½ łyżeczki soli', 0.5, '¾ łyżeczki soli'],
            'po myślniku' => ['mąka pszenna – 500 g', 1.5, 'mąka pszenna – 750 g'],
            'po dwukropku' => ['jajka: 3', 2.0, 'jajka: 6'],
            'liczba z jednostką na końcu' => ['masło 200 g', 0.5, 'masło 100 g'],
            'sztuki skrótem na końcu' => ['jajka 3 szt.', 2.0, 'jajka 6 szt.'],
            'około' => ['ok. 250 ml śmietany', 2.0, 'ok. 500 ml śmietany'],
            'zakres' => ['2-3 ząbki czosnku', 2.0, '4-6 ząbków czosnku'],
            // #2249: zakres słowem, tak jak czyta go parser wartości odżywczych.
            'zakres „do”' => ['2 do 3 jajka', 2.0, '4 do 6 jajka'],
            'zakres „lub” z jednostką i ułamkiem' => ['1 lub 2 łyżki cukru', 1.5, '1½ lub 3 łyżki cukru'],
            'zakres „albo” w dół' => ['2 albo 3 szklanki mąki', 0.5, '1 albo 1½ szklanki mąki'],
            'zakres „do” po myślniku' => ['mąka – 200 do 300 g', 1.5, 'mąka – 300 do 450 g'],
            'słowo „do” bez liczby to nie zakres' => ['1 szklanka mleka do ciasta', 2.0, '2 szklanki mleka do ciasta'],
            'kilogramy po przecinku' => ['1 kg ziemniaków', 1.25, '1,25 kg ziemniaków'],
            'litry z przecinkiem w zapisie' => ['0,5 l mleka', 1.5, '0,75 l mleka'],
            'tylko pierwsza liczba' => ['2 puszki pomidorów (po 400 g)', 1.5, '3 puszki pomidorów (po 400 g)'],
            'szklanka bez liczby to jedna szklanka' => ['szklanka mąki', 2.0, '2 szklanki mąki'],
            'Łyżka z wielkiej litery bez liczby' => ['Łyżka miodu', 0.5, '½ łyżki miodu'],
            'opakowanie' => ['1 opakowanie cukru waniliowego', 2.0, '2 opakowania cukru waniliowego'],
            'nigdy do zera' => ['1/3 szklanki oleju', 0.25, '⅛ szklanki oleju'],
            'powyżej dziesięciu — całości' => ['4 ziemniaki', 3.3, '13 ziemniaki'],
        ];
    }

    /** @return array<string, array{0: string, 1: float, 2: string}> */
    public static function liczbyZGrupowaniem(): array
    {
        return [
            'na początku' => ['1 000 g mąki', 0.5, '500 g mąki'],
            'po myślniku' => ['mąka – 1 000 g', 0.5, 'mąka – 500 g'],
            'po dwukropku' => ['mąka: 1 000 g', 2.0, 'mąka: 2000 g'],
            'w środku zdania ze znaną jednostką' => ['mąka 1 000 g', 0.5, 'mąka 500 g'],
            'spacja nierozdzielająca' => ["1\u{00A0}000 g mąki", 0.5, '500 g mąki'],
            'wąska spacja nierozdzielająca' => ["1\u{202F}000 g mąki", 0.5, '500 g mąki'],
            'dwie grupy cyfr' => ['1 000 000 g mąki', 0.5, '500000 g mąki'],
            'obie granice zakresu' => ['1 000–2 000 g mąki', 0.5, '500–1000 g mąki'],
            'zakres słowem' => ['mąka – 1 000 do 2 000 g', 0.5, 'mąka – 500 do 1000 g'],
        ];
    }

    #[DataProvider('liczbyZGrupowaniem')]
    public function test_grupowanie_tysiecy_przelicza_cala_ilosc(string $tekst, float $mnoznik, string $oczekiwany): void
    {
        $wynik = PrzeliczSkladnik::przelicz($tekst, false, $mnoznik);

        $this->assertSame($oczekiwany, $wynik->tekst(), 'GRUPOWANIE_TYSIECY_WYNIK: ilość musi być przeliczona w całości.');
        $this->assertTrue($wynik->zmieniony, 'GRUPOWANIE_TYSIECY_WYNIK: wynik powinien być oznaczony jako zmieniony.');
    }

    #[DataProvider('przeliczane')]
    public function test_przelicza_ilosc_i_odmienia_jednostke(string $tekst, float $mnoznik, string $oczekiwany): void
    {
        $wynik = PrzeliczSkladnik::przelicz($tekst, false, $mnoznik);

        $this->assertTrue($wynik->zmieniony, "„{$tekst}” × {$mnoznik} nie został przeliczony.");
        $this->assertSame($oczekiwany, $wynik->tekst());
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function nieprzeliczane(): array
    {
        return [
            'szczypta' => ['szczypta soli', false],
            'kilka szczypt' => ['2 szczypty pieprzu', false],
            'do smaku' => ['1 łyżeczka soli do smaku', false],
            'ile weźmie' => ['mleko — ile weźmie', false],
            'odrobina' => ['odrobina gałki muszkatołowej', false],
            'na oko' => ['2 garście mąki na oko', false],
            'bez liczby' => ['natka pietruszki', false],
            'liczba bez jednostki w środku' => ['mąka typ 650', false],
            'liczba przyklejona myślnikiem' => ['3-składnikowy sos', false],
            'flaga „bez ilości”' => ['200 ml mleka', true],
            'zła grupa cyfr na początku' => ['1 00 g mąki', false],
            'zła grupa cyfr przed jednostką' => ['mąka 1 00 g', false],
            'czterocyfrowa grupa' => ['1 0000 g mąki', false],
            'tabulator nie jest separatorem tysięcy' => ["1\t000 g mąki", false],
            'tabulator przed jednostką' => ["mąka 1\t000 g", false],
            'nieobsługiwana dziesiętna liczba grupowana' => ['1 000,5 g mąki', false],
            'podwójna spacja na początku' => ['1  500 g mąki', false],
            'podwójna spacja przed jednostką' => ['mąka 1  500 g', false],
            'podwójna spacja po poprawnej grupie' => ['1 000  500 g mąki', false],
            'dwa tabulatory przed jednostką' => ["mąka 1\t\t500 g", false],
        ];
    }

    #[DataProvider('nieprzeliczane')]
    public function test_nie_rusza_tego_czego_nie_wolno_mnozyc(string $tekst, bool $bezIlosci): void
    {
        $wynik = PrzeliczSkladnik::przelicz($tekst, $bezIlosci, 3.0);

        $this->assertFalse($wynik->zmieniony, "„{$tekst}” nie powinien się przeliczyć.");
        $this->assertSame($tekst, $wynik->tekst());
    }

    public function test_mnoznik_jeden_oddaje_tekst_autora_co_do_znaku(): void
    {
        $wynik = PrzeliczSkladnik::przelicz('  2 Łyżki   masła ', false, 1.0);

        $this->assertFalse($wynik->zmieniony);
        $this->assertSame('  2 Łyżki   masła ', $wynik->tekst());
    }

    public function test_procent_opisuje_produkt_a_pozniejsza_masa_jest_iloscia(): void
    {
        foreach ([
            ['30 % śmietanki — 200 g', 2.0, '30 % śmietanki — 400 g', true],
            ['30 % śmietanki — 200 g', 0.5, '30 % śmietanki — 100 g', true],
            ['30 % śmietanki — 200 g', 1.0, '30 % śmietanki — 200 g', false],
            ['30 proc. śmietanki — 200 g', 2.0, '30 proc. śmietanki — 400 g', true],
            ['30 procent śmietanki — 200 g', 2.0, '30 procent śmietanki — 400 g', true],
            ['30 procentowej śmietanki — 200 g', 2.0, '30 procentowej śmietanki — 400 g', true],
            ['30 % śmietanki', 2.0, '30 % śmietanki', false],
            ['30 proc. śmietanki', 2.0, '30 proc. śmietanki', false],
            ['30% śmietanki — 200 g', 2.0, '30% śmietanki — 400 g', true],
            ['śmietanka 30% — 200 g', 2.0, 'śmietanka 30% — 400 g', true],
            ['200 g śmietanki 30 %', 2.0, '400 g śmietanki 30 %', true],
            ['30 g śmietanki', 2.0, '60 g śmietanki', true],
            ['30 % śmietanki — 200-300 g', 2.0, '30 % śmietanki — 400-600 g', true],
        ] as [$tekst, $mnoznik, $oczekiwany, $zmieniony]) {
            $wynik = PrzeliczSkladnik::przelicz($tekst, false, $mnoznik);

            $this->assertSame($oczekiwany, $wynik->tekst(), 'PORCJE_2629_PROCENT_NIE_JEST_ILOSCIA');
            $this->assertSame($zmieniony, $wynik->zmieniony, 'PORCJE_2629_PROCENT_NIE_JEST_ILOSCIA');
        }
    }

    public function test_wynik_rozdziela_ilosc_od_zdania_autora(): void
    {
        $wynik = PrzeliczSkladnik::przelicz('mąka pszenna – 500 g, przesiana', false, 2.0);

        $this->assertSame('mąka pszenna – ', $wynik->przed);
        $this->assertSame('1000 g', $wynik->ilosc);
        $this->assertSame(', przesiana', $wynik->po);
    }

    public function test_zaokraglenie_kuchenne_daje_ulamki_ze_znakiem(): void
    {
        $this->assertSame('¼', IloscKuchenna::zapis(0.2, JednostkaKuchenna::KUCHENNA));
        $this->assertSame('⅓', IloscKuchenna::zapis(0.34, JednostkaKuchenna::KUCHENNA));
        $this->assertSame('⅔', IloscKuchenna::zapis(0.7, JednostkaKuchenna::KUCHENNA));
        $this->assertSame('2¾', IloscKuchenna::zapis(2.8, JednostkaKuchenna::KUCHENNA));
        $this->assertSame('7½', IloscKuchenna::zapis(7.4, JednostkaKuchenna::KUCHENNA));
        $this->assertSame('0,3', IloscKuchenna::zapis(0.26, JednostkaKuchenna::METRYCZNA));
        $this->assertSame('1,05', IloscKuchenna::zapis(1.05, JednostkaKuchenna::METRYCZNA_DUZA));
        // #2655: zaokrąglenie liczone w g, nie w kg — 1,04 kg to 1040 g, krok 50 g.
        $this->assertSame('1,05', IloscKuchenna::zapis(1.04, JednostkaKuchenna::METRYCZNA_DUZA));
        $this->assertSame('1,05', IloscKuchenna::zapis(1.049, JednostkaKuchenna::METRYCZNA_DUZA));
    }

    /** @return array<string, array{0: string, 1: string, 2: float, 3: string, 4: string}> */
    public static function rownowazneZapisy(): array
    {
        return [
            'kg i g, ćwiartka' => ['0,1 kg drożdży', '100 g drożdży', 0.25, '0,025 kg drożdży', '25 g drożdży'],
            'l i ml, ćwiartka' => ['0,1 l mleka', '100 ml mleka', 0.25, '0,025 l mleka', '25 ml mleka'],
            'kg i g, połowa' => ['0,25 kg cukru', '250 g cukru', 0.5, '0,13 kg cukru', '130 g cukru'],
            'l i ml, 0,25 l' => ['0,25 l śmietanki', '250 ml śmietanki', 0.5, '0,13 l śmietanki', '130 ml śmietanki'],
            'dag i g' => ['1 dag drożdży', '10 g drożdży', 0.25, '0,25 dag drożdży', '2,5 g drożdży'],
            'kg i g, większa ilość' => ['1 kg mąki', '1000 g mąki', 0.25, '0,25 kg mąki', '250 g mąki'],
            'kg i g, mnożnik ponad jeden' => ['0,3 kg masła', '300 g masła', 1.5, '0,45 kg masła', '450 g masła'],
            'kg i g, przejście przez 1 kg' => ['0,7 kg mąki', '700 g mąki', 1.6, '1,1 kg mąki', '1100 g mąki'],
            'zakres kg i g' => ['0,1–0,2 kg soli', '100–200 g soli', 0.25, '0,025–0,05 kg soli', '25–50 g soli'],
        ];
    }

    #[DataProvider('rownowazneZapisy')]
    public function test_ta_sama_masa_w_roznych_jednostkach_daje_ten_sam_wynik(string $wKg, string $wG, float $mnoznik, string $oczekiwanyKg, string $oczekiwanyG): void
    {
        $a = PrzeliczSkladnik::przelicz($wKg, false, $mnoznik);
        $b = PrzeliczSkladnik::przelicz($wG, false, $mnoznik);

        // „PORCJE_2655_ZAOKRAGLENIE_W_BAZIE” — kontrola ujemna: wróć do kroku 0,05 kg.
        $this->assertSame($oczekiwanyKg, $a->tekst(), 'PORCJE_2655_ZAOKRAGLENIE_W_BAZIE');
        $this->assertSame($oczekiwanyG, $b->tekst(), 'PORCJE_2655_ZAOKRAGLENIE_W_BAZIE');
        // Jednostka autora zostaje, a fizyczna ilość jest ta sama.
        $this->assertEqualsWithDelta(
            self::wBazie($oczekiwanyKg),
            self::wBazie($oczekiwanyG),
            1e-6,
            'PORCJE_2655_ZAOKRAGLENIE_W_BAZIE',
        );
    }

    private static function wBazie(string $tekst): float
    {
        preg_match('/^([\d,]+)(?:–([\d,]+))? ?(kg|g|dag|l|ml)/u', $tekst, $m);
        $wspolczynnik = ['kg' => 1000, 'l' => 1000, 'dag' => 10, 'g' => 1, 'ml' => 1][$m[3]];

        return (float) str_replace(',', '.', $m[2] !== '' ? $m[2] : $m[1]) * $wspolczynnik;
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function sumy(): array
    {
        return [
            'kg i g, ×2' => ['1 kg i 200 g mąki', 2.0],
            'kg i g, ×0,5' => ['1 kg i 200 g mąki', 0.5],
            'kg + g' => ['1 kg + 200 g mąki', 2.0],
            'kg+g bez spacji' => ['1 kg+200 g mąki', 2.0],
            'kg oraz g' => ['1 kg oraz 200 g mąki', 2.0],
            'kg plus g' => ['1 kg plus 200 g mąki', 2.0],
            'kg g bez spójnika' => ['1 kg 200 g mąki', 2.0],
            'po myślniku' => ['mąka – 1 kg i 200 g', 2.0],
            'masa z mianem' => ['1 kilogram i 200 gramów mąki', 2.0],
            'szklanka i łyżki' => ['1 szklanka i 2 łyżki mleka', 2.0],
            'litr i ml' => ['1 l i 200 ml mleka', 0.5],
            'mnożenie w nawiasie' => ['500 g (2 × 250 g) mąki', 2.0],
            'mnożenie z x' => ['500 g (2x250 g)', 2.0],
            'mnożenie po jednostce w środku zdania' => ['mąka 500 g (2 × 250 g)', 0.5],
        ];
    }

    #[DataProvider('sumy')]
    public function test_suma_ilosci_nie_jest_przeliczana_po_kawalku(string $tekst, float $mnoznik): void
    {
        $wynik = PrzeliczSkladnik::przelicz($tekst, false, $mnoznik);

        // „PORCJE_2609_SUMA_BEZ_PRZELICZENIA” — kontrola ujemna: wyłącz `toSuma()`.
        $this->assertFalse($wynik->zmieniony, 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertTrue($wynik->nieprzeliczony, 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertSame($tekst, $wynik->tekst(), 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
        $this->assertNull(PrzeliczSkladnik::odczytaj($tekst), 'PORCJE_2609_SUMA_BEZ_PRZELICZENIA');
    }

    /** @return array<string, array{0: string, 1: float, 2: string}> */
    public static function niesumy(): array
    {
        return [
            'opakowanie z gramaturą' => ['2 puszki (po 400 g)', 2.0, '4 puszki (po 400 g)'],
            'opakowanie, gramatura w nawiasie' => ['2 puszki pomidorów (400 g)', 0.5, '1 puszka pomidorów (400 g)'],
            'dwa produkty przez „i”' => ['2 jajka i 200 g mąki', 2.0, '4 jajka i 200 g mąki'],
            'inna wielkość: masa i objętość' => ['200 g masła i 100 ml mleka', 2.0, '400 g masła i 100 ml mleka'],
            'dwa produkty, słowo między' => ['200 g mąki i 100 g cukru', 2.0, '400 g mąki i 100 g cukru'],
            'zakres to nie suma' => ['2–3 łyżki oleju', 2.0, '4–6 łyżek oleju'],
            'typ mąki' => ['500 g mąki typ 650', 2.0, '1000 g mąki typ 650'],
            'nawias bez mnożenia' => ['500 g (przesianej)', 2.0, '1000 g (przesianej)'],
        ];
    }

    #[DataProvider('niesumy')]
    public function test_nie_sumy_sa_przeliczane_jak_dotad(string $tekst, float $mnoznik, string $oczekiwany): void
    {
        $wynik = PrzeliczSkladnik::przelicz($tekst, false, $mnoznik);

        $this->assertTrue($wynik->zmieniony, $tekst);
        $this->assertFalse($wynik->nieprzeliczony, $tekst);
        $this->assertSame($oczekiwany, $wynik->tekst());
    }

    public function test_przy_mnozniku_jeden_suma_nie_ma_uwagi(): void
    {
        $wynik = PrzeliczSkladnik::przelicz('1 kg i 200 g mąki', false, 1.0);

        $this->assertFalse($wynik->nieprzeliczony);
        $this->assertSame('1 kg i 200 g mąki', $wynik->tekst());
    }
}
