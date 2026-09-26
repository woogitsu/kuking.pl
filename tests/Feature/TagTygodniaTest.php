<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Tag;
use App\Models\TagHighlight;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tag tygodnia (issue #18): wyróżnienie zwykłego tagu na wybrane dni,
 * blok na `/home` i plan w panelu — za flagą domyślnie wyłączoną.
 *
 * @bez-kontroli-dodatniej base_path() służy tylko do wykonania pliku konfiguracji i odczytu domyślnej wartości flagi jako danych PHP; żadna asercja nie dotyczy tekstu źródła aplikacji.
 */
class TagTygodniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.strefa' => 'Europe/Warsaw', 'kuking.tag_tygodnia.wlaczony' => true]);
    }

    private function tag(string $slug, string $nazwa): Tag
    {
        return Tag::create(['slug' => $slug, 'name' => $nazwa, 'normalized_name' => mb_strtolower($nazwa)]);
    }

    private function wyroznij(Tag $tag, string $od, string $do, ?string $notatka = null): TagHighlight
    {
        return TagHighlight::create(['tag_id' => $tag->getKey(), 'starts_on' => $od, 'ends_on' => $do, 'note' => $notatka]);
    }

    public function test_domyslnie_flaga_jest_wylaczona_i_blok_sie_nie_pokazuje(): void
    {
        $domyslne = require base_path('config/kuking.php');
        $this->assertFalse($domyslne['tag_tygodnia']['wlaczony']);

        config(['kuking.tag_tygodnia.wlaczony' => false]);
        $this->travelTo('2026-11-18 12:00:00');
        $this->wyroznij($this->tag('pierogi', 'Pierogi'), '2026-11-16', '2026-11-22', 'Pokażcie swoje pierogi.');

        $this->actingAs($this->user('basia'))
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Tag tygodnia');
    }

    public function test_w_dniach_wyroznienia_home_pokazuje_blok_z_notatka_i_cta_z_tagiem(): void
    {
        $this->travelTo('2026-11-18 12:00:00');
        $this->wyroznij($this->tag('pierogi', 'Pierogi'), '2026-11-16', '2026-11-22', 'Pokażcie swoje pierogi.');

        $this->actingAs($this->user('basia'))
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Tag tygodnia: Pierogi')
            ->assertSee('Pokażcie swoje pierogi.')
            ->assertSee(route('posts.create', ['tag' => 'pierogi']), false);
    }

    public function test_po_ostatnim_dniu_blok_znika_a_strona_tagu_zostaje(): void
    {
        $tag = $this->tag('pierogi', 'Pierogi');
        $this->wyroznij($tag, '2026-11-16', '2026-11-22');
        $basia = $this->user('basia');

        // 23:30 w Warszawie 22 listopada — ostatni dzień jeszcze trwa, choć w UTC to 22:30.
        $this->travelTo('2026-11-22 22:30:00');
        $this->actingAs($basia)->get(route('home'))->assertSee('Tag tygodnia: Pierogi');

        // 0:30 w Warszawie 23 listopada.
        $this->travelTo('2026-11-22 23:30:00');
        $this->actingAs($basia)->get(route('home'))->assertDontSee('Tag tygodnia');
        $this->get(route('tags.show', $tag))->assertOk();
        $this->assertDatabaseCount('tag_highlights', 1);
    }

    public function test_ukryty_tag_nie_dostaje_bloku(): void
    {
        $this->travelTo('2026-11-18 12:00:00');
        $tag = $this->tag('pierogi', 'Pierogi');
        $this->wyroznij($tag, '2026-11-16', '2026-11-22');
        $tag->forceFill(['status' => Tag::STATUS_HIDDEN])->save();

        $this->assertNull(TagHighlight::doPokazania());
    }

    public function test_cta_zaznacza_tag_w_formularzu_wpisu(): void
    {
        $this->tag('pierogi', 'Pierogi');

        $this->actingAs($this->user('basia'))
            ->get(route('posts.create', ['tag' => 'pierogi']))
            ->assertOk()
            ->assertSee('Pierogi');
    }

    public function test_baza_nie_przyjmie_dwoch_nachodzacych_wyroznien(): void
    {
        $this->wyroznij($this->tag('pierogi', 'Pierogi'), '2026-11-16', '2026-11-22');

        $this->expectException(QueryException::class);
        $this->wyroznij($this->tag('bigos', 'Bigos'), '2026-11-22', '2026-11-29');
    }

    public function test_baza_nie_przyjmie_konca_przed_poczatkiem(): void
    {
        $this->expectException(QueryException::class);
        $this->wyroznij($this->tag('pierogi', 'Pierogi'), '2026-11-22', '2026-11-16');
    }

    public function test_ten_sam_tag_moze_wrocic_za_rok(): void
    {
        $tag = $this->tag('pierogi', 'Pierogi');
        $this->wyroznij($tag, '2026-11-16', '2026-11-22');
        $this->wyroznij($tag, '2027-11-15', '2027-11-21');

        $this->assertSame(2, DB::table('tag_highlights')->where('tag_id', $tag->getKey())->count());
    }

    public function test_moderator_planuje_kolejny_tag_bez_zmiany_kodu(): void
    {
        $this->tag('bigos', 'Bigos');

        $this->actingAs($this->moderator())
            ->post(route('admin.tag-highlights.store'), [
                'tag_tygodnia' => 'Bigos',
                'od_dnia' => '2026-11-23',
                'do_dnia' => '2026-11-29',
                'notatka_tygodnia' => '  Bigos na zimę.  ',
            ])
            ->assertRedirect(route('admin.tag-promotions'));

        $this->assertDatabaseHas('tag_highlights', ['note' => 'Bigos na zimę.']);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'tag_highlight.added')->count());
    }

    public function test_nachodzace_dni_wracaja_z_bledem_i_wpisanymi_danymi(): void
    {
        $this->wyroznij($this->tag('pierogi', 'Pierogi'), '2026-11-16', '2026-11-22');
        $this->tag('bigos', 'Bigos');

        $this->actingAs($this->moderator())
            ->from(route('admin.tag-promotions'))
            ->post(route('admin.tag-highlights.store'), [
                'tag_tygodnia' => 'Bigos',
                'od_dnia' => '2026-11-20',
                'do_dnia' => '2026-11-26',
                'notatka_tygodnia' => 'Bigos na zimę.',
            ])
            ->assertRedirect(route('admin.tag-promotions'))
            ->assertSessionHasErrors('od_dnia')
            ->assertSessionHasInput('notatka_tygodnia', 'Bigos na zimę.');

        $this->assertDatabaseCount('tag_highlights', 1);
    }

    public function test_koniec_przed_poczatkiem_to_blad_przy_polu_po_polsku(): void
    {
        $this->tag('bigos', 'Bigos');

        $this->actingAs($this->moderator())
            ->post(route('admin.tag-highlights.store'), [
                'tag_tygodnia' => 'Bigos',
                'od_dnia' => '2026-11-26',
                'do_dnia' => '2026-11-20',
            ])
            ->assertSessionHasErrors(['do_dnia' => 'Ostatni dzień nie może być wcześniej niż pierwszy. Popraw jedną z dat.']);
    }

    public function test_zwykly_uzytkownik_nie_planuje_tagu_tygodnia(): void
    {
        $this->tag('bigos', 'Bigos');

        $this->actingAs($this->user('basia'))
            ->post(route('admin.tag-highlights.store'), ['tag_tygodnia' => 'Bigos', 'od_dnia' => '2026-11-23', 'do_dnia' => '2026-11-29'])
            ->assertNotFound();

        $this->assertDatabaseCount('tag_highlights', 0);
    }

    public function test_przy_wylaczonej_fladze_panel_nie_przyjmuje_planu_i_nie_pokazuje_sekcji(): void
    {
        config(['kuking.tag_tygodnia.wlaczony' => false]);
        $this->tag('bigos', 'Bigos');
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->post(route('admin.tag-highlights.store'), ['tag_tygodnia' => 'Bigos', 'od_dnia' => '2026-11-23', 'do_dnia' => '2026-11-29'])
            ->assertNotFound();
        $this->actingAs($moderator)->get(route('admin.tag-promotions'))->assertOk()->assertDontSee('Zaplanuj tag tygodnia');
    }

    public function test_panel_pokazuje_formularz_i_archiwum_z_polskimi_datami(): void
    {
        $this->wyroznij($this->tag('pierogi', 'Pierogi'), '2026-11-16', '2026-11-22', 'Pokażcie swoje pierogi.');

        $this->actingAs($this->moderator())
            ->get(route('admin.tag-promotions'))
            ->assertOk()
            ->assertSee('Zaplanuj tag tygodnia')
            ->assertSee('od 16 listopada 2026', false)
            ->assertSee('do 22 listopada 2026', false)
            ->assertSee('Pokażcie swoje pierogi.');
    }

    public function test_usuniecie_wyroznienia_zostawia_tag(): void
    {
        $tag = $this->tag('pierogi', 'Pierogi');
        $wyroznienie = $this->wyroznij($tag, '2026-11-16', '2026-11-22');

        $this->actingAs($this->moderator())
            ->delete(route('admin.tag-highlights.destroy', $wyroznienie))
            ->assertRedirect(route('admin.tag-promotions'));

        $this->assertDatabaseCount('tag_highlights', 0);
        $this->assertDatabaseHas('tags', ['id' => $tag->getKey()]);
    }
}
