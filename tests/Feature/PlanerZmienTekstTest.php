<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\Actions\ZmienTekstPozycjiPlanu;
use App\Models\CookedEvent;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Poprawianie własnego tekstu w Planerze bez usuwania pozycji (#2454, V2).
 *
 * Czas zamrożony na czwartek 1 października 2026. Pomiary idą przez HTTP;
 * akcja domenowa jest wołana wprost tylko tam, gdzie kryterium wymaga kontroli
 * niezależnej od trasy.
 */
final class PlanerZmienTekstTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pozycja(User $kto, string $dzien, ?Recipe $przepis = null, ?string $tekst = null): MealPlanEntry
    {
        $wpis = new MealPlanEntry(['day' => $dzien, 'recipe_id' => $przepis?->getKey(), 'label' => $tekst]);
        $wpis->user_id = $kto->getKey();
        $wpis->save();

        return $wpis;
    }

    private function przepis(User $autor, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);
    }

    private function bladTekstu(): string
    {
        $bledy = session('errors');
        $bag = $bledy instanceof ViewErrorBag
            ? $bledy->getBag('default')
            : new MessageBag((array) ($bledy['default']['messages'] ?? []));

        return (string) $bag->first('label');
    }

    private function popraw(User $kto, MealPlanEntry $wpis, string $tekst, ?string $stan = null)
    {
        return $this->actingAs($kto)->patch(route('planer.text.update', $wpis), [
            'label' => $tekst,
            'stan' => $stan ?? ZmienTekstPozycjiPlanu::znacznik($wpis->refresh()),
        ]);
    }

    public function test_poprawka_zmienia_tylko_tekst_a_pozycja_zachowuje_dzien_id_i_date_utworzenia(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-02', tekst: 'obiad u mamy');
        $wpis->forceFill(['created_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:00'])->saveQuietly();
        $przed = $wpis->refresh()->getAttributes();

        $this->popraw($ja, $wpis, '  obiad   u Kasi o 14 ')
            ->assertRedirect(route('planer.show', ['tydzien' => '2026-10-02']).'#dzien-2026-10-02')
            ->assertSessionHas('status', 'Tekst poprawiony. Pozycja zostaje na piątek, 2 października.');

        $po = $wpis->refresh()->getAttributes();
        $this->assertSame('obiad u Kasi o 14', $po['label']);
        foreach (['id', 'user_id', 'day', 'recipe_id', 'created_at', 'done_at'] as $kolumna) {
            $this->assertSame($przed[$kolumna], $po[$kolumna], "Kolumna {$kolumna} ma zostać bez zmian.");
        }
        $this->assertNotSame($przed['updated_at'], $po['updated_at']);
        $this->assertSame(1, MealPlanEntry::query()->count(), 'Poprawka nie tworzy kopii.');

        $html = (string) $this->actingAs($ja)->get(route('planer.show', ['tydzien' => '2026-10-02']))->getContent();
        preg_match('~<section[^>]*aria-labelledby="dzien-2026-10-02"[^>]*>(.*?)</section>~s', $html, $piatek);
        $this->assertStringContainsString('obiad u Kasi o 14', $piatek[1]);
        $this->assertStringNotContainsString('obiad u mamy', $piatek[1]);
    }

    public function test_ekran_ma_etykiete_novalidate_obecny_tekst_i_przycisk_tylko_przy_wlasnych_pozycjach(): void
    {
        $ja = $this->user('planujaca');
        $wlasna = $this->pozycja($ja, '2026-10-01', tekst: 'obiad u mamy');
        $this->pozycja($ja, '2026-10-01', $this->przepis($ja, ['title' => 'Zupa ogorkowa']));

        $planer = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($planer, 'Zmień tekst<span'), 'Przycisk tylko przy ręcznej pozycji.');
        $this->assertStringContainsString('href="'.route('planer.text.edit', $wlasna).'"', $planer);

        $html = (string) $this->actingAs($ja)->get(route('planer.text.edit', $wlasna))->assertOk()->getContent();
        $this->assertStringContainsString('value="obiad u mamy"', $html);
        $this->assertMatchesRegularExpression('~<form[^>]*novalidate[^>]*>~', $html);
        $this->assertStringContainsString('<label for="f-label">', $html);
        $this->assertStringContainsString('Najwyżej 120 znaków.', $html);
        $this->assertStringContainsString('Anuluj, zostaw jak jest', $html);
    }

    public function test_obca_osoba_gosc_i_moderator_nie_zmieniaja_cudzego_planu(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-02', tekst: 'Tajny obiad');
        $znacznik = ZmienTekstPozycjiPlanu::znacznik($wpis);

        foreach ([$this->user('obca'), $this->moderator()] as $obcy) {
            $this->popraw($obcy, $wpis, 'Podmienione', $znacznik)->assertForbidden();
            $this->actingAs($obcy)->get(route('planer.text.edit', $wpis))->assertForbidden();
        }
        auth()->logout();
        $this->patch(route('planer.text.update', $wpis), ['label' => 'Podmienione', 'stan' => $znacznik])->assertRedirect(route('login'));
        $this->get(route('planer.text.edit', $wpis))->assertRedirect(route('login'));

        $this->assertSame('Tajny obiad', $wpis->refresh()->label);

        // Własność sprawdza też sama domena, niezależnie od trasy.
        $wynik = app(ZmienTekstPozycjiPlanu::class)->handle($this->user('obca2'), (string) $wpis->getKey(), 'Podmienione', $znacznik);
        $this->assertSame(ZmienTekstPozycjiPlanu::BRAK, $wynik);
        $this->assertSame('Tajny obiad', $wpis->refresh()->label);
    }

    public function test_pusty_za_dlugi_i_zly_typ_wracaja_przy_polu_z_wpisanym_tekstem_a_stary_tekst_zostaje(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-02', tekst: 'obiad u mamy');
        $dlugi = str_repeat('a', 121);

        $this->popraw($ja, $wpis, "   \n ")->assertRedirect(route('planer.text.edit', $wpis))->assertSessionHasErrors('label');
        $this->popraw($ja, $wpis, $dlugi)->assertSessionHasErrors('label');
        $this->assertSame('Skróć wpis do 120 znaków i zapisz jeszcze raz.', $this->bladTekstu());
        $this->actingAs($ja)->patch(route('planer.text.update', $wpis), ['label' => ['x'], 'stan' => ZmienTekstPozycjiPlanu::znacznik($wpis)])
            ->assertSessionHasErrors('label');
        $this->actingAs($ja)->patch(route('planer.text.update', $wpis), ['label' => 'x'])->assertSessionHasErrors('stan');

        // Dokładnie 120 znaków jest dozwolone.
        $this->popraw($ja, $wpis, str_repeat('b', 120))->assertSessionHas('status_rodzaj', 'sukces');

        $wpis->refresh()->forceFill(['label' => 'obiad u mamy'])->saveQuietly();
        $html = (string) $this->followRedirects($this->actingAs($ja)->patch(route('planer.text.update', $wpis), [
            'label' => $dlugi, 'stan' => ZmienTekstPozycjiPlanu::znacznik($wpis->refresh()),
        ]))->getContent();
        $this->assertStringContainsString('error-summary', $html);
        $this->assertStringContainsString('field-error', $html);
        $this->assertStringContainsString('value="'.$dlugi.'"', $html, 'Wpisana poprawka nie ginie.');
        $this->assertSame('obiad u mamy', $wpis->refresh()->label);
    }

    public function test_duplikat_innej_pozycji_tego_dnia_odrzuca_zmiane_a_ten_sam_tekst_jest_bezpieczny(): void
    {
        $ja = $this->user('planujaca');
        $a = $this->pozycja($ja, '2026-10-02', tekst: 'obiad u mamy');
        $b = $this->pozycja($ja, '2026-10-02', tekst: 'kolacja');
        $this->pozycja($ja, '2026-10-03', tekst: 'obiad u Kasi'); // inny dzień — nie koliduje

        $this->popraw($ja, $b, '  obiad u   mamy ')->assertSessionHasErrors('label');
        $this->assertStringContainsString('Taki wpis już jest w planie', $this->bladTekstu());
        $this->assertSame('kolacja', $b->refresh()->label);
        $this->assertSame(2, MealPlanEntry::query()->where('day', '2026-10-02')->count(), 'Nic nie jest łączone ani kasowane.');

        // Taki sam tekst jak na innym dniu — wolno.
        $this->popraw($ja, $b, 'obiad u Kasi')->assertSessionHas('status_rodzaj', 'sukces');

        // Niezmieniony tekst (także po normalizacji) nie zmienia niczego.
        $updated = $a->refresh()->updated_at;
        $this->popraw($ja, $a, ' obiad  u mamy ')
            ->assertSessionHas('status', 'Ta pozycja ma już taki tekst. Nic nie zostało zmienione.');
        $this->assertEquals($updated, $a->refresh()->updated_at);
    }

    public function test_edycja_dziala_przy_pelnym_dniu_i_nie_dodaje_pozycji(): void
    {
        $ja = $this->user('planujaca');
        $limit = (int) config('kuking.planer.wpisow_na_dzien');
        $pierwsza = null;
        for ($i = 1; $i <= $limit; $i++) {
            $wpis = $this->pozycja($ja, '2026-10-02', tekst: 'Danie '.$i);
            $pierwsza ??= $wpis;
        }

        $this->popraw($ja, $pierwsza, 'Danie poprawione')->assertSessionHas('status_rodzaj', 'sukces');

        $this->assertSame($limit, MealPlanEntry::query()->where('day', '2026-10-02')->count());
        $this->assertSame('Danie poprawione', $pierwsza->refresh()->label);
    }

    public function test_stara_karta_nie_nadpisuje_nowszej_poprawki_a_wpisany_tekst_zostaje_w_polu(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-02', tekst: 'obiad u mamy');
        $staryZnacznik = ZmienTekstPozycjiPlanu::znacznik($wpis);

        // Pierwsza karta zapisuje poprawkę.
        $this->popraw($ja, $wpis, 'obiad u Kasi', $staryZnacznik)->assertSessionHas('status_rodzaj', 'sukces');

        // Druga karta nadal widzi stary tekst i wysyła swoją poprawkę.
        $this->popraw($ja, $wpis, 'kolacja u cioci', $staryZnacznik)
            ->assertRedirect(route('planer.text.edit', $wpis))
            ->assertSessionHas('status_rodzaj', 'blad');
        $this->assertStringContainsString('innym oknie', (string) session('status'));
        $this->assertSame('obiad u Kasi', $wpis->refresh()->label);

        // Formularz po konflikcie: aktualny tekst nad polem, wpisany w polu, nowy znacznik.
        $html = (string) $this->followRedirects($this->actingAs($ja)->patch(route('planer.text.update', $wpis), [
            'label' => 'kolacja u cioci', 'stan' => $staryZnacznik,
        ]))->getContent();
        $this->assertStringContainsString('<strong>obiad u Kasi</strong>', $html);
        $this->assertStringContainsString('value="kolacja u cioci"', $html);
        $this->assertStringContainsString('name="stan" value="'.ZmienTekstPozycjiPlanu::znacznik($wpis).'"', $html);

        // Świadome ponowne „Zapisz” z aktualnym znacznikiem zastępuje tekst.
        $this->popraw($ja, $wpis, 'kolacja u cioci')->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame('kolacja u cioci', $wpis->refresh()->label);
    }

    public function test_pozycja_przepisu_i_pozostalosc_po_przepisie_nie_zamieniaja_sie_we_wlasny_tekst(): void
    {
        $ja = $this->user('planujaca');
        $autorka = $this->user('kucharka');
        $sekret = $this->przepis($autorka, ['title' => 'Sekretny bigos']);
        $zniszczony = $this->przepis($autorka, ['title' => 'Kasza skasowana twardo']);
        $zPrzepisu = $this->pozycja($ja, '2026-10-02', $sekret);
        $pozostalosc = $this->pozycja($ja, '2026-10-02', $zniszczony);
        $sekret->forceFill(['visibility' => 'private'])->save();
        $zniszczony->forceDelete();
        $pozostalosc->refresh();

        foreach ([$zPrzepisu, $pozostalosc] as $wpis) {
            $przed = $wpis->refresh()->getAttributes();

            $odpowiedz = $this->actingAs($ja)->get(route('planer.text.edit', $wpis));
            $odpowiedz->assertRedirect(route('planer.show', ['tydzien' => '2026-10-02']));
            $this->assertStringNotContainsString('Sekretny bigos', (string) session('status'));

            $this->actingAs($ja)->patch(route('planer.text.update', $wpis), ['label' => 'Własny tekst', 'stan' => hash('sha256', '')])
                ->assertSessionHas('status_rodzaj', 'blad');
            $this->assertSame($przed, $wpis->refresh()->getAttributes(), 'Pozycja przepisu nie może zostać przekształcona.');
        }

        $html = (string) $this->actingAs($ja)->get(route('planer.show', ['tydzien' => '2026-10-02']))->getContent();
        $this->assertStringNotContainsString('Sekretny bigos', $html);
        $this->assertStringNotContainsString('Zmień tekst<span', $html, 'Przycisk tylko przy ręcznych pozycjach.');
    }

    public function test_poprawka_nie_rusza_zakupow_wykonan_ani_innych_pozycji(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-02', tekst: 'obiad u mamy');
        $inna = $this->pozycja($ja, '2026-10-02', tekst: 'kolacja');
        $zakup = new ShoppingListItem(['text' => 'mleko']);
        $zakup->user_id = $ja->getKey();
        $zakup->source = ShoppingListItem::SOURCE_MANUAL;
        $zakup->position = 0;
        $zakup->save();
        $zakupPrzed = $zakup->refresh()->getAttributes();
        $innaPrzed = $inna->refresh()->getAttributes();

        $this->popraw($ja, $wpis, 'obiad u Kasi');

        $this->assertSame($zakupPrzed, $zakup->refresh()->getAttributes());
        $this->assertSame($innaPrzed, $inna->refresh()->getAttributes());
        $this->assertSame(0, CookedEvent::query()->count());
    }

    public function test_zapis_bierze_blokade_konta_przed_odczytem_pozycji_i_sprawdzeniem_duplikatu(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-02', tekst: 'obiad u mamy');
        $znacznik = ZmienTekstPozycjiPlanu::znacznik($wpis);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->popraw($ja, $wpis, 'obiad u Kasi', $znacznik);
        $zapytania = array_map(fn (array $q): string => strtolower($q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $blokadaKonta = null;
        $odczytPozycji = null;
        $duplikat = null;
        foreach ($zapytania as $i => $sql) {
            if ($blokadaKonta === null && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
                $blokadaKonta = $i;
            }
            if ($odczytPozycji === null && str_contains($sql, 'from "meal_plan_entries"') && str_contains($sql, 'for update')) {
                $odczytPozycji = $i;
            }
            if ($duplikat === null && str_contains($sql, 'select exists') && str_contains($sql, 'meal_plan_entries')) {
                $duplikat = $i;
            }
        }

        $this->assertNotNull($blokadaKonta, 'Brak blokady wiersza konta.');
        $this->assertNotNull($odczytPozycji, 'Pozycja musi być czytana pod blokadą.');
        $this->assertNotNull($duplikat, 'Brak sprawdzenia duplikatu.');
        $this->assertLessThan($odczytPozycji, $blokadaKonta);
        $this->assertLessThan($duplikat, $odczytPozycji);
    }
}
