<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suma dobowych sufitów poczty musi zmieścić się pod limitem dostawcy.
 *
 * PO CO TEN TEST ISTNIEJE
 * Konto EmailLabs na planie STARTUP daje **300 listów na dobę na CAŁY
 * serwis** (`docs/decyzje/POCZTA.md` §1) — jedno wiadro, z którego czerpie
 * wszystko: potwierdzenia rejestracji, przypomnienia haseł, logowanie
 * linkiem (issue #25) i tygodniowe podsumowanie (issue #11).
 *
 * Każda funkcja, która wysyła WIELE listów naraz, ma własny dobowy sufit
 * (`App\Domain\Security\DziennyBudzetListow`) — i każda z nich widzi TYLKO
 * SWÓJ. To jest dobre rozwiązanie na jedną funkcję i fatalne na trzy: gdyby
 * każda dostała po 120, suma wyniosłaby 360 przy limicie 300, a pierwszą
 * rzeczą, która by wtedy przestała działać, jest POTWIERDZENIE REJESTRACJI —
 * bo ono sufitu nie ma i nie może mieć.
 *
 * Nikt tej sumy nie policzy sam z siebie: to trzy liczby w trzech różnych
 * sekcjach `config/kuking.php`, a ktoś kiedyś podniesie jedną z nich, żeby
 * „wysłać więcej biuletynów", i nie zajrzy do pozostałych. Ten test jest tym
 * jedynym miejscem, w którym rachunek jest wykonywany.
 *
 * CO ROBIĆ, GDY TEN TEST PADNIE
 * NIE podnosić `poczta.limit_dostawcy_dobowy` — ta liczba opisuje cudzy plan
 * taryfowy, nie nasze życzenie. Albo obniżyć któryś sufit, albo (jeśli
 * właściciel przeszedł na plan płatny) zmienić limit RAZEM
 * z `docs/decyzje/POCZTA.md` i `docs/DECISIONS.md` D-057.
 */
class PodzialLimituPocztyTest extends TestCase
{
    use RefreshDatabase;

    public function test_suma_sufitow_i_rezerwy_miesci_sie_pod_limitem_dostawcy(): void
    {
        $limit = (int) config('kuking.poczta.limit_dostawcy_dobowy');
        $rezerwa = (int) config('kuking.poczta.rezerwa_transakcyjna');

        $podsumowanie = (int) config('kuking.digest.dzienny_limit');

        // `login_link` przychodzi z gałęzi logowania linkiem (issue #25).
        // Domyślne zero znaczy „tej funkcji jeszcze tu nie ma" i wtedy test
        // sprawdza rachunek bez niej — a po scaleniu zacznie liczyć także ją,
        // bez żadnej zmiany w tym pliku.
        $logowanieLinkiem = (int) config('kuking.login_link.dzienny_budzet', 0);

        $suma = $podsumowanie + $logowanieLinkiem + $rezerwa;

        $this->assertLessThanOrEqual(
            $limit,
            $suma,
            "Dobowe sufity poczty nie mieszczą się pod limitem dostawcy: podsumowanie {$podsumowanie} "
            ."+ logowanie linkiem {$logowanieLinkiem} + rezerwa transakcyjna {$rezerwa} = {$suma}, "
            ."a limit to {$limit}. Obniż któryś sufit — NIE podnoś limitu, bo ta liczba opisuje plan "
            .'u dostawcy, nie nasze życzenie (docs/decyzje/POCZTA.md §1).',
        );
    }

    /**
     * Asercja kontrolna do testu wyżej: rachunek ma sens tylko wtedy, gdy
     * rezerwa transakcyjna naprawdę jest niezerowa. Rezerwa ustawiona na zero
     * przepuściłaby dowolnie wysokie sufity, a to jest dokładnie ta pomyłka,
     * przed którą ten plik ma chronić.
     */
    public function test_rezerwa_transakcyjna_nie_moze_byc_zerowa(): void
    {
        $this->assertGreaterThan(
            0,
            (int) config('kuking.poczta.rezerwa_transakcyjna'),
            'Rezerwa transakcyjna wynosi zero, czyli serwis nie zostawia ani jednego listu '
            .'na potwierdzenie rejestracji i przypomnienie hasła. Tych dwóch rzeczy nie da się '
            .'przełożyć na jutro.',
        );
    }

    /**
     * Sufit podsumowań musi mieć własny licznik, a nie dzielić go z logowaniem
     * linkiem — inaczej dwie funkcje wyjadałyby sobie nawzajem budżet i żadna
     * nie mieściłaby się w swoim.
     */
    public function test_kazda_funkcja_ma_wlasny_licznik_dobowy(): void
    {
        config()->set('kuking.digest.dzienny_limit', 5);
        config()->set('kuking.login_link.dzienny_budzet', 5);

        $podsumowanie = DziennyBudzetListow::dlaPodsumowania();
        $logowanie = DziennyBudzetListow::dlaLinkuLogowania();

        $podsumowanie->zajmij();
        $podsumowanie->zajmij();

        $this->assertSame(2, $podsumowanie->zuzyte());
        $this->assertSame(0, $logowanie->zuzyte(), 'Liczniki dwóch funkcji dzielą jeden klucz w cache.');
    }

    public function test_sufit_podsumowan_czyta_wlasny_klucz_konfiguracji(): void
    {
        config()->set('kuking.digest.dzienny_limit', 7);
        config()->set('kuking.login_link.dzienny_budzet', 99);

        $this->assertSame(7, DziennyBudzetListow::dlaPodsumowania()->budzet());
    }
}
