<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Wpis — pełna macierz: public / followers / private.
 */
class PostWidocznoscTest extends WidocznoscTestCase
{
    protected function widocznosci(): array
    {
        return ['public', 'followers', 'private'];
    }

    protected function utworz(string $widocznosc): Model
    {
        return Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'body' => 'Tajny rosol '.$widocznosc,
        ]);
    }

    protected function adres(Model $tresc): string
    {
        return route('posts.show', $tresc);
    }

    // -----------------------------------------------------------------
    // Status konta autora (audyt A5) — oś ORTOGONALNA do widoczności:
    // niezależnie od `visibility`, samo konto autora może odciąć dostęp.
    // -----------------------------------------------------------------

    public function test_ban_autora_ukrywa_wpis_publiczny_przed_obcymi(): void
    {
        $wpis = $this->utworz('public');
        $this->autor->ban();

        foreach (['obcy', 'obserwujacy', 'zablokowany'] as $ktoPole) {
            $this->actingAs($this->{$ktoPole})
                ->get($this->adres($wpis))
                ->assertStatus(403);
        }

        Auth::logout();
        $this->assertGuest();
        $this->get($this->adres($wpis))->assertStatus(403);
    }

    /**
     * Zbyt szeroka naprawa wyglądałaby dokładnie tak: ban autora ukrywa
     * wpis przed KAŻDYM — łącznie z samym autorem i moderatorem. Ten test
     * pada bez wyjątku dla właściciela i moderatora w Policy.
     *
     * Sprawdzamy `Gate`, nie pełne HTTP: zbanowane konto jest wylogowywane
     * przy KAŻDYM żądaniu przez `EnsureAccountIsActive` (issue #39) — to
     * osobna, wcześniejsza warstwa bezpieczeństwa i nie ma jej jak ominąć
     * przez `actingAs()`. Wyjątek w `PostPolicy` broni się mimo to: to samo
     * sprawdzenie odpowiada za dostęp poza HTTP-em (np. z poziomu moderacji
     * albo komendy `artisan`), gdzie ta warstwa nie stoi na drodze.
     */
    public function test_ban_autora_nie_odcina_autora_ani_moderatora(): void
    {
        $wpis = $this->utworz('public');
        $this->autor->ban();

        $this->assertTrue(Gate::forUser($this->autor)->allows('view', $wpis));
        $this->assertTrue(Gate::forUser($this->moderator())->allows('view', $wpis));
    }

    /**
     * Zawieszenie to kara CZASOWA i tylko na publikowanie — treść zostaje
     * czytelna dla wszystkich tak samo, jak zostaje czytelny profil
     * zawieszonej osoby (`UserPolicy::viewProfile`). Inaczej „poprawka"
     * cofnęłaby dostęp głębiej niż robi to reszta produktu.
     */
    public function test_zawieszenie_autora_nie_ukrywa_wpisu_publicznego(): void
    {
        $wpis = $this->utworz('public');
        $this->autor->suspend();

        $this->actingAs($this->obcy)
            ->get($this->adres($wpis))
            ->assertOk();
    }
}
