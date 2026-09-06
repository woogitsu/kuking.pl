<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RecipeStep;
use Tests\TestCase;

/**
 * `RecipeStep::timerLabel()` — czas minutnika po polsku (issue #24).
 *
 * Bez DB: `new RecipeStep(...)` nie dotyka bazy, więc to jest prawdziwy
 * test jednostkowy, nie feature test w przebraniu.
 */
class RecipeStepTimerLabelTest extends TestCase
{
    public function test_brak_minutnika_zwraca_null(): void
    {
        $this->assertNull((new RecipeStep(['timer_seconds' => null]))->timerLabel());
    }

    public function test_zero_sekund_zwraca_null(): void
    {
        // Zero nie jest minutnikiem, którym warto komuś zawracać głowę —
        // to samo traktowanie co `null` (baza i tak nie pozwala na wartości
        // ujemne, patrz CHECK w migracji).
        $this->assertNull((new RecipeStep(['timer_seconds' => 0]))->timerLabel());
    }

    public function test_same_sekundy_ponizej_minuty(): void
    {
        $this->assertSame('45 sekund', (new RecipeStep(['timer_seconds' => 45]))->timerLabel());
    }

    public function test_jedna_sekunda_ma_liczbe_pojedyncza(): void
    {
        $this->assertSame('1 sekunda', (new RecipeStep(['timer_seconds' => 1]))->timerLabel());
    }

    public function test_pelne_minuty_bez_sekund(): void
    {
        $this->assertSame('1 minuta', (new RecipeStep(['timer_seconds' => 60]))->timerLabel());
        $this->assertSame('12 minut', (new RecipeStep(['timer_seconds' => 720]))->timerLabel());
    }

    public function test_minuty_i_sekundy_razem(): void
    {
        $this->assertSame('1 minuta i 30 sekund', (new RecipeStep(['timer_seconds' => 90]))->timerLabel());
        $this->assertSame('2 minuty i 5 sekund', (new RecipeStep(['timer_seconds' => 125]))->timerLabel());
    }
}
