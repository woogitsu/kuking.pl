<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BRAMKA LICZONA NA AUTORZE PRZEPISU, A NIE NA AUTORZE WPISU.
 *
 * CO STAŁO W ZAPYTANIU
 * Archiwum profilu (`ProfileController::tylkoWidoczneWpisy()`) i ekran zeszytu
 * (`CollectionController::show()`) mają po dwa warunki:
 *
 *  - `zWidocznymPrzepisem($widz)` — schodzi do `Recipe::scopeWidoczneDla()`,
 *    czyli blokady, widoczność i publikacja PRZEPISU. Statusu konta ten zakres
 *    CELOWO nie zna, mówi o tym wprost komentarz przy
 *    `User::scopeDostepnyJakoAutor()`;
 *  - `dostepnyJakoAutor()` / filtr `visibility` — liczony na autorze WPISU.
 *
 * Wiersz `posts` ma jednak DWIE różne osoby: `posts.author_id` i
 * `posts.recipe_id → recipes.author_id`. Gdy autor przepisu zostaje zbanowany
 * albo oznaczony do usunięcia, jego przepis daje 403 pod własnym adresem
 * i znika z list przepisów — ale wpis wskazujący go od KOGO INNEGO przechodził
 * przez obie bramki naraz, bo każda z nich pytała o inną osobę. Karta
 * `x-post-card` rysuje z relacji `$post->recipe` tytuł, zdjęcie główne
 * i odnośnik, w którym slug niesie ten sam tytuł zapisany inaczej.
 *
 * ZASIĘG DZIŚ I DLACZEGO TEST MIMO TO STOI
 * Żadna trasa HTTP nie tworzy dziś takiego wiersza: jedyną drogą powstawania
 * `posts.recipe_id` jest `WpisWskazujacyPrzepis::dopisz()`, które ustawia
 * `author_id` NA AUTORZE PRZEPISU — więc obie osoby są tą samą osobą i stara
 * bramka przypadkiem wystarczała. `PublishPost::handle()` ma jednak parametr
 * `recipeId` gotowy od dawna, kolumna nie ma żadnego ograniczenia wiążącego
 * ją z autorem wpisu, a pierwszy ekran, który ten parametr poda („ugotowałem
 * z cudzego przepisu"), otworzy wyciek bez dotykania tych kontrolerów.
 * Ten test pilnuje ZAPYTANIA, a nie dzisiejszego zestawu tras.
 *
 * DLACZEGO KAŻDY PRZYPADEK MA KONTROLĘ DODATNIĄ
 * „Nie widać tytułu" przechodzi także wtedy, gdy ekran jest pusty — bo zmienił
 * się szablon albo zapytanie zwróciło zero z zupełnie innego powodu. W tej
 * samej odpowiedzi musi więc być widoczny bliźniaczy wpis wskazujący przepis
 * autora bez sankcji ORAZ zwykły wpis bez przepisu: ten drugi pilnuje, żeby
 * bramka nie skasowała archiwum, które nie ma z przepisami nic wspólnego.
 */
class WpisNieUjawniaPrzepisuZbanowanegoAutoraTest extends TestCase
{
    use RefreshDatabase;

    public function test_archiwum_profilu_nie_pokazuje_tytulu_przepisu_zbanowanego_autora(): void
    {
        ['gospodarz' => $gospodarz, 'czytelnik' => $czytelnik] = $this->scena();

        $this->actingAs($czytelnik)
            ->get(route('profile.show', ['username' => $gospodarz->profile->username]))
            ->assertOk()
            ->assertDontSee('Sernik zbanowanego')
            ->assertSee('Sernik kontrolny')
            ->assertSee('Zwykly wpis bez przepisu');
    }

    public function test_archiwum_profilu_tak_samo_dla_konta_oznaczonego_do_usuniecia(): void
    {
        ['gospodarz' => $gospodarz, 'czytelnik' => $czytelnik, 'zbanowany' => $zbanowany] = $this->scena();

        $zbanowany->status = User::STATUS_PENDING_DELETE;
        $zbanowany->save();

        $this->actingAs($czytelnik)
            ->get(route('profile.show', ['username' => $gospodarz->profile->username]))
            ->assertOk()
            ->assertDontSee('Sernik zbanowanego')
            ->assertSee('Sernik kontrolny');
    }

    public function test_gosc_bez_konta_tez_nie_dostaje_tytulu(): void
    {
        ['gospodarz' => $gospodarz] = $this->scena();

        $this->get(route('profile.show', ['username' => $gospodarz->profile->username]))
            ->assertOk()
            ->assertDontSee('Sernik zbanowanego')
            ->assertSee('Sernik kontrolny');
    }

    public function test_zeszyt_nie_pokazuje_tytulu_przepisu_zbanowanego_autora(): void
    {
        ['czytelnik' => $czytelnik, 'zeszyt' => $zeszyt] = $this->scena();

        $this->actingAs($czytelnik)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertDontSee('Sernik zbanowanego')
            ->assertSee('Sernik kontrolny')
            ->assertSee('Zwykly wpis bez przepisu');
    }

    public function test_zeszyt_tak_samo_dla_konta_oznaczonego_do_usuniecia(): void
    {
        ['czytelnik' => $czytelnik, 'zeszyt' => $zeszyt, 'zbanowany' => $zbanowany] = $this->scena();

        $zbanowany->status = User::STATUS_PENDING_DELETE;
        $zbanowany->save();

        $this->actingAs($czytelnik)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertDontSee('Sernik zbanowanego')
            ->assertSee('Sernik kontrolny');
    }

    /**
     * Gospodarz (konto bez sankcji) ma trzy wpisy: jeden wskazuje przepis
     * autora ZBANOWANEGO, drugi — bliźniaczy przepis autora bez sankcji,
     * trzeci nie wskazuje żadnego przepisu. Wszystkie trzy leżą też
     * w prywatnym zeszycie czytelnika.
     *
     * @return array{gospodarz: User, czytelnik: User, zbanowany: User, zeszyt: Collection}
     */
    private function scena(): array
    {
        $gospodarz = $this->user('gospodarz');
        $czytelnik = $this->user('czytelnik');
        $zbanowany = $this->user('zbanowany', ['status' => User::STATUS_BANNED]);
        $zdrowy = $this->user('zdrowy');

        $zly = Recipe::factory()->create([
            'author_id' => $zbanowany->getKey(),
            'visibility' => 'public',
            'title' => 'Sernik zbanowanego',
            'slug' => 'sernik-zbanowanego-'.Str::lower(Str::random(6)),
        ]);

        $dobry = Recipe::factory()->create([
            'author_id' => $zdrowy->getKey(),
            'visibility' => 'public',
            'title' => 'Sernik kontrolny',
            'slug' => 'sernik-kontrolny-'.Str::lower(Str::random(6)),
        ]);

        // Wpisy NALEŻĄ DO GOSPODARZA, a wskazują cudze przepisy — o to w tym
        // teście chodzi. `recipe_id` nie idzie przez `$fillable`, więc
        // ustawiamy je fabryką tak samo, jak robi to kolumna w bazie.
        $zlyWpis = Post::factory()->create([
            'author_id' => $gospodarz->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'body' => null,
            'recipe_id' => $zly->getKey(),
        ]);

        $dobryWpis = Post::factory()->create([
            'author_id' => $gospodarz->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'body' => null,
            'recipe_id' => $dobry->getKey(),
        ]);

        $bezPrzepisu = Post::factory()->create([
            'author_id' => $gospodarz->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'body' => 'Zwykly wpis bez przepisu',
        ]);

        $zeszyt = Collection::create([
            'owner_id' => $czytelnik->getKey(),
            'name' => 'Na święta',
            'visibility' => 'private',
        ]);

        $zeszyt->posts()->attach([
            $zlyWpis->getKey() => ['created_at' => now()->subMinutes(3)],
            $dobryWpis->getKey() => ['created_at' => now()->subMinutes(2)],
            $bezPrzepisu->getKey() => ['created_at' => now()->subMinute()],
        ]);

        return [
            'gospodarz' => $gospodarz,
            'czytelnik' => $czytelnik,
            'zbanowany' => $zbanowany,
            'zeszyt' => $zeszyt,
        ];
    }
}
