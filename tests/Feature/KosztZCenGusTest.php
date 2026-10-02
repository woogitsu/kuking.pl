<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Koszt\CennikSkladnikow;
use App\Domain\Recipes\Koszt\IloscZTekstu;
use App\Domain\Recipes\Koszt\SzacunekKosztuZCen;
use App\Models\CenaSkladnika;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Orientacyjny koszt dania z cen GUS, gdy autor nie podał kwoty (D-286, część 2).
 *
 * Testy szacunku chodzą na MAŁYM cenniku z tego pliku, a nie na
 * `database/data/ceny_skladnikow.csv` — ceny w repozytorium zmieniają się
 * co kwartał, a oczekiwana kwota ma być policzona tu ręcznie i stać
 * w miejscu. Prawdziwy plik ma osobny test: że się wczytuje w całości
 * i że każda cena mówi, skąd jest.
 *
 * @bez-kontroli-dodatniej Plik CSV jest czytany wyłącznie po to, żeby policzyć jego wiersze, a asercje na tekście dotyczą wyniku działania kodu (zdania szacunku), nie treści źródeł; kontrole ujemne — liczenie wody do pokrycia i dopasowanie początkiem słowa — wykonano ręcznie i oba testy oblały.
 */
class KosztZCenGusTest extends TestCase
{
    use RefreshDatabase;

    private const NAGLOWEK = 'klucz,nazwa,wzorce,wyklucz,cena_zl,za_ilosc,jednostka,g_na_jednostke,g_szklanka,g_lyzka,g_lyzeczka,g_sztuka,okres,zrodlo,zmienna_bdl';

