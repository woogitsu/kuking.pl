<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contact\Actions\WyslijOdpowiedz;
use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\AuditLogEntry;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1352 — akcja domenowa sama sprawdza prawo `reply`, zanim zapisze
 * odpowiedź i wypuści list. Test woła akcję BEZPOŚREDNIO, z pominięciem
 * kontrolera: po usunięciu sprawdzenia z akcji oblewa, choć test HTTP
 * nadal przechodzi.
 */
class WyslijOdpowiedzUprawnieniaTest extends TestCase
{
    use RefreshDatabase;

    public static function osobyBezPrawa(): array
    {
        return [
            'zwykła osoba' => [fn (self $t): User => $t->user()],
            'administrator po odebraniu roli' => [function (self $t): User {
                $admin = $t->admin();
                $admin->forceFill(['role' => User::ROLE_USER])->save();

                return $admin->refresh();
            }],
            'moderator po zawieszeniu konta' => [function (self $t): User {
                $moderator = $t->moderator();
                $moderator->forceFill(['status' => User::STATUS_SUSPENDED])->save();

                return $moderator->refresh();
            }],
        ];
    }

    #[DataProvider('osobyBezPrawa')]
    public function test_akcja_odmawia_osobie_bez_prawa_do_listu(\Closure $osoba): void
    {
        Mail::fake();
        $kto = $osoba($this);
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);

        try {
            app(WyslijOdpowiedz::class)->handle($wiadomosc, $kto, 'Odpowiedź.', replyKey: (string) Str::uuid());
            $this->fail('Akcja wysłała odpowiedź osobie bez prawa do listu.');
        } catch (AuthorizationException) {
            // oczekiwane
        }

        $this->assertSame(0, ContactMessageReply::count());
        Mail::assertNothingSent();
        $this->assertSame(0, AuditLogEntry::where('action', 'like', 'admin.contact_reply_%')->count());
    }

    public function test_czynny_moderator_wysyla_jak_dotad(): void
    {
        Mail::fake();
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);

        $odpowiedz = app(WyslijOdpowiedz::class)->handle($wiadomosc, $this->moderator(), 'Odpowiedź.', replyKey: (string) Str::uuid());

        $this->assertSame(ContactMessageReply::STATUS_WYSLANA, $odpowiedz->refresh()->status);
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 1);
        $this->assertSame(1, AuditLogEntry::where('action', 'admin.contact_reply_sent')->count());
    }
}
