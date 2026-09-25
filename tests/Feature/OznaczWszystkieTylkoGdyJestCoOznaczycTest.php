<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1402 — „Oznacz wszystkie jako przeczytane" tylko wtedy, gdy jest
 * co oznaczyć.
 *
 * Przed poprawką oba przyciski (nad listą i pod nią) stały przy każdej
 * niepustej liście, także gdy wszystko było już przeczytane. Kliknięcie
 * nic nie zmieniało, a strona odpowiadała „Wszystkie powiadomienia
 * oznaczone jako przeczytane." — martwy przycisk z potwierdzeniem
 * nieistniejącej pracy (AGENTS.md §5).
 *
 * Warunek liczy się na tym samym zbiorze co lista i licznik
 * (`visibleTo`) i po WSZYSTKICH stronach, bo akcja obejmuje wszystkie.
 */
class OznaczWszystkieTylkoGdyJestCoOznaczycTest extends TestCase
{
    use RefreshDatabase;

    private const PRZYCISK = 'Oznacz wszystkie jako przeczytane';

    public function test_same_przeczytane_nie_pokazuja_przycisku(): void
    {
        $ala = $this->user('ala');
        $this->obserwujeAle($ala, $this->user('basia'))->forceFill(['read_at' => now()])->save();

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('zaczyna Cię obserwować.') // lista przeczytanych zostaje
            ->assertDontSee(self::PRZYCISK);
    }

    public function test_pusta_lista_nie_pokazuje_przycisku(): void
    {
        $this->actingAs($this->user('ala'))
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee(self::PRZYCISK);
    }

    public function test_mieszanka_pokazuje_oba_przyciski(): void
    {
        $ala = $this->user('ala');
        $this->obserwujeAle($ala, $this->user('basia'))->forceFill(['read_at' => now()])->save();
        $this->obserwujeAle($ala, $this->user('celina'));

        $html = $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->getContent();

        // Kontrola dodatnia: przy nieprzeczytanym przyciski są — nad listą
        // i pod nią (intencja #276).
        $this->assertSame(2, substr_count($html, 'action="'.route('notifications.read').'"'));
    }

    public function test_nieprzeczytane_na_drugiej_stronie_wlacza_przycisk_na_pierwszej(): void
    {
        $ala = $this->user('ala');
        $nieprzeczytane = $this->obserwujeAle($ala, $this->user('stara', ['display_name' => 'Stara Znajoma']));
        $nieprzeczytane->forceFill(['created_at' => now()->subDay()])->save();

        // 30 przeczytanych nowszych wypełnia całą pierwszą stronę.
        $basia = $this->user('basia');
        for ($i = 0; $i < 30; $i++) {
            Notification::query()->forceCreate([
                'user_id' => $ala->getKey(),
                'actor_id' => $basia->getKey(),
                'type' => Notification::TYPE_FOLLOW,
                'data' => ['username' => $basia->profile->username],
                'read_at' => now(),
                'created_at' => now(),
            ]);
        }

        // Kontrola samego układu: pierwsza strona to wyłącznie przeczytane,
        // a jedyne nieprzeczytane czeka na drugiej.
        $this->assertSame(31, Notification::query()->where('user_id', $ala->getKey())->count());
        $this->assertSame(1, $ala->fresh()->unreadNotificationsCount());

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Stara Znajoma')
            ->assertSee(self::PRZYCISK);
    }

    public function test_powiadomienie_ukryte_blokada_nie_wlacza_przycisku(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia');
        $this->obserwujeAle($ala, $this->user('celina'))->forceFill(['read_at' => now()])->save();
        $odBasi = $this->obserwujeAle($ala, $basia);

        app(BlockUser::class)->handle($ala, $basia);

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee(self::PRZYCISK);

        $this->assertNull($odBasi->fresh()->read_at);
    }

    public function test_nic_do_oznaczenia_daje_informacje_a_nie_sukces(): void
    {
        $ala = $this->user('ala');
        $this->obserwujeAle($ala, $this->user('basia'))->forceFill(['read_at' => now()])->save();

        // Np. druga karta zdążyła oznaczyć wszystko wcześniej.
        $this->actingAs($ala)
            ->from(route('notifications.index'))
            ->post(route('notifications.read'))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', 'Nie było nic do oznaczenia — wszystkie powiadomienia są już przeczytane.');
    }

    public function test_oznaczenie_nieprzeczytanych_potwierdza_sukces(): void
    {
        $ala = $this->user('ala');
        $powiadomienie = $this->obserwujeAle($ala, $this->user('basia'));

        $this->actingAs($ala)
            ->from(route('notifications.index'))
            ->post(route('notifications.read'))
            ->assertSessionHas('status', 'Wszystkie powiadomienia oznaczone jako przeczytane.');

        $this->assertNotNull($powiadomienie->fresh()->read_at);
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
