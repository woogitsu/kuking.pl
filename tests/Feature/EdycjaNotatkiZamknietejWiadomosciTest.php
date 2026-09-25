<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Edycja notatki zamkniętej wiadomości nie nadpisuje daty ani autora załatwienia (#843).
 */
class EdycjaNotatkiZamknietejWiadomosciTest extends TestCase
{
    use RefreshDatabase;

    public function test_edycja_notatki_zostawia_pierwotnego_autora_i_date_zalatwienia(): void
    {
        $pierwotnyOperator = $this->moderator();
        $kolejnyOperator = $this->moderator();

        $pierwotnaData = now()->subDays(5);
        $wiadomosc = ContactMessage::factory()->create([
            'version' => 0, 'status' => ContactMessage::STATUS_ZALATWIONA,
            'handled_by' => $pierwotnyOperator->getKey(),
            'handled_at' => $pierwotnaData,
            'handler_note' => 'Pierwotna notatka.',
        ]);

        $this->actingAs($kolejnyOperator)
            ->post(route('admin.contact.update', $wiadomosc), [
                'version' => 0, 'status' => ContactMessage::STATUS_ZALATWIONA,
                'handler_note' => 'Dopisano numer zgłoszenia w GitHub: #123.',
            ])
            ->assertRedirect(route('admin.contact.show', $wiadomosc));

        $wiadomosc->refresh();

        $this->assertSame('Dopisano numer zgłoszenia w GitHub: #123.', $wiadomosc->handler_note);
        $this->assertSame(
            $pierwotnyOperator->getKey(),
            $wiadomosc->handled_by,
            'Edycja notatki nadpisała autora załatwienia wiadomości.',
        );
        $this->assertEquals(
            $pierwotnaData->getTimestamp(),
            $wiadomosc->handled_at?->getTimestamp(),
            'Edycja notatki nadpisała datę załatwienia wiadomości, resetując zegar retencji.',
        );
    }

    public function test_notatka_nie_odsuwa_retencji_a_ponowne_zamkniecie_ma_nowa_date(): void
    {
        $operator = $this->moderator();
        $other = $this->moderator();
        $message = ContactMessage::factory()->zalatwiona($operator)->create(['handled_at' => now()->subMonthsNoOverflow(13)]);
        $retention = app(PrzedawnioneWiadomosciDoOperatora::class);
        $this->assertSame(1, $retention->posprzataj(12, true));
        $this->actingAs($other)->post(route('admin.contact.update', $message), [
            'version' => 0, 'status' => 'done', 'handler_note' => 'Dopisane po roku.',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $retention->posprzataj(12, true));
        $message->refresh();
        $date = $message->handled_at->toISOString();
        $message->oznaczJako('done', $other);
        $this->assertSame($date, $message->refresh()->handled_at->toISOString());
        $message->oznaczJako('new', $other);
        $this->assertNull($message->refresh()->handled_at);
        $this->assertNull($message->handled_by);
        $message->oznaczJako('done', $other);
        $this->assertSame($other->id, $message->refresh()->handled_by);
        $this->assertNotSame($date, $message->handled_at->toISOString());
        $this->assertSame(0, $retention->posprzataj(12, true));
    }
}
