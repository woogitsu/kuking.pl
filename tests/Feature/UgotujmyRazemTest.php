<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\AuditLogEntry;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WeeklyRecipePick;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * „Ugotujmy razem” (F3): przepis tygodnia wybrany przez gospodarza, wykonania
 * z tego tygodnia po czasie, archiwum — i granice widoczności.
 *
 * Zegar jest zawsze przymrożony (`docs/PULAPKI_TESTOW.md` §9): tydzień
 * bieżący to 2026-W40 (28 września – 4 października 2026), chyba że test
 * mówi inaczej.
 */
class UgotujmyRazemTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.strefa' => 'Europe/Warsaw']);
        // Czwartek 1 października 2026, 12:00 czasu polskiego.
        $this->travelTo('2026-10-01 10:00:00');
    }

    private function przepis(string $tytul, array $atrybuty = []): Recipe
    {
        $autor = $atrybuty['author'] ?? $this->user(null, ['display_name' => 'Basia']);
        unset($atrybuty['author']);

        return Recipe::factory()->create(array_merge([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'slug' => str($tytul)->slug()->toString(),
        ], $atrybuty));
    }

    private function wybierz(Recipe $recipe, string $poniedzialek): WeeklyRecipePick
    {
        $pick = new WeeklyRecipePick;
        $pick->forceFill(['week_starts_on' => $poniedzialek, 'recipe_id' => $recipe->getKey()])->save();

        return $pick;
    }

    private function wykonanie(Recipe $recipe, string $kiedyUtc, string $notatka, ?User $kucharz = null): CookedEvent
    {
        return CookedEvent::factory()->create([
            'recipe_id' => $recipe->getKey(),
            'user_id' => ($kucharz ?? $this->user())->getKey(),
            'cooked_at' => $kiedyUtc,
            'note' => $notatka,
        ]);
    }

    private function tresc(string $url): string
    {
        return $this->trescEkranu((string) $this->get($url)->assertOk()->getContent());
    }

    public function test_gosc_widzi_przepis_tygodnia_i_wykonania_z_tego_tygodnia_od_najnowszego(): void
    {
        $golabki = $this->przepis('Gołąbki Basi');
        $inny = $this->przepis('Sernik');
        $this->wybierz($golabki, '2026-09-28');

        $this->wykonanie($golabki, '2026-09-28 09:00:00', 'Poniedziałkowe gołąbki');
        $this->wykonanie($golabki, '2026-09-30 16:00:00', 'Środowe gołąbki');
        $this->wykonanie($golabki, '2026-09-25 12:00:00', 'Gołąbki sprzed tygodnia');
        $this->wykonanie($inny, '2026-09-29 12:00:00', 'Sernik z tego tygodnia');

        $html = $this->tresc(route('ugotujmy-razem'));

        $this->assertStringContainsString('Wybór gospodarza', $html);
        $this->assertStringContainsString('Gołąbki Basi', $html);
        $this->assertStringContainsString(route('cooking.show', $golabki->slug), $html);
        $this->assertStringContainsString('Ugotuję w tym tygodniu', $html);

        // Chronologicznie, od najnowszego — i tylko ten przepis z tego tygodnia.
        $sroda = strpos($html, 'Środowe gołąbki');
        $poniedzialek = strpos($html, 'Poniedziałkowe gołąbki');
        $this->assertNotFalse($sroda);
        $this->assertNotFalse($poniedzialek);
        $this->assertLessThan($poniedzialek, $sroda);
        $this->assertStringNotContainsString('Gołąbki sprzed tygodnia', $html);
        $this->assertStringNotContainsString('Sernik z tego tygodnia', $html);

        // Bez liczników presji: żadnego „N wykonań” ani paska liczb spod przepisu.
        $this->assertStringNotContainsString('pasek-liczb', $html);
        $this->assertDoesNotMatchRegularExpression('/\d+\s+wykona(nie|nia|ń)\b/u', $html);
    }

    public function test_kolejnosc_to_czas_a_nie_wczesniejszy_zapis_w_bazie(): void
    {
        // Kontrola na „przypadkową” kolejność wstawiania: najnowsze wykonanie
        // wchodzi do bazy PIERWSZE, a i tak stoi na górze.
        $golabki = $this->przepis('Gołąbki Basi');
        $this->wybierz($golabki, '2026-09-28');

        $this->wykonanie($golabki, '2026-10-01 08:00:00', 'Czwartkowe gołąbki');
        $this->wykonanie($golabki, '2026-09-29 08:00:00', 'Wtorkowe gołąbki');

        $html = $this->tresc(route('ugotujmy-razem'));

        $this->assertLessThan(strpos($html, 'Wtorkowe gołąbki'), strpos($html, 'Czwartkowe gołąbki'));
    }

    public function test_granica_tygodnia_liczy_sie_w_polskiej_strefie(): void
    {
        $poprzedni = $this->przepis('Pierogi ruskie');
        $biezacy = $this->przepis('Gołąbki Basi');
        $this->wybierz($poprzedni, '2026-09-21');
        $this->wybierz($biezacy, '2026-09-28');

        // Niedziela 27.09, 23:30 czasu polskiego — w UTC 21:30 tego samego dnia.
        $this->wykonanie($biezacy, '2026-09-27 21:30:00', 'Niedziela przed północą');
        // Poniedziałek 28.09, 0:10 czasu polskiego — w UTC jeszcze niedziela 22:10.
        $this->wykonanie($biezacy, '2026-09-27 22:10:00', 'Poniedziałek tuż po północy');

        $html = $this->tresc(route('ugotujmy-razem'));
        $this->assertStringContainsString('Poniedziałek tuż po północy', $html);
        $this->assertStringNotContainsString('Niedziela przed północą', $html);

        // Który tydzień jest „ten”, też rozstrzyga polski kalendarz.
        $this->travelTo('2026-09-27 21:30:00'); // niedziela 23:30 w Polsce
        $this->assertStringContainsString('Pierogi ruskie', $this->tresc(route('ugotujmy-razem')));

        $this->travelTo('2026-09-27 22:30:00'); // poniedziałek 0:30 w Polsce, w UTC jeszcze niedziela
        $html = $this->tresc(route('ugotujmy-razem'));
        $this->assertStringContainsString('W tym tygodniu gotujemy razem', $html);
        $this->assertStringContainsString('Gołąbki Basi', $html);
    }

    public function test_bez_wyboru_strona_mowi_co_zrobic_a_odkrywanie_nie_ma_odnosnika(): void
    {
        $html = $this->tresc(route('ugotujmy-razem'));

        $this->assertStringContainsString('W tym tygodniu nie ma jeszcze wspólnego przepisu', $html);
        $this->assertStringContainsString(route('discover'), $html);

        $odkrywanie = $this->trescEkranu((string) $this->get(route('discover'))->assertOk()->getContent());
        $this->assertStringNotContainsString('data-ugotujmy-razem', $odkrywanie);
    }

    public function test_odkrywanie_prowadzi_do_przepisu_tygodnia(): void
    {
        $this->wybierz($this->przepis('Gołąbki Basi'), '2026-09-28');

        $odkrywanie = $this->trescEkranu((string) $this->get(route('discover'))->assertOk()->getContent());

        $this->assertStringContainsString('data-ugotujmy-razem', $odkrywanie);
        $this->assertStringContainsString(route('ugotujmy-razem'), $odkrywanie);
        $this->assertStringContainsString('Ugotujmy razem: Gołąbki Basi', $odkrywanie);
    }

    public function test_przyszly_tydzien_nie_wychodzi_przed_czasem(): void
    {
        $this->wybierz($this->przepis('Bigos na później'), '2026-10-05');

        $this->assertStringNotContainsString('Bigos na później', $this->tresc(route('ugotujmy-razem')));
        $this->get(route('ugotujmy-razem.tydzien', '2026-W41'))->assertNotFound();
        $this->actingAs($this->moderator())->get(route('ugotujmy-razem.tydzien', '2026-W41'))->assertNotFound();
    }

    public function test_archiwum_pokazuje_poprzednie_tygodnie_z_ich_wykonaniami(): void
    {
        $pierogi = $this->przepis('Pierogi ruskie');
        $this->wybierz($pierogi, '2026-09-21');
        $this->wybierz($this->przepis('Gołąbki Basi'), '2026-09-28');

        $this->wykonanie($pierogi, '2026-09-23 12:00:00', 'Pierogi w środę');
        $this->wykonanie($pierogi, '2026-09-29 12:00:00', 'Pierogi już po tygodniu');

        $html = $this->tresc(route('ugotujmy-razem'));
        $this->assertStringContainsString('Poprzednie tygodnie', $html);
        $this->assertStringContainsString(route('ugotujmy-razem.tydzien', '2026-W39'), $html);

        $tydzien = $this->tresc(route('ugotujmy-razem.tydzien', '2026-W39'));
        $this->assertStringContainsString('To jest archiwum', $tydzien);
        $this->assertStringContainsString('Pierogi w środę', $tydzien);
        $this->assertStringNotContainsString('Pierogi już po tygodniu', $tydzien);
        // Minione tygodnie nie zapraszają do gotowania „w tym tygodniu”.
        $this->assertStringNotContainsString('Ugotuję w tym tygodniu', $tydzien);

        // Tydzień bieżący pod adresem archiwum wraca na stronę główną funkcji.
        $this->get(route('ugotujmy-razem.tydzien', '2026-W40'))->assertRedirect(route('ugotujmy-razem'));
        // Tydzień bez wyboru i tydzień, którego nie ma w kalendarzu, to 404.
        $this->get(route('ugotujmy-razem.tydzien', '2026-W30'))->assertNotFound();
        $this->get(route('ugotujmy-razem.tydzien', '2025-W53'))->assertNotFound();
    }

    public function test_przepis_ukryty_albo_juz_nie_publiczny_znika_dla_kazdego(): void
    {
        $autor = $this->user(null, ['display_name' => 'Basia']);
        $obserwujaca = $this->user();
        DB::table('follows')->insert(['follower_id' => $obserwujaca->getKey(), 'followed_id' => $autor->getKey(), 'created_at' => now()]);

        $ukryty = $this->przepis('Przepis ukryty przez moderację', ['author' => $autor]);
        $this->wybierz($ukryty, '2026-09-28');
        $dlaObserwujacych = $this->przepis('Przepis dla obserwujących', ['author' => $autor]);
        $this->wybierz($dlaObserwujacych, '2026-09-21');

        $ukryty->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();
        $dlaObserwujacych->forceFill(['visibility' => 'followers'])->save();

        foreach ([null, $obserwujaca, $autor, $this->moderator()] as $widz) {
            if ($widz !== null) {
                $this->actingAs($widz);
            }

            $html = $this->tresc(route('ugotujmy-razem'));
            $this->assertStringNotContainsString('Przepis ukryty przez moderację', $html);
            $this->assertStringNotContainsString('Przepis dla obserwujących', $html);
            $this->get(route('ugotujmy-razem.tydzien', '2026-W39'))->assertNotFound();
        }
    }

    public function test_blokada_z_autorem_zamyka_przepis_tygodnia_a_blokada_z_kucharzem_jego_wykonanie(): void
    {
        $autor = $this->user(null, ['display_name' => 'Basia']);
        $widz = $this->user();
        $kucharz = $this->user(null, ['display_name' => 'Zablokowany kucharz']);

        $golabki = $this->przepis('Gołąbki Basi', ['author' => $autor]);
        $this->wybierz($golabki, '2026-09-28');
        $this->wykonanie($golabki, '2026-09-29 12:00:00', 'Od osoby zablokowanej', $kucharz);
        $this->wykonanie($golabki, '2026-09-29 13:00:00', 'Od zwykłej osoby');

        app(BlockUser::class)->handle($widz, $kucharz);
        $this->actingAs($widz);
        $html = $this->tresc(route('ugotujmy-razem'));
        $this->assertStringContainsString('Od zwykłej osoby', $html);
        $this->assertStringNotContainsString('Od osoby zablokowanej', $html);

        app(BlockUser::class)->handle($autor, $widz);
        $html = $this->tresc(route('ugotujmy-razem'));
        $this->assertStringNotContainsString('Gołąbki Basi', $html);
        $this->assertStringContainsString('W tym tygodniu nie ma jeszcze wspólnego przepisu', $html);
    }

    public function test_wykonanie_zbanowanej_osoby_znika_jak_pod_przepisem(): void
    {
        $golabki = $this->przepis('Gołąbki Basi');
        $this->wybierz($golabki, '2026-09-28');
        $zbanowany = $this->user();
        $this->wykonanie($golabki, '2026-09-29 12:00:00', 'Od osoby zbanowanej', $zbanowany);
        $this->wykonanie($golabki, '2026-09-29 13:00:00', 'Od zwykłej osoby');

        $zbanowany->ban();

        $html = $this->tresc(route('ugotujmy-razem'));
        $this->assertStringContainsString('Od zwykłej osoby', $html);
        $this->assertStringNotContainsString('Od osoby zbanowanej', $html);
    }

    public function test_zwykla_osoba_nie_wybiera_przepisu_tygodnia(): void
    {
        $golabki = $this->przepis('Gołąbki Basi');
        $zwykla = $this->user();

        // Panel dla zwykłego konta nie istnieje (middleware `moderator` → 404).
        $this->actingAs($zwykla)->get(route('admin.ugotujmy-razem'))->assertNotFound();
        $this->actingAs($zwykla)
            ->post(route('admin.ugotujmy-razem.store'), ['tydzien' => '2026-W40', 'przepis' => $golabki->slug])
            ->assertNotFound();

        $this->assertSame(0, WeeklyRecipePick::query()->count());
    }

    public function test_gospodarz_wybiera_przepis_z_adresu_i_zostaje_slad_w_dzienniku(): void
    {
        $golabki = $this->przepis('Gołąbki Basi');
        $pierogi = $this->przepis('Pierogi ruskie');
        $gospodarz = $this->moderator();

        $formularz = $this->actingAs($gospodarz)->get(route('admin.ugotujmy-razem'))->assertOk()->getContent();
        $this->assertStringContainsString('<label for="f-tydzien">', (string) $formularz);
        $this->assertStringContainsString('value="2026-W40"', (string) $formularz);
        // Zakończony tydzień nie jest do wyboru.
        $this->assertStringNotContainsString('value="2026-W39"', (string) $formularz);

        $this->post(route('admin.ugotujmy-razem.store'), [
            'tydzien' => '2026-W40',
            'przepis' => 'https://kuking.pl/przepisy/'.$golabki->slug.'?porcje=4',
        ])->assertRedirect(route('admin.ugotujmy-razem'));

        $pick = WeeklyRecipePick::query()->sole();
        $this->assertSame('2026-09-28', $pick->week_starts_on->toDateString());
        $this->assertSame($golabki->getKey(), $pick->recipe_id);
        $this->assertSame($gospodarz->getKey(), $pick->chosen_by);

        // Zmiana przepisu na ten sam tydzień zastępuje wybór i zapisuje poprzedni.
        $this->post(route('admin.ugotujmy-razem.store'), ['tydzien' => '2026-W40', 'przepis' => $pierogi->slug])
            ->assertRedirect(route('admin.ugotujmy-razem'));

        $this->assertSame($pierogi->getKey(), WeeklyRecipePick::query()->sole()->recipe_id);

        $wpisy = AuditLogEntry::query()->where('action', 'ugotujmy_razem.chosen')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $wpisy);
        $this->assertSame($gospodarz->getKey(), $wpisy->last()->actor_id);
        $this->assertSame('2026-W40', $wpisy->last()->metadata['tydzien']);
        $this->assertSame($golabki->getKey(), $wpisy->last()->metadata['poprzedni_przepis']);
    }

    public function test_gospodarz_nie_wybierze_przepisu_niepublicznego_a_wpisany_adres_zostaje(): void
    {
        $prywatny = $this->przepis('Przepis prywatny', ['visibility' => 'private']);

        $html = (string) $this->actingAs($this->moderator())
            ->from(route('admin.ugotujmy-razem'))
            ->followingRedirects()
            ->post(route('admin.ugotujmy-razem.store'), ['tydzien' => '2026-W40', 'przepis' => $prywatny->slug])
            ->assertOk()
            ->getContent();

        $this->assertSame(0, WeeklyRecipePick::query()->count());

        // Błąd przy polu ORAZ w podsumowaniu, a wpisany adres zostaje (UX 50+).
        $this->assertSame(2, substr_count($html, 'Wybierz opublikowany przepis widoczny dla wszystkich'));
        $this->assertStringContainsString('value="'.$prywatny->slug.'"', $html);

        $this->post(route('admin.ugotujmy-razem.store'), ['tydzien' => '2026-W39', 'przepis' => $prywatny->slug])
            ->assertSessionHasErrors('tydzien');
        $this->post(route('admin.ugotujmy-razem.store'), ['tydzien' => '2026-W40', 'przepis' => 'nie-ma-takiego'])
            ->assertSessionHasErrors('przepis');
    }

    public function test_zdjecie_wyboru_wymaga_potwierdzenia_i_nie_rusza_archiwum(): void
    {
        $gospodarz = $this->moderator();
        $biezacy = $this->wybierz($this->przepis('Gołąbki Basi'), '2026-09-28');
        $miniony = $this->wybierz($this->przepis('Pierogi ruskie'), '2026-09-21');

        $this->actingAs($gospodarz)->delete(route('admin.ugotujmy-razem.destroy', $biezacy));
        $this->assertModelExists($biezacy);

        $this->delete(route('admin.ugotujmy-razem.destroy', $miniony), ['potwierdzam' => '1'])
            ->assertSessionHasErrors('tydzien');
        $this->assertModelExists($miniony);

        $this->delete(route('admin.ugotujmy-razem.destroy', $biezacy), ['potwierdzam' => '1'])
            ->assertRedirect(route('admin.ugotujmy-razem'));
        $this->assertModelMissing($biezacy);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'ugotujmy_razem.removed')->count());
    }

    public function test_baza_pilnuje_jednego_przepisu_na_tydzien_i_poniedzialku(): void
    {
        $this->wybierz($this->przepis('Gołąbki Basi'), '2026-09-28');

        try {
            DB::transaction(fn () => $this->wybierz($this->przepis('Drugi na ten sam tydzień'), '2026-09-28'));
            $this->fail('Baza przyjęła drugi przepis na ten sam tydzień.');
        } catch (UniqueConstraintViolationException) {
        }

        try {
            DB::transaction(fn () => $this->wybierz($this->przepis('Środa zamiast poniedziałku'), '2026-09-30'));
            $this->fail('Baza przyjęła tydzień zaczynający się w środę.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('weekly_recipe_picks_poniedzialek_check', $e->getMessage());
        }
    }
}
