<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zdejmowanie wygasłych kar nie cofa NOWSZEJ decyzji moderatora (#2019).
 *
 * CO BYŁO ŹLE
 * `kuking:zdejmij-wygasle-kary` czyta listę kont „zawieszone, termin minął”
 * bez blokady, a dopiero potem, konto po koncie, woła `reinstate()`. Ta
 * metoda bierze świeży wiersz pod `ZamekKonta`, ale NIE pyta, czy to nadal
 * jest wygasłe zawieszenie — każdy status poza cyklem usuwania zamienia na
 * `active`. Jeśli między odczytem listy a przywróceniem moderator zbanował
 * konto albo zawiesił je na nowy termin, automat odwracał tę decyzję
 * i jeszcze zapisywał w audycie „kara wygasła”, czyli fałszywy ślad.
 *
 * JAK TEN TEST WCHODZI W SZCZELINĘ
 * Na jednym połączeniu, deterministycznie: słuchacz zapytań czeka na
 * zapytanie listy (to z `status_expires_at <= ?` i sortowaniem po
 * terminie) i ZARAZ PO NIM, zanim komenda dojdzie do pierwszego konta,
 * wykonuje prawdziwą decyzję moderatora (`ban()` albo `suspend($termin)`).
 * Komenda ma więc w ręku starą listę, a w bazie leży już nowa kara —
 * dokładnie przeplot z issue. Ten sam przeplot na DWÓCH prawdziwych
 * połączeniach mierzy `tests/Dwa/PrzywrocenieWygaslejKaryNaDwochPolaczeniachTest.php`
 * (grupa `dwa-polaczenia`, w CI nieblokująca — dlatego ten test jest tutaj).
 */
class PrzywrocenieWygaslejKaryNieCofaNowszejDecyzjiTest extends TestCase
{
    use RefreshDatabase;

    private const WPIS = 'account.suspension_expired';

    private function zawieszonyPoTerminie(string $username): User
    {
        return $this->user($username, [
            'status' => User::STATUS_SUSPENDED,
            'status_expires_at' => now()->subHour(),
        ]);
    }

    /**
     * Wykonuje `$decyzja` raz — tuż po tym, jak komenda odczytała listę
     * kandydatów, a przed przywróceniem pierwszego z nich.
     */
    private function poOdczycieListy(Closure $decyzja): void
    {
        $wykonana = false;

        DB::listen(static function (QueryExecuted $zapytanie) use (&$wykonana, $decyzja): void {
            if ($wykonana
                || ! str_contains($zapytanie->sql, '"status_expires_at" <= ?')
                || ! str_contains($zapytanie->sql, 'order by "status_expires_at"')) {
                return;
            }

            $wykonana = true;
            $decyzja();
        });
    }

    private function uruchom(): string
    {
        $this->assertSame(0, Artisan::call('kuking:zdejmij-wygasle-kary'));

        return Artisan::output();
    }

    private function wpisyDla(User $user): int
    {
        return AuditLogEntry::query()
            ->where('action', self::WPIS)
            ->where('subject_id', $user->getKey())
            ->count();
    }

    public function test_ban_nalozony_po_odczycie_listy_zostaje_i_nie_ma_falszywego_wpisu(): void
    {
        $zenek = $this->zawieszonyPoTerminie('zenek');
        // Drugie konto na tej samej liście: pominięcie jednego nie może
        // zatrzymać przywracania reszty.
        $basia = $this->zawieszonyPoTerminie('basia');

        $this->poOdczycieListy(static fn () => User::query()->findOrFail($zenek->getKey())->ban());

        $wyjscie = $this->uruchom();

        $zenek->refresh();
        $this->assertSame(User::STATUS_BANNED, $zenek->status, 'Automat zdjął ban nałożony po odczycie listy.');
        $this->assertNull($zenek->status_expires_at);
        $this->assertSame(0, $this->wpisyDla($zenek), 'Automat zapisał „kara wygasła” przy obowiązującym banie.');

        // Kontrola dodatnia na tym samym przebiegu.
        $this->assertSame(User::STATUS_ACTIVE, $basia->refresh()->status);
        $this->assertSame(1, $this->wpisyDla($basia));

        $this->assertStringContainsString('Pominięto: '.$zenek->getKey(), $wyjscie);
        $this->assertStringContainsString('Przywrócono: '.$basia->getKey(), $wyjscie);
    }

    public function test_nowy_termin_zawieszenia_po_odczycie_listy_zostaje_i_nie_ma_falszywego_wpisu(): void
    {
        $zenek = $this->zawieszonyPoTerminie('zenek');
        $nowyTermin = now()->addDays(7)->startOfSecond();

        $this->poOdczycieListy(static fn () => User::query()->findOrFail($zenek->getKey())->suspend($nowyTermin));

        $wyjscie = $this->uruchom();

        $zenek->refresh();
        $this->assertSame(User::STATUS_SUSPENDED, $zenek->status, 'Automat zdjął zawieszenie z nowym terminem.');
        $this->assertNotNull($zenek->status_expires_at, 'Automat wyzerował nowy termin zawieszenia.');
        $this->assertTrue($zenek->status_expires_at->equalTo($nowyTermin));
        $this->assertSame(0, $this->wpisyDla($zenek));
        $this->assertStringContainsString('Pominięto: '.$zenek->getKey(), $wyjscie);
    }

    public function test_zawieszenie_bezterminowe_po_odczycie_listy_zostaje(): void
    {
        $zenek = $this->zawieszonyPoTerminie('zenek');

        $this->poOdczycieListy(static fn () => User::query()->findOrFail($zenek->getKey())->suspend());

        $this->artisan('kuking:zdejmij-wygasle-kary')->assertSuccessful();

        $zenek->refresh();
        $this->assertSame(User::STATUS_SUSPENDED, $zenek->status);
        $this->assertNull($zenek->status_expires_at);
        $this->assertSame(0, $this->wpisyDla($zenek));
    }

    /** Kontrola dodatnia: bez ingerencji wygasłe zawieszenie nadal wraca do `active`, z jednym wpisem. */
    public function test_wygasle_zawieszenie_bez_ingerencji_jest_przywracane_z_jednym_wpisem(): void
    {
        $zenek = $this->zawieszonyPoTerminie('zenek');

        $this->artisan('kuking:zdejmij-wygasle-kary')
            ->expectsOutputToContain('Przywrócono: '.$zenek->getKey())
            ->expectsOutputToContain('Przywrócono kont: 1.')
            ->assertSuccessful();

        $zenek->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $zenek->status);
        $this->assertNull($zenek->status_expires_at);
        $this->assertSame(1, $this->wpisyDla($zenek));
    }
}
