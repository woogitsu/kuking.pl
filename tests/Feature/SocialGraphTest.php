<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Graf społeczny i reguła nadrzędna: BLOKADA WYGRYWA ZE WSZYSTKIM.
 */
class SocialGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_mozna_obserwowac_i_przestac_obserwowac(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $this->actingAs($basia)->post(route('social.follow', 'marek'))->assertRedirect();
        $this->assertTrue($basia->fresh()->isFollowing($marek));

        $this->actingAs($basia)->delete(route('social.unfollow', 'marek'))->assertRedirect();
        $this->assertFalse($basia->fresh()->isFollowing($marek));
    }

    public function test_zablokowany_uzytkownik_nie_moze_obserwowac(): void
    {
        $basia = $this->user('basia');
        $spam = $this->user('spam');

        app(BlockUser::class)->handle($basia, $spam);

        $this->expectException(RuntimeException::class);

        app(FollowUser::class)->handle($spam, $basia);
    }

    public function test_blokada_kasuje_obserwowanie_w_obie_strony(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        app(FollowUser::class)->handle($basia, $marek);
        app(FollowUser::class)->handle($marek, $basia);

        app(BlockUser::class)->handle($basia, $marek);

        // Gdyby follow został, zablokowana osoba nadal widziałaby treści
        // w swoim feedzie — czyli blokada nie działałaby wcale.
        $this->assertFalse($basia->fresh()->isFollowing($marek));
        $this->assertFalse($marek->fresh()->isFollowing($basia));
    }

    public function test_nie_mozna_obserwowac_samego_siebie(): void
    {
        $basia = $this->user('basia');

        $this->expectException(RuntimeException::class);

        app(FollowUser::class)->handle($basia, $basia);
    }

    public function test_blokada_dziala_w_obie_strony_przy_sprawdzaniu_relacji(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        app(BlockUser::class)->handle($basia, $marek);

        $this->assertTrue($basia->fresh()->hasBlockRelationWith($marek->fresh()));
        $this->assertTrue($marek->fresh()->hasBlockRelationWith($basia->fresh()));
    }
}
