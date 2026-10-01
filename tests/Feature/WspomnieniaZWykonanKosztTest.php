<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wspomnienia\Wspomnienia;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wspomnienia z własnych wykonań (issue #2347): koszt zapytania i to, że
 * tytuł niedostępnego przepisu nie wycieka na stronę główną.
 *
 * Funkcja weszła w F6 (`WspomnieniaZWykonanTest` pilnuje reguł); ten plik
 * domyka to, czego tam brakowało: test planu i stałą liczbę zapytań.
 */
class WspomnieniaZWykonanKosztTest extends TestCase
{
    use RefreshDatabase;

    private function wykonanie(User $kucharz, Recipe $przepis, int $lat = 1): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'cooked_at' => Czas::lokalnie(Carbon::now())->subYears($lat)->setTime(12, 0),
        ]);
    }

    /** @return list<array{query: string, bindings: array<int, mixed>}> */
    private function zapytania(User $kto): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(Wspomnienia::class)->wykonanieDlaOsoby($kto);
        DB::disableQueryLog();

        return DB::getQueryLog();
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_wykonan(): void
    {
        $zofia = $this->user('zofia');
        $this->wykonanie($zofia, Recipe::factory()->create());
        $malo = count($this->zapytania($zofia));

        foreach (range(2, 9) as $lat) {
            $this->wykonanie($zofia, Recipe::factory()->create(), $lat);
        }
        $duzo = count($this->zapytania($zofia));

        $this->assertSame($malo, $duzo, 'Zapytanie o wspomnienie ma N+1.');
        $this->assertLessThanOrEqual(8, $duzo);
    }

    public function test_plan_zapytania_idzie_po_indeksie_osoby_i_czasu(): void
    {
        $zofia = $this->user('zofia');
        $this->wykonanie($zofia, Recipe::factory()->create());

        $glowne = collect($this->zapytania($zofia))
            ->first(fn (array $q) => str_contains($q['query'], 'from "cooked_events"'));
        $this->assertNotNull($glowne, json_encode(array_column($this->zapytania($zofia), 'query')));

        // Na prawie pustej tabeli planer wybiera skan sekwencyjny, więc
        // wyłączamy go w tej transakcji — pytamy, czy indeks DA SIĘ użyć.
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select('EXPLAIN '.$glowne['query'], $glowne['bindings']))
            ->map(fn ($w) => (string) array_values((array) $w)[0])->implode("\n");

        $this->assertStringContainsString('cooked_events_user_idx', $plan, $plan);
    }

    public function test_niedostepny_przepis_nie_trafia_do_strony_glownej(): void
    {
        $zofia = $this->user('zofia');
        $zbanowany = $this->user('zbanowany', ['status' => User::STATUS_BANNED]);

        $przepisy = [
            'Tajny Schab' => Recipe::factory()->create(['title' => 'Tajny Schab', 'visibility' => 'private']),
            'Zbanowany Barszcz' => Recipe::factory()->create(['title' => 'Zbanowany Barszcz', 'author_id' => $zbanowany->getKey()]),
            'Usunieta Zupa' => Recipe::factory()->create(['title' => 'Usunieta Zupa']),
        ];
        foreach ($przepisy as $przepis) {
            $this->wykonanie($zofia, $przepis);
        }
        $przepisy['Usunieta Zupa']->delete();

        $html = (string) $this->actingAs($zofia)->get(route('home'))->assertOk()->getContent();

        foreach (array_keys($przepisy) as $tytul) {
            $this->assertStringNotContainsString($tytul, $html, "Tytuł niedostępnego przepisu „{$tytul}” trafił do HTML.");
        }
        $this->assertStringNotContainsString('Ugotuj znowu', $html);
    }

    public function test_gosc_nie_widzi_bloku(): void
    {
        $this->wykonanie($this->user('obca'), Recipe::factory()->create());

        $this->get(route('home'))->assertDontSee('Ugotuj znowu');
    }
}
