<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zakładka „Ugotowane" na CUDZYM profilu nie zdradza tytułu przepisu,
 * którego widz nie ma prawa zobaczyć.
 *
 * CO BYŁO ZEPSUTE
 * `ProfileController::tylkoZWidocznychPrzepisow()` ma nad sobą komentarz
 * kończący się zdaniem „Wyciek przez tytuł to nadal wyciek" — czyli reguła
 * była tam UZNANA. Ale filtr, którego używała, sprawdzał WYŁĄCZNIE kolumnę
 * `visibility`. Nie sprawdzał statusu przepisu ani dostępności konta jego
 * autora. Skutek: karta wykonania pokazywała tytuł przepisu ukrytego przez
 * moderację albo przepisu autora zbanowanego, mimo że i adres wykonania,
 * i adres przepisu dawały temu samemu widzowi 403.
 *
 * DRUGI, SUBTELNIEJSZY BŁĄD W TYM SAMYM MIEJSCU
 * Filtr dostawał jako „właściciela" osobę, na której profilu stoimy —
 * czyli KUCHARZA. A `visibility: followers` dotyczy relacji z AUTOREM
 * PRZEPISU, nie z kucharzem. Kto obserwował kucharza, ale nie autora,
 * widział tytuł przepisu „tylko dla obserwujących" autora.
 *
 * DLACZEGO POPRAWKA USUWA FILTR, A NIE GO ROZBUDOWUJE
 * `Recipe::scopeWidoczneDla()` odpowiada na dokładnie to pytanie i jest
 * pokryty własną macierzą testów: liczy widoczność względem autora
 * przepisu, blokady w obie strony i status przepisu. Ręcznie pisany filtr
 * w kontrolerze był DRUGĄ implementacją tej samej reguły — czyli tym, co
 * w tym repozytorium pęka najczęściej. Zostaje jeden zakres plus granica
 * polityki (`dostepnyJakoAutor`), której zakres celowo nie zawiera.
 *
 * Znalezione przez agenta audytującego komentarze i „Ugotowałem",
 * z pomiarem; plik był poza jego zakresem, więc zgłosił go z patchem.
 */
class ProfilZakladkaUgotowaneNieZdradzaTytuluTest extends TestCase
{
    use RefreshDatabase;

    public function test_tytul_przepisu_ukrytego_przez_moderacje_nie_wychodzi(): void
    {
        [$obcy, $kucharz] = $this->scena();

        $odpowiedz = $this->actingAs($obcy)->get(route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane']));
        $odpowiedz->assertOk();

        $odpowiedz->assertDontSee('Przepis ukryty moderacja');
        $odpowiedz->assertSee('Przepis kontrolny');
    }

    public function test_tytul_przepisu_autora_zbanowanego_nie_wychodzi(): void
    {
        [$obcy] = $this->scena();

        $this->actingAs($obcy)->get(route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane']))
            ->assertOk()
            ->assertDontSee('Przepis zbanowanego')
            ->assertSee('Przepis kontrolny');
    }

    public function test_obserwowanie_kucharza_nie_odblokowuje_przepisu_dla_obserwujacych_autora(): void
    {
        // Drugi, subtelniejszy błąd: `followers` dotyczy relacji z AUTOREM
        // przepisu, a filtr pytał o relację z kucharzem.
        [$obcy, $kucharz] = $this->scena();

        $obcy->following()->attach($kucharz->getKey());

        $this->actingAs($obcy)->get(route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane']))
            ->assertOk()
            ->assertDontSee('Przepis dla obserwujacych')
            ->assertSee('Przepis kontrolny');
    }

    public function test_kucharz_widzi_na_wlasnym_profilu_wszystkie_swoje_wykonania(): void
    {
        // Asercja KONTROLNA całego pliku: poprawka nie może polegać na
        // ukryciu zakładki. Właściciel profilu widzi swoje wykonania nawet
        // wtedy, gdy cudzy przepis został w międzyczasie ukryty — notatka
        // i zdjęcie należą do niego.
        [, $kucharz] = $this->scena();

        $this->actingAs($kucharz)->get(route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane']))
            ->assertOk()
            ->assertSee('Przepis kontrolny')
            ->assertSee('Przepis ukryty moderacja');
    }

    /**
     * Kucharz z czterema wykonaniami: jednym kontrolnym i trzema, których
     * tytułu obcy widzieć nie ma prawa.
     *
     * @return array{0: User, 1: User}
     */
    private function scena(): array
    {
        $obcy = $this->user('obcy');
        $kucharz = $this->user('kucharz');
        $autor = $this->user('autor');
        $zbanowany = $this->user('zbanowany', ['status' => User::STATUS_BANNED]);

        $przepisy = [
            'Przepis kontrolny' => [$autor, 'public', 'published'],
            'Przepis ukryty moderacja' => [$autor, 'public', 'hidden'],
            'Przepis zbanowanego' => [$zbanowany, 'public', 'published'],
            'Przepis dla obserwujacych' => [$autor, 'followers', 'published'],
        ];

        foreach ($przepisy as $tytul => [$ktoAutor, $widocznosc, $status]) {
            $przepis = Recipe::factory()->create([
                'author_id' => $ktoAutor->getKey(),
                'visibility' => $widocznosc,
                'status' => $status,
                'title' => $tytul,
                'slug' => Str::slug($tytul).'-'.Str::lower(Str::random(6)),
            ]);

            CookedEvent::factory()->create([
                'user_id' => $kucharz->getKey(),
                'recipe_id' => $przepis->getKey(),
                'cooked_at' => now()->subDay(),
            ]);
        }

        return [$obcy, $kucharz];
    }
}
