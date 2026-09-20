<?php

declare(strict_types=1);

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Próbnik poza zestawem CI: zapisuje stan, nie ustanawia kontraktu #847. */
class ContactRetentionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_current_behavior_without_choosing_retention_policy(): void
    {
        $this->assertSame('55439', (string) config('database.connections.pgsql.port'));
        $this->assertSame('kuking_flota_gpt-pytania-widoki', DB::connection()->getDatabaseName());
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $operator = $this->moderator();
        $cases = [];
        foreach (['closed', 'reopened', 'reclosed'] as $kind) {
            $message = ContactMessage::factory()->zalatwiona($operator, now()->subYear()->addHour())
                ->create(['contact_email' => 'probe@example.test', 'created_at' => now()->subYears(2)]);
            if ($kind !== 'closed') {
                $message->oznaczJako(ContactMessage::STATUS_W_TOKU, $operator);
            }
            $before = $message->fresh()->handled_at?->toIso8601String();
            $html = $this->actingAs($operator)->get(route('admin.contact.show', $message))->assertOk()->getContent();
            $this->post(route('admin.contact.reply', $message), ['odpowiedz' => 'Testowa odpowiedź, bez prawdziwej wysyłki.'])->assertRedirect()->assertSessionHasNoErrors();
            $reply = $message->odpowiedzi()->sole();
            $afterReply = $message->fresh();
            if ($kind === 'reclosed') {
                $message->oznaczJako(ContactMessage::STATUS_ZALATWIONA, $operator);
            }
            $cases[$kind] = ['id' => $message->id, 'reply_id' => $reply->id,
                'before' => $before, 'after_reply' => $afterReply->handled_at?->toIso8601String(),
                'status_after_reply' => $afterReply->status, 'sent_at' => $reply->sent_at?->toIso8601String(),
                'reply_form' => str_contains($html, 'name="odpowiedz"')];
        }
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 3);
        $cleaner = app(PrzedawnioneWiadomosciDoOperatora::class);
        $before = $cleaner->posprzataj(12, true);
        $this->travelTo(Carbon::parse('2026-09-21 12:00:00', 'UTC'));
        $dryRun = $cleaner->posprzataj(12, true);
        $afterDryRun = [ContactMessage::count(), ContactMessageReply::count()];
        $deleted = $cleaner->posprzataj(12);
        foreach ($cases as &$case) {
            $case['message_survives'] = ContactMessage::whereKey($case['id'])->exists();
            $case['reply_survives'] = ContactMessageReply::whereKey($case['reply_id'])->exists();
            unset($case['id'], $case['reply_id']);
        }
        unset($case);
        echo json_encode(compact('before', 'dryRun', 'afterDryRun', 'deleted', 'cases'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
