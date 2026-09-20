<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\NotifyUserExportReady;
use App\Mail\DataExportReady;
use App\Models\DataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ExportNotificationTest extends TestCase
{
    use RefreshDatabase;

    public static function unavailable(): array
    {
        return [['expired'], ['deleted'], ['pending_delete'], ['erased'], ['no_deadline']];
    }

    #[DataProvider('unavailable')]
    public function test_opozniony_list_nie_obiecuje_niedostepnej_paczki(string $reason): void
    {
        Mail::fake();
        $user = $this->user();
        $export = DataExport::create(['user_id' => $user->id, 'status' => 'ready', 'expires_at' => now()->addDay()]);
        if ($reason === 'expired') {
            $export->update(['expires_at' => now()]);
        } elseif ($reason === 'deleted') {
            $export->delete();
        } elseif ($reason === 'no_deadline') {
            $export->update(['expires_at' => null]);
        } else {
            $user->forceFill(['status' => $reason, 'data_erased_at' => $reason === 'erased' ? now() : null])->save();
        }
        (new NotifyUserExportReady($export->id))->handle();
        Mail::assertNothingSent();
    }

    public function test_wysylka_czyta_aktualny_adres_i_nie_powtarza_wyslanego_listu(): void
    {
        Mail::fake();
        $user = $this->user();
        $export = DataExport::create(['user_id' => $user->id, 'status' => 'ready', 'expires_at' => now()->addDay()]);
        $job = new NotifyUserExportReady($export->id);
        $user->forceFill(['email' => 'nowy@example.com'])->save();
        $job->handle();
        $job->handle();
        Mail::assertSent(DataExportReady::class, fn ($mail) => $mail->hasTo('nowy@example.com'));
        Mail::assertSentCount(1);
        $this->assertNotNull($export->refresh()->notified_at);
    }

    public function test_widok_pokazuje_gdzie_sprawdzic_paczke_bez_listu(): void
    {
        $this->actingAs($this->user())->get(route('settings.data'))->assertOk()
            ->assertSee('Gotowość sprawdzisz tutaj, w sekcji „Twoje paczki”, także gdy e-mail jeszcze nie dotrze.');
    }

    public function test_przy_znanym_braku_poczty_nie_obiecujemy_listu(): void
    {
        Queue::fake();
        config(['mail.default' => 'log']);
        $this->actingAs($this->user());
        foreach ([1, 2] as $attempt) {
            $response = $this->post(route('settings.data.export'))->assertRedirect();
            $response->assertSessionHas('status', function (string $message): bool {
                $this->assertStringNotContainsString('Wyślemy', $message);
                $this->assertStringNotContainsString('Napiszemy', $message);
                $this->assertStringContainsString('Twoje paczki', $message);

                return true;
            });
        }
    }

    public function test_przy_dostepnym_transporcie_potwierdzenie_mowi_takze_o_liscie(): void
    {
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $this->actingAs($this->user())->post(route('settings.data.export'))->assertRedirect()
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'Wyślemy też powiadomienie e-mailem.'));
    }

    public function test_koncowa_awaria_listu_zostawia_bezpieczny_slad(): void
    {
        $user = $this->user();
        $export = DataExport::create(['user_id' => $user->id, 'status' => 'ready', 'expires_at' => now()->addDay()]);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('adres@example.com token=sekret'));
        $log = Log::spy();
        $job = new NotifyUserExportReady($export->id);
        $caught = null;
        try {
            $job->handle();
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringNotContainsString('adres@example.com', (string) $caught);
        $this->assertStringNotContainsString('sekret', (string) $caught);
        $this->assertNull($caught->getPrevious());
        $job->failed($caught);
        $log->shouldHaveReceived('error')->once()->with('Wyczerpano próby powiadomienia o paczce z danymi', ['data_export_id' => $export->id]);
        $this->assertSame('ready', $export->refresh()->status);
    }

    public function test_migracja_cofa_sie_na_pustych_znacznikach_i_odmawia_po_wysylce(): void
    {
        $migration = require database_path('migrations/2026_09_20_180000_add_notified_at_to_data_exports.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('data_exports', 'notified_at'));
        $migration->up();
        $export = DataExport::create(['user_id' => $this->user()->id, 'status' => 'ready']);
        $export->forceFill(['notified_at' => now()])->save();
        $caught = null;
        try {
            $migration->down();
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertTrue(Schema::hasColumn('data_exports', 'notified_at'));
        $this->assertNotNull($export->refresh()->notified_at);
    }
}
