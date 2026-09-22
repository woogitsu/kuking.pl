<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jedno wysłanie formularza „Opublikuj" to JEDEN przepis (audyt podwójnego
 * wysłania z 12 września 2026, mechanizm z D-027 i
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`).
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * Dwa razy `POST /dodaj/przepis` z identycznym ciałem dawało DWA wiersze
 * w `recipes`. Drugi dostawał własny slug, więc człowiek lądował pod adresem
 * `…/rosol-babci-zofii-2` — przy przepisie, o którego istnieniu nie wiedział.
 * Razem z nim powstawał drugi komplet składników i kroków, druga wersja
 * w `recipe_versions`, drugi wpis `recipe.published` w dzienniku audytowym
 * i drugi wpis w strumieniu obserwujących.
 *
 * CZEGO TEN PLIK NIE DOWODZI (pułapka 6 z `docs/PULAPKI_TESTOW.md`)
 * Zachowania przy DWÓCH POŁĄCZENIACH. `RefreshDatabase` trzyma wszystko
 * w jednej, niezatwierdzonej transakcji, więc dwa żądania wysłane tu PO
 * KOLEI dzielą jedno połączenie i jedną transakcję. To wystarcza, żeby
 * pokazać usterkę, która była w kodzie (drugie wysłanie zakładało drugi
 * przepis), i żeby oblać się po jej cofnięciu — ale nie jest pomiarem
 * przeplotu. Prawdziwy przeplot mierzy grupa `dwa-polaczenia`
 * (`./scripts/testy-dwa-polaczenia.sh`).
 */
class IdempotencjaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private function kluczZFormularza(string $html): ?string
    {
        return preg_match('/name="klucz_wyslania" value="([^"]+)"/', $html, $trafienia) === 1
            ? $trafienia[1]
            : null;
    }

    private function kluczZFormularzaPrzepisu(User $autor): ?string
    {
        return $this->kluczZFormularza(
            (string) $this->actingAs($autor)->get(route('recipes.create'))->getContent(),
        );
    }

    /** @return array<string, mixed> */
    private function tresc(?string $klucz, string $tytul = 'Rosół babci Zofii'): array
    {
        return [
            'title' => $tytul,
            'visibility' => 'public',
            'skladniki_tekst' => "kura\nmarchew\npietruszka",
            'przygotowanie_tekst' => "Zagotuj wodę.\n\nWłóż kurę i warzywa.",
            'klucz_wyslania' => $klucz,
        ];
    }

    public function test_dwa_klikniecia_opublikuj_daja_jeden_przepis(): void
    {
        $autor = $this->user('kucharkazwolnymlaczem');
        $tresc = $this->tresc($this->kluczZFormularzaPrzepisu($autor));

        $pierwsze = $this->actingAs($autor)->post(route('recipes.store'), $tresc);
        $drugie = $this->actingAs($autor)->post(route('recipes.store'), $tresc);

        $drugie->assertSessionHasNoErrors();

        $this->assertSame(1, Recipe::query()->count(), 'Podwójne kliknięcie założyło dwa przepisy.');
        $this->assertSame(
            $pierwsze->headers->get('Location'),
            $drugie->headers->get('Location'),
            'Drugie kliknięcie odesłało człowieka pod inny adres niż pierwsze — czyli pod przepis, o którym nie wie.',
        );

        // Wszystko, co wisi na przepisie, też ma być pojedyncze. Bez tych
        // czterech asercji test przeszedłby także wtedy, gdyby drugie
        // wysłanie zostawiło po sobie wersję, wpis w strumieniu albo ślad
        // w dzienniku audytowym.
        $this->assertSame(1, DB::table('recipe_versions')->count(), 'Powstały dwie wersje przepisu.');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'recipe.published')->count());
        $this->assertSame(1, Post::query()->count(), 'W strumieniu obserwujących stanęły dwa wpisy z tym samym przepisem.');
        $this->assertSame(3, DB::table('recipe_ingredients')->count(), 'Składniki zapisały się dwa razy.');

        $drugie->assertSessionHas(
            'status',
            'Ten przepis już zapisaliśmy — to jest on. Drugie kliknięcie nie założyło drugiego przepisu.',
        );
    }

    public function test_drugi_przepis_z_nowego_formularza_zapisuje_sie(): void
    {
        // KONTROLA DODATNIA dla testu wyżej: ochrona dotyczy JEDNEGO
        // wysłania, nie autora i nie tytułu. Kto pisze dwa przepisy pod
        // rząd, ma dostać dwa.
        $autor = $this->user('piszacadwa');

        $this->actingAs($autor)
            ->post(route('recipes.store'), $this->tresc($this->kluczZFormularzaPrzepisu($autor), 'Rosół babci Zofii'))
            ->assertSessionHasNoErrors();

        $this->actingAs($autor)
            ->post(route('recipes.store'), $this->tresc($this->kluczZFormularzaPrzepisu($autor), 'Pierogi ruskie'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Recipe::query()->count(), 'Drugi, prawdziwy przepis nie zapisał się.');
    }

    public function test_ten_sam_przepis_wyslany_ponownie_z_nowego_formularza_zapisuje_sie(): void
    {
        // Ten sam tytuł i ta sama treść z DRUGIEGO, osobno otwartego
        // formularza to świadome powtórzenie — na przykład poprawiona wersja
        // wpisana od nowa. Klucz jest inny, więc przechodzi.
        $autor = $this->user('powtarzajaca');

        $this->actingAs($autor)
            ->post(route('recipes.store'), $this->tresc($this->kluczZFormularzaPrzepisu($autor)))
            ->assertSessionHasNoErrors();

        $this->actingAs($autor)
            ->post(route('recipes.store'), $this->tresc($this->kluczZFormularzaPrzepisu($autor)))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Recipe::query()->count());
    }

    public function test_klucz_z_cudzego_formularza_nie_blokuje_wlasnego_przepisu(): void
    {
        // UUID w żądaniu nie jest autoryzacją (AGENTS.md §7). Indeks jest na
        // parze (autor, klucz), więc cudzy klucz nie ma czego odbić.
        $pierwsza = $this->user('pierwszaautorka');
        $druga = $this->user('drugaautorka');

        $klucz = $this->kluczZFormularzaPrzepisu($pierwsza);

        $this->actingAs($pierwsza)
            ->post(route('recipes.store'), $this->tresc($klucz, 'Rosół pierwszej osoby'))
            ->assertSessionHasNoErrors();

        $przepisPierwszej = Recipe::query()->firstOrFail();

        $obce = $this->actingAs($druga)->post(route('recipes.store'), $this->tresc($klucz, 'Rosół drugiej osoby'));

        $obce->assertSessionHasNoErrors();

        $this->assertSame(2, Recipe::query()->count(), 'Cudzy klucz zablokował własny przepis.');
        $this->assertNotSame(
            route('recipes.show', $przepisPierwszej->slug),
            $obce->headers->get('Location'),
            'Cudzy klucz odesłał kogoś obcego pod przepis innej osoby.',
        );
        $this->assertSame($pierwsza->getKey(), $przepisPierwszej->fresh()->author_id);
    }

    public function test_wyslanie_bez_klucza_dalej_zapisuje_przepis(): void
    {
        // Zawodzenie OTWARTE (ADR §4.3): brak klucza znaczy „zapisz
        // normalnie", nigdy „odmawiam".
        $autor = $this->user('bezukrytegopola');

        $tresc = $this->tresc(null);
        unset($tresc['klucz_wyslania']);

        $this->actingAs($autor)->post(route('recipes.store'), $tresc)->assertSessionHasNoErrors();

        $this->assertSame(1, Recipe::query()->count());
        $this->assertNull(Recipe::query()->firstOrFail()->klucz_wyslania);
    }

    public function test_klucz_przezywa_blad_walidacji_formularza_przepisu(): void
    {
        // Klucza nie zużywa wysłanie, które przepisu nie założyło — inaczej
        // po jednym błędzie walidacji poprawiony przepis nie miałby jak
        // przejść (ADR §8.4, awaria, dla której istnieje wyłącznik).
        $autor = $this->user('poprawiajaca');

        $klucz = $this->kluczZFormularzaPrzepisu($autor);

        $this->actingAs($autor)
            ->post(route('recipes.store'), $this->tresc($klucz, 'xx'))
            ->assertSessionHasErrors('title');

        $this->assertSame(0, Recipe::query()->count());

        $poBledzie = $this->actingAs($autor)->get(route('recipes.create'));

        $this->assertSame(
            $klucz,
            $this->kluczZFormularza((string) $poBledzie->getContent()),
            'Po nieudanej walidacji formularz dostał NOWY klucz — ochrona znika po pierwszym błędzie.',
        );

        $this->actingAs($autor)
            ->post(route('recipes.store'), $this->tresc($klucz))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Recipe::query()->count(), 'Poprawiony przepis nie zapisał się.');
    }

    public function test_edycja_przepisu_nie_kasuje_klucza_wyslania(): void
    {
        // Klucz jest tożsamością ZAŁOŻENIA przepisu. Gdyby `recipes.update`
        // wpisywało tu `null` (a nie przysyła żadnej wartości), pierwsze
        // zapisanie szczegółów zdejmowałoby ochronę po cichu.
        $autor = $this->user('dopisujaca');
        $klucz = $this->kluczZFormularzaPrzepisu($autor);

        $this->actingAs($autor)->post(route('recipes.store'), $this->tresc($klucz))->assertSessionHasNoErrors();

        $przepis = Recipe::query()->firstOrFail();
        $this->assertSame($klucz, $przepis->klucz_wyslania);

        $this->actingAs($autor)->put(route('recipes.update', $przepis->slug), [
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'summary' => 'Dopisany opis.',
            'steps' => [['instruction' => 'Zagotuj wodę.']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            $klucz,
            $przepis->fresh()->klucz_wyslania,
            'Edycja przepisu skasowała klucz wysłania — ochrona znikła przy pierwszym dopisaniu szczegółów.',
        );
    }

    public function test_baza_odbija_drugi_przepis_z_tym_samym_kluczem_z_pominieciem_kontrolera(): void
    {
        // GWARANCJI NIE DAJE `exists()` W PHP, TYLKO INDEKS W BAZIE (D-079).
        // Ten test omija kontroler i akcję domenową — zostaje samo
        // ograniczenie.
        $autor = $this->user('wprostdobazy');
        $klucz = (string) Str::uuid7();

        $wiersz = [
            'author_id' => $autor->getKey(),
            'klucz_wyslania' => $klucz,
            'title' => 'Rosół wstawiony wprost do bazy',
            'visibility' => 'public',
            'status' => Recipe::STATUS_DRAFT,
            'source_type' => Recipe::SOURCE_OWN,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('recipes')->insert(['id' => (string) Str::uuid7(), 'slug' => 'rosol-wprost-1'] + $wiersz);

        $this->expectException(QueryException::class);

        DB::table('recipes')->insert(['id' => (string) Str::uuid7(), 'slug' => 'rosol-wprost-2'] + $wiersz);
    }

    public function test_baza_przyjmuje_wiele_przepisow_tego_samego_autora_bez_klucza(): void
    {
        // Indeks jest CZĘŚCIOWY — wiersze bez klucza (seeder, fabryka,
        // przepisy sprzed migracji) zostają poza nim. Bez tego testu
        // `NOT NULL` albo pełny UNIQUE przeszedłby niezauważony.
        $autor = $this->user('autorwielu');

        foreach ([1, 2, 3] as $numer) {
            DB::table('recipes')->insert([
                'id' => (string) Str::uuid7(),
                'author_id' => $autor->getKey(),
                'klucz_wyslania' => null,
                'title' => 'Przepis '.$numer,
                'slug' => 'przepis-bez-klucza-'.$numer,
                'visibility' => 'public',
                'status' => Recipe::STATUS_DRAFT,
                'source_type' => Recipe::SOURCE_OWN,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(3, Recipe::query()->count());
    }
}
