<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\PlikKalendarza;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plik iCalendar z wybranych pozycji planera (#2529, V2, D-333 — paczka E).
 *
 * Czas zamrożony na czwartek 1 października 2026 (tydzień 28.09–04.10).
 * Pomiary idą przez HTTP i treść prawdziwej odpowiedzi.
 */
final class PlanerPlikKalendarzaTest extends TestCase
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

    private function przepis(User $autor, string $tytul, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'title' => $tytul,
            ...$atrybuty,
        ]);
    }

    /** @param  list<MealPlanEntry>  $wpisy */
    private function pobierz(User $kto, array $wpisy, string $tydzien = '2026-09-28')
    {
        return $this->actingAs($kto)->post(route('planer.calendar.download'), [
            'tydzien' => $tydzien,
            'wpisy' => array_map(fn (MealPlanEntry $w): string => (string) $w->getKey(), $wpisy),
        ]);
    }

    /** @return list<string> */
    private function linie(string $tresc): array
    {
        // Rozwijanie złożonych linii (RFC 5545 §3.1): CRLF + spacja = ciągłość.
        return explode("\r\n", rtrim(str_replace("\r\n ", '', $tresc), "\r\n"));
    }

    public function test_gosc_nie_dostaje_ani_ekranu_ani_pliku(): void
    {
        $this->get(route('planer.calendar'))->assertRedirect(route('login'));
        $this->post(route('planer.calendar.download'), ['tydzien' => '2026-09-28', 'wpisy' => []])->assertRedirect(route('login'));
    }

    public function test_ekran_pokazuje_dokladnie_nazwy_i_daty_oraz_ostrzega_ze_to_kopia(): void
    {
        $ja = $this->user('planujaca');
        $this->pozycja($ja, '2026-10-01', $this->przepis($ja, 'Zupa ogórkowa'));
        $this->pozycja($ja, '2026-10-02', null, 'Obiad u mamy');

        $html = (string) $this->actingAs($ja)->get(route('planer.calendar', ['tydzien' => '2026-10-01']))->assertOk()->getContent();

        $this->assertStringContainsString('Czwartek, 1 października 2026 — <strong>Zupa ogórkowa</strong>', $html);
        $this->assertStringContainsString('Piątek, 2 października 2026 — <strong>Obiad u mamy</strong>', $html);
        $this->assertStringContainsString('(własny wpis)', $html);
        $this->assertStringContainsString('jednorazowa kopia', $html);
        $this->assertStringContainsString('może trafić do chmury', $html);
        $this->assertStringContainsString('się nie zmieni ani nie zniknie', $html);
        $this->assertStringContainsString('Nie obiecujemy', $html);
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_plik_ma_naglowki_crlf_wydarzenia_calodniowe_i_stabilny_uid(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->pozycja($ja, '2026-10-01', $this->przepis($ja, 'Zupa ogórkowa'));
        $wlasny = $this->pozycja($ja, '2026-10-02', null, 'Obiad u mamy');

        $odpowiedz = $this->pobierz($ja, [$zupa, $wlasny])->assertOk();
        $tresc = (string) $odpowiedz->getContent();

        $this->assertStringStartsWith('text/calendar; charset=utf-8', (string) $odpowiedz->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="kuking-plan-2026-09-28.ics"', (string) $odpowiedz->headers->get('Content-Disposition'));
        $cache = (string) $odpowiedz->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('public', $cache);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $tresc);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $tresc);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $tresc), 'PLIK_2529_CRLF: w pliku jest goły LF.');

        $linie = $this->linie($tresc);
        $this->assertContains('DTSTART;VALUE=DATE:20261001', $linie);
        $this->assertContains('DTEND;VALUE=DATE:20261002', $linie);
        $this->assertContains('DTSTART;VALUE=DATE:20261002', $linie);
        $this->assertContains('SUMMARY:Zupa ogórkowa', $linie);
        $this->assertContains('SUMMARY:Obiad u mamy', $linie);
        $this->assertContains('UID:plan-'.$zupa->getKey().'@kuking.pl', $linie);
        $this->assertContains('DTSTAMP:20261001T080000Z', $linie);
        $this->assertSame(2, count(array_filter($linie, fn (string $l): bool => $l === 'BEGIN:VEVENT')));

        // Ponowne pobranie daje te same UID.
        $drugi = $this->linie((string) $this->pobierz($ja, [$zupa])->getContent());
        $this->assertContains('UID:plan-'.$zupa->getKey().'@kuking.pl', $drugi);
    }

    public function test_nie_ma_alarmow_skladnikow_krokow_dopiskow_ani_adresow(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($ja, 'Pierogi');
        RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => 'mąka tajny składnik', 'position' => 0]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'instruction' => 'tajny krok', 'position' => 0]);
        $wpis = $this->pozycja($ja, '2026-10-01', $przepis);
        $wpis->forceFill(['note' => 'prywatny dopisek'])->save();

        $tresc = (string) $this->pobierz($ja, [$wpis])->getContent();

        foreach (['VALARM', 'tajny', 'prywatny dopisek', 'http', 'ATTENDEE', 'ORGANIZER', '@example', $ja->email] as $zakazane) {
            $this->assertStringNotContainsString($zakazane, $tresc, "Plik zawiera „{$zakazane}”.");
        }
        $this->assertStringContainsString('SUMMARY:Pierogi', $tresc);
    }

    public function test_granica_roku_i_dlugi_miesiac_nie_przesuwaja_dat(): void
    {
        $ja = $this->user('planujaca');
        $sylwester = $this->pozycja($ja, '2026-12-31', null, 'Sylwester');
        $luty = $this->pozycja($ja, '2028-02-28', null, 'Przestępny');

        $linie = $this->linie((string) $this->pobierz($ja, [$sylwester], '2026-12-28')->getContent());
        $this->assertContains('DTSTART;VALUE=DATE:20261231', $linie);
        $this->assertContains('DTEND;VALUE=DATE:20270101', $linie);

        $linie = $this->linie((string) $this->pobierz($ja, [$luty], '2028-02-28')->getContent());
        $this->assertContains('DTEND;VALUE=DATE:20280229', $linie);
    }

    public function test_tekst_ze_znakami_specjalnymi_i_nowa_linia_nie_wstrzykuje_wlasciwosci(): void
    {
        $ja = $this->user('planujaca');
        $zly = $this->pozycja($ja, '2026-10-01', null, 'x');
        DB::table('meal_plan_entries')->where('id', $zly->getKey())->update([
            'label' => "Obiad, \\ średnik; i\r\nEND:VEVENT\r\nBEGIN:VEVENT\nSUMMARY:Podmieniony",
        ]);

        $tresc = (string) $this->pobierz($ja, [$zly])->getContent();
        $linie = $this->linie($tresc);

        $this->assertSame(1, count(array_filter($linie, fn (string $l): bool => $l === 'BEGIN:VEVENT')), 'PLIK_2529_ESCAPING: nowa linia otworzyła drugie wydarzenie.');
        $this->assertSame(1, count(array_filter($linie, fn (string $l): bool => str_starts_with($l, 'SUMMARY'))), 'PLIK_2529_ESCAPING: nowa linia dodała właściwość SUMMARY.');
        $this->assertContains('SUMMARY:Obiad\\, \\\\ średnik\\; i\\nEND:VEVENT\\nBEGIN:VEVENT\\nSUMMARY:Podmieniony', $linie);
    }

    public function test_dlugie_linie_sa_skladane_po_75_oktetow_bez_rozcinania_znakow(): void
    {
        $ja = $this->user('planujaca');
        $nazwa = str_repeat('Żółć gęślą jaźń ', 7);
        $wpis = $this->pozycja($ja, '2026-10-01', null, trim($nazwa));

        $tresc = (string) $this->pobierz($ja, [$wpis])->getContent();

        foreach (explode("\r\n", rtrim($tresc, "\r\n")) as $fizyczna) {
            $this->assertLessThanOrEqual(75, strlen($fizyczna), 'PLIK_2529_SKLADANIE: linia dłuższa niż 75 oktetów.');
            $this->assertTrue(mb_check_encoding($fizyczna, 'UTF-8'), 'PLIK_2529_SKLADANIE: rozcięty znak UTF-8.');
        }
        $this->assertContains('SUMMARY:'.trim($nazwa), $this->linie($tresc));
    }

    public function test_zloz_linie_krotka_zostaje_a_dlugi_ascii_ma_wiersze_po_75_i_74_plus_spacja(): void
    {
        $this->assertSame('SUMMARY:krotko', PlikKalendarza::zlozLinie('SUMMARY:krotko'));

        $zlozona = PlikKalendarza::zlozLinie('X:'.str_repeat('a', 200));
        $wiersze = explode("\r\n", $zlozona);
        $this->assertSame(75, strlen($wiersze[0]));
        $this->assertSame(75, strlen($wiersze[1]));
        $this->assertStringStartsWith(' ', $wiersze[1]);
        $this->assertSame('X:'.str_repeat('a', 200), str_replace("\r\n ", '', $zlozona));
    }

    public function test_pusty_wybor_daje_blad_po_polsku_a_nie_plik(): void
    {
        $ja = $this->user('planujaca');
        $this->pozycja($ja, '2026-10-01', null, 'Obiad');

        $this->actingAs($ja)->from(route('planer.calendar'))->post(route('planer.calendar.download'), ['tydzien' => '2026-09-28'])
            ->assertRedirect(route('planer.calendar'))
            ->assertSessionHasErrors(['wpisy' => 'Zaznacz co najmniej jedną pozycję, którą chcesz zapisać w pliku kalendarza.']);
    }

    public function test_cudza_pozycja_to_odmowa_i_zaden_plik(): void
    {
        $ja = $this->user('planujaca');
        $obca = $this->user('obca');
        $moja = $this->pozycja($ja, '2026-10-01', null, 'Moje');
        $cudza = $this->pozycja($obca, '2026-10-01', null, 'Cudze tajne');

        $odpowiedz = $this->pobierz($ja, [$moja, $cudza]);

        $this->assertSame(403, $odpowiedz->getStatusCode(), 'PLIK_2529_CUDZA_POZYCJA: cudzy identyfikator dał plik.');
        $this->assertStringNotContainsString('Cudze tajne', (string) $odpowiedz->getContent());
    }

    public function test_pozycja_z_innego_tygodnia_niz_wybrany_nie_trafia_do_pliku(): void
    {
        $ja = $this->user('planujaca');
        $inny = $this->pozycja($ja, '2026-10-08', null, 'Za tydzień');

        $this->pobierz($ja, [$inny], '2026-09-28')->assertSessionHasErrors('wpisy');
    }

    public function test_przepis_ktory_przestal_byc_widoczny_nie_daje_tytulu_w_podgladzie_ani_w_pliku(): void
    {
        $ja = $this->user('planujaca');
        $autorka = $this->user('autorka');
        $przepis = $this->przepis($autorka, 'Tajny sernik');
        $wpis = $this->pozycja($ja, '2026-10-01', $przepis);
        $przepis->forceFill(['visibility' => 'private'])->save();

        $ekran = (string) $this->actingAs($ja)->get(route('planer.calendar', ['tydzien' => '2026-10-01']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Tajny sernik', $ekran);
        $this->assertStringContainsString('przepis jest już niedostępny', $ekran);

        $odpowiedz = $this->pobierz($ja, [$wpis]);
        $odpowiedz->assertSessionHasErrors('wpisy');
        $this->assertStringNotContainsString('Tajny sernik', (string) $odpowiedz->getContent());
    }

    public function test_pusty_tydzien_mowi_co_zrobic(): void
    {
        $html = (string) $this->actingAs($this->user('planujaca'))->get(route('planer.calendar', ['tydzien' => '2026-10-01']))->assertOk()->getContent();

        $this->assertStringContainsString('nie ma jeszcze żadnych pozycji w planie', $html);
        $this->assertStringNotContainsString('Pobierz plik kalendarza', $html);
    }

    public function test_nie_ma_publicznego_stalego_adresu_do_subskrypcji(): void
    {
        $ja = $this->user('planujaca');
        $this->pozycja($ja, '2026-10-01', null, 'Obiad');

        foreach (['/planer/kalendarz.ics', '/planer/kalendarz/'.$ja->getKey().'.ics', '/kalendarz.ics'] as $adres) {
            $this->get($adres)->assertNotFound();
            $this->actingAs($ja)->get($adres)->assertNotFound();
        }
        // Ten sam adres jako GET to tylko ekran wyboru (HTML), nigdy plik — plik daje wyłącznie POST z CSRF.
        $ekran = $this->actingAs($ja)->get(route('planer.calendar.download'));
        $this->assertStringStartsWith('text/html', (string) $ekran->headers->get('Content-Type'));
        $this->assertStringNotContainsString('BEGIN:VCALENDAR', (string) $ekran->getContent());
    }

    public function test_planer_ma_przycisk_do_kalendarza(): void
    {
        $html = (string) $this->actingAs($this->user('planujaca'))->get(route('planer.show'))->assertOk()->getContent();

        $this->assertStringContainsString(route('planer.calendar', ['tydzien' => '2026-09-28']), $html);
        $this->assertStringContainsString('Plan do kalendarza (plik)', $html);
    }
}
