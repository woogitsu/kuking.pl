<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\StepTimer;
use App\Exceptions\BladDlaCzlowieka;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Przelicznik minutnika kroku — jedno miejsce dla obu dróg zapisu.
 *
 * Test jest jednostkowy, bez bazy i bez HTTP, bo to jest właśnie sens
 * wyciągnięcia tej arytmetyki z kontrolera: reguła da się sprawdzić bez
 * formularza i nie da się jej obejść, dodając drugą drogę zapisu
 * (AGENTS.md §4).
 */
class StepTimerTest extends TestCase
{
    public function test_minuty_czlowieka_zamieniaja_sie_na_sekundy_bazy(): void
    {
        // POZYTYWNA: to, co wpisał człowiek, i to, co czyta tryb gotowania.
        $this->assertSame(45 * 60, StepTimer::secondsFromMinutes('45'));
        $this->assertSame(60, StepTimer::secondsFromMinutes(1));
        $this->assertSame(StepTimer::MAX_MINUTES * 60, StepTimer::secondsFromMinutes((string) StepTimer::MAX_MINUTES));

        // KONTROLNA: spacje wokół liczby to nie błąd człowieka.
        $this->assertSame(20 * 60, StepTimer::secondsFromMinutes('  20 '));
    }

    public function test_puste_pole_i_zero_znacza_bez_minutnika(): void
    {
        // Pole jest nieobowiązkowe i większość kroków go nie ma.
        $this->assertNull(StepTimer::secondsFromMinutes(null));
        $this->assertNull(StepTimer::secondsFromMinutes(''));
        $this->assertNull(StepTimer::secondsFromMinutes('   '));

        // Zero to też „bez minutnika": `RecipeStep::timerLabel()` nic dla zera
        // nie pokaże, więc zapisane zero byłoby wartością, która wygląda jak
        // ustawiona i nie działa.
        $this->assertNull(StepTimer::secondsFromMinutes('0'));
        $this->assertNull(StepTimer::secondsFromMinutes(0));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function wartosciOdrzucane(): array
    {
        return [
            'ujemna' => ['-5', StepTimer::KOMUNIKAT_UJEMNY],
            'ujemna liczba' => [-5, StepTimer::KOMUNIKAT_UJEMNY],
            'ponad tydzień' => ['20000', StepTimer::KOMUNIKAT_ZA_DUZO],
            'dłuższa niż int' => ['99999999999999999999', StepTimer::KOMUNIKAT_ZA_DUZO],
            'tekst' => ['pół godziny', StepTimer::KOMUNIKAT_NIE_LICZBA],
            'z przecinkiem' => ['45,5', StepTimer::KOMUNIKAT_NIE_LICZBA],
            'z kropką' => ['45.5', StepTimer::KOMUNIKAT_NIE_LICZBA],
            'tablica' => [['45'], StepTimer::KOMUNIKAT_NIE_LICZBA],
            'prawda' => [true, StepTimer::KOMUNIKAT_NIE_LICZBA],
        ];
    }

    #[DataProvider('wartosciOdrzucane')]
    public function test_wartosc_bez_sensu_odpada_z_komunikatem_mowiacym_co_zrobic(mixed $wartosc, string $komunikat): void
    {
        try {
            StepTimer::secondsFromMinutes($wartosc);
            $this->fail('Wartość przeszła przez bramkę, choć nie powinna.');
        } catch (BladDlaCzlowieka $e) {
            // NEGATYWNA: odrzucone, i to KTÓRYM zdaniem — „to nie jest liczba"
            // przy wartości ujemnej byłoby nieprawdą i nie mówiłoby, co poprawić.
            $this->assertSame($komunikat, $e->getMessage());
        }
    }

    public function test_granica_tygodnia_przechodzi_a_minuta_wiecej_nie(): void
    {
        // POZYTYWNA: dokładnie tydzień jeszcze wolno („zakwas dojrzewa trzy dni").
        $this->assertSame(StepTimer::MAX_MINUTES * 60, StepTimer::secondsFromMinutes(StepTimer::MAX_MINUTES));

        // NEGATYWNA: jedna minuta więcej już nie.
        $this->expectException(BladDlaCzlowieka::class);
        StepTimer::secondsFromMinutes(StepTimer::MAX_MINUTES + 1);
    }

    public function test_sekundy_z_bazy_wracaja_do_formularza_jako_minuty(): void
    {
        // POZYTYWNA: droga powrotna, czyli to, co widzi człowiek w edycji.
        $this->assertSame(45, StepTimer::minutesFromSeconds(45 * 60));
        $this->assertSame(1, StepTimer::minutesFromSeconds(60));

        // Zaokrąglenie W GÓRĘ: 90 sekund to „2", nie „1". W dół dałoby przy
        // minutniku krótszym niż minuta puste pole — i pierwszy zapis
        // z formularza skasowałby minutnik, którego nikt nie chciał ruszać.
        $this->assertSame(2, StepTimer::minutesFromSeconds(90));
        $this->assertSame(1, StepTimer::minutesFromSeconds(30));

        // KONTROLNA: brak minutnika zostaje brakiem minutnika.
        $this->assertNull(StepTimer::minutesFromSeconds(null));
        $this->assertNull(StepTimer::minutesFromSeconds(0));
    }

    public function test_przelicznik_w_obie_strony_nie_gubi_wpisanej_liczby(): void
    {
        // Edycja przepisu przepuszcza minutnik przez OBIE metody: sekundy
        // z bazy → minuty w polu → sekundy z powrotem. Ta pętla nie może
        // zmieniać wartości, bo wtedy samo otwarcie i zapisanie formularza
        // przestawiałoby minutniki bez powodu.
        foreach ([1, 2, 45, 90, 1440, StepTimer::MAX_MINUTES] as $minuty) {
            $sekundy = StepTimer::secondsFromMinutes((string) $minuty);

            $this->assertSame(
                $sekundy,
                StepTimer::secondsFromMinutes((string) StepTimer::minutesFromSeconds($sekundy)),
                "Minutnik {$minuty} min zmienił się po przejściu przez formularz.",
            );
        }
    }
}
