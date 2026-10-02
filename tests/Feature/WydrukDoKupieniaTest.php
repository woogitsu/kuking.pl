<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Wydrukuj do kupienia” (issue #2495, decyzja właściciela z 2.10.2026,
 * D-333): kartka z samymi nieodhaczonymi pozycjami WŁASNEJ listy, z pustym
 * kwadratem przy każdej, wiernym tekstem i kolejnością, datą odczytu i
 * zdaniem, że to kopia. Odczyt nic nie zapisuje.
 */
class WydrukDoKupieniaTest extends TestCase
{
    use RefreshDatabase;

    /** Dużo pozycji, ale poniżej limitu listy. */
    private const DUZO_POZYCJI = 40;

    private User $halina;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 14:30:00', 'UTC'));
        $this->halina = $this->user('halina');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pozycja(User $kto, string $tekst, int $miejsce, bool $odhaczona = false, ?Recipe $przepis = null): ShoppingListItem
    {
        $p = new ShoppingListItem(['text' => $tekst]);
        $p->user_id = $kto->getKey();
        $p->source = $przepis === null ? ShoppingListItem::SOURCE_MANUAL : ShoppingListItem::SOURCE_RECIPE;
        $p->recipe_id = $przepis?->getKey();
        $p->position = $miejsce;
        $p->checked_at = $odhaczona ? now() : null;
        $p->save();

        return $p;
    }

    /** @return list<string> */
    private function kartka(string $html): array
    {
        preg_match('/<article class="sciagawka kartka-zakupow.*?<\/article>/s', $html, $blok);
        preg_match_all('/<span class="kartka-zakupow-tekst">(.*?)<\/span>/s', $blok[0] ?? '', $m);

        return array_map(fn (string $t): string => html_entity_decode($t), $m[1]);
    }

    public function test_kartka_ma_tylko_nieodhaczone_pozycje_w_kolejnosci_listy_z_dokladnym_tekstem(): void
    {
        $this->pozycja($this->halina, '2 jajka', 0);
        $this->pozycja($this->halina, 'kupione mleko', 1, true);
        $this->pozycja($this->halina, '1/2 do 3/4 szklanki mąki', 2);
        $this->pozycja($this->halina, '2 jajka', 3);
        $this->pozycja($this->halina, 'szczypta soli i  podwójna spacja & <b>znaczniki</b>', 4);
        $this->pozycja($this->halina, 'około 1½ łyżki masła', 5);

        $html = $this->actingAs($this->halina)->get(route('shopping.print'))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertSame([
            '2 jajka',
            '1/2 do 3/4 szklanki mąki',
            '2 jajka',
            'szczypta soli i  podwójna spacja & <b>znaczniki</b>',
            'około 1½ łyżki masła',
        ], $this->kartka($html), 'bez parsowania, łączenia identycznych linii i odhaczonych');
        $this->assertStringNotContainsString('kupione mleko', $html);
        $this->assertStringNotContainsString('<b>znaczniki</b>', $html, 'tekst jest escapowany');
        $this->assertSame(5, substr_count($html, 'class="kartka-zakupow-pole"'), 'pusty kwadrat przy każdej pozycji');
    }

    public function test_kartka_ma_tytul_date_odczytu_zdanie_o_kopii_i_noindex(): void
    {
        $this->pozycja($this->halina, 'mleko', 0);

        $odpowiedz = $this->actingAs($this->halina)->get(route('shopping.print'))->assertOk();
        $html = $odpowiedz->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('<h2 id="kartka-zakupow-tytul">Do kupienia</h2>', $html);
        $this->assertStringContainsString('Stan z: 5 października 2026, 16:30.', $html);
        $this->assertStringContainsString('To kopia — nie zmienia się razem z listą.', $html);
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringContainsString('data-drukuj-przepis', $html);
        $this->assertStringContainsString('Wydrukuj kartkę', $html);
    }

