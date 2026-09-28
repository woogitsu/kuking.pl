<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plakietka nieprzeczytanych w belce ma sufit „99+" i nie liczy wszystkich
 * zaległości na każdej stronie (audyt B4 S1).
 */
class PlakietkaPowiadomienZSufitemTest extends TestCase
{
    use RefreshDatabase;

    public function test_powyzej_sufitu_plakietka_mowi_99_plus(): void
    {
        $ala = $this->user('alaplakietka');
        $this->powiadomienia($ala, User::PLAKIETKA_POWIADOMIEN_DO + 5);

        $this->assertSame(User::PLAKIETKA_POWIADOMIEN_DO + 1, $ala->unreadNotificationsBadgeCount());

        $this->actingAs($ala)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('99+')
            ->assertSee('ponad 99 nieprzeczytanych')
            ->assertDontSee('>104<', false);
    }

    public function test_ponizej_sufitu_plakietka_liczy_dokladnie(): void
    {
        $ala = $this->user('alamalo');
        $this->powiadomienia($ala, 3);

        $this->assertSame(3, $ala->unreadNotificationsBadgeCount());
        $this->assertSame($ala->unreadNotificationsCount(), $ala->unreadNotificationsBadgeCount());

        $this->actingAs($ala)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('<span class="badge badge-cooked">3</span>', false)
            ->assertDontSee('99+');
    }

    public function test_zapytanie_plakietki_ma_limit(): void
    {
        $ala = $this->user('alalimit');

        DB::enableQueryLog();
        $ala->unreadNotificationsBadgeCount();
        $zapytania = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertCount(1, $zapytania);
        $this->assertStringContainsString('limit '.(User::PLAKIETKA_POWIADOMIEN_DO + 1), $zapytania[0],
            'Plakietka liczy wszystkie nieprzeczytane — koszt rośnie z zaległościami.');
    }

    private function powiadomienia(User $odbiorca, int $ile): void
    {
        $aktor = $this->user('aktor'.substr((string) $odbiorca->getKey(), 0, 8));

        foreach (range(1, $ile) as $i) {
            Notification::create([
                'user_id' => $odbiorca->getKey(),
                'actor_id' => $aktor->getKey(),
                'type' => Notification::TYPE_FOLLOW,
                'data' => [],
            ]);
        }
    }
}
