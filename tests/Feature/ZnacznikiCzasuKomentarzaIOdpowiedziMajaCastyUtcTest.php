<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kontrakt czasu (#2407, DB-001/DB-002): kolumny `timestamptz`, które modele
 * oddawały jako surowy napis z bazy, mają jawny cast `datetime` w UTC
 * (`app.timezone` = UTC; strefa wyświetlania to `kuking.strefa`, nie model).
 *
 * Dotyczy `comments.body_removed_at` oraz `contact_message_replies.
 * sending_started_at` i `audit_recorded_at`. Bez castu `$x->pole` jest napisem
 * zależnym od strefy sesji PostgreSQL, a porównanie `>` robi się na tekście.
 */
class ZnacznikiCzasuKomentarzaIOdpowiedziMajaCastyUtcTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chwile tuż przy przejściach DST w Europe/Warsaw (29.03.2026 01:00 UTC
     * i 25.10.2026 01:00 UTC) — tam lokalna strefa najłatwiej zmienia wynik.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function chwile(): array
    {
        return [
            'tuz przed wiosennym przejsciem' => ['2026-03-29 00:59:59+00', '2026-03-29T00:59:59+00:00'],
            'tuz po wiosennym przejsciu' => ['2026-03-29 01:00:01+00', '2026-03-29T01:00:01+00:00'],
            'jesienna godzina powtorzona' => ['2026-10-25 00:30:00+00', '2026-10-25T00:30:00+00:00'],
            'po jesiennym przejsciu' => ['2026-10-25 01:30:00+00', '2026-10-25T01:30:00+00:00'],
        ];
    }

    public function test_kontrakt_strefy_aplikacji_to_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    #[DataProvider('chwile')]
    public function test_komentarz_oddaje_body_removed_at_jako_carbon_w_utc(string $wBazie, string $iso): void
    {
        $komentarz = Comment::factory()->create();
        DB::table('comments')->where('id', $komentarz->id)->update(['body_removed_at' => $wBazie]);

        $odczyt = Comment::query()->findOrFail($komentarz->id);

        $this->assertInstanceOf(CarbonInterface::class, $odczyt->body_removed_at);
        $this->assertSame($iso, $odczyt->body_removed_at->toIso8601String());
        $this->assertSame(0, $odczyt->body_removed_at->getOffset());
        $this->assertStringEndsWith('Z"', (string) json_encode($odczyt->body_removed_at));
    }

    #[DataProvider('chwile')]
    public function test_odpowiedz_kontaktowa_oddaje_znaczniki_jako_carbon_w_utc(string $wBazie, string $iso): void
    {
        $wiadomosc = ContactMessage::factory()->create();
        $odpowiedz = new ContactMessageReply;
        $odpowiedz->forceFill([
            'contact_message_id' => $wiadomosc->id,
            'author_id' => User::factory()->create()->id,
            'body' => 'Odpowiedź.',
        ])->save();
        DB::table('contact_message_replies')->where('id', $odpowiedz->id)
            ->update(['sending_started_at' => $wBazie, 'audit_recorded_at' => $wBazie]);

        $odczyt = ContactMessageReply::query()->findOrFail($odpowiedz->id);

        $this->assertInstanceOf(CarbonInterface::class, $odczyt->sending_started_at);
        $this->assertInstanceOf(CarbonInterface::class, $odczyt->audit_recorded_at);
        $this->assertSame($iso, $odczyt->sending_started_at->toIso8601String());
        $this->assertSame($iso, $odczyt->audit_recorded_at->toIso8601String());
        $this->assertSame(0, $odczyt->sending_started_at->getOffset());
    }

    public function test_porownanie_z_chwila_dziala_na_czasie_a_nie_na_napisach(): void
    {
        $komentarz = Comment::factory()->create();
        DB::table('comments')->where('id', $komentarz->id)->update(['body_removed_at' => '2026-03-29 01:00:01+00']);

        $odczyt = Comment::query()->findOrFail($komentarz->id);

        $porownania = [
            'po 00:59:59 UTC' => $odczyt->body_removed_at->gt(Carbon::parse('2026-03-29 00:59:59', 'UTC')),
            'przed rokiem 2030' => $odczyt->body_removed_at->lt(Carbon::parse('2030-01-01', 'UTC')),
        ];
        $this->assertSame(['po 00:59:59 UTC' => true, 'przed rokiem 2030' => true], $porownania);
    }
}
