<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issue #983 — zmiana między wyborem źródła Startu a paginacją nie zostawia
 * pustego ekranu.
 *
 * `isEmptyFor()` / `maTresci()` i `paginate()` to osobne zapytania; przy Read
 * Committed każde widzi inny zatwierdzony stan. Test wstrzymuje żądanie
 * DOKŁADNIE między nimi: po N-tym `select exists(... from "posts" ...)`
 * (1. = `FollowingFeed::isEmptyFor()`, 2. = `TagFeed::maTresci()`) wykonuje
 * zmianę, którą w produkcji zatwierdziłaby druga sesja. W teście idzie tym
 * samym połączeniem — dla kontrolera to jest to samo: następne zapytanie
 * widzi już nowy stan.
 *
 * Kontrola dodatnia: z dawnym `match ($zrodlo)` w `home()` (bez
 * `pierwszaStronaZrodla()`) każdy test z wyścigiem dostaje pusty Start.
 */
class StartNieZostajePustyPoZmianieZrodlaTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, string $tresc, ?Tag $tag = null): Post
    {
        $post = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => $tresc]);
        if ($tag !== null) {
            $post->tags()->attach($tag->getKey(), ['position' => 0]);
        }

        return $post;
    }

    private function tag(): Tag
    {
        return Tag::create(['slug' => 'zupy', 'name' => 'Zupy', 'normalized_name' => 'zupy']);
    }

    private function obserwuj(User $kto, User $kogo): void
    {
        DB::table('follows')->insert(['follower_id' => $kto->id, 'followed_id' => $kogo->id, 'created_at' => now()]);
    }

    /** Wykonuje `$zmiana` zaraz po `$ktore`-tym sprawdzeniu istnienia wpisów. */
    private function startZeZmianaPo(User $widz, int $ktore, Closure $zmiana): TestResponse
    {
        $licznik = 0;
        DB::listen(function ($zapytanie) use (&$licznik, $ktore, $zmiana): void {
            if (str_starts_with($zapytanie->sql, 'select exists(select * from "posts"') && ++$licznik === $ktore) {
                $zmiana();
            }
        });

        return $this->actingAs($widz)->get(route('home'))->assertOk();
    }

    /** @return list<string> */
    private function tresci(TestResponse $odpowiedz): array
    {
        return $odpowiedz->viewData('posts')->getCollection()->pluck('body')->all();
    }

    public function test_cofniete_obserwowanie_przechodzi_do_tagow(): void
    {
        $widz = $this->user('widz');
        $znajoma = $this->user('znajoma');
        $obcy = $this->user('obcy');
        $zupy = $this->tag();
        $this->wpis($znajoma, 'Od znajomej');
        $this->wpis($obcy, 'Z tagu', $zupy);
        $this->obserwuj($widz, $znajoma);
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $odpowiedz = $this->startZeZmianaPo($widz, 1, fn () => DB::table('follows')->delete());

        $odpowiedz->assertViewHas('zrodloFeedu', 'tagi')->assertViewHas('showingDiscover', false);
        $this->assertSame(['Z tagu'], $this->tresci($odpowiedz));
    }

    public function test_blokada_bez_tagow_przechodzi_do_odkrywania(): void
    {
        $widz = $this->user('widz');
        $znajoma = $this->user('znajoma');
        $obcy = $this->user('obcy');
        $this->wpis($znajoma, 'Od znajomej');
        $this->wpis($obcy, 'Świeży wpis');
        $this->obserwuj($widz, $znajoma);

        // Blokada usuwa relację obserwowania — tak jak robi to aplikacja.
        $odpowiedz = $this->startZeZmianaPo($widz, 1, function () use ($widz, $znajoma): void {
            DB::table('blocks')->insert(['blocker_id' => $widz->id, 'blocked_id' => $znajoma->id, 'created_at' => now()]);
            DB::table('follows')->delete();
        });

        $odpowiedz->assertViewHas('zrodloFeedu', 'odkrywanie')->assertViewHas('showingDiscover', true);
        $this->assertSame(['Świeży wpis'], $this->tresci($odpowiedz));
    }

    public function test_wlasny_wpis_nie_blokuje_przejscia_dalej(): void
    {
        $widz = $this->user('widz');
        $znajoma = $this->user('znajoma');
        $this->wpis($widz, 'Mój własny');
        $this->wpis($znajoma, 'Od znajomej');
        $this->obserwuj($widz, $znajoma);

        $odpowiedz = $this->startZeZmianaPo($widz, 1, fn () => DB::table('follows')->delete());

        // Bez obserwowanych zostałby feed z samym własnym wpisem — dokładnie
        // to, czego `isEmptyFor()` celowo nie liczy jako treści.
        $odpowiedz->assertViewHas('zrodloFeedu', 'odkrywanie');
        $this->assertContains('Od znajomej', $this->tresci($odpowiedz));
    }

    public function test_znikniecie_tresci_tagow_po_wyborze_przechodzi_do_odkrywania(): void
    {
        $widz = $this->user('widz');
        $obcy = $this->user('obcy');
        $zupy = $this->tag();
        $this->wpis($obcy, 'Z tagu', $zupy);
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $odpowiedz = $this->startZeZmianaPo($widz, 2, fn () => DB::table('tag_follows')->delete());

        $odpowiedz->assertViewHas('zrodloFeedu', 'odkrywanie')->assertViewHas('showingDiscover', true);
        $this->assertSame(['Z tagu'], $this->tresci($odpowiedz));
    }

    public function test_bez_zmiany_wygrywa_pierwsze_niepuste_zrodlo(): void
    {
        $widz = $this->user('widz');
        $znajoma = $this->user('znajoma');
        $obcy = $this->user('obcy');
        $zupy = $this->tag();
        $this->wpis($widz, 'Mój własny');
        $this->wpis($obcy, 'Z tagu', $zupy);
        $this->wpis($znajoma, 'Od znajomej');
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        // Bez obserwowanych ludzi własny wpis nie wystarcza — wygrywają tagi.
        $this->actingAs($widz)->get(route('home'))->assertViewHas('zrodloFeedu', 'tagi');

        $this->obserwuj($widz, $znajoma);
        $odpowiedz = $this->actingAs($widz)->get(route('home'))->assertViewHas('zrodloFeedu', 'obserwowani');
        $this->assertSame(['Od znajomej', 'Mój własny'], $this->tresci($odpowiedz));
    }
}
