<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1401: „Oznacz wszystkie jako przeczytane” gasiło też powiadomienia
 * ukryte przez blokadę (`markAllRead()` bez `visibleTo()`). Po odblokowaniu
 * wracały bez znacznika „Nowe”, choć nikt ich nie widział.
 */
class OznaczWszystkieTylkoWidoczneTest extends TestCase
{
    use RefreshDatabase;

    public function test_oznacz_wszystkie_nie_rusza_powiadomienia_od_zablokowanej_osoby(): void
    {
        [$ala, $ukryte, $widoczne] = $this->alaZDwomaPowiadomieniami();
        $basia = User::query()->findOrFail($ukryte->actor_id);
        $powitanie = app(NotifyUser::class)->handle(recipient: $ala, type: Notification::TYPE_WELCOME);
        $this->assertNotNull($powitanie);

        app(BlockUser::class)->handle($ala, $basia);

        $this->actingAs($ala)->post(route('notifications.read'))->assertRedirect();

        // Kontrola dodatnia: przycisk w ogóle zadziałał na widocznym.
        $this->assertNotNull($widoczne->refresh()->read_at);
        $this->assertNotNull($powitanie->refresh()->read_at, 'Powiadomienie bez sprawcy jest widoczne, więc też ma zgasnąć.');
        $this->assertNull($ukryte->refresh()->read_at, 'Ukryte blokadą powiadomienie nie może zostać „przeczytane”.');

        app(UnblockUser::class)->handle($ala, $basia);

        $this->assertNull($ukryte->refresh()->read_at);
        $this->assertSame(1, $ala->refresh()->unreadNotificationsCount());
    }

    public function test_blokada_w_druga_strone_tez_chroni_nieprzeczytany_stan(): void
    {
        [$ala, $ukryte, $widoczne] = $this->alaZDwomaPowiadomieniami();
        $basia = User::query()->findOrFail($ukryte->actor_id);

        app(BlockUser::class)->handle($basia, $ala);

        $this->actingAs($ala)->post(route('notifications.read'))->assertRedirect();
        // Powtórne kliknięcie niczego nie psuje.
        $this->actingAs($ala)->post(route('notifications.read'))->assertRedirect();

        $this->assertNotNull($widoczne->refresh()->read_at);
        $this->assertNull($ukryte->refresh()->read_at);
    }

    public function test_bezposredni_post_przy_pustej_widocznej_liscie_nic_nie_zmienia(): void
    {
        [$ala, $ukryte, $widoczne] = $this->alaZDwomaPowiadomieniami();
        $widoczne->forceFill(['read_at' => now()->subDay()])->save();
        $basia = User::query()->findOrFail($ukryte->actor_id);

        app(BlockUser::class)->handle($ala, $basia);

        $this->actingAs($ala)->post(route('notifications.read'))->assertRedirect();

        $this->assertNull($ukryte->refresh()->read_at);
    }

    /** @return array{0: User, 1: Notification, 2: Notification} */
    private function alaZDwomaPowiadomieniami(): array
    {
        $ala = $this->user('ala1401');
        $basia = $this->user('basia1401');
        $celina = $this->user('celina1401');

        foreach ([$basia, $celina] as $sprawca) {
            app(NotifyUser::class)->handle(
                recipient: $ala,
                type: Notification::TYPE_FOLLOW,
                actor: $sprawca,
                data: ['username' => $sprawca->profile->username],
            );
        }

        $ukryte = Notification::query()->where('user_id', $ala->getKey())->where('actor_id', $basia->getKey())->firstOrFail();
        $widoczne = Notification::query()->where('user_id', $ala->getKey())->where('actor_id', $celina->getKey())->firstOrFail();

        $this->assertNull($ukryte->read_at);
        $this->assertNull($widoczne->read_at);

        return [$ala, $ukryte, $widoczne];
    }
}
