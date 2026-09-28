<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\Odzywcze\ParserSkladnika;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Parser ilości, jednostki i nazwy na prawdziwych polskich zapisach (D-299).
 *
 * Zapisy pochodzą z `DemoSeeder`, z opowieści w `tresc-zalazkowa.json`
 * i z tego, jak ludzie piszą składniki z kartek: ułamki z klawiatury
 * telefonu, „pół kilo”, zakresy „2–3”, ilość po myślniku, waga w nawiasie.
 */
final class ParserSkladnikaTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: float|null, 2: string|null, 3: string}>
     */
    public static function zapisy(): iterable
    {
        yield 'liczba i szklanka' => ['2 szklanki mąki pszennej', 2.0, 'szklanka', 'maki pszennej'];
        yield 'ilość po myślniku' => ['mąka pszenna – 500 g', 500.0, 'g', 'maka pszenna'];
        yield 'gramy bez spacji' => ['500g mąki', 500.0, 'g', 'maki'];
        yield 'ułamek z klawiatury' => ['½ szklanki mleka', 0.5, 'szklanka', 'mleka'];
        yield 'liczba mieszana ze znakiem' => ['1½ szklanki mleka', 1.5, 'szklanka', 'mleka'];
        yield 'liczba mieszana z ukośnikiem' => ['1 1/2 szklanki cukru', 1.5, 'szklanka', 'cukru'];
        yield 'pół słownie' => ['pół kostki masła', 0.5, 'kostka', 'masla'];
        yield 'pół kilo' => ['pół kilo ziemniaków', 0.5, 'kg', 'ziemniakow'];
        yield 'półtorej' => ['półtorej szklanki mąki', 1.5, 'szklanka', 'maki'];
        yield 'dwie i pół' => ['dwie i pół szklanki mąki', 2.5, 'szklanka', 'maki'];
        yield 'przecinek dziesiętny' => ['1,5 kg ziemniaków', 1.5, 'kg', 'ziemniakow'];
        yield 'zakres z półpauzą' => ['2–3 ząbki czosnku', 2.5, 'zabek', 'czosnku'];
        yield 'zakres z łącznikiem' => ['2-3 ząbki czosnku', 2.5, 'zabek', 'czosnku'];
        yield 'sztuki bez jednostki' => ['3 jajka', 3.0, null, 'jajka'];
        yield 'ilość po dwukropku' => ['jajka: 3', 3.0, null, 'jajka'];
        yield 'sztuki na końcu' => ['jajka 3 szt.', 3.0, 'szt', 'jajka'];
        yield 'gramy na końcu' => ['masło 200 g', 200.0, 'g', 'maslo'];
        yield 'około' => ['ok. 200 g boczku', 200.0, 'g', 'boczku'];
        yield 'dekagramy' => ['10 dag sera żółtego', 10.0, 'dag', 'sera zoltego'];
        yield 'mililitry bez spacji' => ['250ml mleka', 250.0, 'ml', 'mleka'];
        yield 'szczypta bez liczby' => ['szczypta soli', 1.0, 'szczypta', 'soli'];
        yield 'jednostka bez liczby' => ['kawałek selera, wielkości pięści', 1.0, 'kawalek', 'selera'];
        yield 'przymiotnik miary' => ['1 płaska łyżeczka soli', 1.0, 'lyzeczka', 'soli'];
        yield 'niecała szklanka' => ['niecała szklanka cukru', 1.0, 'szklanka', 'cukru'];
        yield 'łyżka stołowa' => ['łyżka stołowa oleju', 1.0, 'lyzka', 'oleju'];
        yield 'dopisek po przecinku' => ['1 kurczak zagrodowy, najlepiej starsza kura', 1.0, null, 'kurczak zagrodowy'];
        yield 'przyimek po jednostce' => ['2 filety z dorsza', 2.0, 'filet', 'dorsza'];
        yield 'kotlety (#1963)' => ['2 kotlety schabowe', 2.0, 'kotlet', 'schabowe'];
        yield 'typ mąki to nie ilość' => ['mąka typ 650', null, null, 'maka typ 650'];
        yield 'procent to nie ilość' => ['mleko 3,2% - 1 l', 1.0, 'l', 'mleko 3,2%'];
        yield 'połówki to nie pół' => ['połówki śliwek', null, null, 'polowki sliwek'];
    }

    #[Test]
    #[DataProvider('zapisy')]
    public function test_czyta_ilosc_jednostke_i_nazwe(string $tekst, ?float $ilosc, ?string $jednostka, string $nazwa): void
    {
        $odczyt = (new ParserSkladnika)->odczytaj($tekst);

        $this->assertSame($ilosc, $odczyt->ilosc, "ilość w „{$tekst}”");
        $this->assertSame($jednostka, $odczyt->jednostka, "jednostka w „{$tekst}”");
        $this->assertSame($nazwa, $odczyt->nazwa, "nazwa w „{$tekst}”");
    }

    #[Test]
    public function test_waga_w_nawiasie_dotyczy_jednego_pojemnika(): void
    {
        $parser = new ParserSkladnika;

        $this->assertSame(400.0, $parser->odczytaj('1 puszka pomidorów (400 g)')->gramyZNawiasu);
        $this->assertSame(800.0, $parser->odczytaj('2 puszki pomidorów (po 400 g)')->gramyZNawiasu);
        $this->assertSame(800.0, $parser->odczytaj('2 puszki pomidorów (400 g)')->gramyZNawiasu, 'Waga przy puszce jest na jedną puszkę.');
        $this->assertSame(200.0, $parser->odczytaj('kostka masła (200 g)')->gramyZNawiasu);
        $this->assertSame(150.0, $parser->odczytaj('3 jajka (150 g)')->gramyZNawiasu, 'Przy sztukach bez „po” nawias to masa całości.');
    }

    #[Test]
    public function test_do_smaku_i_do_podania_znacza_brak_ilosci(): void
    {
        $parser = new ParserSkladnika;

        $this->assertTrue($parser->odczytaj('sól do smaku')->bezIlosciZTekstu);
        $this->assertTrue($parser->odczytaj('sól — do smaku, na końcu')->bezIlosciZTekstu);
        $this->assertSame('sol', $parser->odczytaj('sól — do smaku, na końcu')->nazwa);
        $this->assertTrue($parser->odczytaj('natka pietruszki do podania')->bezIlosciZTekstu);
        $this->assertFalse($parser->odczytaj('olej do smażenia')->bezIlosciZTekstu, 'Olej do smażenia to realna, nieznana ilość — nie „do smaku”.');
    }

    #[Test]
    public function test_slowo_jednostki_zostaje_do_nazwy_gdy_moze_byc_jej_czescia(): void
    {
        $parser = new ParserSkladnika;

        $this->assertSame('liscie laurowe', $parser->odczytaj('2 liście laurowe')->nazwaZJednostka);
        $this->assertNull($parser->odczytaj('2 szklanki mąki')->nazwaZJednostka, 'Szklanka nigdy nie jest częścią nazwy.');
    }
}
