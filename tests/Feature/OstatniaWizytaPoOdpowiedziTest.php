<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Zapis `users.ostatnio_widziany_at` idzie PO odpowiedzi (issue #1044).
 *
 * Trasy `/_1044/...` są w grupie `web`, więc przechodzą przez globalny
 * `AktualizujOstatniaWizyte`. Kontroler odczytuje znacznik prosto z bazy —
 * to jest chwila „budowania odpowiedzi”. Klient testowy wywołuje potem
 * `Kernel::terminate()`, a tam Laravel wykonuje funkcje z `defer()`.
 *
 * Kontrola dodatnia (zmierzona 24.09.2026): przywrócenie w middleware
 * `$this->zanotuj->handle($user)` (zapis przed kontrolerem) wywraca
 * test_kontroler_widzi_jeszcze_stary_znacznik; usunięcie `->always()`
 * wywraca test_zapis_idzie_takze_po_odpowiedzi_z_bledem; usunięcie warunku
 * progu z `UPDATE` wywraca test_rownolegly_zapis_w_oknie_progu_nie_jest_nadpisany;
 * usunięcie warunku statusu wywraca test_konto_zamkniete_w_trakcie_zadania_nie_zasila_metryki.
 */
class OstatniaWizytaPoOdpowiedziTest extends TestCase
{
    use RefreshDatabase;

    private const PRZED = '2026-09-06 10:00:00';

    private const TERAZ = '2026-09-08 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.analytics.last_seen_throttle_minutes' => 15]);
        $this->travelTo(Carbon::parse(self::TERAZ, 'UTC'));

        $odczyt = fn () => (string) DB::table('users')->where('id', auth()->id())->value('ostatnio_widziany_at');

        Route::middleware('web')->get('/_1044/odczyt', $odczyt);
        Route::middleware('web')->get('/_1044/404', fn () => abort(404));
        Route::middleware('web')->get('/_1044/500', fn () => throw new RuntimeException('awaria testowa 1044'));
        Route::middleware('web')->get('/_1044/zamknij', function () use ($odczyt) {
            DB::table('users')->where('id', auth()->id())->update(['status' => User::STATUS_PENDING_DELETE]);

            return $odczyt();
        });
        Route::middleware('web')->get('/_1044/rownolegle', function () {
            // Inne żądanie tej osoby zdążyło zapisać minutę temu.
            DB::table('users')->where('id', auth()->id())->update(['ostatnio_widziany_at' => now()->subMinute()]);

            return 'ok';
        });
    }

    private function osoba(): User
    {
        return $this->user('basia', ['ostatnio_widziany_at' => Carbon::parse(self::PRZED, 'UTC')]);
    }

    private function znacznik(User $user): ?Carbon
    {
        return $user->fresh()->ostatnio_widziany_at;
    }

    public function test_kontroler_widzi_jeszcze_stary_znacznik(): void
    {
        $basia = $this->osoba();

        $odpowiedz = $this->actingAs($basia)->get('/_1044/odczyt')->assertOk();

        $this->assertTrue(
            Carbon::parse($odpowiedz->getContent(), 'UTC')->equalTo(Carbon::parse(self::PRZED, 'UTC')),
            'Zapis wizyty wykonał się przed kontrolerem — odpowiedź czeka na UPDATE (#1044).',
        );
        $this->assertTrue(
            $this->znacznik($basia)?->equalTo(Carbon::parse(self::TERAZ, 'UTC')) === true,
            'Po odpowiedzi znacznik powinien nieść chwilę żądania.',
        );
    }

    public function test_zapis_idzie_takze_po_odpowiedzi_z_bledem(): void
    {
        foreach (['/_1044/404' => 404, '/_1044/500' => 500] as $adres => $kod) {
            $basia = $this->osoba();

            $this->actingAs($basia)->get($adres)->assertStatus($kod);

            $this->assertTrue(
                $this->znacznik($basia)?->equalTo(Carbon::parse(self::TERAZ, 'UTC')) === true,
                "Po odpowiedzi {$kod} znacznik nie został zapisany — `defer()` bez `->always()` pomija 4xx/5xx.",
            );

            $basia->forceDelete();
        }
    }

    public function test_konto_zamkniete_w_trakcie_zadania_nie_zasila_metryki(): void
    {
        $basia = $this->osoba();

        $this->actingAs($basia)->get('/_1044/zamknij')->assertOk();

        $this->assertTrue(
            $this->znacznik($basia)?->equalTo(Carbon::parse(self::PRZED, 'UTC')) === true,
            'Konto zamknięte w trakcie żądania dostało znacznik wizyty po odpowiedzi.',
        );
    }

    public function test_rownolegly_zapis_w_oknie_progu_nie_jest_nadpisany(): void
    {
        $basia = $this->osoba();

        $this->actingAs($basia)->get('/_1044/rownolegle')->assertOk();

        $this->assertTrue(
            $this->znacznik($basia)?->equalTo(now()->subMinute()) === true,
            'Odroczony zapis nadpisał świeższy znacznik z równoległego żądania — próg ma pilnować sam UPDATE.',
        );
    }

    public function test_w_oknie_progu_nic_nie_jest_odkladane(): void
    {
        $basia = $this->user('basia', ['ostatnio_widziany_at' => now()->subMinutes(5)]);
        $zapisy = 0;
        DB::listen(function ($zapytanie) use (&$zapisy): void {
            if (str_starts_with($zapytanie->sql, 'update') && str_contains($zapytanie->sql, 'ostatnio_widziany_at')) {
                $zapisy++;
            }
        });

        $this->actingAs($basia)->get('/_1044/odczyt')->assertOk();

        $this->assertSame(0, $zapisy);
    }

    public function test_awaria_odroczonego_zapisu_nie_zmienia_odpowiedzi_ani_nie_loguje_uuid(): void
    {
        $basia = $this->osoba();
        $dziennik = Log::spy();
        DB::beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'update') && str_contains($sql, 'ostatnio_widziany_at')) {
                throw new RuntimeException('awaria zapisu '.$sql);
            }
        });

        $this->actingAs($basia)->get('/_1044/odczyt')->assertOk();

        $dziennik->shouldHaveReceived('warning')->withArgs(function (string $komunikat, array $kontekst) use ($basia): bool {
            return $komunikat === 'Nie udało się zapisać ostatniej wizyty użytkownika.'
                && $kontekst === ['wyjatek' => RuntimeException::class]
                && ! str_contains(json_encode($kontekst, JSON_THROW_ON_ERROR), (string) $basia->getKey());
        })->once();
        $this->assertTrue($this->znacznik($basia)?->equalTo(Carbon::parse(self::PRZED, 'UTC')) === true);
    }
}
