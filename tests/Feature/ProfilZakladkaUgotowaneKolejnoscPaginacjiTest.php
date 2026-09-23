<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stabilna kolejność i rozstrzyganie remisów w zakładce profilu „Ugotowane" (issue #735).
 *
 * Zakładka profilu `?zakladka=ugotowane` paginuje wykonania po 12.
 * Wcześniej brakowało jawnego `ORDER BY`, co powodowało, że baza oddawała rekordy
 * w przypadkowej kolejności fizycznej, a przy remisach czasu gotowania ten sam rekord
 * mógł pojawić się na dwóch stronach lub zostać pominięty.
 *
 * Poprawny kontrakt: `cooked_at DESC, id DESC` (spójnie z Recipe::cookedEvents).
 */
class ProfilZakladkaUgotowaneKolejnoscPaginacjiTest extends TestCase
{
    use RefreshDatabase;

    public function test_zakladka_ugotowane_sortuje_chronologicznie_i_rozstrzyga_remisy_w_paginacji(): void
    {
        $kucharz = $this->user('kucharz');
        $autor = $this->user('autor');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $bazaCzasu = now()->startOfDay();

        // 25 wykonań z celowo niechronologiczną kolejnością wstawiania i grupami remisów
        $czasy = [
            $bazaCzasu->copy()->subDays(5),
            $bazaCzasu->copy()->subDays(5),
            $bazaCzasu->copy()->subDays(5),
            $bazaCzasu->copy()->subDays(10),
            $bazaCzasu->copy()->subDays(10),
            $bazaCzasu->copy()->subDays(1),   // wstawione w środku, ale nowsze
            $bazaCzasu->copy()->subDays(1),
            $bazaCzasu->copy()->subDays(1),
            $bazaCzasu->copy()->subDays(1),
            $bazaCzasu->copy()->subDays(20),  // starsze
            $bazaCzasu->copy()->subDays(5),   // kolejny remis do grupy subDays(5)
            $bazaCzasu->copy()->subDays(1),   // kolejny remis do grupy subDays(1)
            $bazaCzasu->copy()->subDays(15),
            $bazaCzasu->copy()->subDays(15),
            $bazaCzasu->copy()->subDays(2),
            $bazaCzasu->copy()->subDays(2),
            $bazaCzasu->copy()->subDays(2),
            $bazaCzasu->copy()->subDays(2),
            $bazaCzasu->copy()->subDays(5),
            $bazaCzasu->copy()->subDays(30),
            $bazaCzasu->copy()->subDays(3),
            $bazaCzasu->copy()->subDays(3),
            $bazaCzasu->copy()->subDays(3),
            $bazaCzasu->copy()->subDays(0),   // najświeższe
            $bazaCzasu->copy()->subDays(25),
        ];

        /** @var list<CookedEvent> $utworzone */
        $utworzone = [];
        foreach ($czasy as $czas) {
            $utworzone[] = CookedEvent::factory()->create([
                'user_id' => $kucharz->getKey(),
                'recipe_id' => $recipe->getKey(),
                'cooked_at' => $czas,
            ]);
        }

        // Oczekiwana kolejność: cooked_at DESC, id DESC
        $oczekiwaneId = CookedEvent::query()
            ->where('user_id', $kucharz->getKey())
            ->latest('cooked_at')
            ->latest('id')
            ->pluck('id')
            ->all();

        $this->assertCount(25, $oczekiwaneId);

        // Pobierz stronę 1 (12 rekordów)
        $odpowiedz1 = $this->actingAs($kucharz)
            ->get(route('profile.show', ['username' => $kucharz->profile->username, 'zakladka' => 'ugotowane', 'page' => 1]))
            ->assertOk();

        /** @var LengthAwarePaginator $paginator1 */
        $paginator1 = $odpowiedz1->viewData('cookedEvents');
        $idStrona1 = $paginator1->getCollection()->pluck('id')->all();
        $this->assertCount(12, $idStrona1);

        // Pobierz stronę 2 (12 rekordów)
        $odpowiedz2 = $this->actingAs($kucharz)
            ->get(route('profile.show', ['username' => $kucharz->profile->username, 'zakladka' => 'ugotowane', 'page' => 2]))
            ->assertOk();

        /** @var LengthAwarePaginator $paginator2 */
        $paginator2 = $odpowiedz2->viewData('cookedEvents');
        $idStrona2 = $paginator2->getCollection()->pluck('id')->all();
        $this->assertCount(12, $idStrona2);

        // Pobierz stronę 3 (1 rekord)
        $odpowiedz3 = $this->actingAs($kucharz)
            ->get(route('profile.show', ['username' => $kucharz->profile->username, 'zakladka' => 'ugotowane', 'page' => 3]))
            ->assertOk();

        /** @var LengthAwarePaginator $paginator3 */
        $paginator3 = $odpowiedz3->viewData('cookedEvents');
        $idStrona3 = $paginator3->getCollection()->pluck('id')->all();
        $this->assertCount(1, $idStrona3);

        $wszystkiePobraneId = array_merge($idStrona1, $idStrona2, $idStrona3);

        // Brak duplikatów między stronami
        $this->assertCount(25, array_unique($wszystkiePobraneId));

        // Dokładna zgodność kolejności z kontraktem cooked_at DESC, id DESC
        $this->assertSame($oczekiwaneId, $wszystkiePobraneId);
    }

