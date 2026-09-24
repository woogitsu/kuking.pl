<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\ContactMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Usunięcie konta operatora nie może wywracać bazy błędem CHECK-a (#844).
 *
 * `handled_by` ma w schemacie `nullOnDelete()`, ale stary CHECK wymagał
 * `num_nonnulls(handled_by, handled_at) = 2` przy statusie innym niż `new`.
 * Usunięcie operatora powodowało próbę `SET NULL` na `handled_by`, co
 * natychmiast naruszało CHECK i rzucało wyjątkiem bazy.
 */
class UsuniecieOperatoraNiePsujeWiadomosciTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_24_100000_allow_null_handled_by_on_contact_messages.php',
        );
    }

    public function test_usuniecie_konta_operatora_ustawia_null_w_handled_by_i_zachowuje_status(): void
    {
        $operator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->zalatwiona($operator)->create();
        $data = $wiadomosc->handled_at->toISOString();

        $this->assertSame($operator->getKey(), $wiadomosc->handled_by);
        $this->assertNotNull($wiadomosc->handled_at);
        $this->assertSame(ContactMessage::STATUS_ZALATWIONA, $wiadomosc->status);

        // Usunięcie konta operatora musi przejść bez wyjątku bazy (SQLSTATE 23514)
        $operator->delete();

        $wiadomosc->refresh();
        $this->assertNull($wiadomosc->handled_by, 'handled_by powinno zostać wyzerowane przez SET NULL.');
        $this->assertSame($data, $wiadomosc->handled_at->toISOString());
        $this->assertNotNull($wiadomosc->handled_at, 'handled_at musi pozostać, bo od niego liczy się retencja.');
        $this->assertSame(ContactMessage::STATUS_ZALATWIONA, $wiadomosc->status);

        // Karta wiadomości pokazuje „obsługa Kuking" zamiast nazwy usuniętego operatora
        $innyModerator = $this->moderator();
        $this->actingAs($innyModerator)
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->assertSee('obsługa Kuking');
    }

    public function test_cofniecie_migracji_odmawia_gdy_w_bazie_sa_wiadomosci_z_wyzerowanym_operatorem(): void
    {
        $operator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->zalatwiona($operator)->create();
        $operator->delete();

        $this->assertDatabaseHas('contact_messages', [
            'id' => $wiadomosc->getKey(),
            'handled_by' => null,
            'status' => ContactMessage::STATUS_ZALATWIONA,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nie można cofnąć migracji');

        $this->migracja()->down();
    }

    public function test_cofniecie_i_ponowne_zalozenie_migracji_dziala_na_czystej_bazie(): void
    {
        // Na bazie bez obsłużonych wiadomości z handled_by = NULL rollback przechodzi bez przeszkód
        $this->migracja()->down();

        // Stary CHECK został przywrócony
        $ograniczenie = DB::select(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'contact_messages_handled_complete'",
        );
        $this->assertNotEmpty($ograniczenie);
        $this->assertStringContainsString('num_nonnulls(handled_by, handled_at) = 2', $ograniczenie[0]->def);

        $this->migracja()->up();

        // Nowy CHECK został wdrożony
        $ograniczenie = DB::select(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'contact_messages_handled_complete'",
        );
        $this->assertNotEmpty($ograniczenie);
        $this->assertStringContainsString('handled_at IS NOT NULL', $ograniczenie[0]->def);
    }

    public function test_nowa_wiadomosc_nadal_nie_moze_miec_daty_obslugi(): void
    {
        $this->expectException(QueryException::class);
        ContactMessage::factory()->create(['handled_at' => now()]);
    }

    public function test_obsluzona_wiadomosc_nadal_wymaga_daty(): void
    {
        $this->expectException(QueryException::class);
        ContactMessage::factory()->create(['status' => 'done']);
    }

    public function test_anonimizacja_operatora_zostawia_powiazanie_i_date(): void
    {
        $operator = $this->moderator();
        $message = ContactMessage::factory()->zalatwiona($operator)->create();
        $date = $message->handled_at->toISOString();
        $operator->markForDeletion();
        $this->assertTrue(app(EraseAccountData::class)->handle($operator));
        $this->assertSame($operator->id, $message->refresh()->handled_by);
        $this->assertSame($date, $message->handled_at->toISOString());
        $this->assertSame('done', $message->status);
    }
}
