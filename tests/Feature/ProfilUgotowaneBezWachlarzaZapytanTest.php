<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pomiar zapytań SQL na profilu w zakładce „Ugotowane" (issue #736).
 *
 * Sprawdzamy, czy liczba zapytań SQL o dane kucharza (user, profile, avatar)
 * rośnie liniowo wraz z liczbą kart wykonania (2 vs 12).
 */
class ProfilUgotowaneBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * @return array{0: User, 1: list<CookedEvent>}
     */
    private function utworzSceneZWykonaniami(int $ileWykonan, string $prefiks): array
    {
        $kucharz = $this->user('kucharz_'.$prefiks);
        $autor = $this->user('autor_'.$prefiks);

        // Ustaw awatar kucharza
        $storeImage = app(StoreUploadedImage::class);
        $avatarMedia = $storeImage->handle($kucharz, UploadedFile::fake()->image('awatar.jpg', 200, 200));
        $kucharz->profile->update(['avatar_id' => $avatarMedia->getKey()]);

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $wykonania = [];
        for ($i = 0; $i < $ileWykonan; $i++) {
            $wykonania[] = CookedEvent::factory()->create([
                'user_id' => $kucharz->getKey(),
                'recipe_id' => $recipe->getKey(),
                'cooked_at' => now()->subMinutes($i),
            ]);
        }

        return [$kucharz, $wykonania];
    }

    private function mierzZapytaniaDlaSceny(int $ileWykonan, string $prefiks, bool $jakoWlasciciel): array
    {
        [$kucharz] = $this->utworzSceneZWykonaniami($ileWykonan, $prefiks);

        $zapytania = [];
        DB::listen(function ($query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });

        $klient = $jakoWlasciciel ? $this->actingAs($kucharz) : $this;

        $odpowiedz = $klient->get(route('profile.show', [
            'username' => $kucharz->profile->username,
            'zakladka' => 'ugotowane',
        ]));

        $odpowiedz->assertOk();

        return $zapytania;
    }

    private function mierzZapytaniaDlaWidza(int $ileWykonan, string $prefiks, User $widz): array
    {
        [$kucharz] = $this->utworzSceneZWykonaniami($ileWykonan, $prefiks);

        $zapytania = [];
        DB::listen(function ($query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });

        $odpowiedz = $this->actingAs($widz)->get(route('profile.show', [
            'username' => $kucharz->profile->username,
            'zakladka' => 'ugotowane',
        ]));

        $odpowiedz->assertOk();

        return $zapytania;
    }

    public function test_pomiar_zapytan_dla_wlasciciela_2_vs_12_wykonan_jest_plaski(): void
    {
        $zapytania2 = $this->mierzZapytaniaDlaSceny(2, 'malo', true);
        $liczba2 = count($zapytania2);

        $zapytania12 = $this->mierzZapytaniaDlaSceny(12, 'duzo', true);
        $liczba12 = count($zapytania12);

        // Liczba zapytań nie rośnie z liczbą kart wykonania:
        // Przed poprawką: 21 (2 karty) -> 41 (12 kart), wzrost o 20 (N+1 po 2 zapytania/kartę).
        // Po poprawce: 16 (2 karty) -> 16 (12 kart), różnica = 0.
        $this->assertSame($liczba2, $liczba12, "Liczba zapytań wzrosła z {$liczba2} przy 2 kartach do {$liczba12} przy 12 kartach!");
    }

    public function test_pomiar_zapytan_dla_widza_niebedacego_wlascicielem_jest_plaski(): void
    {
        $widz = $this->user('widz_obcy');

        $zapytania2 = $this->mierzZapytaniaDlaWidza(2, 'widz_malo', $widz);
        $liczba2 = count($zapytania2);

        $zapytania12 = $this->mierzZapytaniaDlaWidza(12, 'widz_duzo', $widz);
        $liczba12 = count($zapytania12);

        $this->assertSame($liczba2, $liczba12, "Liczba zapytań dla widza wzrosła z {$liczba2} przy 2 kartach do {$liczba12} przy 12 kartach!");
    }

    public function test_karta_wykonania_renderuje_poprawnie_kucharza_jego_awatar_i_podpis(): void
    {
        [$kucharz, $wykonania] = $this->utworzSceneZWykonaniami(1, 'render');

        $response = $this->actingAs($kucharz)
            ->get(route('profile.show', [
                'username' => $kucharz->profile->username,
                'zakladka' => 'ugotowane',
            ]))
            ->assertOk();

        $response->assertSee($kucharz->profile->username);
        $response->assertSee($kucharz->displayName());
        $response->assertSee('class="avatar"', false);
    }
}
