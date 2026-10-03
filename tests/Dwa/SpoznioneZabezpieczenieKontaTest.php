<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Users\Actions\RequestEmailChange;
use App\Models\AuditLogEntry;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Support\Sesja\GeneracjaSesji;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** #2851/#2854: precheck na starej sesji nie jest zgodą na późniejszy zapis. */
#[Group('dwa-polaczenia')]
final class SpoznioneZabezpieczenieKontaTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $adresyZTokenem = [];

    protected function tearDown(): void
    {
        if ($this->adresyZTokenem !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $this->adresyZTokenem)->delete();
        }

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function drogi(): array
    {
        return [
            'zmiana hasła po resecie' => ['zmiana', 'reset'],
            'zmiana hasła po zmianie w drugiej sesji' => ['zmiana', 'zmiana'],
            'zmiana hasła po resecie do tego samego hasła' => ['zmiana', 'reset-to-same'],
            'wylogowanie innych po resecie' => ['wyloguj', 'reset'],
            'wylogowanie innych po zmianie w drugiej sesji' => ['wyloguj', 'zmiana'],
            'wylogowanie innych po resecie do tego samego hasła' => ['wyloguj', 'reset-to-same'],
        ];
    }

    #[DataProvider('drogi')]
    public function test_spoznione_zadanie_nie_odnawia_odwolanej_sesji_ani_nie_zapisuje_nic_po_barierze(string $drogaA, string $drogaB): void
    {
        Notification::fake();
        $konto = $this->konto();
        $adres = (string) $konto->email;
        app(RequestEmailChange::class)->handle($konto, 'nowy-'.bin2hex(random_bytes(5)).'@example.test');
        $token = str_starts_with($drogaB, 'reset') ? Password::createToken($konto) : null;
        if ($token !== null) {
            $this->adresyZTokenem[] = $adres;
        }

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2851, 1)', []);
        $spoznione = $this->wTle('spoznione-zabezpieczenie-konta', [
            'konto' => (string) $konto->getKey(),
            'droga' => $drogaA,
            'obecne' => 'haslo-testowe-123',
            'haslo' => 'haslo-spoznione-2851',
            'generacja' => (string) $konto->session_generation,
        ], ['SESSION_DRIVER' => 'database']);

        try {
            $this->czekajNaZablokowane(1); // A naprawdę przeszło stary Hash::check.
            $argumentyB = [
                'konto' => (string) $konto->getKey(),
                'droga' => str_starts_with($drogaB, 'reset') ? 'reset' : 'zmiana',
                'haslo' => $drogaB === 'reset-to-same' ? 'haslo-testowe-123' : 'haslo-wlasciciela-2851',
            ];
            if (str_starts_with($drogaB, 'reset')) {
                $argumentyB['token'] = (string) $token;
                $argumentyB['email'] = $adres;
            } else {
                $argumentyB['obecne'] = 'haslo-testowe-123';
            }

            $wczesniejsze = $this->wTle('ustaw-haslo', $argumentyB)->wynik();
            $this->assertTrue($wczesniejsze['ok'], $wczesniejsze['komunikat']);
            $this->assertSame([], $wczesniejsze['wartosc']['bledy'] ?? null);

            $poB = $konto->fresh();
            $this->assertInstanceOf(User::class, $poB);
            $this->assertTrue(Hash::check($argumentyB['haslo'], (string) $poB->password));
            $audytPoB = AuditLogEntry::query()->where('subject_id', $konto->getKey())->count();
            $oczekujacePoB = PendingEmailChange::query()->where('user_id', $konto->getKey())->count();
            $tokenyPoB = DB::table('password_reset_tokens')->where('email', $adres)->count();
            $sesjaWlasciciela = Str::random(40);
            DB::table('sessions')->insert([
                'id' => $sesjaWlasciciela,
                'user_id' => $konto->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'sesja-wlasciciela-po-zmianie',
                'payload' => '',
                'last_activity' => time(),
            ]);
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $wynikA = $spoznione->wynik();
        $this->assertBezZakleszczenia($wynikA, 'spóźnione zabezpieczenie konta');
        $this->assertTrue($wynikA['ok'], $wynikA['komunikat']);

        $poA = $konto->fresh();
        $this->assertInstanceOf(User::class, $poA);
        $this->assertSame($poB->password, $poA->password, 'HASLO_2851_STARA_SESJA_NIE_ZMIENIA');
        $this->assertSame($poB->session_generation, $poA->session_generation, 'SESJE_2854_STARA_SESJA_NIE_AWANSUJE');
        $this->assertSame($poB->remember_token, $poA->remember_token);
        $this->assertSame($audytPoB, AuditLogEntry::query()->where('subject_id', $konto->getKey())->count());
        $this->assertSame($oczekujacePoB, PendingEmailChange::query()->where('user_id', $konto->getKey())->count());
        $this->assertSame($tokenyPoB, DB::table('password_reset_tokens')->where('email', $adres)->count());
        $this->assertSame(1, DB::table('sessions')->where('id', $sesjaWlasciciela)->where('user_id', $konto->getKey())->count());
        $this->assertSame(0, $wynikA['wartosc']['listy'] ?? null);
        $this->assertSame(route('login'), $wynikA['wartosc']['redirect'] ?? null);
        $this->assertFalse($wynikA['wartosc']['auth'] ?? true, 'STARA_SESJA_2851_2854_ODMOWA');
        $this->assertSame([], $wynikA['wartosc']['old'] ?? null, 'HASLO_2851_2854_BEZ_OLD_INPUT');

        // Kolejne prawdziwe żądanie HTTP z uwierzytelnieniem z poprzedniej
        // generacji nie może odzyskać ekranu bezpieczeństwa konta.
        $odpowiedz = $this->actingAs($poA)
            ->withSession([GeneracjaSesji::KLUCZ => (int) $konto->session_generation])
            ->get(route('settings.security'));
        $this->assertSame(route('login'), $odpowiedz->headers->get('Location'), 'HTTP_2851_2854_STARA_GENERACJA_ODMOWA');
    }
}