    public function test_kartka_nie_ma_pochodzenia_adresow_przepisow_ani_danych_konta(): void
    {
        $przepis = Recipe::factory()->create(['title' => 'Tajemnicza zupa', 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $this->pozycja($this->halina, '2 pomidory', 0, false, $przepis);
        $this->pozycja($this->halina, 'pietruszka', 1);
        $przepis->forceFill(['visibility' => 'private'])->save();

        $html = $this->actingAs($this->halina)->get(route('shopping.print'))->assertOk()->getContent();

        $this->assertIsString($html);
        $kartka = (string) preg_replace('/^.*?(<article class="sciagawka kartka-zakupow)/s', '$1', $html);
        $kartka = (string) preg_replace('/<\/article>.*$/s', '', $kartka);
        $this->assertStringContainsString('2 pomidory', $kartka, 'zachowany tekst pozycji drukuje się także, gdy przepis jest niedostępny');
        $this->assertStringNotContainsString('Tajemnicza zupa', $html);
        $this->assertStringNotContainsString($przepis->slug, $html);
        $this->assertStringNotContainsString('<a ', $kartka);
        $this->assertStringNotContainsString('<form', $kartka);
        $this->assertStringNotContainsString('<button', $kartka);
        $this->assertStringNotContainsString('halina', $kartka);
    }

    public function test_pusta_lista_do_kupienia_daje_komunikat_i_nie_drukuje_odhaczonych(): void
    {
        $this->pozycja($this->halina, 'kupione mleko', 0, true);

        $html = $this->actingAs($this->halina)->get(route('shopping.print'))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('Na liście zakupów nie ma teraz nic do kupienia', $html);
        $this->assertStringNotContainsString('kupione mleko', $html);
        $this->assertStringNotContainsString('kartka-zakupow-lista', $html);
        $this->assertStringNotContainsString('data-drukuj-przepis', $html);
    }

    public function test_odczyt_niczego_nie_zmienia(): void
    {
        $this->pozycja($this->halina, 'mleko', 0);
        $this->pozycja($this->halina, 'chleb', 1, true);
        $przed = DB::table('shopping_list_items')->orderBy('position')->get()->toArray();
        $cofniec = DB::table('shopping_list_undos')->count();

        $this->actingAs($this->halina)->get(route('shopping.print'))->assertOk();
        $this->actingAs($this->halina)->get(route('shopping.print', ['druk' => 1]))->assertOk()->assertSee('Jak wydrukować tę kartkę');

        $this->assertEquals($przed, DB::table('shopping_list_items')->orderBy('position')->get()->toArray());
        $this->assertSame($cofniec, DB::table('shopping_list_undos')->count());
    }

    public function test_tylko_wlasciciel_widzi_swoja_liste_a_gosc_idzie_do_logowania(): void
    {
        $this->pozycja($this->halina, 'moje mleko', 0);
        $obca = $this->user('obca');
        $this->pozycja($obca, 'cudzy chleb', 0);

        $html = $this->actingAs($obca)->get(route('shopping.print'))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertSame(['cudzy chleb'], $this->kartka($html));
        $this->assertStringNotContainsString('moje mleko', $html);
        // Parametr w adresie nie wskazuje cudzej listy.
        $inny = $this->actingAs($obca)->get(route('shopping.print', ['user' => $this->halina->getKey(), 'user_id' => $this->halina->getKey()]))->getContent();
        $this->assertIsString($inny);
        $this->assertStringNotContainsString('moje mleko', $inny);

        auth()->logout();
        $this->get(route('shopping.print'))->assertRedirect(route('login'));
    }

    public function test_dluga_lista_i_dlugie_linie_sa_wszystkie_na_stronie_bez_ucinania(): void
    {
        $dluga = str_repeat('bardzo długa pozycja zakupowa ', 6).'KONIEC';
        $this->pozycja($this->halina, $dluga, 0);
        for ($i = 1; $i < self::DUZO_POZYCJI; $i++) {
            $this->pozycja($this->halina, "pozycja $i", $i);
        }

        $html = $this->actingAs($this->halina)->get(route('shopping.print'))->assertOk()->getContent();

        $this->assertIsString($html);
        $teksty = $this->kartka($html);
        $this->assertCount(self::DUZO_POZYCJI, $teksty);
        $this->assertSame($dluga, $teksty[0]);
        $this->assertStringContainsString('KONIEC', $html);
    }

    public function test_przycisk_na_liscie_jest_tylko_gdy_jest_co_do_kupienia(): void
    {
        $this->pozycja($this->halina, 'kupione', 0, true);
        $this->actingAs($this->halina)->get(route('shopping.index'))->assertOk()->assertDontSee('Wydrukuj do kupienia');

        $this->pozycja($this->halina, 'mleko', 1);
        $html = $this->actingAs($this->halina)->get(route('shopping.index'))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('>Wydrukuj do kupienia</a>', $html);
        $this->assertStringContainsString('href="'.route('shopping.print').'"', $html);
    }
}
