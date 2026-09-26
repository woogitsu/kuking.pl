<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Hide;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wymazanie konta zabiera prywatne decyzje tej osoby — przegląd #1781.
 *
 * Kont z Kuking się nie kasuje, tylko anonimizuje (D-022), więc `ON DELETE
 * CASCADE` z migracji nigdy tu nie zadziała: bez jawnego kasowania
 * w `EraseAccountData` ukrycia (`hides`, #1810) zostawały po wymazanym koncie
 * przy domyślnym `delete_scope = minimum`.
 *
 * To samo dotyczy reakcji „Smakowicie wygląda" (`post_reactions`, #1813).
 *
 * Kontrola ujemna: linia `Hide::query()->where('user_id', …)->delete()`
 * (albo `PostReaction::…`) zdjęta z `EraseAccountData` → odpowiedni test
 * oblewa na liczbie wierszy.
 */
class WymazanieKontaUsuwaPrywatneDecyzjeTest extends TestCase
{
    use RefreshDatabase;

    public function test_wymazanie_konta_z_zakresem_minimum_kasuje_jego_ukrycia_ale_nie_cudze(): void
    {
        $odchodzi = $this->user('odchodzi', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);
        $autorka = $this->user('autorka');
        $wpis = Post::factory()->create(['author_id' => $autorka->getKey(), 'published_at' => now()->subHour()]);
        $inna = $this->user('inna');

        Hide::ukryjDla($odchodzi, 'post_id', (string) $wpis->getKey());
        Hide::ukryjDla($odchodzi, 'hidden_user_id', (string) $autorka->getKey());
        // Cudza decyzja o tym koncie — należy do innej osoby i zostaje.
        Hide::ukryjDla($inna, 'hidden_user_id', (string) $odchodzi->getKey());
        $this->assertSame(2, Hide::query()->where('user_id', $odchodzi->getKey())->count(), 'Kontrola: ukrycia są w bazie przed wymazaniem.');

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi->fresh()));

        $this->assertSame(0, Hide::query()->where('user_id', $odchodzi->getKey())->count(), 'Po wymazaniu konta zostały jego ukrycia.');
        $this->assertSame(1, Hide::query()->where('user_id', $inna->getKey())->count(), 'Wymazanie zabrało cudze ukrycie.');
    }

    public function test_wymazanie_konta_z_zakresem_minimum_kasuje_jego_reakcje_ale_nie_cudze(): void
    {
        $odchodzi = $this->user('odchodzi', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);
        $autorka = $this->user('autorka');
        $wpisAutorki = Post::factory()->create(['author_id' => $autorka->getKey(), 'published_at' => now()->subHour()]);
        $wpisOdchodzacej = Post::factory()->create(['author_id' => $odchodzi->getKey(), 'published_at' => now()->subHour()]);

        PostReaction::query()->insert([
            ['post_id' => $wpisAutorki->getKey(), 'user_id' => $odchodzi->getKey(), 'created_at' => now()],
            // Cudza reakcja pod wpisem odchodzącej — to słowa autorki, zostają.
            ['post_id' => $wpisOdchodzacej->getKey(), 'user_id' => $autorka->getKey(), 'created_at' => now()],
        ]);
        $this->assertSame(1, PostReaction::query()->where('user_id', $odchodzi->getKey())->count(), 'Kontrola: reakcja jest w bazie przed wymazaniem.');

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi->fresh()));

        $this->assertSame(0, PostReaction::query()->where('user_id', $odchodzi->getKey())->count(), 'Po wymazaniu konta zostały jego reakcje.');
        $this->assertSame(1, PostReaction::query()->where('user_id', $autorka->getKey())->count(), 'Wymazanie zabrało cudzą reakcję.');
    }
}
