<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contact\Actions\WyslijOdpowiedz;
use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\AuditLogEntry;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AwarieOdpowiedziKontaktuTest extends TestCase
{
    use RefreshDatabase;

    private function failAfter(string $sql): void
    {
        $armed = true;
        DB::listen(function ($query) use ($sql, &$armed): void {
            if ($armed && str_contains($query->sql, $sql)) {
                $armed = false;
                throw new RuntimeException('Kontrolowana awaria po SQL: '.$sql);
            }
        });
    }

    public static function auditFailures(): array
    {
        return [
            'znacznik' => ['update "contact_message_replies" set "audit_recorded_at"'],
            'wpis' => ['insert into "audit_log"'],
        ];
    }

    #[DataProvider('auditFailures')]
    public function test_awaria_audytu_cofa_znacznik_i_wejscie_dokancza_bez_nowego_listu(string $sql): void
    {
        Mail::fake();
        $operator = $this->moderator();
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $key = (string) Str::uuid();
        $this->failAfter($sql);
        $reply = app(WyslijOdpowiedz::class)->handle($message, $operator, 'Odpowiedź.', replyKey: $key);
        $this->assertSame('wyslana', $reply->refresh()->status);
        $this->assertNull($reply->audit_recorded_at);
        $this->assertSame(0, AuditLogEntry::where('action', 'admin.contact_reply_sent')->count());

        $this->actingAs($operator)->get(route('admin.contact.show', $message))->assertOk();
        $this->assertNotNull($reply->refresh()->audit_recorded_at);
        $this->assertSame(1, AuditLogEntry::where('action', 'admin.contact_reply_sent')->count());
        app(WyslijOdpowiedz::class)->handle($message, $operator, 'Odpowiedź.', replyKey: $key);
        $this->assertSame(1, AuditLogEntry::where('action', 'admin.contact_reply_sent')->count());
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 1);

        AuditLogEntry::where('action', 'admin.contact_reply_sent')->delete();
        $this->get(route('admin.contact.show', $message))->assertOk();
        $this->assertSame(0, AuditLogEntry::where('action', 'admin.contact_reply_sent')->count(), 'Retencja nie może wskrzesić audytu.');
    }

    public static function beforeMailFailures(): array
    {
        return [
            'utworzenie' => ['insert into "contact_message_replies"', 0],
            'rezerwacja' => ['update "contact_message_replies" set "sending_started_at"', 1],
        ];
    }

    #[DataProvider('beforeMailFailures')]
    public function test_awaria_przed_wysylka_daje_sie_dokonczyc(string $sql, int $rows): void
    {
        Mail::fake();
        $operator = $this->moderator();
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $key = (string) Str::uuid();
        $this->failAfter($sql);
        try {
            app(WyslijOdpowiedz::class)->handle($message, $operator, 'Odpowiedź.', replyKey: $key);
            $this->fail('Pułapka SQL nie została wyzwolona.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Kontrolowana awaria po SQL:', $error->getMessage());
        }
        $this->assertSame($rows, $message->odpowiedzi()->count());
        Mail::assertNothingSent();
        app(WyslijOdpowiedz::class)->handle($message, $operator, 'Odpowiedź.', replyKey: $key);
        $this->assertSame(1, $message->odpowiedzi()->count());
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 1);
    }

    public function test_awaria_zapisu_wyniku_po_wyslaniu_nie_wysyla_ponownie(): void
    {
        Mail::fake();
        $operator = $this->moderator();
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $key = (string) Str::uuid();
        $this->failAfter('update "contact_message_replies" set "status"');
        try {
            app(WyslijOdpowiedz::class)->handle($message, $operator, 'Odpowiedź.', replyKey: $key);
            $this->fail('Pułapka SQL nie została wyzwolona.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Kontrolowana awaria po SQL:', $error->getMessage());
        }
        $reply = $message->odpowiedzi()->sole();
        $this->assertSame(ContactMessageReply::STATUS_W_TOKU, $reply->status);
        $this->assertNotNull($reply->sending_started_at);
        app(WyslijOdpowiedz::class)->handle($message, $operator, 'Odpowiedź.', replyKey: $key);
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 1);
    }

    public function test_wycofanie_znacznikow_odmawia_po_pierwszym_formularzu(): void
    {
        Mail::fake();
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        app(WyslijOdpowiedz::class)->handle($message, $this->moderator(), 'Odpowiedź.', replyKey: (string) Str::uuid());
        $migration = require database_path('migrations/2026_09_24_120000_add_contact_reply_delivery_markers.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_awaria_zapisu_wyniku_zachowuje_oba_szkice_i_pozwala_sprawdzic_historie(): void
    {
        Mail::fake();
        $operator = $this->moderator();
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $key = (string) Str::uuid();
        $this->failAfter('update "contact_message_replies" set "status"');
        $this->actingAs($operator)->from(route('admin.contact.show', $message))
            ->post(route('admin.contact.reply', $message), [
                'odpowiedz' => 'Treść zachowana.', 'reply_key' => $key,
                'handler_note' => 'Niezapisana notatka.', 'version' => 0, 'status' => 'new',
            ])->assertRedirect(route('admin.contact.show', $message))
            ->assertSessionHasErrors('odpowiedz')
            ->assertSessionHasInput('odpowiedz', 'Treść zachowana.')
            ->assertSessionHasInput('handler_note', 'Niezapisana notatka.')
            ->assertSessionHasInput('reply_key', $key);
        $this->assertSame('w_toku', $message->odpowiedzi()->sole()->status);
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 1);
    }

    public function test_wycofanie_i_ponowienie_migracji_na_pustej_bazie(): void
    {
        foreach (['2026_09_24_120000_add_contact_reply_delivery_markers.php', '2026_09_24_110000_add_contact_message_version.php'] as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->down();
            $migration->up();
        }
        $this->assertSame(0, ContactMessage::factory()->create()->refresh()->version);
        $this->assertTrue(Schema::hasColumn('contact_message_replies', 'reply_key'));
    }
}
