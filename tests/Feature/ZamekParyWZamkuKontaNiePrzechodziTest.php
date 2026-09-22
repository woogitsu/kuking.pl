<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\ZamekPary;
use App\Domain\Users\ZamekKonta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `ZamekPary` wewnątrz `ZamekKonta` — odmowa, i to bezwarunkowa.
 *
 * DLACZEGO TEN PLIK ISTNIEJE. Audyt kolejności blokad z 11 września 2026
 * przeszedł cały graf i znalazł jedną rzecz, której nie zamykał żaden test:
 * `ZamekKonta::zablokuj($a, …)` trzyma `users[$a] FOR UPDATE` przez całe
 * wywołanie zwrotne, a nic nie zabraniało zawołać z jego wnętrza
 * `ZamekPary::zablokuj($a, $b, …)`. Audyt nazwał to wprost „hipotezą
 * przyszłego regresu, nie aktualnym znaleziskiem" — bo dziś żadna droga
 * w `app/` tego nie robi.
 *
 * Hipoteza przyszłego regresu jest jednak dokładnie tym, co testy mają
 * łapać: dziś nikt tego nie robi, a interfejs obu klas na to pozwala i nic
 * nie krzyczy. Jedna zmiana w `ConfirmEmailChange` albo w dowolnej akcji
 * e-mailowej, która kiedyś zechce dotknąć obserwowania, i cykl jest gotowy.
 *
 * CZEGO TEN TEST NIE MIERZY. Samego zakleszczenia. Zakleszczenie potrzebuje
 * dwóch połączeń i mieszka w grupie `dwa-polaczenia` (D-105); tutaj sprawdzamy
 * PROTOKÓŁ — że zabroniona kolejność nie da się nawet wywołać. To jest
 * mocniejsze niż pomiar skutku, bo skutek zależy od tego, które konto ma
 * większy UUID, czyli sypałby się raz na dwa przypadki.
 */
class ZamekParyWZamkuKontaNiePrzechodziTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_zamek_pary_w_zamku_konta_wybucha_zamiast_czekac(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/zawołany wewnątrz ZamekKonta/u');

        ZamekKonta::zablokuj($basia, fn () => ZamekPary::zablokuj($basia, $marek, fn () => null));
    }

    /**
     * KONTROLA DODATNIA. Bez niej test wyżej przechodziłby też wtedy, gdyby
     * `ZamekPary` wybuchał ZAWSZE — czyli gdyby obserwowanie i blokowanie
     * przestały działać w ogóle.
     */
    #[Test]
    public function test_zwykly_zamek_pary_dziala_dalej(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $wynik = ZamekPary::zablokuj(
            $basia,
            $marek,
            fn (?object $a, ?object $b) => [(string) $a?->getKey(), (string) $b?->getKey()],
        );

        $this->assertSame([(string) $basia->getKey(), (string) $marek->getKey()], $wynik);
    }

    /**
     * DRUGA KONTROLA DODATNIA, o innym kształcie: zamek konta wewnątrz zamka
     * PARY jest bezpieczny i ma dalej przechodzić. Bierze blokadę na wiersz,
     * który ta transakcja już trzyma, więc nie dokłada ani jednej krawędzi
     * do grafu oczekiwania.
     *
     * Bez tego testu ktoś „uszczelniłby" strażnik w obie strony i zamknął
     * drogę, która nie jest groźna.
     */
    #[Test]
    public function test_zamek_konta_w_zamku_pary_przechodzi(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $wynik = ZamekPary::zablokuj(
            $basia,
            $marek,
            fn () => ZamekKonta::zablokuj($basia, fn (?object $swiezy) => (string) $swiezy?->getKey()),
        );

        $this->assertSame((string) $basia->getKey(), $wynik);
    }

    /**
     * LICZNIK WRACA DO ZERA TAKŻE PRZEZ WYJĄTEK.
     *
     * To jest najcichsza możliwa awaria tego strażnika: gdyby zmniejszenie
     * licznika nie szło przez `finally`, pierwszy wyjątek z wnętrza zamka
     * konta zostawiłby licznik podniesiony NA ZAWSZE w tym procesie — i od
     * tej chwili każde obserwowanie i każde blokowanie wybuchałoby
     * komunikatem o zakleszczeniu, którego nie ma. W testach objawiłoby się
     * to lawiną czerwieni w plikach, które nic nie zmieniły.
     */
    #[Test]
    public function test_wyjatek_w_zamku_konta_nie_zostawia_licznika_podniesionego(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        try {
            ZamekKonta::zablokuj($basia, function (): void {
                throw new RuntimeException('coś padło w środku');
            });
        } catch (RuntimeException) {
            // tego właśnie oczekujemy
        }

        $this->assertFalse(ZamekKonta::trzymanyWTymProcesie(), 'Licznik blokad konta został podniesiony na zawsze.');

        // A skutek widać wprost: zwykły zamek pary musi dalej działać.
        $this->assertNotNull(
            ZamekPary::zablokuj($basia, $marek, fn (?object $a) => $a),
            'Po wyjątku w zamku konta zwykły zamek pary przestał działać.',
        );
    }

    /**
     * ZAGNIEŻDŻENIE ZAMKA KONTA W SOBIE SAMYM jest dozwolone — dlatego to
     * licznik, a nie flaga. Gdyby była flaga, drugie wyjście z zagnieżdżenia
     * wyczyściłoby ją, choć zewnętrzna blokada jeszcze trwa, i strażnik
     * przepuściłby dokładnie to, czego pilnuje.
     */
    #[Test]
    public function test_zamek_konta_w_sobie_samym_dalej_pilnuje_zamka_pary(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/zawołany wewnątrz ZamekKonta/u');

        ZamekKonta::zablokuj($basia, fn () => ZamekKonta::zablokuj(
            $basia,
            fn () => null,
        ) ?? ZamekPary::zablokuj($basia, $marek, fn () => null));
    }
}
