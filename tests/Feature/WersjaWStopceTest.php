<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Wersja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Numer wersji w stopce.
 *
 * PO CO TO JEST
 * Żeby jednym spojrzeniem na stronę dało się sprawdzić, co dokładnie na niej
 * działa — bez wchodzenia w panel Railway i bez zgadywania, czy ostatnie
 * wdrożenie przeszło.
 *
 * Dlatego wersja ma DWIE części i obie są potrzebne:
 *
 *   „Alfa 0.1"  — etap produktu, podbijany ręcznie;
 *   „a1b2c3d"   — skrót wdrożonego commita, zmieniany samo przez Railway.
 *
 * Sama etykieta stałaby tygodniami bez zmian i nie odpowiadałaby na pytanie
 * „czy moja poprawka już weszła". Sam skrót nic nie mówi człowiekowi, który
 * nie zna gita.
 */
class WersjaWStopceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wersja_jest_widoczna_dla_niezalogowanego(): void
    {
        // Gość też ma widzieć wersję — inaczej trzeba by się logować, żeby
        // sprawdzić, czy wdrożenie doszło.
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(Wersja::etykieta());
    }

    public function test_wersja_jest_widoczna_dla_zalogowanego(): void
    {
        $this->actingAs($this->user('basia'))
            ->get(route('home'))
            ->assertOk()
            ->assertSee(Wersja::etykieta());
    }

    public function test_pokazuje_skrot_wdrozonego_commita(): void
    {
        config(['kuking.wersja.commit' => '1a4ab54c4141bccb6cce9e1947cbfb0a227e4894']);

        $this->assertSame('1a4ab54', Wersja::wydanie());
        $this->assertStringContainsString('1a4ab54', Wersja::pelna());
    }

    public function test_bez_wdrozenia_mowi_wprost_ze_to_lokalnie(): void
    {
        // Lokalnie i w testach nie ma zmiennej od Railway. Pusty tekst albo
        // myślnik kazałyby się domyślać; „lokalnie" mówi wprost.
        config(['kuking.wersja.commit' => null]);

        $this->assertSame('lokalnie', Wersja::wydanie());
    }

    public function test_etykieta_i_wydanie_sa_rozdzielone_czytelnie(): void
    {
        config(['kuking.wersja.commit' => 'abcdef1234567890']);

        // Dwie różne informacje mają być rozróżnialne wzrokiem, a nie sklejone
        // w jeden ciąg, którego trzeba się domyślać.
        $this->assertSame(Wersja::etykieta().' · abcdef1', Wersja::pelna());
    }
}
