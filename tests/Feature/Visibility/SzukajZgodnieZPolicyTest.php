<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Search\SearchQuery;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wyszukiwarka odnajduje to, co widz może otworzyć — i nic więcej (issue #1320).
 *
 * Do tego issue `SearchQuery::recipes()` pytało zawsze o `publiclyVisible()`.
 * Obserwująca nie znajdowała po tytule przepisu „dla obserwujących", który
 * otwierała z profilu autora, a autor — własnego przepisu „tylko dla mnie".
 * Wyszukiwarka mówiła „nie ma", choć Policy mówiła „wolno".
 *
 * Każdy test sprawdza IDENTYFIKATORY wyników domeny, nie tylko tekst strony,
 * i każdy dodatni wynik ma obok kontrolę ujemną dla kogoś, kto go widzieć
 * nie może.
 */
class SzukajZgodnieZPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $obserwujaca;

    private User $obca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->obserwujaca = $this->user('obserwujaca');
        $this->obca = $this->user('obca');

        app(FollowUser::class)->handle($this->obserwujaca, $this->autor);
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function przepis(string $widocznosc, string $tytul, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create(array_merge([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'title' => $tytul,
            'slug' => Str::slug($tytul).'-'.Str::lower(Str::random(6)),
        ], $atrybuty));
    }

    /** @return list<string> */
    private function idWynikow(?User $widz, string $fraza = 'bigos'): array
    {
        return (new SearchQuery)->recipes($fraza, $widz)->pluck('id')->sort()->values()->all();
    }

    private function szukaj(?User $widz, string $fraza = 'bigos'): TestResponse
    {
        $adres = route('search').'?q='.urlencode($fraza).'&sekcja=przepisy';

        if ($widz === null) {
            Auth::logout();

            return $this->get($adres);
        }

        return $this->actingAs($widz)->get($adres);
    }

    public function test_obserwujaca_znajduje_przepis_dla_obserwujacych_a_obca_i_gosc_nie(): void
    {
        $przepis = $this->przepis('followers', 'Bigos dla swoich');

        $this->assertSame([$przepis->getKey()], $this->idWynikow($this->obserwujaca));
        $this->szukaj($this->obserwujaca)->assertOk()->assertSee('Bigos dla swoich');

        // Kontrola ujemna — to jest granica, której ta zmiana nie może przesunąć.
        $this->assertSame([], $this->idWynikow($this->obca));
        $this->assertSame([], $this->idWynikow(null));
        $this->szukaj($this->obca)->assertOk()->assertDontSee('Bigos dla swoich');
        $this->szukaj(null)->assertOk()->assertDontSee('Bigos dla swoich');
    }

    public function test_przepis_dla_obserwujacych_znika_po_cofnieciu_obserwowania(): void
    {
        $this->przepis('followers', 'Bigos dla swoich');

        app(UnfollowUser::class)->handle($this->obserwujaca, $this->autor);

        $this->assertSame([], $this->idWynikow($this->obserwujaca->fresh()));
    }

    public function test_autor_znajduje_wlasny_prywatny_przepis_a_nikt_inny_nie(): void
    {
        $przepis = $this->przepis('private', 'Sekretny bigos');

        $this->assertSame([$przepis->getKey()], $this->idWynikow($this->autor));

        $this->assertSame([], $this->idWynikow($this->obserwujaca));
        $this->assertSame([], $this->idWynikow($this->obca));
        $this->assertSame([], $this->idWynikow(null));
    }

    /**
     * `widoczneDla()` wpuszcza autorowi jego szkice (zeszyt ich potrzebuje).
     * Wyszukiwarka ich nie pokazuje — to byłaby osobna decyzja produktowa.
     */
    public function test_autor_nie_znajduje_wlasnego_szkicu(): void
    {
        $opublikowany = $this->przepis('public', 'Bigos gotowy');
        Recipe::factory()->draft()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
            'title' => 'Bigos niedokonczony',
            'slug' => 'bigos-niedokonczony',
        ]);

        $this->assertSame([$opublikowany->getKey()], $this->idWynikow($this->autor));
    }

    public function test_blokada_w_obie_strony_wycina_przepis_dla_obserwujacych(): void
    {
        $this->przepis('followers', 'Bigos dla swoich');

        app(BlockUser::class)->handle($this->obserwujaca, $this->autor);
        $this->assertSame([], $this->idWynikow($this->obserwujaca));

        $druga = $this->user('druga');
        app(FollowUser::class)->handle($druga, $this->autor);
        app(BlockUser::class)->handle($this->autor, $druga);
        $this->assertSame([], $this->idWynikow($druga));
    }

    public function test_zawieszony_autor_nie_wraca_do_wynikow_obserwujacej(): void
    {
        $this->przepis('followers', 'Bigos dla swoich');
        $this->autor->suspend();

        $this->assertSame([], $this->idWynikow($this->obserwujaca));
    }

    /**
     * Zbiór wyników = dokładnie te opublikowane przepisy, które
     * `RecipePolicy::view` pozwala otworzyć temu widzowi. Moderatora tu nie
     * ma celowo: Policy wpuszcza go pod adres, wyszukiwarka nie jest
     * narzędziem moderacji.
     */
    public function test_wyniki_pokrywaja_sie_z_policy_dla_kazdego_widza(): void
    {
        foreach (['public', 'followers', 'private'] as $widocznosc) {
            $this->przepis($widocznosc, 'Bigos '.$widocznosc);
        }

        $widzowie = ['autorka' => $this->autor, 'obserwująca' => $this->obserwujaca, 'obca' => $this->obca, 'gość' => null];

        foreach ($widzowie as $kto => $widz) {
            $wolno = Recipe::query()->published()->get()
                ->filter(fn (Recipe $r): bool => Gate::forUser($widz)->allows('view', $r))
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($wolno, $this->idWynikow($widz), 'Widz: '.$kto);
        }
    }

    public function test_karta_wyniku_nazywa_ograniczona_widocznosc(): void
    {
        $this->przepis('followers', 'Bigos dla swoich');
        $this->przepis('private', 'Sekretny bigos');
        $this->przepis('public', 'Zwykly bigos');

        $autor = $this->szukaj($this->autor)->assertOk();
        $autor->assertSeeInOrder(['Bigos dla swoich', 'Tylko dla obserwujących'], false);
        $autor->assertSeeInOrder(['Sekretny bigos', 'Tylko dla mnie'], false);

        // Kontrola ujemna: publiczne wyniki nie dostają plakietki, więc obca
        // osoba, która widzi wyłącznie publiczny przepis, nie widzi żadnej.
        $this->szukaj($this->obca)->assertOk()
            ->assertSee('Zwykly bigos')
            ->assertDontSee('Tylko dla obserwujących')
            ->assertDontSee('Tylko dla mnie');
    }
}
