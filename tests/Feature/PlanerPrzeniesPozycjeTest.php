<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\Actions\PrzeniesPozycjePlanu;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * „Przenieś na inny dzień” przy jednej pozycji Planera (#2447, V2).
 *
 * Czas zamrożony na czwartek 1 października 2026 (okno planera: od
 * 2026-08-02 do 2027-10-01). Pomiary idą przez HTTP i końcowy HTML; akcja
 * domenowa jest wołana wprost tylko tam, gdzie kryterium wymaga kontroli
 * niezależnej od trasy.
 */
final class PlanerPrzeniesPozycjeTest extends TestCase
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

    private function bladDnia(): string
    {
        $bledy = session('errors');
        $bag = $bledy instanceof ViewErrorBag
            ? $bledy->getBag('default')
            : new MessageBag((array) ($bledy['default']['messages'] ?? []));

        return (string) $bag->first('day');
    }

    private function przenies(User $kto, MealPlanEntry $wpis, string $nowyDzien, ?string $widzianyDzien = null)
    {
        return $this->actingAs($kto)->patch(route('planer.move', $wpis), [
            'day' => $nowyDzien,
            'stan' => $widzianyDzien ?? $wpis->day->toDateString(),
        ]);
    }

    public function test_przeniesienie_przepisu_i_wlasnego_tekstu_zachowuje_pozycje_i_zmienia_tylko_dzien(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->pozycja($ja, '2026-09-30', $this->przepis($ja, ['title' => 'Zupa ogorkowa']));
        $wlasny = $this->pozycja($ja, '2026-09-30', tekst: 'Obiad u mamy');
        $zupa->forceFill(['created_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:00'])->saveQuietly();
        $wlasny->forceFill(['created_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:00'])->saveQuietly();
        $przed = [$zupa->refresh()->getAttributes(), $wlasny->refresh()->getAttributes()];

        foreach ([$zupa, $wlasny] as $i => $wpis) {
            $this->przenies($ja, $wpis, '2026-10-02')
                ->assertRedirect(route('planer.show', ['tydzien' => '2026-09-28']).'#dzien-2026-10-02')
                ->assertSessionHas('status', 'Przeniesione na piątek, 2 października.');

            $po = $wpis->refresh()->getAttributes();
            $this->assertSame('2026-10-02', $wpis->day->toDateString());
            foreach (['id', 'user_id', 'recipe_id', 'label', 'created_at', 'done_at'] as $kolumna) {
                $this->assertSame($przed[$i][$kolumna], $po[$kolumna], "Kolumna {$kolumna} ma zostać bez zmian.");
            }
            $this->assertNotSame($przed[$i]['updated_at'], $po['updated_at'], 'Zwykłe updated_at ma się zmienić.');
        }

        $this->assertSame(2, MealPlanEntry::query()->count(), 'Przeniesienie nie tworzy kopii.');
        $this->assertSame(0, MealPlanEntry::query()->where('day', '2026-09-30')->count());

        $html = (string) $this->actingAs($ja)->get(route('planer.show', ['tydzien' => '2026-10-02']))->getContent();
        preg_match('~<section[^>]*aria-labelledby="dzien-2026-10-02"[^>]*>(.*?)</section>~s', $html, $m);
        $this->assertStringContainsString('Zupa ogorkowa', $m[1]);
        $this->assertStringContainsString('Obiad u mamy', $m[1]);
    }

    public function test_ekran_przenoszenia_ma_etykiete_novalidate_i_przycisk_przy_pozycji(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-09-30', tekst: 'Obiad u mamy');

        $planer = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('planer.move.form', $wpis).'"', $planer);
        $this->assertStringContainsString('Przenieś na inny dzień', $planer);

        $html = (string) $this->actingAs($ja)->get(route('planer.move.form', $wpis))->assertOk()->getContent();
        $this->assertStringContainsString('Obiad u mamy', $html);
        $this->assertMatchesRegularExpression('~<form[^>]*novalidate[^>]*>~', $html);
        $this->assertStringContainsString('<label for="f-day">', $html);
        $this->assertStringContainsString('name="stan" value="2026-09-30"', $html);
        $this->assertStringContainsString('Przenieś</button>', $html);
        $this->assertStringContainsString('Anuluj, zostaw jak jest', $html);
    }

    public function test_obca_osoba_gosc_i_moderator_nie_zmieniaja_cudzego_planu(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-09-30', tekst: 'Tajny obiad');

        $this->przenies($this->user('obca'), $wpis, '2026-10-02')->assertForbidden();
        $this->przenies($this->moderator(), $wpis, '2026-10-02')->assertForbidden();
        $this->actingAs($this->user('obca2'))->get(route('planer.move.form', $wpis))->assertForbidden();
        $this->actingAs($this->moderator())->get(route('planer.move.form', $wpis))->assertForbidden();

        auth()->logout();
        $this->patch(route('planer.move', $wpis), ['day' => '2026-10-02', 'stan' => '2026-09-30'])->assertRedirect(route('login'));
        $this->get(route('planer.move.form', $wpis))->assertRedirect(route('login'));

        $this->assertSame('2026-09-30', $wpis->refresh()->day->toDateString());

        // Własność sprawdza też sama domena, niezależnie od trasy.
        $wynik = app(PrzeniesPozycjePlanu::class)->handle(
            $this->user('obca3'), (string) $wpis->getKey(), CarbonImmutable::parse('2026-10-02'), '2026-09-30',
        );
        $this->assertSame(PrzeniesPozycjePlanu::BRAK, $wynik);
        $this->assertSame('2026-09-30', $wpis->refresh()->day->toDateString());
    }

    public function test_duplikat_pelny_dzien_i_data_spoza_zakresu_zostawiaja_pozycje_a_wybor_wraca_w_formularzu(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->przepis($ja, ['title' => 'Zupa ogorkowa']);
        $limit = (int) config('kuking.planer.wpisow_na_dzien');

        $przenoszona = $this->pozycja($ja, '2026-09-30', $zupa);
        $this->pozycja($ja, '2026-10-02', $zupa); // duplikat przepisu
        $wlasna = $this->pozycja($ja, '2026-09-30', tekst: 'Obiad u mamy');
        $this->pozycja($ja, '2026-10-02', tekst: 'Obiad u mamy'); // duplikat tekstu
        for ($i = 1; $i <= $limit; $i++) {
            $this->pozycja($ja, '2026-10-03', tekst: 'Danie '.$i);
        }
        $inna = $this->pozycja($ja, '2026-09-30', tekst: 'Kolacja');
        $przed = MealPlanEntry::query()->count();

        $przypadki = [
            [$przenoszona, '2026-10-02', 'Taka pozycja jest już w planie'],
            [$wlasna, '2026-10-02', 'Taka pozycja jest już w planie'],
            [$inna, '2026-10-03', "ma już {$limit} pozycji"],
            [$inna, '2027-10-02', 'Wybierz dzień w zakresie planera'],
            [$inna, '2026-08-01', 'Wybierz dzień w zakresie planera'],
        ];
        foreach ($przypadki as [$wpis, $dzien, $fragment]) {
            $odpowiedz = $this->przenies($ja, $wpis, $dzien)->assertRedirect(route('planer.move.form', $wpis));
            $odpowiedz->assertSessionHasErrors('day');
            $this->assertStringContainsString($fragment, $this->bladDnia());
            $this->assertSame('2026-09-30', $wpis->refresh()->day->toDateString(), 'Pozycja zostaje na dawnym dniu.');

            // Błąd przy polu i w podsumowaniu, wybrana data zachowana.
            $html = (string) $this->followRedirects($this->actingAs($ja)->patch(route('planer.move', $wpis), ['day' => $dzien, 'stan' => '2026-09-30']))->getContent();
            $this->assertStringContainsString('error-summary', $html);
            $this->assertStringContainsString('field-error', $html);
            $this->assertStringContainsString('value="'.$dzien.'"', $html);
        }

        $this->assertSame($przed, MealPlanEntry::query()->count(), 'Nic nie zostało scalone ani usunięte.');
    }

    public function test_zly_format_daty_i_brak_znacznika_daja_polski_komunikat_bez_zmiany(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-09-30', tekst: 'Obiad u mamy');

        $this->actingAs($ja)->patch(route('planer.move', $wpis), ['day' => 'jutro', 'stan' => '2026-09-30'])
            ->assertRedirect(route('planer.move.form', $wpis))
            ->assertSessionHasErrors(['day' => 'Wybierz dzień z kalendarza albo wpisz go w formacie rrrr-mm-dd, np. 2026-10-02.']);
        $this->actingAs($ja)->patch(route('planer.move', $wpis), ['day' => '2026-10-02'])
            ->assertSessionHasErrors('stan');
        $this->actingAs($ja)->patch(route('planer.move', $wpis), ['day' => ['2026-10-02'], 'stan' => '2026-09-30'])
            ->assertSessionHasErrors('day');

        $this->assertSame('2026-09-30', $wpis->refresh()->day->toDateString());
    }

    public function test_wybor_obecnego_dnia_i_powtorzenie_zadania_nie_tworza_kopii(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-09-30', tekst: 'Obiad u mamy');
        $updated = $wpis->refresh()->updated_at;

        $this->przenies($ja, $wpis, '2026-09-30')
            ->assertSessionHas('status', 'Ta pozycja już jest w planie na środę, 30 września. Nic nie zostało zmienione.');
        $this->assertEquals($updated, $wpis->refresh()->updated_at, 'Bezpieczna operacja bez zmiany.');

        $this->przenies($ja, $wpis, '2026-10-02')->assertSessionHas('status', 'Przeniesione na piątek, 2 października.');
        // To samo żądanie wysłane drugi raz (podwójne kliknięcie, stara karta).
        $this->actingAs($ja)->patch(route('planer.move', $wpis), ['day' => '2026-10-02', 'stan' => '2026-09-30'])
            ->assertSessionHas('status', 'Ta pozycja już jest w planie na piątek, 2 października. Nic nie zostało zmienione.');

        $this->assertSame(1, MealPlanEntry::query()->count());
        $this->assertSame('2026-10-02', $wpis->refresh()->day->toDateString());
    }

    public function test_dwie_karty_nie_nadpisuja_nowszej_decyzji_po_cichu(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-09-30', tekst: 'Obiad u mamy');

        // Obie karty widziały środę. Pierwsza przenosi na piątek.
        $this->przenies($ja, $wpis, '2026-10-02', '2026-09-30')->assertSessionHas('status');

        // Druga, nieaktualna, chce sobotę — odrzucona, piątek zostaje.
        $this->przenies($ja, $wpis, '2026-10-03', '2026-09-30')
            ->assertRedirect(route('planer.show', ['tydzien' => '2026-10-02']))
            ->assertSessionHas('status_rodzaj', 'blad');
        $this->assertStringContainsString('innym oknie', (string) session('status'));
        $this->assertSame('2026-10-02', $wpis->refresh()->day->toDateString());
    }

    public function test_niedostepny_i_usuniety_przepis_mozna_przeniesc_bez_ujawnienia_tytulu(): void
    {
        $ja = $this->user('planujaca');
        $autorka = $this->user('kucharka');
        $zawezony = $this->przepis($autorka, ['title' => 'Sekretny bigos']);
        $zniszczony = $this->przepis($autorka, ['title' => 'Kasza skasowana twardo']);
        $wZawezonym = $this->pozycja($ja, '2026-09-30', $zawezony);
        $wZniszczonym = $this->pozycja($ja, '2026-09-30', $zniszczony);
        $zawezony->forceFill(['visibility' => 'private'])->save();
        $zniszczony->forceDelete();

        foreach ([$wZawezonym, $wZniszczonym] as $wpis) {
            $wpis->refresh();
            $html = (string) $this->actingAs($ja)->get(route('planer.move.form', $wpis))->assertOk()->getContent();
            $this->assertStringNotContainsString('Sekretny bigos', $html);
            $this->assertStringNotContainsString('Kasza skasowana', $html);

            $this->przenies($ja, $wpis, '2026-10-02')->assertSessionHas('status', 'Przeniesione na piątek, 2 października.');
            $this->assertSame('2026-10-02', $wpis->refresh()->day->toDateString());
        }
        $this->assertSame($zawezony->getKey(), $wZawezonym->refresh()->recipe_id);
        $this->assertNull($wZniszczonym->refresh()->recipe_id);
        $this->assertNull($wZniszczonym->label);

        // Duplikat niedostępnego przepisu: komunikat nie wymienia tytułu.
        $drugi = $this->pozycja($ja, '2026-10-05', $zawezony);
        $this->przenies($ja, $drugi, '2026-10-02');
        $this->assertStringNotContainsString('Sekretny bigos', (string) $this->bladDnia());
        $this->assertSame('2026-10-05', $drugi->refresh()->day->toDateString());
    }

    public function test_przeniesienie_nie_rusza_innych_pozycji_ani_listy_zakupow(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($ja);
        $przenoszona = $this->pozycja($ja, '2026-09-30', $przepis);
        $zostaje = $this->pozycja($ja, '2026-09-30', tekst: 'Kolacja');
        $zakup = new ShoppingListItem(['text' => 'mleko']);
        $zakup->user_id = $ja->getKey();
        $zakup->source = ShoppingListItem::SOURCE_MANUAL;
        $zakup->position = 0;
        $zakup->save();
        $zakupPrzed = $zakup->refresh()->getAttributes();
        $zostajePrzed = $zostaje->refresh()->getAttributes();

        $this->przenies($ja, $przenoszona, '2026-10-02');

        $this->assertSame($zostajePrzed, $zostaje->refresh()->getAttributes());
        $this->assertSame($zakupPrzed, $zakup->refresh()->getAttributes());
        $this->assertSame(1, ShoppingListItem::query()->count());
    }

    public function test_przeniesienie_bierze_blokade_konta_przed_odczytem_pozycji_i_dnia_docelowego(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-09-30', tekst: 'Obiad u mamy');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->przenies($ja, $wpis, '2026-10-02');
        $zapytania = array_map(fn (array $q): string => strtolower($q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $blokadaKonta = null;
        $odczytPozycji = null;
        $zliczenie = null;
        foreach ($zapytania as $i => $sql) {
            if ($blokadaKonta === null && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
                $blokadaKonta = $i;
            }
            if ($odczytPozycji === null && str_contains($sql, 'from "meal_plan_entries"') && str_contains($sql, 'for update')) {
                $odczytPozycji = $i;
            }
            if ($zliczenie === null && str_contains($sql, 'count(*)') && str_contains($sql, 'meal_plan_entries')) {
                $zliczenie = $i;
            }
        }

        $this->assertNotNull($blokadaKonta, 'Brak blokady wiersza konta.');
        $this->assertNotNull($odczytPozycji, 'Pozycja musi być czytana pod blokadą.');
        $this->assertNotNull($zliczenie, 'Brak sprawdzenia limitu dnia.');
        $this->assertLessThan($odczytPozycji, $blokadaKonta);
        $this->assertLessThan($zliczenie, $odczytPozycji);
    }
}
