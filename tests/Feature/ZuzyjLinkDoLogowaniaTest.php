<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\Actions\ZuzyjLinkDoLogowania;
use App\Domain\Security\WejscieLinkiemWycofane;
use App\Models\AuditLogEntry;
use App\Models\LoginLinkToken;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `ZuzyjLinkDoLogowania` — zużycie tokenu wyjęte z `LoginLinkController::store()` (#970).
 *
 * Akcja wołana bez HTTP. Kolejność blokad (konto, potem token) i zachowanie
 * przy każdym rodzaju nieaktualnego linku zostają jak przed wyjęciem;
 * przebieg przez przeglądarkę pilnują dalej `LogowanieLinkiemTest`
 * i `WyscigLinkuDoLogowaniaTest`.
 */
class ZuzyjLinkDoLogowaniaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: LoginLinkToken} jawny token i jego wiersz */
    private function token(User $konto, int $minutDoWygasniecia = 30): array
    {
        $jawny = LoginLinkToken::nowyToken();

        $wiersz = new LoginLinkToken;
        $wiersz->user_id = $konto->getKey();
        $wiersz->token_hash = LoginLinkToken::skrot($jawny);
        $wiersz->created_at = now()->subHour();
        $wiersz->expires_at = now()->addMinutes($minutDoWygasniecia);
        $wiersz->save();

        return [$jawny, $wiersz];
    }

    private function akcja(): ZuzyjLinkDoLogowania
    {
        return app(ZuzyjLinkDoLogowania::class);
    }

    private function wpisow(): int
    {
        return AuditLogEntry::query()->where('action', 'account.login_link_used')->count();
    }

    #[Test]
    public function wazny_token_wpuszcza_konto_kasuje_sie_i_zostawia_jeden_wpis(): void
    {
        $konto = $this->user();
        [$jawny] = $this->token($konto);

        $wpuszczony = $this->akcja()->handle($jawny, '127.0.0.1');

        $this->assertTrue($konto->is($wpuszczony));
        $this->assertSame(0, LoginLinkToken::query()->count());
        $this->assertSame(1, $this->wpisow());
    }

    #[Test]
    public function token_jest_jednorazowy_drugie_uzycie_nie_wpuszcza(): void
    {
        $konto = $this->user();
        [$jawny] = $this->token($konto);

        $this->assertNotNull($this->akcja()->handle($jawny, null));
        $this->assertNull($this->akcja()->handle($jawny, null));
        $this->assertSame(1, $this->wpisow());
    }

    #[Test]
    public function nieznany_albo_uciety_token_nic_nie_zmienia(): void
    {
        $konto = $this->user();
        $this->token($konto);

        foreach ([LoginLinkToken::nowyToken(), 'krotki', ''] as $token) {
            $this->assertNull($this->akcja()->handle($token, null));
        }

        $this->assertSame(1, LoginLinkToken::query()->count(), 'Cudzy, ważny token nie może zniknąć.');
        $this->assertSame(0, $this->wpisow());
    }

    #[Test]
    public function wygasly_token_jest_sprzatany_i_nie_wpuszcza(): void
    {
        $konto = $this->user();
        [$jawny] = $this->token($konto, -5);

        $this->assertNull($this->akcja()->handle($jawny, null));
        $this->assertSame(0, LoginLinkToken::query()->count());
        $this->assertSame(0, $this->wpisow());
    }

    #[Test]
    public function konto_zamkniete_nie_wchodzi_a_link_i_tak_jest_zuzyty(): void
    {
        $konto = $this->user();
        [$jawny] = $this->token($konto);
        $konto->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->assertNull($this->akcja()->handle($jawny, null));
        $this->assertSame(0, LoginLinkToken::query()->count());
        $this->assertSame(0, $this->wpisow());
    }

    #[Test]
    public function konto_obslugi_serwisu_nie_wchodzi_ta_droga(): void
    {
        $moderator = $this->moderator();
        [$jawny] = $this->token($moderator);

        $this->assertNull($this->akcja()->handle($jawny, null));
        $this->assertSame(0, $this->wpisow());
    }

    #[Test]
    public function awaria_dziennika_wycofuje_zuzycie_i_link_dziala_przy_ponowieniu(): void
    {
        $konto = $this->user();
        [$jawny] = $this->token($konto);

        $awaria = true;
        DB::listen(function (QueryExecuted $zapytanie) use (&$awaria): void {
            if ($awaria
                && str_contains($zapytanie->sql, 'insert into "audit_log"')
                && in_array('account.login_link_used', $zapytanie->bindings, true)) {
                throw new RuntimeException('Wstrzyknięta awaria dziennika.');
            }
        });

        try {
            $this->akcja()->handle($jawny, null);
            $this->fail('Awaria dziennika miała wyjść jako WejscieLinkiemWycofane.');
        } catch (WejscieLinkiemWycofane $e) {
            $this->assertStringContainsString('„account.login_link_used"', $e->getMessage());
            $this->assertStringNotContainsString($jawny, $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        $this->assertSame(1, LoginLinkToken::query()->count(), 'Token musi wrócić.');
        $this->assertSame(0, $this->wpisow());

        $awaria = false;
        $this->assertTrue($konto->is($this->akcja()->handle($jawny, null)));
        $this->assertSame(1, $this->wpisow());
    }
}