    // ------------------------------------------------------------------
    // Odczyt ilości z tekstu
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: ?array{ilosc: float, miara: string}}> */
    public static function ilosci(): array
    {
        return [
            'szklanki' => ['2 szklanki mąki', ['ilosc' => 2.0, 'miara' => 'szklanka']],
            'dekagramy' => ['50 dag kiełbasy', ['ilosc' => 500.0, 'miara' => 'g']],
            'kilogram z przecinkiem' => ['1,5 kg ziemniaków', ['ilosc' => 1500.0, 'miara' => 'g']],
            'litr' => ['1 l mleka', ['ilosc' => 1000.0, 'miara' => 'ml']],
            'sztuki bez słowa miary' => ['3 jajka', ['ilosc' => 3.0, 'miara' => 'sztuka']],
            'ułamek znakiem' => ['½ łyżeczki soli', ['ilosc' => 0.5, 'miara' => 'lyzeczka']],
            'ułamek mieszany' => ['1 1/2 szklanki mleka', ['ilosc' => 1.5, 'miara' => 'szklanka']],
            'tysiąc gramów' => ['1 000 g jajek', ['ilosc' => 1000.0, 'miara' => 'g']],
            'tysiące po nazwie' => ['jajka 1 500 g', ['ilosc' => 1500.0, 'miara' => 'g']],
            'kilka grup tysięcy' => ['1 234 567 g mąki', ['ilosc' => 1234567.0, 'miara' => 'g']],
            'nierozpoznane grupowanie' => ['1 00 g jajek', null],
            'nierówne grupy tysięcy' => ['12 345 67 g mąki', null],
            'przedział' => ['2-3 łyżki oleju', ['ilosc' => 2.5, 'miara' => 'lyzka']],
            'przedział do w gramach' => ['200 do 300 g mąki', ['ilosc' => 250.0, 'miara' => 'g']],
            'przedział lub w sztukach' => ['2 lub 3 jajka', ['ilosc' => 2.5, 'miara' => 'sztuka']],
            'przedział albo z ułamkami' => ['1/2 albo 3/4 l mleka', ['ilosc' => 625.0, 'miara' => 'ml']],
            'przedział z przecinkami' => ['1,5 do 2,5 kg mąki', ['ilosc' => 2000.0, 'miara' => 'g']],
            'do jako cel, nie przedział' => ['2 łyżki do smażenia', ['ilosc' => 2.0, 'miara' => 'lyzka']],
            'uszkodzony przedział' => ['200 do dużo g mąki', null],
            'pół słownie' => ['pół szklanki cukru', ['ilosc' => 0.5, 'miara' => 'szklanka']],
            'ilość po nazwie' => ['mąka pszenna — 2 szklanki', ['ilosc' => 2.0, 'miara' => 'szklanka']],
            'procent to nie ilość' => ['śmietana 18%', null],
            'typ mąki to nie ilość' => ['mąka typ 650', null],
            'garść' => ['garść koperku', null],
            'garść z liczbą' => ['1 garść koperku', ['ilosc' => 1.0, 'miara' => 'nieprzeliczalna']],
            'bez ilości' => ['sól do smaku', null],
            // #1964: „kotlet” to miara, nie „sztuka” produktu.
            'kotlety' => ['2 kotlety schabowe', ['ilosc' => 2.0, 'miara' => 'kotlet']],
            'kotlet' => ['1 kotlet schabowy', ['ilosc' => 1.0, 'miara' => 'kotlet']],
            'pół kotleta' => ['pół kotleta', ['ilosc' => 0.5, 'miara' => 'kotlet']],
        ];
    }

    #[Test]
    #[DataProvider('ilosci')]
    public function ilosc_z_tekstu(string $tekst, ?array $oczekiwane): void
    {
        $this->assertEquals($oczekiwane, IloscZTekstu::rozbierz($tekst));
    }

    #[Test]
    public function dopelniacz_jednostki_po_ulamku_zachowuje_mase_i_objetosc(): void
    {
        foreach ([
            '0,5 grama soli' => ['ilosc' => 0.5, 'miara' => 'g'],
            '0,5 dekagrama mąki' => ['ilosc' => 5.0, 'miara' => 'g'],
            '0,5 kilograma masła' => ['ilosc' => 500.0, 'miara' => 'g'],
            '0,5 mililitra mleka' => ['ilosc' => 0.5, 'miara' => 'ml'],
            'pół kilograma masła' => ['ilosc' => 500.0, 'miara' => 'g'],
            '1/2 mililitra mleka' => ['ilosc' => 0.5, 'miara' => 'ml'],
        ] as $tekst => $oczekiwane) {
            $this->assertSame(
                $oczekiwane,
                IloscZTekstu::rozbierz($tekst),
                'KOSZT_2643_DOPELNIACZ_MIARY_NIE_JEST_SZTUKA',
            );
        }

        $this->assertSame(['ilosc' => 0.5, 'miara' => 'sztuka'], IloscZTekstu::rozbierz('0,5 jajka'));
        $this->assertSame(['ilosc' => 500.0, 'miara' => 'g'], IloscZTekstu::rozbierz('0,5 kg masła'));
        $this->assertSame(['ilosc' => 625.0, 'miara' => 'ml'], IloscZTekstu::rozbierz('0,5 do 0,75 litra mleka'));
    }

    #[Test]
    public function dopelniacz_kilograma_daje_koszt_pol_kilograma_a_nie_pol_sztuki(): void
    {
        $plik = $this->plikCsv([
            'maslo,masło,maslo|masla,,10.00,1,kg,1000,,,,200,2025,GUS,',
        ]);
        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow', ['--plik' => $plik]), Artisan::output());

        $slownie = $this->przepis(['0,5 kilograma masła']);
        $gramy = $this->przepis(['500 g masła']);
        $polSztuki = $this->przepis(['0,5 sztuki masła']);

        $zMiara = app(SzacunekKosztuZCen::class)->dla($slownie);
        $zGramow = app(SzacunekKosztuZCen::class)->dla($gramy);
        $zeSztuki = app(SzacunekKosztuZCen::class)->dla($polSztuki);

        $this->assertNotNull($zMiara);
        $this->assertNotNull($zGramow);
        $this->assertNotNull($zeSztuki);
        $this->assertTrue($zMiara->jestPrzedzial());
        $this->assertEquals($zGramow, $zMiara, 'KOSZT_2643_WYCENA_POL_KILOGRAMA');
        $this->assertNotEquals($zeSztuki, $zMiara, 'Pół sztuki ma w tym cenniku tylko 100 g.');
        $this->assertSame('0,5 kilograma masła', $slownie->ingredients->first()->ingredient_text);
    }

    #[Test]
    public function grupowane_tysiace_zachowuja_cala_mase_i_nie_staja_sie_sztukami(): void
    {
        foreach ([' ', "\u{00A0}", "\u{202F}"] as $odstep) {
            $this->assertSame(
                ['ilosc' => 1000.0, 'miara' => 'g'],
                IloscZTekstu::rozbierz("1{$odstep}000 g jajek"),
                'KOSZT_2561_GRUPOWANE_TYSIACE_NIE_SA_SZTUKAMI',
            );
            $this->assertSame(['ilosc' => 1500.0, 'miara' => 'g'], IloscZTekstu::rozbierz("jajka 1{$odstep}500 g"));
        }

        $this->assertSame(['ilosc' => 1500.0, 'miara' => 'g'], IloscZTekstu::rozbierz('1 000 do 2 000 g mąki'));
        $this->assertSame(['ilosc' => 1500.0, 'miara' => 'g'], IloscZTekstu::rozbierz('1,5 kg mąki'));
        $this->assertSame(['ilosc' => 1500.0, 'miara' => 'g'], IloscZTekstu::rozbierz('1 1/2 kg mąki'));
        $this->assertSame(['ilosc' => 3.0, 'miara' => 'sztuka'], IloscZTekstu::rozbierz('3 jajka'));

        foreach (['1 00 g jajek', '1 0000 g jajek', '12 345 67 g jajek', 'jajka 1 00 g'] as $tekst) {
            $this->assertNull(IloscZTekstu::rozbierz($tekst), 'Uszkodzona liczba nie może dać częściowej wyceny.');
        }
    }

    #[Test]
    public function grupowane_tysiace_zachowuja_mase_pokrycie_i_kwote_pelnego_szacunku(): void
    {
        $this->cennik();

        $zapisCaly = $this->przepis(['1000 g jajek']);
        $zapisGrupowany = $this->przepis(['1 000 g jajek']);
        $bezpiecznaOdmowa = $this->przepis(['1 00 g jajek', '2 jajka']);
        $zwykleSztuki = $this->przepis(['3 jajka']);

        $caly = app(SzacunekKosztuZCen::class)->dla($zapisCaly);
        $grupowany = app(SzacunekKosztuZCen::class)->dla($zapisGrupowany);
        $odmowa = app(SzacunekKosztuZCen::class)->dla($bezpiecznaOdmowa);
        $sztuki = app(SzacunekKosztuZCen::class)->dla($zwykleSztuki);

        $this->assertNotNull($caly);
        $this->assertTrue($caly->jestPrzedzial());
        $this->assertSame(16, $caly->od, '1000 g jajek z ceną za sztukę 60 g ma koszt 18,83 zł przed rozrzutem.');
        $this->assertSame(22, $caly->do);
        $this->assertEquals($caly, $grupowany, 'KOSZT_2561_PELNA_WYCENA_MASY_NIE_SZTUKE');
        $this->assertNotNull($odmowa);
        $this->assertFalse($odmowa->jestPrzedzial(), 'Błędne grupowanie nie może tworzyć wiarygodnego przedziału.');
        $this->assertNotNull($sztuki);
        $this->assertTrue($sztuki->jestPrzedzial(), 'Zapis trzech jajek pozostaje poprawną liczbą sztuk.');
        $this->assertSame('1 000 g jajek', $zapisGrupowany->ingredients->first()->ingredient_text);
    }

    #[Test]
    public function slowne_zakresy_zachowuja_obie_granice_i_miare(): void
    {
        foreach (['do', 'lub', 'albo'] as $spojnik) {
            $this->assertSame(
                ['ilosc' => 250.0, 'miara' => 'g'],
                IloscZTekstu::rozbierz("200 {$spojnik} 300 g mąki"),
                'KOSZT_2477_SLOWNY_ZAKRES_ZACHOWUJE_GRANICE_I_MIARE',
            );
        }
    }

    #[Test]
    public function uszkodzony_zakres_nie_liczy_poprawnego_poczatku(): void
    {
        foreach (['200 do 300- g mąki', '200 do 300.5.5 g mąki', '200 do 300 g do 400 g mąki'] as $tekst) {
            $this->assertNull(
                IloscZTekstu::rozbierz($tekst),
                'KOSZT_2477_USZKODZONY_ZAKRES_NIE_LICZY_POCZATKU',
            );
        }

        $this->assertSame(['ilosc' => 2.0, 'miara' => 'lyzka'], IloscZTekstu::rozbierz('2 łyżki do smażenia'));
        $this->assertSame(['ilosc' => 250.0, 'miara' => 'g'], IloscZTekstu::rozbierz('200-300 g mąki'));
    }

    #[Test]
    public function uszkodzony_ogon_jednej_liczby_nie_udaje_poprawnej_ilosci(): void
    {
        foreach (['300- g mąki', '300.5.5 g mąki', '300 g do 400 g mąki'] as $tekst) {
            $this->assertNull(
                IloscZTekstu::rozbierz($tekst),
                'KOSZT_2477_USZKODZONA_JEDNA_LICZBA_NIE_DAJE_WYCENY',
            );
        }

        $this->assertSame(['ilosc' => 2.0, 'miara' => 'lyzka'], IloscZTekstu::rozbierz('2 łyżki do smażenia'));
        $this->assertSame(['ilosc' => 300.0, 'miara' => 'g'], IloscZTekstu::rozbierz('300 g mąki'));
    }

    #[Test]
    public function opis_celu_po_produkcie_nie_kasuje_odczytanej_masy(): void
    {
        foreach (['300 g maki do 2 porcji', '300 g mąki do 2 porcji', '300 g maki do 2 ciast', '300 g maki (do 2 ciast)'] as $tekst) {
            $this->assertSame(
                ['ilosc' => 300.0, 'miara' => 'g'],
                IloscZTekstu::rozbierz($tekst),
                'KOSZT_2508_OPIS_CELU_NIE_KASUJE_MASY',
            );
        }
        $this->assertNull(IloscZTekstu::rozbierz('300 g do 400 g mąki'));
        $this->assertNull(IloscZTekstu::rozbierz('300.5.5 g mąki'));
    }

    #[Test]
    public function opis_celu_zachowuje_kwote_i_pokrycie_pelnej_wyceny(): void
    {
        $this->cennik();
        $bezDopisku = app(SzacunekKosztuZCen::class)->dla($this->przepis(['300 g mąki']));
        $this->assertNotNull($bezDopisku);
        $this->assertTrue($bezDopisku->jestPrzedzial());
        foreach (['300 g mąki do 2 porcji', '300 g mąki do 2 ciast', '300 g mąki (do 2 ciast)'] as $tekst) {
            $zDopiskiem = app(SzacunekKosztuZCen::class)->dla($this->przepis([$tekst]));
            $this->assertNotNull($zDopiskiem, 'KOSZT_2508_PELNA_WYCENA_OPISU_CELU');
            $this->assertEquals($bezDopisku, $zDopiskiem, 'KOSZT_2508_PELNA_WYCENA_OPISU_CELU');
        }
    }

    #[Test]
    public function druga_bezposrednia_ilosc_nie_zaniza_masy_do_pierwszej_liczby(): void
    {
        foreach ([
            '1 kg i 200 g mąki', '1 kg 200 g mąki', '500 g + 200 g mąki',
            '1 kg oraz 200 g mąki', '1 kg i 200 mąki', '500 g + 2 jajka',
        ] as $tekst) {
            $this->assertNull(
                IloscZTekstu::rozbierz($tekst),
                'KOSZT_2578_DRUGA_ILOSC_NIE_ZANIZA_MASY',
            );
        }

        $this->assertSame(['ilosc' => 1200.0, 'miara' => 'g'], IloscZTekstu::rozbierz('1200 g mąki'));
        $this->assertSame(['ilosc' => 700.0, 'miara' => 'g'], IloscZTekstu::rozbierz('700 g mąki'));
        $this->assertSame(['ilosc' => 300.0, 'miara' => 'g'], IloscZTekstu::rozbierz('300 g mąki do 2 porcji'));
        $this->assertSame(['ilosc' => 1000.0, 'miara' => 'g'], IloscZTekstu::rozbierz('1 kg mąki 20%'));
        $this->assertSame(['ilosc' => 250.0, 'miara' => 'g'], IloscZTekstu::rozbierz('200 do 300 g mąki'));
        $this->assertNull(IloscZTekstu::rozbierz('mąka typ 650'));
        $this->assertNull(IloscZTekstu::rozbierz('śmietana 18%'));
    }

    #[Test]
    public function druga_bezposrednia_ilosc_daje_jawny_brak_wyceny_zamiast_zanizonej_kwoty(): void
    {
        $this->cennik();
        $pelny = app(SzacunekKosztuZCen::class)->dla($this->przepis(['1200 g mąki', '2 jajka']));
        $this->assertNotNull($pelny);
        $this->assertTrue($pelny->jestPrzedzial());

        foreach (['1 kg i 200 g mąki', '1 kg 200 g mąki', '500 g + 200 g mąki'] as $tekst) {
            $przepis = $this->przepis([$tekst, '2 jajka']);
            $wynik = app(SzacunekKosztuZCen::class)->dla($przepis);

            $this->assertNotNull($wynik);
            $this->assertFalse($wynik->jestPrzedzial(), 'KOSZT_2578_PELNA_WYCENA_NIE_UDAJE_PIERWSZEJ_MASY');
            $this->assertNull($wynik->od);
            $this->assertNull($wynik->do);
            $this->assertStringContainsString($tekst, $wynik->zdanie());
            $this->assertSame($tekst, $przepis->ingredients->first()->ingredient_text);
        }
    }

    // ------------------------------------------------------------------
    // Dopasowanie do cennika
    // ------------------------------------------------------------------

    #[Test]
    public function dopasowanie_calymi_slowami_z_wykluczeniami_i_kolejnoscia(): void
    {
        $this->cennik();
        $cennik = new CennikSkladnikow;

        $this->assertSame('maka_pszenna', $cennik->dopasuj('2 szklanki mąki')?->klucz);
        // „maka" nie może trafić w „makaron" — całe słowa, nie początki.
        $this->assertNull($cennik->dopasuj('300 g makaronu'));
        // Mąka ziemniaczana to nie mąka pszenna.
        $this->assertNull($cennik->dopasuj('2 łyżki mąki ziemniaczanej'));
        // Pierwszy pasujący wiersz wygrywa: sucha przed zwykłą.
        $this->assertSame('kielbasa_sucha', $cennik->dopasuj('20 dag kiełbasy suchej')?->klucz);
        $this->assertSame('kielbasa', $cennik->dopasuj('20 dag kiełbasy')?->klucz);
    }

    // ------------------------------------------------------------------
    // Szacunek
    // ------------------------------------------------------------------

    #[Test]
    public function slowne_zakresy_daja_taki_sam_koszt_jak_rownorzedne_zapisy_liczbowe(): void
    {
        $this->cennik();

        foreach ([
            ['200 do 300 g mąki', '250 g mąki'],
            ['2 lub 3 jajka', '2,5 jajka'],
            ['1/2 albo 3/4 l mleka', '625 ml mleka'],
        ] as [$slownie, $liczbowo]) {
            $zapisSlowny = app(SzacunekKosztuZCen::class)->dla($this->przepis([$slownie]));
            $zapisLiczbowy = app(SzacunekKosztuZCen::class)->dla($this->przepis([$liczbowo]));

            $this->assertNotNull($zapisSlowny, 'KOSZT_2477_PELNA_WYCENA_ZAKRESU');
            $this->assertTrue($zapisSlowny->jestPrzedzial(), 'KOSZT_2477_PELNA_WYCENA_ZAKRESU');
            $this->assertEquals($zapisLiczbowy, $zapisSlowny, 'KOSZT_2477_PELNA_WYCENA_ZAKRESU');
        }
    }

    #[Test]
    public function uszkodzony_zakres_nie_udaje_sztuk_a_woda_i_no_amount_nie_podbijaja_pokrycia(): void
    {
        $this->cennik();

        $bledny = app(SzacunekKosztuZCen::class)->dla($this->przepis(['200 do dużo g mąki', '2 jajka']));
        $this->assertNotNull($bledny);
        $this->assertFalse($bledny->jestPrzedzial());
        $this->assertStringContainsString('200 do dużo g mąki', $bledny->zdanie());

        $uszkodzony = app(SzacunekKosztuZCen::class)->dla($this->przepis(['200 do 300- g mąki', '2 jajka']));
        $this->assertNotNull($uszkodzony);
        $this->assertFalse($uszkodzony->jestPrzedzial());
        $this->assertStringContainsString('200 do 300- g mąki', $uszkodzony->zdanie());

        $podstawa = app(SzacunekKosztuZCen::class)->dla($this->przepis(['200 do 300 g mąki']));
        $zWoda = $this->przepis(['200 do 300 g mąki', '3 l wody', '5 kg warzyw']);
        $bezFlagi = app(SzacunekKosztuZCen::class)->dla($zWoda);
        $this->assertNotNull($bezFlagi);
        $this->assertFalse($bezFlagi->jestPrzedzial(), 'Nieznana masa bez no_amount musi zatrzymać szacunek.');
        $zWoda->ingredients()->where('ingredient_text', '5 kg warzyw')->update(['no_amount' => true]);
        $wynik = app(SzacunekKosztuZCen::class)->dla($zWoda->fresh());

        $this->assertNotNull($podstawa);
        $this->assertTrue($podstawa->jestPrzedzial());
        $this->assertEquals($podstawa, $wynik);
    }

    #[Test]
    public function nalesniki_dostaja_przedzial_policzony_recznie(): void
    {
        $this->cennik();

        $przepis = $this->przepis([
            '2 szklanki mąki',        // 2 × 160 g × 3,76 zł/kg = 1,2032 zł
            '2 jajka',                // 2 × 1,13 zł            = 2,26 zł
            '2 szklanki mleka',       // 500 ml × 4,40 zł/l     = 2,20 zł
            '1 szklanka wody',        // woda: 0 zł i poza pokryciem
            'szczypta soli',          // drobiazg — pomijany
            '2 łyżki oleju',          // 28 g × 9,55 zł / 920 g = 0,2907 zł
        ]);

        // Razem 5,9539 zł → ±15% → 5,06…6,85 → „ok. 5–7 zł".
        $wynik = app(SzacunekKosztuZCen::class)->dla($przepis);

        $this->assertNotNull($wynik);
        $this->assertTrue($wynik->jestPrzedzial(), (string) $wynik->powod);
        $this->assertSame(5, $wynik->od);
        $this->assertSame(7, $wynik->do);
        $this->assertSame(
            'Orientacyjny koszt: ok. 5–7 zł za całość (średnie ceny detaliczne GUS z 2025 r.). W Twoim sklepie może być inaczej.',
            $wynik->zdanie(),
        );

        // Deterministycznie: drugi raz to samo.
        $this->assertEquals($wynik, app(SzacunekKosztuZCen::class)->dla($przepis->fresh()));
    }

    /**
     * D-286, część 3: warzywa (ziemniaki, cebula, marchew) z MRiRW/ZSRIR
     * obok mięsa z GUS — zdanie musi wymienić OBA źródła, nie tylko GUS.
     */
    #[Test]
    public function zupa_jarzynowa_liczy_z_gus_i_zsrir_razem(): void
    {
        $plik = $this->plikCsv([
            'kielbasa,kiełbasa,kielbasa|kielbasy,,32.96,1,kg,1000,,,,,2025,GUS - za 1kg,1753078',
            'ziemniaki,ziemniaki,ziemniak|ziemniaki|ziemniakow,,1.85,1,kg,1000,,,,150,2026,"MRiRW, ZSRIR: notowania - ziemniaki",',
            'cebula,cebula,cebula|cebule,,2.42,1,kg,1000,,,,100,2026,"MRiRW, ZSRIR: notowania - cebula biala",',
            'marchew,marchew,marchew|marchewki,,2.89,1,kg,1000,,,,80,2026,"MRiRW, ZSRIR: notowania - marchew",',
        ]);
        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow', ['--plik' => $plik]), Artisan::output());

        $przepis = $this->przepis([
            '20 dag kiełbasy',   // 200 g × 32,96 zł/kg = 6,592 zł  (GUS)
            '1 kg ziemniaków',   // 1000 g × 1,85 zł/kg = 1,85 zł   (MRiRW/ZSRIR)
            '2 cebule',          // 2 × 100 g × 2,42 zł/kg = 0,484 zł (MRiRW/ZSRIR)
            '3 marchewki',       // 3 × 80 g × 2,89 zł/kg = 0,6936 zł (MRiRW/ZSRIR)
        ]);

        // Razem 9,6196 zł → ±15% → 8,18…11,06 → „ok. 8–12 zł".
        $wynik = app(SzacunekKosztuZCen::class)->dla($przepis);

        $this->assertNotNull($wynik);
        $this->assertTrue($wynik->jestPrzedzial(), (string) $wynik->powod);
        $this->assertSame(8, $wynik->od);
        $this->assertSame(12, $wynik->do);
        $this->assertSame(
            'Orientacyjny koszt: ok. 8–12 zł za całość (średnie ceny detaliczne GUS i MRiRW/ZSRIR z 2025, 2026 r.). W Twoim sklepie może być inaczej.',
            $wynik->zdanie(),
        );
    }

    /**
     * D-286, część 3 (decyzja właściciela z 26.09.2026): warzywo policzone
     * z ceny HURTOWEJ × przelicznik musi mieć to WPROST napisane w zdaniu
     * pod kosztem, nie tylko w `zrodlo` cennika.
     */
    #[Test]
    public function kapusniak_mowi_wprost_ze_kapusta_to_szacunek_z_hurtu(): void
    {
        $plik = $this->plikCsv([
            'kielbasa,kiełbasa,kielbasa|kielbasy,,32.96,1,kg,1000,,,,,2025,GUS - za 1kg,1753078',
            'kapusta,kapusta biała,kapusta|kapusty,,2.29,1,kg,1000,,,,1200,2026,"MRiRW, ZSRIR: szacunek z cen hurtowych x 1,6, kapusta biala",',
        ]);
        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow', ['--plik' => $plik]), Artisan::output());

        $przepis = $this->przepis([
            '20 dag kiełbasy',   // 200 g × 32,96 zł/kg = 6,592 zł (GUS, detal)
            '1 kg kapusty',      // 1000 g × 2,29 zł/kg = 2,29 zł  (MRiRW/ZSRIR, HURT × 1,6)
        ]);

        // Razem 8,882 zł → ±15% → 7,55…10,21 → „ok. 7–11 zł".
        $wynik = app(SzacunekKosztuZCen::class)->dla($przepis);

        $this->assertNotNull($wynik);
        $this->assertTrue($wynik->jestPrzedzial(), (string) $wynik->powod);
        $this->assertSame(7, $wynik->od);
        $this->assertSame(11, $wynik->do);
        $this->assertSame(
            'Orientacyjny koszt: ok. 7–11 zł za całość (średnie ceny detaliczne GUS i MRiRW/ZSRIR z 2025, 2026 r.; '
            .'część cen to szacunek z cen hurtowych MRiRW/ZSRIR). W Twoim sklepie może być inaczej.',
            $wynik->zdanie(),
        );
    }

    /**
     * Ręcznie policzony przelicznik hurt→detal dla siedmiu warzyw, których
     * ZSRIR notuje wyłącznie hurtowo — D-286, część 3. Wartości hurtowe to
     * średnia z pięciu rynków (Bronisze, Kalisz, Łódź, Poznań, Rzeszów),
     * odczytana z arkusza „HURT WARZ” biuletynu MRiRW/ZSRIR z 14-22.09.2026,
     * ręcznie z min-max każdego rynku. Cena w pliku CSV musi być tą średnią
     * razy `SzacunekKosztuZCen::MNOZNIK_HURT_DETAL` — inaczej mnożnik
     * i cennik po cichu się rozjadą.
     */
    #[Test]
    public function ceny_hurtowe_warzyw_w_pliku_sa_srednia_razy_mnoznik(): void
    {
        // [klucz w pliku => średnia hurtowa (zł/kg albo zł/szt) ręcznie
        // policzona z min-max pięciu rynków w arkuszu „HURT WARZ”].
        $hurtowe = [
            'kapusta' => 1.43,
            'buraki' => 1.63,
            'por' => 2.455,
            'seler' => 3.22,
            'pietruszka' => 4.85,
            'salata' => 3.058,
            'ogorek' => 4.59,
        ];

        $wiersze = $this->wczytajRealnyCennik();

        foreach ($hurtowe as $klucz => $hurtSrednia) {
            $this->assertArrayHasKey($klucz, $wiersze, "Brakuje wiersza „{$klucz}” w pliku cennika.");

            $oczekiwana = round($hurtSrednia * SzacunekKosztuZCen::MNOZNIK_HURT_DETAL, 2);

            $this->assertEqualsWithDelta(
                $oczekiwana,
                (float) $wiersze[$klucz]['cena_zl'],
                0.005,
                "Cena „{$klucz}” w pliku nie zgadza się z hurtSrednia × MNOZNIK_HURT_DETAL "
                .'(zmieniono jedno bez drugiego?).',
            );
            $this->assertStringContainsString(
                'szacunek z cen hurtowych',
                $wiersze[$klucz]['zrodlo'],
                "Wiersz „{$klucz}” nie mówi wprost, że cena jest szacunkiem z hurtu.",
            );
        }
    }

    #[Test]
    public function za_male_pokrycie_masy_daje_wyjasnienie_bez_kwoty(): void
    {
        $this->cennik();

        // Kurczak z ceną (1,5 kg), warzywa bez ceny (0,4 kg) → 79% < 90%.
        // Woda (3 l) NIE ratuje pokrycia — gdyby się liczyła, wyszłoby
        // 4,5 kg z 4,9 kg = 92% i rosół dostałby kwotę bez ani jednej marchewki.
        $przepis = $this->przepis(['1,5 kg kurczaka', '40 dag warzyw', '3 l wody']);

        $wynik = app(SzacunekKosztuZCen::class)->dla($przepis);

        $this->assertNotNull($wynik);
        $this->assertFalse($wynik->jestPrzedzial());
        $this->assertStringContainsString('nie mamy średnich cen części składników', $wynik->zdanie());
        $this->assertStringContainsString('„40 dag warzyw”', $wynik->zdanie());
    }

    #[Test]
    public function skladnik_bez_ustalonej_masy_zatrzymuje_szacunek(): void
    {
        $this->cennik();

        $przepis = $this->przepis(['2 szklanki mąki', '2 cebule']);

        $wynik = app(SzacunekKosztuZCen::class)->dla($przepis);

        $this->assertNotNull($wynik);
        $this->assertFalse($wynik->jestPrzedzial());
        $this->assertStringContainsString('„2 cebule”', $wynik->zdanie());
        $this->assertStringContainsString('To nie błąd', $wynik->zdanie());
    }

    /**
     * #1964: „2 kotlety schabowe” spadało do miary „sztuka”, a schab w cenniku
     * ma cenę za kg bez `g_sztuka` — składnik wypadał z pokrycia. Kotlet ma
     * teraz masę 120 g, ale TYLKO dla schabu (ta sama wartość co
     * `schab,kotlet,120` w miarach domowych wartości odżywczych).
     */
    #[Test]
    public function kotlety_schabowe_licza_sie_jak_gramy_schabu(): void
    {
        $this->cennik();

        $kotlety = app(SzacunekKosztuZCen::class)->dla($this->przepis(['2 kotlety schabowe']));
        $gramy = app(SzacunekKosztuZCen::class)->dla($this->przepis(['240 g schabu']));

        // 2 × 120 g × 25,04 zł/kg = 6,0096 zł → ±15% → 5,11…6,91 → „ok. 5–7 zł".
        $this->assertNotNull($kotlety);
        $this->assertTrue($kotlety->jestPrzedzial(), (string) $kotlety->powod);
        $this->assertSame(5, $kotlety->od);
        $this->assertSame(7, $kotlety->do);
        $this->assertEquals($gramy, $kotlety);
    }

    /**
     * Kontrola ujemna do #1964: masa kotleta nie jest ogólna. Kurczak ma
     * `g_sztuka` (cały kurczak, 1600 g) — „kotlet z kurczaka” nie może się
     * po cichu przeliczyć na 1,6 kg ani na 120 g schabu. Brak źródła masy
     * zostaje brakiem, a szacunek mówi, czego nie wie.
     */
    #[Test]
    public function kotlet_bez_znanej_masy_dla_produktu_zatrzymuje_szacunek(): void
    {
        $this->cennik();

        $wynik = app(SzacunekKosztuZCen::class)->dla($this->przepis(['2 kotlety schabowe', '2 kotlety z kurczaka']));

        $this->assertNotNull($wynik);
        $this->assertFalse($wynik->jestPrzedzial());
        $this->assertStringContainsString('nie wiemy, ile waży „2 kotlety z kurczaka”', $wynik->zdanie());
    }

    /**
     * Prawdziwy cennik: „kotlety schabowe” trafiają w wiersz schabu
     * (przymiotnik „schabowy” we wzorcach), a masa kotleta istnieje tylko
     * dla kluczy, które cennik naprawdę ma.
     */
    #[Test]
    public function prawdziwy_cennik_zna_kotlety_schabowe(): void
    {
        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow'));
        $cennik = new CennikSkladnikow;

        foreach (['2 kotlety schabowe', '1 kotlet schabowy', '4 kotletów schabowych'] as $tekst) {
            $this->assertSame('schab', $cennik->dopasuj($tekst)?->klucz, $tekst);
        }

        foreach (array_keys(SzacunekKosztuZCen::MASA_KOTLETA) as $klucz) {
            $this->assertNotNull(CenaSkladnika::find($klucz), "Masa kotleta dla „{$klucz}”, którego nie ma w cenniku.");
        }
    }

    #[Test]
    public function prawdziwy_cennik_nie_myli_maki_gryczanej_z_kasza(): void
    {
        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow'));
        $cennik = new CennikSkladnikow;

        foreach (['500 g mąki gryczanej', '500 g mąka gryczana', '500 g mąki ryżowej'] as $tekst) {
            $this->assertNull($cennik->dopasuj($tekst), 'KOSZT_2605_MAKA_NIE_JEST_KASZA: '.$tekst);
        }

        foreach (['500 g kaszy gryczanej', '500 g kasza gryczana', '500 g kaszę gryczaną'] as $tekst) {
            $this->assertSame('kasza_gryczana', $cennik->dopasuj($tekst)?->klucz, $tekst);
        }

        $this->assertSame('maka_pszenna', $cennik->dopasuj('500 g mąki pszennej')?->klucz);
    }

    #[Test]
    public function prawdziwy_cennik_nie_myli_maki_gryczanej_w_pelnej_wycenie(): void
    {
        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow'));

        $samaKasza = app(SzacunekKosztuZCen::class)->dla($this->przepis(['500 g kaszy gryczanej']));
        $this->assertNotNull($samaKasza);
        $this->assertTrue($samaKasza->jestPrzedzial());

        $zMaka = app(SzacunekKosztuZCen::class)->dla($this->przepis([
            '500 g kaszy gryczanej',
            '500 g mąki gryczanej',
        ]));

        $this->assertNotNull($zMaka);
        $this->assertFalse($zMaka->jestPrzedzial(), 'KOSZT_2605_MAKA_NIE_DAJE_POKRYCIA');
        $this->assertStringContainsString('nie mamy średnich cen części składników', $zMaka->zdanie());
        $this->assertStringContainsString('„500 g mąki gryczanej”', $zMaka->zdanie());
    }

    /**
     * Zbieżność z miarami domowymi wartości odżywczych (D-286, „Zbieżność”):
     * dopóki koszt trzyma własną masę kotleta, nie może się ona rozjechać
     * z `database/data/odzywcze/miary.csv`. To wymagany zasób importu;
     * jego zniknięcie musi oblać test, zamiast wyłączyć kontrolę.
     */
    #[Test]
    public function masa_kotleta_zgadza_sie_z_miarami_domowymi(): void
    {
        $plik = base_path('database/data/odzywcze/miary.csv');

        $this->assertFileExists($plik, 'Brak wymaganego pliku database/data/odzywcze/miary.csv.');

        $miary = [];
        foreach (array_slice(file($plik, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 1) as $linia) {
            $w = str_getcsv($linia, escape: '');
            if (($w[1] ?? '') === 'kotlet') {
                $miary[$w[0]] = (float) $w[2];
            }
        }

        foreach (SzacunekKosztuZCen::MASA_KOTLETA as $klucz => $gramy) {
            $this->assertSame($miary[$klucz] ?? null, $gramy, "Masa kotleta „{$klucz}” różni się od miary.csv.");
        }
    }

    #[Test]
    public function bez_ani_jednego_trafienia_w_cennik_milczymy(): void
    {
        $this->cennik();

        $this->assertNull(app(SzacunekKosztuZCen::class)->dla($this->przepis(['1 kg ziemniaków', '2 cebule'])));
    }

    #[Test]
    public function pusty_cennik_nic_nie_pokazuje(): void
    {
        $this->assertNull(app(SzacunekKosztuZCen::class)->dla($this->przepis(['2 szklanki mąki'])));
    }

    #[Test]
    public function strona_przepisu_pokazuje_przedzial_gdy_autor_nie_podal_kwoty(): void
    {
        $this->cennik();
        $przepis = $this->przepis(['2 szklanki mąki', '2 jajka', '2 szklanki mleka', '2 łyżki oleju']);

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Orientacyjny koszt: ok. 5–7 zł za całość', false)
            ->assertSee('W Twoim sklepie może być inaczej.')
            ->assertDontSee('wg autora');
    }

    #[Test]
    public function kwota_autora_wygrywa_z_szacunkiem(): void
    {
        $this->cennik();
        $przepis = $this->przepis(['2 szklanki mąki', '2 jajka', '2 szklanki mleka'], ['estimated_cost_pln' => 12]);

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Szacunkowy koszt: ok. 12 zł (wg autora)')
            ->assertDontSee('Orientacyjny koszt');
    }

    // ------------------------------------------------------------------
    // Komenda i plik w repozytorium
    // ------------------------------------------------------------------

    #[Test]
    public function plik_z_repozytorium_wczytuje_sie_w_calosci_i_kazda_cena_ma_zrodlo(): void
    {
        $wierszePliku = count(file(base_path('database/data/ceny_skladnikow.csv'), FILE_SKIP_EMPTY_LINES)) - 1;

        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow'));
        $this->assertSame($wierszePliku, CenaSkladnika::count());

        foreach (CenaSkladnika::all() as $wiersz) {
            $this->assertNotSame('', trim($wiersz->zrodlo), "Cena {$wiersz->klucz} nie mówi, skąd jest.");

            // GUS (BDL) odświeża się numerem zmiennej; MRiRW/ZSRIR (warzywa,
            // D-286 część 3) numeru zmiennej nie ma — jego wiersze odświeża
            // `scripts/ceny-warzyw-zsrir-pobierz.py` po tytule notowania,
            // nie po numerze. Każdy priced wiersz musi jednak jawnie należeć
            // do jednego z tych dwóch źródeł — inny napis w `zrodlo` jest
            // pomyłką, nie trzecim, cichym źródłem.
            if ($wiersz->bezplatny()) {
                continue;
            }

            if (str_starts_with($wiersz->zrodlo, 'GUS')) {
                $this->assertNotNull($wiersz->zmienna_bdl, "Cena {$wiersz->klucz} nie ma numeru zmiennej GUS — nie da się jej odświeżyć.");
            } elseif (str_starts_with($wiersz->zrodlo, 'MRiRW')) {
                $this->assertNull($wiersz->zmienna_bdl, "Cena {$wiersz->klucz} ma numer zmiennej GUS, ale źródło to MRiRW/ZSRIR.");
            } else {
                $this->fail("Cena {$wiersz->klucz} ma źródło spoza GUS i MRiRW/ZSRIR: {$wiersz->zrodlo}");
            }
        }

        // Ponowne wczytanie tego samego pliku daje ten sam stan.
        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow'));
        $this->assertSame($wierszePliku, CenaSkladnika::count());
    }

    #[Test]
    public function bledny_plik_niczego_nie_zmienia(): void
    {
        $this->cennik();
        $przed = CenaSkladnika::count();

        $plik = $this->plikCsv([
            'maka_pszenna,mąka pszenna,maka|maki,,-3.76,1,kg,1000,160,10,3,,2025,GUS,1749185',
        ]);

        $this->assertSame(1, Artisan::call('kuking:ceny-skladnikow', ['--plik' => $plik]));
        $this->assertStringContainsString('cena_zl musi być liczbą', Artisan::output());
        $this->assertSame($przed, CenaSkladnika::count(), 'Błędny plik skasował cennik.');
    }

    #[Test]
    public function wiersze_spoza_pliku_znikaja_a_tryb_sprawdz_nic_nie_zapisuje(): void
    {
        $this->cennik();

        $plik = $this->plikCsv(['cukier,cukier biały,cukier|cukru,,3.28,1,kg,1000,220,15,5,,2025,GUS,4994']);

        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow', ['--plik' => $plik, '--sprawdz' => true]));
        $this->assertGreaterThan(1, CenaSkladnika::count());

        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow', ['--plik' => $plik]));
        $this->assertSame(['cukier'], CenaSkladnika::pluck('klucz')->all());
    }

    // ------------------------------------------------------------------

    private function cennik(): void
    {
        $plik = $this->plikCsv([
            'woda,woda,woda|wody|wode,gazow,0,1,l,1000,250,15,5,,stała,koszt pomijalny,',
            'kielbasa_sucha,kiełbasa sucha,kielbasa sucha|kielbasy suchej,,52.02,1,kg,1000,,,,,2025,GUS,8264',
            'kielbasa,kiełbasa,kielbasa|kielbasy,,32.96,1,kg,1000,,,,,2025,GUS,1753078',
            'kurczak,kurczak,kurczak|kurczaka,filet|piers,13.24,1,kg,1000,,,,1600,2025,GUS,4961',
            'mleko,mleko,mleko|mleka,kokos,4.40,1,l,1000,250,15,5,,2025,GUS,4975',
            'jajka,jajko,jajko|jajka|jajek|jaj,,1.13,1,szt,60,,,,60,2025,GUS,4993',
            'olej,olej rzepakowy,olej|oleju,kokos,9.55,1,l,920,230,14,5,,2025,GUS,4984',
            'maka_pszenna,mąka pszenna,maka|maki|make,ziemniaczan|kukurydz,3.76,1,kg,1000,160,10,3,,2025,GUS,1749185',
            'schab,schab bez kości,schab|schabu|schabowy|schabowe|schabowych,,25.04,1,kg,1000,,,,,2025,GUS,633049',
        ]);

        $this->assertSame(0, Artisan::call('kuking:ceny-skladnikow', ['--plik' => $plik]), Artisan::output());
    }

    /**
     * Prawdziwy plik `database/data/ceny_skladnikow.csv`, po kluczu — do
     * testów, które sprawdzają same LICZBY w repozytorium (np. przelicznik
     * hurt→detal), a nie zachowanie kodu na małym cenniku testowym.
     *
     * @return array<string, array<string, string>>
     */
    private function wczytajRealnyCennik(): array
    {
        $uchwyt = fopen(base_path('database/data/ceny_skladnikow.csv'), 'r');
        $this->assertNotFalse($uchwyt);

        $naglowek = fgetcsv($uchwyt, escape: '');
        $wiersze = [];

        while (($wiersz = fgetcsv($uchwyt, escape: '')) !== false) {
            if ($wiersz === [null] || $wiersz === ['']) {
                continue;
            }

            $wiersze[$wiersz[0]] = array_combine($naglowek, $wiersz);
        }

        fclose($uchwyt);

        return $wiersze;
    }

    /**
     * @param  list<string>  $wiersze
     */
    private function plikCsv(array $wiersze): string
    {
        $plik = storage_path('framework/testing/ceny-'.bin2hex(random_bytes(4)).'.csv');
        @mkdir(dirname($plik), 0777, true);
        file_put_contents($plik, self::NAGLOWEK."\n".implode("\n", $wiersze)."\n");

        return $plik;
    }

    /**
     * @param  list<string>  $skladniki
     * @param  array<string, mixed>  $atrybuty
     */
    private function przepis(array $skladniki, array $atrybuty = []): Recipe
    {
        $autor = $this->user('autor'.bin2hex(random_bytes(2)));
        $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'visibility' => 'public', ...$atrybuty]);

        foreach ($skladniki as $i => $tekst) {
            $przepis->ingredients()->create(['ingredient_text' => $tekst, 'position' => $i]);
        }
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Wymieszaj i usmaż.']);

        return $przepis->fresh();
    }
}
