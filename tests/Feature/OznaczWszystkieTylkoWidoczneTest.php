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
 * Issue #969 — „Oznacz wszystkie jako przeczytane" działa na TYM SAMYM
 * zbiorze, który pokazuje lista i licznik (`Notification::visibleTo()`).
 *
 * Przed poprawką `markAllRead()` ustawiało `read_at` wszystkim
 * nieprzeczytanym wierszom odbiorcy — także ukrytym blokadą. Po zdjęciu
 * blokady takie powiadomienie wracało na listę już jako przeczytane,
 * choć człowiek nigdy go nie widział. Blokada filtruje przy ODCZYCIE,
 * żeby odblokowanie przywracało stan sprzed blokady
 * (`PowiadomieniaOdZablokowanychTest`) — przycisk nie może tego obchodzić.
 */
class OznaczWszystkieTylkoWidoczneTest extends TestCase
{
    use RefreshDatabase;

    public function test_oznacz_wszystkie_pomija_powiadomienie_ukryte_blokada(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia', ['display_name' => 'Basia Zablokowana']);
        $celina = $this->user('celina', ['display_name' => 'Celina Widoczna']);

        $odBasi = $this->obserwujeAle($ala, $basia);
        $odCeliny = $this->obserwujeAle($ala, $celina);

        app(BlockUser::class)->handle($ala, $basia);
        $this->assertSame(1, $ala->fresh()->unreadNotificationsCount());

        $this->actingAs($ala)
            ->from(route('notifications.index'))
            ->post(route('notifications.read'))
            ->assertRedirect(route('notifications.index'));

        // Kontrola dodatnia: widoczne powiadomienie ZOSTAŁO oznaczone
        // i licznik spadł do zera — przycisk dalej działa.
        $this->assertNotNull($odCeliny->fresh()->read_at);
        $this->assertSame(0, $ala->fresh()->unreadNotificationsCount());

        // Właściwa regresja: ukryte zostaje nieprzeczytane.
        $this->assertNull($odBasi->fresh()->read_at);

        // Po świadomym odblokowaniu wraca jako nowe, a nie „przeczytane".
        app(UnblockUser::class)->handle($ala, $basia);
        $this->assertSame(1, $ala->fresh()->unreadNotificationsCount());

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Basia Zablokowana');
    }

    public function test_blokada_w_druga_strone_tez_nie_daje_oznaczyc_ukrytego(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia');

        $odBasi = $this->obserwujeAle($ala, $basia);

        app(BlockUser::class)->handle($basia, $ala);

        $this->actingAs($ala)->post(route('notifications.read'))->assertRedirect();

        $this->assertNull($odBasi->fresh()->read_at);
    }

    private function obserwujeAle(User $ala, User $kto): Notification
    {
        app(NotifyUser::class)->handle(
            recipient: $ala,
            type: Notification::TYPE_FOLLOW,
            actor: $kto,
            data: ['username' => $kto->profile->username],
        );

        return Notification::query()
            ->where('user_id', $ala->getKey())
            ->where('actor_id', $kto->getKey())
            ->sole();
    }
}
