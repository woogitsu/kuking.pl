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
 * TYLKO z tego jednego zeszytu. Brak parametru zostaje operacją globalną —
 * i to jest jedyna droga, którą oferuje strona przepisu, więc tam musi być
 * jawnie nazwana i potwierdzona (AGENTS.md §5).
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

    /**
     * ZMIENIONY ŚWIADOMIE PRZY UJEDNOLICANIU (D-225).
     *
     * W pierwotnej postaci #776/D-224/D-225 ta scena wymagała, żeby strona
     * przepisu NIE MIAŁA `<details class="confirm">` — pytanie „czy na
     * pewno" miało zniknąć na rzecz komunikatu PO akcji. Właściciel
     * rozstrzygnął to inaczej (D-229), łącząc obie prace zamiast wybierać
     * jedną: pytanie WRACA na tym jedynym ekranie o zasięgu globalnym,
     * bo `detach()` kasuje notatkę przy zapisie razem z nim, a „Zapisz
     * ponownie" po akcji tej notatki nie odzyskuje — to uzasadnia pytanie
     * przed czymś, co jest tylko CZĘŚCIOWO odwracalne.
     *
     * PRAWDZIWY ZARZUT Z #775 NIE ZNIKA — brzmiał „usuwa ze wszystkich
     * zeszytów BEZ UJAWNIENIA ZAKRESU". Scena pilnuje więc TERAZ obu rzeczy
     * naraz: pytania przed akcją (z jawnym ostrzeżeniem o notatkach) I zdania
     * po akcji, które nazywa zakres liczbą i nie obiecuje więcej, niż „Zapisz
     * ponownie" naprawdę oddaje.
     */
    public function test_strona_przepisu_pyta_przed_usunieciem_i_nazywa_zakres_po_akcji(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create();

        $a = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'A', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'B', 'visibility' => 'private']);
        $a->recipes()->attach($przepis->getKey());
        $b->recipes()->attach($przepis->getKey());

        $tresc = $this->actingAs($basia)->get($przepis->url())->assertOk()->getContent();

        // POTWIERDZENIE PRZED AKCJĄ, BEZ JavaScriptu (D-229): `<details>`
        // zwykłego HTML-a, a nie `onsubmit="return confirm(...)"`. Pytanie
        // nazywa zakres (WSZYSTKICH zeszytów) i ostrzega wprost, że notatki
        // przy zapisie znikną razem z nim.
        $this->assertStringContainsString('Usuń z zeszytu', $tresc);
        $this->assertStringContainsString('<details class="confirm">', $tresc);
        $this->assertStringContainsString('wszystkich', $tresc);
        $this->assertStringContainsString('Notatki przy nim znikną razem z zapisem', $tresc);

        $odpowiedz = $this->actingAs($basia)
            ->from($przepis->url())
            ->delete(route('collections.unsave', $przepis->slug));

        $odpowiedz->assertRedirect();

        // ZAKRES NAZWANY LICZBĄ, KTÓRA JEST PRAWDZIWA — dwa zeszyty, więc
        // zdanie mówi „z 2", a nie ogólnikowe „ze wszystkich". Komunikat NIE
        // obiecuje pełnej odwracalności: „Zapisz ponownie" przywraca zapis,
        // nie notatkę przy nim.
        $odpowiedz->assertSessionHas('status', fn (string $tekst) => str_contains($tekst, 'z 2 Twoich zeszytów')
            && str_contains($tekst, 'notatka przy nim już nie wróci'));

        $odpowiedz->assertSessionHas('status_powrot', fn (array $powrot) => $powrot['etykieta'] === 'Zapisz ponownie'
            && $powrot['akcja'] === route('collections.save', $przepis->slug));

        $this->assertFalse($a->recipes()->whereKey($przepis->getKey())->exists());
        $this->assertFalse($b->recipes()->whereKey($przepis->getKey())->exists());
    }

    /**
     * KONTROLA DODATNIA (D-229): gdyby ktoś kiedyś wrócił do zwykłego
     * formularza DELETE bez `<x-confirm-button>`, ta scena ma to złapać —
     * inaczej strażnik wyżej mierzyłby przypadkiem coś, co akurat przeszło.
     */
    public function test_strona_przepisu_nie_usuwa_zwyklym_delete_bez_potwierdzenia(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create();
        $basia->defaultCollection()->recipes()->attach($przepis->getKey());

        $tresc = $this->actingAs($basia)->get($przepis->url())->assertOk()->getContent();

        // Formularz DELETE istnieje dopiero WEWNĄTRZ `<details class="confirm">`
        // — poza nim nie ma żadnego zwykłego `<form method="POST">…DELETE`
        // wiszącego bezpośrednio pod przyciskiem „Usuń z zeszytu".
        $poza = preg_replace('/<details class="confirm">.*?<\/details>/s', '', $tresc);
        $this->assertStringNotContainsString(route('collections.unsave', $przepis->slug), (string) $poza);
    }

    /**
     * Zakres lokalny nazywa zeszyt po imieniu, a „Zapisz ponownie" wraca
     * DOKŁADNIE TAM, skąd wyjęto — nie do zeszytu domyślnego (D-225).
     */
    public function test_powrot_po_usunieciu_lokalnym_wraca_do_tego_samego_zeszytu(): void
    {
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $zeszyt = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $zeszyt->posts()->attach($wpis->getKey());

        $odpowiedz = $this->actingAs($basia)
            ->from(route('collections.show', $zeszyt))
            ->delete(route('collections.unsave-post', $wpis), ['collection_id' => $zeszyt->getKey()]);

        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHas('status', fn (string $tekst) => str_contains($tekst, 'Obiady'));
        $odpowiedz->assertSessionHas('status_powrot', fn (array $powrot) => ($powrot['pola']['collection_id'] ?? null) === (string) $zeszyt->getKey());

        $html = $this->actingAs($basia)->get(route('collections.show', $zeszyt))->assertOk()->getContent();

        $this->assertStringContainsString('Zapisz ponownie', $html);

        $this->actingAs($basia)
            ->post(route('collections.save-post', $wpis), ['collection_id' => $zeszyt->getKey()])
            ->assertRedirect();

        $this->assertSame(1, $zeszyt->posts()->whereKey($wpis->getKey())->count());
        $this->assertSame(0, $basia->defaultCollection()->posts()->whereKey($wpis->getKey())->count());
    }
}
