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
 *
 * Issue #1401: „Oznacz wszystkie jako przeczytane” gasiło też powiadomienia
 * ukryte przez blokadę (`markAllRead()` bez `visibleTo()`). Po odblokowaniu
 * wracały bez znacznika „Nowe”, choć nikt ich nie widział.
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

    // --- Issue #1401 (gałąź), ten sam kontrakt od drugiej strony ---

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
