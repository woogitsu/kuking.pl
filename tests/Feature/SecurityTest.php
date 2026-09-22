<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testy, które istnieją po to, żeby jedna nieuważna zmiana nie otworzyła
 * dziury. Każdy z nich odpowiada konkretnemu zapisowi z AGENTS.md.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_nie_da_sie_usunac_cudzego_wpisu(): void
    {
        $autor = $this->user('autor');
        $obcy = $this->user('obcy');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($obcy)->delete(route('posts.destroy', $post))->assertForbidden();
        $this->assertDatabaseHas('posts', ['id' => $post->getKey(), 'deleted_at' => null]);
    }

    public function test_nie_da_sie_edytowac_cudzego_przepisu(): void
    {
        $autor = $this->user('autor');
        $obcy = $this->user('obcy');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($obcy)->get(route('recipes.edit', $recipe->slug))->assertForbidden();
    }

    public function test_status_i_rola_nie_daja_sie_ustawic_z_zadania(): void
    {
        $basia = $this->user('basia');

        // Klasyczny mass assignment: ktoś dokłada pola do formularza profilu.
        //
        // `assertSessionHasNoErrors` jest tu KONTROLĄ, nie ozdobą: bez niej
        // test przechodziłby także wtedy, gdyby żądanie odpadło na walidacji
        // — a wtedy `role` i `status` zostają nietknięte z zupełnie innego
        // powodu niż ten, o który ten test pyta.
        $this->actingAs($basia)->put(route('settings.profile'), [
            'display_name' => 'Basia',
            'username' => 'basia',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_BANNED,
        ])->assertSessionHasNoErrors();

        $basia = $basia->fresh();

        $this->assertSame(User::ROLE_USER, $basia->role);
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);

        // A TO JEST WŁAŚCIWY POMIAR REGUŁY Z AGENTS.md §7.
        //
        // Asercje wyżej mierzą jeden kontroler, który tabeli `users` w ogóle
        // nie dotyka (`ProfileSettingsController::update()` zapisuje wyłącznie
        // `$profile`). Zmierzone wprost: po dopisaniu `status` i `role` do
        // `User::$fillable` ten test przechodził bez mrugnięcia — pilnował
        // więc kontrolera, a nie reguły, którą cytuje.
        //
        // Regułą jest to, że masowe przypisanie NIGDY nie ustawia stanu konta,
        // niezależnie od tego, który kontroler je woła. Eloquent bez trybu
        // strict po prostu odrzuca pola poza `$fillable`, więc widać to na
        // wartościach — dopisanie ich do listy zapala tę asercję.
        $basia->update([
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_BANNED,
        ]);

        $this->assertSame(
            User::ROLE_USER,
            $basia->fresh()->role,
            'Rolę da się ustawić masowym przypisaniem — `role` wróciło do $fillable (AGENTS.md §7).',
        );
        $this->assertSame(
            User::STATUS_ACTIVE,
            $basia->fresh()->status,
            'Status konta da się ustawić masowym przypisaniem — zmiana stanu konta ma być jawną, nazwaną metodą.',
        );
    }

    public function test_tresc_uzytkownika_jest_escapowana(): void
    {
        $basia = $this->user('basia');

        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => '<script>alert("xss")</script>',
        ]);

        $this->get(route('posts.show', $post))
            ->assertOk()
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_zablokowana_osoba_nie_widzi_tresci_blokujacego(): void
    {
        $basia = $this->user('basia');
        $spam = $this->user('spam');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        app(BlockUser::class)->handle($basia, $spam);

        $this->actingAs($spam->fresh())->get(route('posts.show', $post))->assertForbidden();
        $this->actingAs($spam->fresh())->get(route('profile.show', 'basia'))->assertForbidden();
    }

    public function test_strony_zalogowanego_wymagaja_logowania(): void
    {
        foreach ([
            route('home'),
            route('add'),
            route('posts.create'),
            route('recipes.create'),
            route('collections.index'),
            route('notifications.index'),
            route('settings.profile'),
            route('settings.data'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_komentarz_da_sie_edytowac_tylko_krotko_po_publikacji(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $swiezy = Comment::factory()->create([
            'author_id' => $basia->getKey(),
            'post_id' => $post->getKey(),
        ]);

        $stary = Comment::factory()->create([
            'author_id' => $basia->getKey(),
            'post_id' => $post->getKey(),
            'created_at' => now()->subHour(),
        ]);

        $this->assertTrue($basia->can('update', $swiezy));
        $this->assertFalse($basia->can('update', $stary));
    }

    public function test_naglowki_bezpieczenstwa_sa_ustawione(): void
    {
        $this->get('/')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_zbyt_wiele_prob_logowania_jest_blokowane(): void
    {
        $this->user('basia');

        // Limit z config/kuking.php ('login' => '5,1') pilnuje trasy,
        // a RateLimiter w kontrolerze dodatkowo pilnuje pary login+IP.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'basia', 'password' => 'zle-haslo-'.$i])
                ->assertSessionHasErrors('login');
        }

        // Szósta próba nie dochodzi już do sprawdzania hasła — nawet
        // poprawne hasło nie loguje, dopóki limit nie wygaśnie.
        $this->post('/login', ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertStatus(429);

        $this->assertGuest();
    }

    public function test_komunikat_logowania_nie_zdradza_czy_konto_istnieje(): void
    {
        $this->user('basia');

        $oczekiwany = 'Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie. '
            .'Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.';

        // Ten sam komunikat dla konta istniejącego i nieistniejącego.
        // Różne komunikaty pozwoliłyby sprawdzać, kto ma tu konto
        // (enumeracja kont).
        $this->post('/login', ['login' => 'basia', 'password' => 'zle'])
            ->assertSessionHasErrors(['login' => $oczekiwany]);

        $this->flushSession();

        $this->post('/login', ['login' => 'nie_ma_takiego', 'password' => 'zle'])
            ->assertSessionHasErrors(['login' => $oczekiwany]);
    }
}
