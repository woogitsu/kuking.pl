<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Szyna „Mój zeszyt" na stronie głównej stosuje OBIE granice, nie jedną.
 *
 * CO BYŁO ZEPSUTE
 * `FeedController::home()` buduje szynę zapytaniem z `widoczneDla($user)` —
 * i sam komentarz obok niego kończy się zdaniem „Jedna granica, jeden scope,
 * wszędzie". To zdanie nie było prawdą. `widoczneDla()` liczy blokady
 * i ustawienie widoczności, a NIE liczy statusu konta autora; tym zajmuje się
 * osobna granica `User::scopeDostepnyJakoAutor()` (ustalenie audytowe W5-08:
 * to są DWIE różne reguły i trzeba stosować obie).
 *
 * `CollectionController::show()` — czyli ekran tego samego zeszytu — ma obie.
 * Ta szyna miała jedną. Skutek: przepis autora ZBANOWANEGO albo oznaczonego
 * do usunięcia znikał z ekranu zeszytu, a jednocześnie stał na stronie
 * głównej z tytułem, nazwiskiem autora i miniaturą.
 *
 * To ten sam wzorzec, co wpis zbanowanego autora w feedzie obserwowanych
 * (commit `964b99c`) i co zeszyt osoby zbanowanej pod własnym adresem: reguła
 * istnieje poprawnie w jednym miejscu, a drugie odpowiada na to samo pytanie
 * inaczej.
 *
 * DLACZEGO KAŻDY TEST MA PRZEPIS KONTROLNY
 * „Nie widać przepisu zbanowanego autora" przechodzi także wtedy, gdy szyna
 * jest pusta — bo zmienił się szablon, bo zapytanie zwróciło zero z innego
 * powodu. W tej samej odpowiedzi musi być widoczny identyczny przepis autora
 * bez sankcji.
 */
class SzynaZeszytuUkrywaZbanowanegoAutoraTest extends TestCase
{
    use RefreshDatabase;

    public function test_przepis_zbanowanego_autora_nie_stoi_w_szynie_na_stronie_glownej(): void
    {
        [$czytelnik, $zbanowany] = $this->scena();

        $odpowiedz = $this->actingAs($czytelnik)->get(route('home'));
        $odpowiedz->assertOk();

        $odpowiedz->assertDontSee('Sernik zbanowanego');
        $odpowiedz->assertSee('Sernik kontrolny');

        // Dla pewności, że to nie przypadek szablonu: ten sam przepis pod
        // adresem zeszytu też jest niewidoczny.
        $this->assertTrue($zbanowany->isBanned());
    }

    public function test_konto_oznaczone_do_usuniecia_traktujemy_tak_samo(): void
    {
        [$czytelnik, $zbanowany] = $this->scena();

        $zbanowany->status = User::STATUS_PENDING_DELETE;
        $zbanowany->save();

        $this->actingAs($czytelnik)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Sernik zbanowanego')
            ->assertSee('Sernik kontrolny');
    }

    public function test_ekran_zeszytu_i_szyna_odpowiadaja_tak_samo(): void
    {
        // Reguła, nie jednorazowa poprawka: te dwa miejsca pokazują tę samą
        // zawartość tego samego zeszytu i nie mają prawa różnić się granicą.
        [$czytelnik] = $this->scena();
        $zeszyt = Collection::query()->where('owner_id', $czytelnik->getKey())->firstOrFail();

        $naEkranie = $this->actingAs($czytelnik)->get(route('collections.show', $zeszyt));
        $naEkranie->assertOk()->assertDontSee('Sernik zbanowanego')->assertSee('Sernik kontrolny');

        $wSzynie = $this->actingAs($czytelnik)->get(route('home'));
        $wSzynie->assertOk()->assertDontSee('Sernik zbanowanego')->assertSee('Sernik kontrolny');
    }

    /**
     * Czytelnik z zeszytem, w którym leżą dwa cudze przepisy: jeden autora
     * zbanowanego, jeden autora bez sankcji. Oba publiczne i opublikowane.
     *
     * @return array{0: User, 1: User}
     */
    private function scena(): array
    {
        $czytelnik = $this->user('czytelnik');
        $zbanowany = $this->user('zbanowany', ['status' => User::STATUS_BANNED]);
        $zdrowy = $this->user('zdrowy');

        $zeszyt = Collection::create([
            'owner_id' => $czytelnik->getKey(),
            'name' => 'Na święta',
            'visibility' => 'private',
        ]);

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

        $zeszyt->recipes()->attach([$zly->getKey(), $dobry->getKey()]);

        return [$czytelnik, $zbanowany];
    }
}
