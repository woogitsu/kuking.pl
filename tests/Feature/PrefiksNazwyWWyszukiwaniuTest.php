<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrefiksNazwyWWyszukiwaniuTest extends TestCase
{
    use RefreshDatabase;

    public function test_skopiowana_nazwa_znajduje_profil_przez_http(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $basia->profile->update(['speciality' => 'zupy']);

        foreach (['basia', '@basia', ' @basia '] as $phrase) {
            foreach (['ludzie', 'wszystko'] as $section) {
                $response = $this->get(route('search', ['q' => $phrase, 'sekcja' => $section]))->assertOk();
                $this->assertSame([$basia->id], $response->viewData('people')->pluck('user_id')->all(), 'Skopiowana nazwa nie odnalazła profilu.');
                $response->assertViewHas('phrase', trim($phrase));
            }
        }
    }

    public function test_skopiowana_nazwa_dziala_takze_w_onboardingu_i_domenie(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $this->assertSame([$basia->id], app(SearchQuery::class)->people('@basia')->pluck('user_id')->all(), 'Skopiowana nazwa nie odnalazła profilu.');
        $response = $this->actingAs($this->user('widz'))
            ->get(route('onboarding.people', ['q' => '@basia']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertSame(1, (new \DOMXPath($dom))->query('//input[@name="follow[]" and @value="basia"]')->length);
    }

    public function test_prefiks_zachowuje_dotychczasowe_pola_fragmenty_i_unicode(): void
    {
        $this->user('zolw', ['display_name' => 'Żółw']);
        $this->user('zolwik', ['display_name' => 'Zosia']);
        $this->user('ania')->profile->update(['speciality' => 'żółwie wypieki']);
        $search = app(SearchQuery::class);
        $expected = $search->people('żółw')->pluck('user_id')->all();
        $this->assertCount(3, $expected);
        $this->assertSame($expected, $search->people('@ŻÓŁW')->pluck('user_id')->all());
        $this->assertSame($expected, $search->people(' @żółw ')->pluck('user_id')->all());
    }

    public function test_prefiks_nie_omija_blokad_ani_stanu_konta(): void
    {
        $viewer = $this->user('widz');
        $visible = $this->user('basia');
        $blocked = $this->user('basiazablokowana');
        $blocking = $this->user('basiablokujaca');
        $viewer->blocking()->attach($blocked->id, ['created_at' => now()]);
        $blocking->blocking()->attach($viewer->id, ['created_at' => now()]);
        foreach (['banned', 'pending_delete', 'erased', 'suspended'] as $status) {
            $this->user('basia'.$status, ['status' => $status, 'data_erased_at' => $status === 'erased' ? now() : null]);
        }
        $response = $this->actingAs($viewer)->get(route('search', ['q' => '@basia', 'sekcja' => 'ludzie']))->assertOk();
        $this->assertSame([$visible->id], $response->viewData('people')->pluck('user_id')->all());
    }

    public function test_nie_usuwa_znaku_wewnatrz_frazy_ani_drugiego_prefiksu(): void
    {
        $literal = $this->user('literalna')->profile;
        $literal->update(['speciality' => 'basia@dom @@basia']);
        $this->user('basia')->profile->update(['speciality' => 'basiadom']);
        foreach (['basia@dom', '@@basia'] as $phrase) {
            $this->assertSame([$literal->user_id], app(SearchQuery::class)->people($phrase)->pluck('user_id')->all());
        }
    }

    public function test_krotka_nazwa_z_prefiksem_ma_instrukcje_i_nie_pyta_bazy(): void
    {
        foreach (['@', '@a'] as $phrase) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertCount(0, app(SearchQuery::class)->people($phrase));
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            $this->get(route('search', ['q' => $phrase, 'sekcja' => 'ludzie']))
                ->assertOk()->assertSee('Wpisz co najmniej dwa znaki.')->assertDontSee('Nic nie znaleźliśmy');
        }
    }

    public function test_prefiks_nie_jest_usuwany_z_zapytania_o_przepisy(): void
    {
        $author = $this->user('autor');
        $literal = Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Zupa', 'summary' => '@basia']);
        Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Rosół', 'summary' => 'basia']);
        $this->assertSame([$literal->id], app(SearchQuery::class)->recipes('@basia')->pluck('id')->all());
    }
}
