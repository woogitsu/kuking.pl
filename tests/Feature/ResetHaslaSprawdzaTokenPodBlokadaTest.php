<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Token resetu jest sprawdzany jeszcze raz POD BLOKADĄ KONTA (issue #2055).
 *
 * `PasswordBroker::reset()` sprawdza token przed naszym wywołaniem zwrotnym,
 * a kasuje go dopiero po nim. Drugie żądanie z tym samym linkiem mogło więc
 * przejść walidację, poczekać na blokadę konta, którą trzymało pierwsze —
 * i po jego zatwierdzeniu nadpisać hasło swoim.
 *
 * Tu, na jednym połączeniu, odgrywamy „pierwsze żądanie" dokładnie w oknie
 * z issue: zaraz PO walidacji tokenu przez brokera, a PRZED blokadą konta.
 * (Nie w chwili samej blokady — wtedy nasza zmiana siedziałaby w transakcji
 * żądania i odmowa wycofałaby ją razem z nim.) Pełny wyścig na dwóch
 * połączeniach: `Tests\Dwa\JedenTokenResetuDwaZadaniaTest`.
 */
class ResetHaslaSprawdzaTokenPodBlokadaTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO_ZWYCIEZCY = 'haslozwyciezcy-2055';

    private const HASLO_SPOZNIONE = 'haslospoznione-2055';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_link_zuzyty_w_czasie_czekania_na_blokade_nie_ustawia_hasla(): void
    {
        $basia = $this->user('basia');
        $token = Password::createToken($basia);

        // „Pierwsze żądanie" kończy się, zanim to dostanie blokadę konta:
        // ustawia swoje hasło i zużywa token.
        $this->poWalidacjiBrokera(function () use ($basia): void {
            DB::table('users')->where('id', $basia->getKey())
                ->update(['password' => Hash::make(self::HASLO_ZWYCIEZCY)]);
            DB::table('password_reset_tokens')->where('email', $basia->email)->delete();
        });

        Event::fake([PasswordReset::class]);

        $this->wyslij($basia, $token)
            ->assertRedirect(route('password.reset', $token))
            ->assertSessionHasErrors(['email' => 'Ten link do ustawienia hasła jest już nieaktualny. Poproś o nowy.']);

        $this->assertTrue(Hash::check(self::HASLO_ZWYCIEZCY, (string) $basia->fresh()?->password));
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'account.password_reset')->count());
        Event::assertNotDispatched(PasswordReset::class);
    }

    public function test_link_wygasly_w_czasie_czekania_na_blokade_nie_ustawia_hasla(): void
    {
        $basia = $this->user('basia');
        $staryHash = (string) $basia->password;
        $token = Password::createToken($basia);

        $this->poWalidacjiBrokera(function () use ($basia): void {
            DB::table('password_reset_tokens')->where('email', $basia->email)
                ->update(['created_at' => now()->subDay()]);
        });

        $this->wyslij($basia, $token)
            ->assertSessionHasErrors(['email' => 'Ten link do ustawienia hasła jest już nieaktualny. Poproś o nowy.']);

        $this->assertSame($staryHash, (string) $basia->fresh()?->password);
    }

    /** Kontrola dodatnia: ten sam przyrząd bez ingerencji przepuszcza reset. */
    public function test_nietkniety_link_dalej_ustawia_haslo_i_znika(): void
    {
        $basia = $this->user('basia');
        $token = Password::createToken($basia);
        $zadzialal = false;

        $this->poWalidacjiBrokera(function () use (&$zadzialal): void {
            $zadzialal = true;
        });

        $this->wyslij($basia, $token)->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertTrue($zadzialal, 'Przyrząd nie trafił w walidację brokera — pozostałe testy nic by nie mierzyły.');
        $this->assertTrue(Hash::check(self::HASLO_SPOZNIONE, (string) $basia->fresh()?->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $basia->email]);
    }

    /**
     * Wykonuje `$co` raz, zaraz po PIERWSZYM odczycie tokenu — to jest
     * `validateReset()` brokera, przed wywołaniem zwrotnym i blokadą konta.
     */
    private function poWalidacjiBrokera(Closure $co): void
    {
        $wykonane = false;

        DB::listen(function (QueryExecuted $zapytanie) use ($co, &$wykonane): void {
            if ($wykonane || ! str_starts_with($zapytanie->sql, 'select * from "password_reset_tokens"')) {
                return;
            }

            $wykonane = true;
            $co();
        });
    }

    private function wyslij(User $user, string $token): TestResponse
    {
        return $this->from(route('password.reset', $token))->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::HASLO_SPOZNIONE,
            'password_confirmation' => self::HASLO_SPOZNIONE,
        ]);
    }
}
