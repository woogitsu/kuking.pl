<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Przepis — pełna macierz: public / followers / private.
 */
class RecipeWidocznoscTest extends WidocznoscTestCase
{
    protected function widocznosci(): array
    {
        return ['public', 'followers', 'private'];
    }

    protected function utworz(string $widocznosc): Model
    {
        return Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'title' => 'Tajny zurek '.$widocznosc,
            'slug' => 'tajny-zurek-'.$widocznosc.'-'.Str::lower(Str::random(6)),
        ]);
    }

    protected function adres(Model $tresc): string
    {
        // `utworz()` tej klasy tworzy przepis; typ zawężamy jawnie (#1731).
        if (! $tresc instanceof Recipe) {
            self::fail('Ten test sprawdza przepis, a dostał '.$tresc::class.'.');
        }

        return route('recipes.show', $tresc->slug);
    }

    // -----------------------------------------------------------------
    // Status konta autora (audyt A5) — oś ORTOGONALNA do widoczności:
    // niezależnie od `visibility`, samo konto autora może odciąć dostęp.
    // -----------------------------------------------------------------

    public function test_ban_autora_ukrywa_przepis_publiczny_przed_obcymi(): void
    {
        $przepis = $this->utworz('public');
        $this->autor->ban();

        foreach (['obcy', 'obserwujacy', 'zablokowany'] as $ktoPole) {
            $this->actingAs($this->{$ktoPole})
                ->get($this->adres($przepis))
                ->assertStatus(403);
        }

        Auth::logout();
        $this->assertGuest();
        $this->get($this->adres($przepis))->assertStatus(403);
    }

    /**
     * Zbyt szeroka naprawa wyglądałaby dokładnie tak: ban autora ukrywa
     * przepis przed KAŻDYM — łącznie z samym autorem i moderatorem. Ten test
     * pada bez wyjątku dla właściciela i moderatora w Policy.
     *
     * Sprawdzamy `Gate`, nie pełne HTTP: zbanowane konto jest wylogowywane
     * przy KAŻDYM żądaniu przez `EnsureAccountIsActive` (issue #39) — to
     * osobna, wcześniejsza warstwa bezpieczeństwa i nie ma jej jak ominąć
     * przez `actingAs()`. Wyjątek w `RecipePolicy` broni się mimo to: to samo
     * sprawdzenie odpowiada za dostęp poza HTTP-em (np. z poziomu moderacji
     * albo komendy `artisan`), gdzie ta warstwa nie stoi na drodze.
     */
    public function test_ban_autora_nie_odcina_autora_ani_moderatora(): void
    {
        $przepis = $this->utworz('public');
        $this->autor->ban();

        $this->assertTrue(Gate::forUser($this->autor)->allows('view', $przepis));
        $this->assertTrue(Gate::forUser($this->moderator())->allows('view', $przepis));
    }

    /**
     * Zawieszenie to kara CZASOWA i tylko na publikowanie — przepis zostaje
     * czytelny dla wszystkich tak samo, jak zostaje czytelny profil
     * zawieszonej osoby (`UserPolicy::viewProfile`).
     */
    public function test_zawieszenie_autora_nie_ukrywa_przepisu_publicznego(): void
    {
        $przepis = $this->utworz('public');
        $this->autor->suspend();

        $this->actingAs($this->obcy)
            ->get($this->adres($przepis))
            ->assertOk();
    }
}
