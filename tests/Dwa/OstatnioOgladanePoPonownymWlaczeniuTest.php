<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\OstatnioOgladane;
use App\Models\RecentRecipeView;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** #2855: spóźniona odpowiedź nie należy do nowego okresu zgody. */
#[Group('dwa-polaczenia')]
final class OstatnioOgladanePoPonownymWlaczeniuTest extends TestDwochPolaczen
{
    public function test_stare_zadanie_po_wylaczeniu_i_ponownym_wlaczeniu_nie_odtwarza_historii(): void
    {
        $widz = $this->konto()->fresh();
        $autor = $this->konto();
        $stary = Recipe::factory()->for($autor, 'author')->create();
        $nowy = Recipe::factory()->for($autor, 'author')->create();
        $historia = app(OstatnioOgladane::class);
        $historia->wlacz($widz);
        $historia->zapisz($widz, $stary);
        $this->assertSame(1, RecentRecipeView::query()->where('user_id', $widz->getKey())->count());

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2855, 1)', []);
        $opoznione = $this->wTle('obejrzyj-przepis-2855', [
            'kto' => (string) $widz->getKey(),
            'przepis' => (string) $nowy->getKey(),
            'bariera' => '1',
        ]);

        try {
            $this->czekajNaZablokowane(1);
            $wlaczonePrzed = $widz->fresh()?->ostatnio_ogladane_wlaczone_at;
            $this->actingAs($widz)->post(route('settings.ogladane.wylacz'))
                ->assertRedirect(route('settings.ogladane'));
            $this->assertSame(0, RecentRecipeView::query()->where('user_id', $widz->getKey())->count());
            $this->actingAs($widz)->post(route('settings.ogladane.wlacz'))
                ->assertRedirect(route('settings.ogladane'));
            $widz->refresh();
            $this->assertNotNull($widz->ostatnio_ogladane_wlaczone_at);
            $this->assertNotEquals($wlaczonePrzed, $widz->ostatnio_ogladane_wlaczone_at);
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $wynik = $opoznione->wynik();
        $this->assertBezZakleszczenia($wynik, 'odroczony zapis oglądania');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(200, $wynik['wartosc']['status'] ?? null);
        $this->assertSame(0, RecentRecipeView::query()->where('user_id', $widz->getKey())->count(), 'HISTORIA_2855_NIE_WRACA_PO_WLACZENIU');
        $this->assertTrue($historia->lista($widz)->isEmpty());

        $swieze = $this->wTle('obejrzyj-przepis-2855', [
            'kto' => (string) $widz->getKey(),
            'przepis' => (string) $nowy->getKey(),
        ])->wynik();
        $this->assertTrue($swieze['ok'], $swieze['komunikat']);
        $this->assertSame(200, $swieze['wartosc']['status'] ?? null);
        $pozycja = RecentRecipeView::query()->where('user_id', $widz->getKey())->where('recipe_id', $nowy->getKey())->first();
        $this->assertNotNull($pozycja);
        $this->assertSame([(string) $nowy->getKey()], $historia->lista($widz)->pluck('recipe.id')->all());

        DB::table('recent_recipe_views')->where('id', $pozycja->getKey())->update(['viewed_at' => now()->subHour()]);
        $ponowna = $this->wTle('obejrzyj-przepis-2855', [
            'kto' => (string) $widz->getKey(),
            'przepis' => (string) $nowy->getKey(),
        ])->wynik();
        $this->assertTrue($ponowna['ok'], $ponowna['komunikat']);
        $this->assertSame(1, RecentRecipeView::query()->where('user_id', $widz->getKey())->count());
        $this->assertTrue($pozycja->fresh()->viewed_at->greaterThan(now()->subMinutes(5)));
    }

    public function test_pozostawienie_listy_wylaczonej_nie_pozwala_na_spozniony_zapis(): void
    {
        $widz = $this->konto()->fresh();
        $autor = $this->konto();
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        app(OstatnioOgladane::class)->wlacz($widz);

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2855, 1)', []);
        $opoznione = $this->wTle('obejrzyj-przepis-2855', [
            'kto' => (string) $widz->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'bariera' => '1',
        ]);
        try {
            $this->czekajNaZablokowane(1);
            $this->actingAs($widz)->post(route('settings.ogladane.wylacz'))
                ->assertRedirect(route('settings.ogladane'));
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $wynik = $opoznione->wynik();
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(200, $wynik['wartosc']['status'] ?? null);
        $this->assertSame(0, RecentRecipeView::query()->where('user_id', $widz->getKey())->count());
    }
}
