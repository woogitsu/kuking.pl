<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Usuń z zeszytu” nie ma prawa kasować zapisu ze WSZYSTKICH zeszytów po
 * cichu (issue #775).
 *
 * CO SIĘ DZIAŁO
 * Przepis (i wpis) zapisany w kilku zeszytach dawał się usunąć jednym
 * przyciskiem na stronie przepisu — `SaveRecipeToCollection::remove()`
 * i `SavePostToCollection::remove()` chodziły po WSZYSTKICH zeszytach
 * właściciela i odpinały wiersz w każdym, bez wyboru i bez potwierdzenia
 * zakresu. Notatka i data zapisu w drugim zeszycie ginęły razem z nim.
 *
 * CO JEST TERAZ
 * `collections.unsave` i `collections.unsave-post` przyjmują opcjonalny
 * `collection_id` (ten sam parametr i ta sama walidacja własności co przy
 * zapisie, `CollectionController::selectedCollection()`). Podanie go usuwa
 * TYLKO z tego jednego zeszytu. Brak parametru zostaje operacją globalną.
 *
 * Strona przepisu SAMA WYBIERA między tymi dwiema drogami (`6ab03a5d`,
 * bloker #775): przy jednym zeszycie wysyła `collection_id` i nie ma czego
 * ogłaszać, przy kilku zostaje przy zakresie globalnym, ale nazywa go NAD
 * przyciskiem. Sceny niżej mierzą oba te przypadki osobno — wcześniej
 * żądały jednego kształtu dla obu i z tego powodu jedna z nich stała się
 * fałszywa po złożeniu z #775 (patrz komentarz przy tamtej scenie).
 */
final class UsuniecieZZeszytuMaZakresTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuniecie_z_jednego_zeszytu_zostawia_drugi_z_notatka_i_data(): void
    {
        $osoba = $this->user('basia');
        $przepis = Recipe::factory()->create();

        $a = Collection::create(['owner_id' => $osoba->getKey(), 'name' => 'Zeszyt A', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $osoba->getKey(), 'name' => 'Zeszyt B', 'visibility' => 'private']);

        $a->recipes()->attach($przepis->getKey(), ['note' => null, 'created_at' => now()->subDay()]);
        $b->recipes()->attach($przepis->getKey(), ['note' => 'Na Wigilię', 'created_at' => now()->subHours(2)]);

        $this->actingAs($osoba)
            ->delete(route('collections.unsave', $przepis->slug), ['collection_id' => $a->getKey()])
            ->assertRedirect();

        $this->assertFalse($a->recipes()->whereKey($przepis->getKey())->exists());

        $bPivot = $b->recipes()->whereKey($przepis->getKey())->first()->pivot;
        $this->assertNotNull($bPivot, 'Zeszyt B stracił zapis, mimo że usuwano tylko z zeszytu A.');
        $this->assertSame('Na Wigilię', $bPivot->note);
    }

    public function test_bez_wskazania_zeszytu_usuwa_ze_wszystkich_wlasnych(): void
    {
        $osoba = $this->user('basia');
        $przepis = Recipe::factory()->create();

        $a = Collection::create(['owner_id' => $osoba->getKey(), 'name' => 'Zeszyt A', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $osoba->getKey(), 'name' => 'Zeszyt B', 'visibility' => 'private']);
        $a->recipes()->attach($przepis->getKey());
        $b->recipes()->attach($przepis->getKey());

        $this->actingAs($osoba)
            ->delete(route('collections.unsave', $przepis->slug))
            ->assertRedirect();

        $this->assertFalse($a->recipes()->whereKey($przepis->getKey())->exists());
        $this->assertFalse($b->recipes()->whereKey($przepis->getKey())->exists());
    }

    public function test_cudzy_collection_id_jest_odrzucany_i_nic_nie_usuwa(): void
    {
        $basia = $this->user('basia');
        $ktos = $this->user('ktos');
        $przepis = Recipe::factory()->create();

        $mojZeszyt = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Mój', 'visibility' => 'private']);
        $cudzyZeszyt = Collection::create(['owner_id' => $ktos->getKey(), 'name' => 'Cudzy', 'visibility' => 'private']);
        $mojZeszyt->recipes()->attach($przepis->getKey());

        $this->actingAs($basia)
            ->delete(route('collections.unsave', $przepis->slug), ['collection_id' => $cudzyZeszyt->getKey()])
            ->assertSessionHasErrors('collection_id');

        $this->assertTrue($mojZeszyt->recipes()->whereKey($przepis->getKey())->exists());
    }

    public function test_wpis_ma_ten_sam_zakres_co_przepis(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $a = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Zeszyt A', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Zeszyt B', 'visibility' => 'private']);
        $a->posts()->attach($wpis->getKey(), ['note' => null, 'created_at' => now()]);
        $b->posts()->attach($wpis->getKey(), ['note' => 'na inny raz', 'created_at' => now()]);

        $this->actingAs($basia)
            ->delete(route('collections.unsave-post', $wpis), ['collection_id' => $a->getKey()])
            ->assertRedirect();

        $this->assertFalse($a->posts()->whereKey($wpis->getKey())->exists());
        $this->assertTrue($b->posts()->whereKey($wpis->getKey())->exists());
    }

    public function test_strona_przepisu_nazywa_zakres_usuniecia_przed_klikniecieem(): void
    {
        // TA SCENA ZMIENIŁA PRZESŁANKĘ, NIE GWARANCJĘ (22 września 2026).
        //
        // Żądała wcześniej `<details class="confirm">` i słowa „wszystkich"
        // ZAWSZE, bo stało w niej założenie: „strona przepisu nie wie,
        // w którym zeszycie stoi człowiek, więc oferuje wyłącznie operację
        // globalną, a globalną trzeba potwierdzić". Pierwsza połowa tego
        // zdania przestała być prawdziwa przy `6ab03a5d` (bloker #775):
        // ekran liczy teraz zeszyty z tym przepisem i przy JEDNYM wysyła
        // `collection_id`, czyli operacja nie jest już globalna.
        //
        // Gwarancja zostaje ta sama i jest tu dalej pilnowana: CZŁOWIEK ZNA
        // ZAKRES ZANIM KLIKNIE. Zmienia się tylko to, czym ten zakres jest
        // niesiony — zawężeniem, gdy da się zawęzić, a zdaniem nad
        // przyciskiem, gdy zawęzić się nie da.
        //
        // Potwierdzenia drugim kliknięciem już nie ma i to jest wybór,
        // nie przeoczenie: wyjęcie stało się odwracalne co do notatki
        // (`SaveRecipeToCollection::restore()`), więc tańszy dla grupy 50+
        // jest przycisk „Przywróć do zeszytu" PO akcji niż pytanie „czy na
        // pewno" przed każdą — w tym przed tą zawężoną, która niczego poza
        // jednym zeszytem nie rusza.
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create();

        // JEDEN ZESZYT — zakres niesie `collection_id`, nie zdanie.
        $basia->defaultCollection()->recipes()->attach($przepis->getKey());

        $tresc = $this->actingAs($basia)->get($przepis->url())->assertOk()->getContent();

        $this->assertStringContainsString(
            'Masz ten przepis w zeszycie',
            $tresc,
            'Ekran przepisu nie mówi, z którego zeszytu wyjmie, choć zeszyt jest dokładnie jeden.',
        );
        $this->assertStringContainsString(
            'name="collection_id"',
            $tresc,
            'Ekran przepisu nie zawęża wyjęcia do jedynego zeszytu — zdjąłby przepis ze wszystkich (issue #775).',
        );

        // KILKA ZESZYTÓW — zawęzić się nie da, więc zakres MUSI paść zdaniem,
        // i to zdaniem związanym z przyciskiem, a nie schowanym gdzieś wyżej.
        $drugi = Collection::create([
            'owner_id' => $basia->getKey(),
            'name' => 'Na święta',
            'visibility' => 'private',
        ]);
        $drugi->recipes()->attach($przepis->getKey(), ['note' => 'Babcine proporcje']);

        $tresc = $this->actingAs($basia)->get($przepis->url())->assertOk()->getContent();

        $this->assertStringContainsString(
            'wszystkich',
            $tresc,
            'Ekran przepisu nie mówi, że przycisk zdejmie przepis ze wszystkich zeszytów.',
        );
        $this->assertStringContainsString(
            'aria-describedby="zakres-wyjecia-',
            $tresc,
            'Zdanie o zakresie nie jest związane z przyciskiem — czytnik ekranu przeczyta je osobno albo wcale.',
        );
    }
}
