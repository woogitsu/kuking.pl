<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1371: „Zobacz" przy `post.first` prowadzi do WSKAZANEGO wpisu,
 * a nie do ogólnej kolejki „Bez odpowiedzi" — ta ma limit 50 i gubi wpis
 * po pierwszej odpowiedzi. Gdy wpisu nie da się otworzyć, kolejka wraca
 * jako cel zastępczy ze zdaniem, co się stało.
 */
class PowiadomienieOPierwszymWpisieProwadziDoWpisuTest extends TestCase
{
    use RefreshDatabase;

    private const KOMUNIKAT = 'Tego wpisu nie da się już otworzyć';

    public function test_zobacz_prowadzi_do_wpisu_mimo_odpowiedzi_i_pelnej_kolejki(): void
    {
        $gospodarz = $this->gospodarz();
        $nowa = $this->user('nowa');
        $wpis = Post::factory()->create(['author_id' => $nowa->getKey(), 'body' => 'Mój pierwszy sernik']);

        // Wpis wypada z kolejki: ktoś już odpowiedział, a starszych czeka ponad 50.
        Comment::factory()->create(['post_id' => $wpis->getKey()]);
        Post::factory()->count(51)->create(['published_at' => now()->subDay()]);

        $powiadomienie = $this->alert($gospodarz, $nowa, ['post_id' => $wpis->getKey(), 'display_name' => 'Nowa']);

        $cel = route('posts.show', $wpis->getKey());
        $this->assertSame($cel, $powiadomienie->adresDocelowy());
        // Kontrola ujemna: to nie jest powrót do samej ogólnej kolejki.
        $this->assertNotSame(route('admin.unanswered'), $powiadomienie->adresDocelowy());

        $this->actingAs($gospodarz)
            ->post(route('notifications.open', $powiadomienie->getKey()))
            ->assertRedirect($cel)
            ->assertSessionMissing('status');

        $this->actingAs($gospodarz)->get($cel)->assertOk()->assertSee('Mój pierwszy sernik');
    }

    public function test_usuniety_ukryty_albo_brak_post_id_wraca_do_kolejki_ze_zdaniem(): void
    {
        $gospodarz = $this->gospodarz();
        $nowa = $this->user('nowa');

        $usuniety = Post::factory()->create(['author_id' => $nowa->getKey(), 'body' => 'Treść usuniętego wpisu']);
        $usuniety->delete();
        $ukryty = Post::factory()->create([
            'author_id' => $nowa->getKey(),
            'body' => 'Treść ukrytego wpisu',
            'status' => Post::STATUS_HIDDEN,
        ]);

        foreach ([
            'usunięty' => ['post_id' => $usuniety->getKey()],
            'ukryty' => ['post_id' => $ukryty->getKey()],
            'starszy bez post_id' => ['display_name' => 'Nowa'],
            'śmieci w post_id' => ['post_id' => 'nie-uuid'],
        ] as $przypadek => $dane) {
            $powiadomienie = $this->alert($gospodarz, $nowa, $dane);

            $this->assertSame(route('admin.unanswered'), $powiadomienie->adresDocelowy(), $przypadek);

            $this->actingAs($gospodarz)
                ->post(route('notifications.open', $powiadomienie->getKey()))
                ->assertRedirect(route('admin.unanswered'));

            $this->actingAs($gospodarz)
                ->get(route('admin.unanswered'))
                ->assertOk()
                ->assertSee(self::KOMUNIKAT)
                ->assertDontSee('Treść usuniętego wpisu')
                ->assertDontSee('Treść ukrytego wpisu');
        }
    }

    // -----------------------------------------------------------------

    /** @param  array<string, mixed>  $dane */
    private function alert(User $gospodarz, User $autor, array $dane): Notification
    {
        return Notification::create([
            'user_id' => $gospodarz->getKey(),
            'actor_id' => $autor->getKey(),
            'type' => Notification::TYPE_FIRST_POST,
            'data' => $dane,
        ])->refresh();
    }

    private function gospodarz(): User
    {
        $gospodarz = $this->user('gospodarz', ['role' => User::ROLE_MODERATOR]);

        // 2FA — inaczej panel `/admin/bez-odpowiedzi` odmawia wejścia (issue #12).
        $totp = app(TwoFactorAuthenticator::class);
        $gospodarz->beginTwoFactorSetup($totp->generateSecret());
        $gospodarz->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        return $gospodarz->refresh();
    }
}
