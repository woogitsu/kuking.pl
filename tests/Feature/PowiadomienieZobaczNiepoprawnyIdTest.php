<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Niepoprawny UUID ma zatrzymać się na trasie, zanim trafi do PostgreSQL (#1880). */
class PowiadomienieZobaczNiepoprawnyIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_niepoprawny_uuid_daje_404_bez_zapytania_a_nieistniejacy_uuid_nadal_daje_404(): void
    {
        $this->actingAs($this->user('basia'));

        DB::enableQueryLog();

        try {
            DB::flushQueryLog();
            $this->post('/powiadomienia/not-a-uuid/zobacz')->assertNotFound();
            $this->assertSame([], $this->zapytaniaONotificationId(),
                'Niepoprawny identyfikator dotarł do zapytania o powiadomienie.');

            DB::flushQueryLog();
            $this->post('/powiadomienia/00000000-0000-4000-8000-000000000000/zobacz')->assertNotFound();
            $this->assertNotEmpty($this->zapytaniaONotificationId(),
                'Poprawny, nieistniejący UUID powinien przejść trasę i zakończyć się na 404 po sprawdzeniu własnych powiadomień.');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /** @return list<string> */
    private function zapytaniaONotificationId(): array
    {
        return array_values(array_map(
            static fn (array $query): string => $query['query'],
            array_filter(DB::getQueryLog(), static fn (array $query): bool =>
                preg_match('/\\bnotifications\\b.*\\bid\\b/i', $query['query']) === 1),
        ));
    }
}
