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

    // =================================================================
    //  PROGI WYGASZANIA — rachunek, którego też nikt nie zrobi za nas
    //  (wspólny licznik poczty, decyzja właściciela z 20 września 2026)
    // =================================================================

    /**
     * Każda klasa listów ma próg. Klasa bez progu czytałaby zero, czyli
     * gasłaby OSTATNIA — a to jest odwrotność tego, co zwykle było zamiarem
     * osoby dopisującej nową klasę.
     */
    public function test_kazda_klasa_listow_ma_wlasny_prog(): void
    {
        $progi = (array) config('kuking.poczta.progi_wygaszania');

        foreach (DziennyBudzetListow::KLASY as $klasa) {
            $this->assertArrayHasKey(
                $klasa,
                $progi,
                "Klasa listów „{$klasa}” nie ma progu w `kuking.poczta.progi_wygaszania`, "
                .'więc czytałaby zero — czyli gasłaby jako ostatnia, razem z potwierdzeniem rejestracji.',
            );
        }
    }

    /**
     * KOLEJNOŚĆ WYGASZANIA JEST DECYZJĄ I MA BYĆ WIDOCZNA W LICZBACH.
     * Podsumowanie gaśnie pierwsze, wejście ostatnie — odwrócenie tych
     * nierówności odwraca całą decyzję, nie zmieniając ani jednej linijki
     * kodu.
     */
    public function test_kolejnosc_wygaszania_jest_zachowana(): void
    {
        $podsumowanie = (int) config('kuking.poczta.progi_wygaszania.podsumowanie');
        $zwykla = (int) config('kuking.poczta.progi_wygaszania.zwykla');
        $wejscie = (int) config('kuking.poczta.progi_wygaszania.wejscie');

        $this->assertGreaterThan($zwykla, $podsumowanie,
            'Tygodniowe podsumowanie ma gasnąć PIERWSZE — jego próg musi być najwyższy.');
        $this->assertGreaterThan($wejscie, $zwykla,
            'Rezerwa dla listów wpuszczających na konto zniknęła: klasa `zwykla` sięga tak samo głęboko.');
        $this->assertSame(0, $wejscie,
            'Potwierdzenie rejestracji i link do logowania mają sięgać po OSTATNI list doby. '
            .'Próg większy od zera zamyka wejście do serwisu, zanim pula naprawdę się skończy.');
    }

    /**
     * Próg podsumowania NIE JEST liczbą z powietrza: wynika z jego własnego
     * sufitu dobowego. Podniesienie `digest.dzienny_limit` bez obniżenia tego
     * progu nie dałoby biuletynowi ani jednego listu więcej, a rozjazd tych
     * dwóch liczb byłby niewidoczny.
     */
    public function test_prog_podsumowania_zgadza_sie_z_jego_sufitem(): void
    {
        $limit = (int) config('kuking.poczta.limit_dostawcy_dobowy');
        $prog = (int) config('kuking.poczta.progi_wygaszania.podsumowanie');
        $sufit = (int) config('kuking.digest.dzienny_limit');

        $this->assertSame(
            $limit - $sufit,
            $prog,
            "Próg wygaszania podsumowań ({$prog}) nie zgadza się z rachunkiem: limit dostawcy {$limit} "
            ."minus dobowy sufit podsumowań {$sufit}. Zmieniono jedną z tych liczb i nie zajrzano do drugiej.",
        );
    }

    /**
     * Próg klasy `zwykla` JEST rezerwą transakcyjną — i to jest cały sens
     * tej rezerwy. Do 20 września 2026 była ona wyłącznie zdaniem
     * w komentarzu, którego nic nie pilnowało.
     */
    public function test_prog_klasy_zwyklej_to_rezerwa_transakcyjna(): void
    {
        $this->assertSame(
            (int) config('kuking.poczta.rezerwa_transakcyjna'),
            (int) config('kuking.poczta.progi_wygaszania.zwykla'),
            'Rezerwa transakcyjna i próg klasy `zwykla` to ta sama decyzja zapisana dwa razy. '
            .'Gdy się rozjadą, rezerwa znów przestanie cokolwiek znaczyć.',
        );
    }
}
