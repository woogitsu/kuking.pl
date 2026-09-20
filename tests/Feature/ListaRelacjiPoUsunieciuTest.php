<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListaRelacjiPoUsunieciuTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuniecie_ostatniej_karty_nie_udaje_pustej_listy(): void
    {
        $owner = $this->user('owner748');
        $people = [];
        for ($i = 0; $i < 21; $i++) {
            $people[] = $person = $this->user('person748_'.$i);
            app(FollowUser::class)->handle($owner, $person);
        }
        $url = route('social.following', ['username' => 'owner748', 'page' => 2]);
        $page = $this->actingAs($owner)->get($url)->assertOk();
        $this->assertCount(1, $page->viewData('people'));
        $last = $page->viewData('people')->first();
        $this->from($url)->delete(route('social.unfollow', $last->profile->username))->assertRedirect($url);
        $this->assertSame(20, $owner->following()->count());
        $response = $this->followingRedirects()->get($url)->assertOk();
        $response->assertDontSee('Jeszcze nikogo nie obserwuje');
        $this->assertCount(20, $response->viewData('people'));
    }

    public function test_obie_listy_odrozniaja_pusta_strone_od_pustej_calosci(): void
    {
        $owner = $this->user('owner748');
        $person = $this->user('person748');
        foreach (['social.followers', 'social.following'] as $route) {
            $this->get(route($route, 'owner748'))->assertOk()->assertViewHas('people', fn ($people) => $people->total() === 0);
        }
        app(FollowUser::class)->handle($owner, $person);
        app(FollowUser::class)->handle($person, $owner);
        foreach (['social.followers', 'social.following'] as $route) {
            $response = $this->followingRedirects()->get(route($route, ['username' => 'owner748', 'page' => 999]))->assertOk();
            $this->assertCount(1, $response->viewData('people'));
            $this->assertSame($person->id, $response->viewData('people')->first()->id);
        }
    }
}