    public function test_zapytanie_zakladki_ugotowane_ma_jawne_klucze_sortowania(): void
    {
        $kucharz = $this->user('kucharz2');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $recipe->getKey(),
        ]);

        $zapytania = [];
        DB::listen(function ($query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });

        $this->actingAs($kucharz)
            ->get(route('profile.show', ['username' => $kucharz->profile->username, 'zakladka' => 'ugotowane']))
            ->assertOk();

        $zapytanieUgotowanych = collect($zapytania)->first(
            fn (string $sql) => str_contains($sql, 'cooked_events') && ! str_contains($sql, 'count('),
        );

        $this->assertNotNull($zapytanieUgotowanych, 'Nie znaleziono zapytania o listę cooked_events');
        $sqlMaly = strtolower($zapytanieUgotowanych);

        $this->assertStringContainsString('order by', $sqlMaly);
        $this->assertMatchesRegularExpression('/order by.*["\']?cooked_at["\']?\s+desc/i', $zapytanieUgotowanych);
        $this->assertMatchesRegularExpression('/order by.*["\']?id["\']?\s+desc/i', $zapytanieUgotowanych);
    }

    public function test_uprawniony_widz_widzi_posortowane_wykonania_z_filtrem_widocznosci(): void
    {
        $kucharz = $this->user('kucharz3');
        $widz = $this->user('widz3');
        $autor = $this->user('autor3');

        $przepisPubliczny = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $przepisPrywatny = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'private',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $e1 = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepisPubliczny->getKey(),
            'cooked_at' => now()->subDays(1),
        ]);

        $e2 = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepisPubliczny->getKey(),
            'cooked_at' => now()->subDays(2),
        ]);

        // Wykonanie przepisu prywatnego — widz nie powinien go zobaczyć
        CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepisPrywatny->getKey(),
            'cooked_at' => now()->subHours(1),
        ]);

        $odpowiedz = $this->actingAs($widz)
            ->get(route('profile.show', ['username' => $kucharz->profile->username, 'zakladka' => 'ugotowane']))
            ->assertOk();

        /** @var LengthAwarePaginator $paginator */
        $paginator = $odpowiedz->viewData('cookedEvents');
        $pobraneId = $paginator->getCollection()->pluck('id')->all();

        $this->assertSame([$e1->getKey(), $e2->getKey()], $pobraneId);
    }
}
