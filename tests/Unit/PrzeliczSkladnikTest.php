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
        $this->assertSame('1,05', IloscKuchenna::zapis(1.04, JednostkaKuchenna::METRYCZNA_DUZA));
    }
}
