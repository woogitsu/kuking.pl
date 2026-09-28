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
            $niepoprawnyId = 'not-a-uuid';
            $this->post("/powiadomienia/{$niepoprawnyId}/zobacz")->assertNotFound();
            $this->assertSame([], $this->zapytaniaZIdentyfikatorem($niepoprawnyId),
                'Niepoprawny identyfikator dotarł do zapytania o powiadomienie.');

            DB::flushQueryLog();
            $nieistniejacyId = '00000000-0000-4000-8000-000000000000';
            $this->post("/powiadomienia/{$nieistniejacyId}/zobacz")->assertNotFound();
            $this->assertNotEmpty($this->zapytaniaZIdentyfikatorem($nieistniejacyId),
                'Poprawny, nieistniejący UUID powinien przejść trasę i zakończyć się na 404 po sprawdzeniu własnych powiadomień.');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /** @return list<string> */
    private function zapytaniaZIdentyfikatorem(string $id): array
    {
        return array_values(array_map(
            static fn (array $query): string => $query['query'],
            array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'notifications') && in_array($id, $query['bindings'], true)),
        ));
    }
}
