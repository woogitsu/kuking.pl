<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnionePowiadomienia;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retencja `notifications` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.2):
 * jeden wiek dla wszystkich, niezależnie od `read_at` (wariant A z ADR §6).
 */
class RetencjaPowiadomienTest extends TestCase
{
    use RefreshDatabase;

    private function powiadomienie(string $userId, \DateTimeInterface|string $createdAt, ?\DateTimeInterface $readAt = null): Notification
    {
        $powiadomienie = Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_COMMENT,
            'data' => ['tresc' => 'test'],
        ]);

        DB::table('notifications')->where('id', $powiadomienie->getKey())->update([
            'created_at' => $createdAt,
            'read_at' => $readAt,
        ]);

        return $powiadomienie->refresh();
    }

    public function test_powiadomienie_starsze_niz_prog_znika_a_mlodsze_zostaje(): void
    {
        config(['kuking.notifications.retention_months' => 24]);
        $basia = $this->user('basia');

        $stare = $this->powiadomienie($basia->getKey(), now()->subMonths(24)->subDay());
        $mlode = $this->powiadomienie($basia->getKey(), now()->subMonths(24)->addDay());

        $skasowane = (new PrzedawnionePowiadomienia)->posprzataj(24);

        $this->assertSame(1, $skasowane);
        $this->assertDatabaseMissing('notifications', ['id' => $stare->getKey()]);
        // Asercja kontrolna.
        $this->assertDatabaseHas('notifications', ['id' => $mlode->getKey()]);
    }

    /**
     * Wariant A z ADR §6: `read_at` NIE MA znaczenia. Nieprzeczytane
     * powiadomienie, stare ponad próg, znika tak samo jak przeczytane —
     * inaczej dałoby się je trzymać bezterminowo, unikając otwarcia.
     */
    public function test_nieprzeczytane_powiadomienie_starsze_niz_prog_rowniez_znika(): void
    {
        config(['kuking.notifications.retention_months' => 24]);
        $basia = $this->user('basia');

        $nieprzeczytane = $this->powiadomienie($basia->getKey(), now()->subMonths(30), readAt: null);
        // Kontrola pozytywna: to naprawdę było nieprzeczytane przed kasowaniem.
        $this->assertNull($nieprzeczytane->fresh()->read_at);

        $skasowane = (new PrzedawnionePowiadomienia)->posprzataj(24);

        $this->assertSame(1, $skasowane);
        $this->assertDatabaseMissing('notifications', ['id' => $nieprzeczytane->getKey()]);
    }

    public function test_komenda_retencji_dziala_i_respektuje_opcje_na_sucho(): void
    {
        config(['kuking.notifications.retention_months' => 24]);
        $basia = $this->user('basia');

        $this->powiadomienie($basia->getKey(), now()->subMonths(30));

        $this->artisan('kuking:sprzataj-powiadomienia', ['--na-sucho' => true])->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);

        $this->artisan('kuking:sprzataj-powiadomienia')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
    }
}
