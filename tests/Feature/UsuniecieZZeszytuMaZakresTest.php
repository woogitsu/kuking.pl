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
     * PRZEPISANY ŚWIADOMIE PRZY SKŁADANIU DWÓCH DRÓG (#775, D-242).
     *
     * W postaci z gałęzi `flota/scal-zeszyt-775` ta scena wymagała na stronie
     * przepisu `<details class="confirm">` — pytania „czy na pewno ze
     * wszystkich zeszytów". Argumentem było, że wyjęcie jest tylko CZĘŚCIOWO
     * odwracalne: `detach()` kasuje wiersz pivotu razem z `note`, a „Zapisz
     * ponownie" notatki nie odzyskuje.
     *
     * TEN ARGUMENT PRZESTAŁ BYĆ PRAWDZIWY. Rdzeń z `naprawa/775-zakres-usuwania-z-zeszytu`
     * dokłada `restore()`, które przywraca zdjęte wiersze RAZEM z notatką
     * i pierwotną datą zapisu. Wyjęcie jest więc odwracalne w całości, a wtedy
     * wraca reguła D-224: pytanie przed każdą odwracalną czynnością uczy
     * odklikiwania i psuje wagę pytań przy rzeczach naprawdę nieodwracalnych.
     *
     * PRAWDZIWY ZARZUT Z #775 NIE ZNIKA — brzmiał „usuwa ze wszystkich
     * zeszytów BEZ UJAWNIENIA ZAKRESU", nie „usuwa bez pytania". Scena pilnuje
     * więc obu rzeczy naraz: zakres stoi NAPISANY NAD PRZYCISKIEM, zanim ktoś
     * kliknie, a zdanie po akcji nazywa go liczbą i daje drogę powrotu, która
     * naprawdę wraca — z notatkami.
     */
    public function test_strona_przepisu_ujawnia_zakres_przed_akcja_i_nazywa_go_po_niej(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create();

        $a = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'A', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'B', 'visibility' => 'private']);
        $a->recipes()->attach($przepis->getKey(), ['note' => 'bez cukru', 'created_at' => now()->subDay()]);
        $b->recipes()->attach($przepis->getKey(), ['note' => 'mniej soli', 'created_at' => now()->subHours(3)]);

        $tresc = $this->actingAs($basia)->get($przepis->url())->assertOk()->getContent();

        // ZAKRES PRZED AKCJĄ, bez JavaScriptu — zwykły akapit przy przycisku,
        // związany z nim przez `aria-describedby`, więc czytnik ekranu czyta
        // zdanie razem z przyciskiem, a nie osobno gdzieś wyżej.
        $this->assertStringContainsString('Usuń z zeszytu', $tresc);
        $this->assertStringContainsString('ze wszystkich Twoich zeszytów', $tresc);
        $this->assertStringContainsString('aria-describedby="zakres-wyjecia-', $tresc);
        $this->assertStringContainsString('Przywróć do zeszytu', $tresc);

        $odpowiedz = $this->actingAs($basia)
            ->from($przepis->url())
            ->delete(route('collections.unsave', $przepis->slug));

        $odpowiedz->assertRedirect();

        // ZAKRES NAZWANY LICZBĄ, KTÓRA JEST PRAWDZIWA — dwa zeszyty, więc
        // zdanie mówi „było ich 2", a nie samo ogólnikowe „ze wszystkich".
        $odpowiedz->assertSessionHas('status', fn (string $tekst) => str_contains($tekst, 'ze wszystkich Twoich zeszytów')
            && str_contains($tekst, '2')
            && str_contains($tekst, 'przywrócić'));

        $odpowiedz->assertSessionHas('status_powrot', fn (array $powrot) => $powrot['etykieta'] === 'Przywróć do zeszytu'
            && $powrot['akcja'] === route('collections.save', $przepis->slug));

        $this->assertFalse($a->recipes()->whereKey($przepis->getKey())->exists());
        $this->assertFalse($b->recipes()->whereKey($przepis->getKey())->exists());

        // I DROGA POWROTU NAPRAWDĘ WRACA — do obu zeszytów, z notatkami.
        // To jest ta różnica, dla której właściciel wybrał rdzeń z #1110:
        // bez tego notatki znikałyby bezpowrotnie, a pytanie przed akcją
        // byłoby jedyną obroną.
        $this->actingAs($basia)->post(route('collections.save', $przepis->slug))->assertRedirect();

        $this->assertSame('bez cukru', $a->recipes()->whereKey($przepis->getKey())->first()?->pivot->note);
        $this->assertSame('mniej soli', $b->recipes()->whereKey($przepis->getKey())->first()?->pivot->note);
    }

    /**
     * KONTROLA DODATNIA (D-242): gdyby ktoś kiedyś wrócił do formularza bez
     * ujawnionego zakresu, ta scena ma to złapać — inaczej strażnik wyżej
     * mierzyłby przypadkiem coś, co akurat przeszło.
     *
     * Adres `collections.unsave` NIE nadaje się do szukania po samym stringu
     * (`/przepisy/{slug}/zapisz` obsługuje i zapis, i wyjęcie — różni je
     * wyłącznie metoda HTTP), więc scena liczy formularze DELETE po DOM-ie:
     * ile stoi w całym dokumencie i ile z nich NIESIE ZAKRES. Zakres niesie
     * albo ukryty `collection_id` (jeden zeszyt — wyjmujemy dokładnie z niego),
     * albo akapit ostrzegawczy związany `aria-describedby` (kilka zeszytów).
     * Liczby muszą się zgadzać.
     */
    public function test_kazdy_formularz_wyjecia_na_stronie_przepisu_niesie_zakres(): void
    {
        $basia = $this->user('basia');

        foreach ([1, 3] as $ileZeszytow) {
            $przepis = Recipe::factory()->create();

            for ($i = 0; $i < $ileZeszytow; $i++) {
                $zeszyt = Collection::create([
                    'owner_id' => $basia->getKey(),
                    'name' => "Zeszyt {$i} dla {$przepis->slug}",
                    'visibility' => 'private',
                ]);
                $zeszyt->recipes()->attach($przepis->getKey(), ['note' => null, 'created_at' => now()]);
            }

            $tresc = $this->actingAs($basia)->get($przepis->url())->assertOk()->getContent();

            $dokument = new \DOMDocument;
            $poprzednie = libxml_use_internal_errors(true);
            $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$tresc);
            libxml_clear_errors();
            libxml_use_internal_errors($poprzednie);

            $xpath = new \DOMXPath($dokument);
            $adres = route('collections.unsave', $przepis->slug);

            $wyrazenie = sprintf(
                '//form[@action="%s"][.//input[@name="_method"][translate(@value, "delete", "DELETE")="DELETE"]]',
                $adres,
            );

            $wszystkie = $xpath->query($wyrazenie);
            $zZakresem = $xpath->query($wyrazenie.'[.//input[@name="collection_id"] or .//button[@aria-describedby]]');

            $this->assertGreaterThan(0, $wszystkie->length, 'Strona przepisu nie ma żadnego formularza wyjęcia.');
            $this->assertSame(
                $wszystkie->length,
                $zZakresem->length,
                "Przy {$ileZeszytow} zeszytach na stronie przepisu stoi formularz wyjęcia BEZ ujawnionego zakresu — to jest dokładnie #775.",
            );
        }
    }

    /**
     * Zakres lokalny nazywa zeszyt po imieniu, a droga powrotu wraca DOKŁADNIE
     * TAM, skąd wyjęto — nie do zeszytu domyślnego — i to razem z notatką.
     */
    public function test_powrot_po_usunieciu_lokalnym_wraca_do_tego_samego_zeszytu(): void
    {
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $zeszyt = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $zeszyt->posts()->attach($wpis->getKey(), ['note' => 'dla Ani bez orzechów', 'created_at' => now()->subWeek()]);

        $odpowiedz = $this->actingAs($basia)
            ->from(route('collections.show', $zeszyt))
            ->delete(route('collections.unsave-post', $wpis), ['collection_id' => $zeszyt->getKey()]);

        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHas('status', fn (string $tekst) => str_contains($tekst, 'Obiady'));
        $odpowiedz->assertSessionHas('status_powrot', fn (array $powrot) => $powrot['etykieta'] === 'Przywróć do zeszytu'
            && $powrot['akcja'] === route('collections.save-post', $wpis));

        $html = $this->actingAs($basia)->get(route('collections.show', $zeszyt))->assertOk()->getContent();

        $this->assertStringContainsString('Przywróć do zeszytu', $html);

        // Przycisk powrotu wysyła SAM ADRES ZAPISU, bez `collection_id` —
        // zeszyt i notatkę zna zapamiętane wyjęcie, nie formularz. Gdyby to
        // było zwykłe „zapisz ponownie", wpis wylądowałby w zeszycie DOMYŚLNYM
        // z pustą notatką i dzisiejszą datą.
        $this->actingAs($basia)
            ->post(route('collections.save-post', $wpis))
            ->assertRedirect();

        $this->assertSame(1, $zeszyt->posts()->whereKey($wpis->getKey())->count());
        $this->assertSame(0, $basia->defaultCollection()->posts()->whereKey($wpis->getKey())->count());
        $this->assertSame('dla Ani bez orzechów', $zeszyt->posts()->whereKey($wpis->getKey())->first()?->pivot->note);
    }
}
