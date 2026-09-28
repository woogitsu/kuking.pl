<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** Dwa połączenia ustawione w kolejce za tą samą blokadą zmiany roli (#2086). */
#[Group('dwa-polaczenia')]
final class DegradacjaAktoraPrzedSkutkiemTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $wiadomosci = [];

    protected function tearDown(): void
    {
        if ($this->wiadomosci !== []) {
            DB::table('contact_messages')->whereIn('id', $this->wiadomosci)->delete();
        }

        parent::tearDown();
    }

    public function test_degradacja_wyprzedza_zdjecie_wpisu_z_urzedu(): void
    {
        $aktor = $this->moderator();
        $this->konta[] = (string) $aktor->getKey();
        $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $wynik = $this->degradacjaPrzedAkcja($aktor, 'zdejmij-z-urzedu', [
            'wpis' => (string) $wpis->getKey(),
        ]);

        $this->assertSame(AuthorizationException::class, $wynik['wyjatek']);
        $this->assertFalse($wpis->fresh()?->trashed());
        $this->assertSame(0, ModerationAction::query()->where('target_id', $wpis->getKey())->count());
    }

    public function test_degradacja_wyprzedza_przyjecie_odpowiedzi_email(): void
    {
        $aktor = $this->moderator();
        $this->konta[] = (string) $aktor->getKey();
        $this->konto(['role' => User::ROLE_ADMIN]);
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $this->wiadomosci[] = (string) $wiadomosc->getKey();

        $wynik = $this->degradacjaPrzedAkcja($aktor, 'wyslij-odpowiedz', [
            'wiadomosc' => (string) $wiadomosc->getKey(),
        ]);

        $this->assertSame(AuthorizationException::class, $wynik['wyjatek']);
        $this->assertSame(0, ContactMessageReply::query()
            ->where('contact_message_id', $wiadomosc->getKey())->count());
        $this->assertSame(0, DB::table('audit_log')->where('subject_id', $wiadomosc->getKey())
            ->where('action', 'like', 'admin.contact_reply_%')->count());
    }

    /**
     * @param  array<string, string>  $argumenty
     * @return array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string}
     */
    private function degradacjaPrzedAkcja(User $aktor, string $scenariusz, array $argumenty): array
    {
        // Kolejka jest deterministyczna: degradacja dochodzi do blokady
        // pierwsza, akcja już wczytuje stary model i czeka za nią.
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(1016, 1)', []);
        $degradacja = $this->wTle('zmien-role', [
            'kto' => (string) $aktor->getKey(),
            'rola' => User::ROLE_USER,
        ]);
        $this->czekajNaZablokowane(1);
        $akcja = $this->wTle($scenariusz, ['kto' => (string) $aktor->getKey(), ...$argumenty]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikDegradacji = $degradacja->wynik();
        $wynikAkcji = $akcja->wynik();
        $this->assertBezZakleszczenia($wynikDegradacji, 'degradacja');
        $this->assertBezZakleszczenia($wynikAkcji, $scenariusz);
        $this->assertTrue($wynikDegradacji['ok'], $wynikDegradacji['komunikat']);
        $this->assertTrue($wynikDegradacji['wartosc']);
        $this->assertSame(User::ROLE_USER, $aktor->fresh()?->role);
        $this->assertFalse($wynikAkcji['ok'], 'Uprzywilejowany skutek przeszedł po degradacji.');

        return $wynikAkcji;
    }
}
