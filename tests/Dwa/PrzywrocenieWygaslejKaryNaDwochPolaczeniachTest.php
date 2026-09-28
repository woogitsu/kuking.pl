<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * ZDEJMOWANIE WYGASŁYCH KAR KONTRA NOWSZA DECYZJA MODERATORA (#2019) —
 * pomiar na dwóch połączeniach.
 *
 * Deterministyczną wersję na jednym połączeniu (prawdziwe `ban()`/`suspend()`
 * wstawione po odczycie listy) ma zwykły zestaw:
 * `tests/Feature/PrzywrocenieWygaslejKaryNieCofaNowszejDecyzjiTest.php`.
 * Tu to samo dzieje się tak, jak na produkcji: komenda chodzi na połączeniu
 * aplikacji (bez `RefreshDatabase`, dane naprawdę zatwierdzone), a decyzja
 * moderatora zatwierdza się na OSOBNYM połączeniu — w szczelinie między
 * odczytem listy kandydatów a przywróceniem konta.
 *
 * ── PRZEPLOT ──
 *
 *   komenda (połączenie aplikacji)          moderator (osobne połączenie)
 *   ─────────────────────────────────       ─────────────────────────────
 *   SELECT kandydatów: „zawieszone,
 *     termin minął” → konto K na liście
 *                                           BEGIN; FOR UPDATE K;
 *                                           ban / nowy termin; COMMIT
 *   ZamekKonta: FOR UPDATE K (świeży)
 *     dziś: widzi nową karę → pomija,
 *           bez wpisu audytu
 *     przed #2019: `active`, termin
 *           wyzerowany, wpis „kara wygasła”
 *
 * Kontrola ujemna (28.09.2026): po usunięciu warunku `nadalWygasle()`
 * w `ZdejmijWygasleZawieszenie` oba przypadki z decyzją moderatora oblewają
 * (konto `active`), kontrola dodatnia przechodzi dalej.
 */
#[Group('dwa-polaczenia')]
final class PrzywrocenieWygaslejKaryNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    private const WPIS = 'account.suspension_expired';

    public function test_ban_zatwierdzony_po_odczycie_listy_wygrywa_z_automatem(): void
    {
        $konto = $this->zawieszonePoTerminie();

        $moderator = $this->nowePolaczenie();
        $this->poOdczycieListy(static function () use ($moderator, $konto): void {
            self::zatwierdz($moderator, "UPDATE users SET status = 'banned', status_expires_at = NULL WHERE id = ?", (string) $konto->getKey());
        });

        $wyjscie = $this->uruchomKomende();

        $swieze = $konto->fresh();
        $this->assertSame(User::STATUS_BANNED, $swieze?->status, 'Automat zdjął ban zatwierdzony po odczycie listy.');
        $this->assertNull($swieze?->status_expires_at);
        $this->assertSame(0, $this->wpisy($konto), 'Automat zapisał „kara wygasła” przy obowiązującym banie.');
        $this->assertStringContainsString('Pominięto: '.$konto->getKey(), $wyjscie);
    }

    public function test_nowy_termin_zawieszenia_zatwierdzony_po_odczycie_listy_wygrywa_z_automatem(): void
    {
        $konto = $this->zawieszonePoTerminie();

        $moderator = $this->nowePolaczenie();
        $this->poOdczycieListy(static function () use ($moderator, $konto): void {
            self::zatwierdz($moderator, "UPDATE users SET status_expires_at = now() + interval '7 days' WHERE id = ?", (string) $konto->getKey());
        });

        $wyjscie = $this->uruchomKomende();

        $swieze = $konto->fresh();
        $this->assertSame(User::STATUS_SUSPENDED, $swieze?->status, 'Automat zdjął zawieszenie z nowym terminem.');
        $this->assertNotNull($swieze?->status_expires_at, 'Automat wyzerował nowy termin zawieszenia.');
        $this->assertTrue($swieze->status_expires_at->isFuture());
        $this->assertSame(0, $this->wpisy($konto));
        $this->assertStringContainsString('Pominięto: '.$konto->getKey(), $wyjscie);
    }

    /** KONTROLA DODATNIA (zasada 6): bez ingerencji komenda naprawdę przywraca konto, z jednym wpisem. */
    public function test_wygasle_zawieszenie_bez_ingerencji_jest_przywracane(): void
    {
        $konto = $this->zawieszonePoTerminie();

        $wyjscie = $this->uruchomKomende();

        $swieze = $konto->fresh();
        $this->assertSame(User::STATUS_ACTIVE, $swieze?->status);
        $this->assertNull($swieze?->status_expires_at);
        $this->assertSame(1, $this->wpisy($konto));
        $this->assertStringContainsString('Przywrócono: '.$konto->getKey(), $wyjscie);
    }

    private function zawieszonePoTerminie(): User
    {
        return $this->konto([
            'status' => User::STATUS_SUSPENDED,
            'status_expires_at' => now()->subHour(),
        ]);
    }

    /**
     * Wykonuje `$decyzja` raz — zaraz po zapytaniu listy kandydatów, zanim
     * komenda sięgnie po blokadę pierwszego konta.
     */
    private function poOdczycieListy(\Closure $decyzja): void
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

    /** Decyzja moderatora: pod blokadą wiersza, zatwierdzona na osobnym połączeniu. */
    private static function zatwierdz(PDO $moderator, string $sql, string $id): void
    {
        $moderator->beginTransaction();
        $moderator->prepare('SELECT 1 FROM users WHERE id = ? FOR UPDATE')->execute([$id]);
        $zmiana = $moderator->prepare($sql);
        $zmiana->execute([$id]);
        $moderator->commit();

        if ($zmiana->rowCount() !== 1) {
            throw new \RuntimeException('Decyzja moderatora nie trafiła w konto — test mierzyłby przeplot, którego nie ma.');
        }
    }

    private function uruchomKomende(): string
    {
        $this->assertSame(0, Artisan::call('kuking:zdejmij-wygasle-kary'));

        return Artisan::output();
    }

    private function wpisy(User $konto): int
    {
        return AuditLogEntry::query()
            ->where('action', self::WPIS)
            ->where('subject_id', $konto->getKey())
            ->count();
    }
}
