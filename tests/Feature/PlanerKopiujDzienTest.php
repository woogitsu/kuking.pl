<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\Actions\SkopiujDzienPlanu;
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
 * „Skopiuj ten dzień” w Planerze (#2494, V2): podgląd i kopia zestawu jednego
 * dnia na inną datę.
 *
 * Czas zamrożony na czwartek 1 października 2026 (okno planera: od
 * 2026-08-02 do 2027-10-01). Pomiary idą przez HTTP i końcowy HTML.
 */
final class PlanerKopiujDzienTest extends TestCase
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

    private function bladCelu(): string
    {
        $bledy = session('errors');
        $bag = $bledy instanceof ViewErrorBag
            ? $bledy->getBag('default')
            : new MessageBag((array) ($bledy['default']['messages'] ?? []));

        return (string) $bag->first('cel');
    }

    /** Odcisk z podglądu — tak jak go widzi formularz. */
    private function odcisk(User $kto, string $zrodlo, string $cel): string
    {
        return app(SkopiujDzienPlanu::class)->ocen($kto, CarbonImmutable::parse($zrodlo), CarbonImmutable::parse($cel))['odcisk'];
    }

    private function skopiuj(User $kto, string $zrodlo, string $cel, ?string $odcisk = null)
    {
        return $this->actingAs($kto)->post(route('planer.copyday.store'), [
            'dzien' => $zrodlo,
            'cel' => $cel,
            'odcisk' => $odcisk ?? $this->odcisk($kto, $zrodlo, $cel),
        ]);
    }

    public function test_kopia_dnia_dopisuje_przepisy_i_wlasne_wpisy_z_nowymi_identyfikatorami_a_zrodlo_zostaje(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->pozycja($ja, '2026-10-04', $this->przepis($ja, ['title' => 'Zupa ogorkowa']));
        $glowne = $this->pozycja($ja, '2026-10-04', tekst: 'Obiad u mamy');
        $zupa->forceFill(['done_at' => now()])->saveQuietly();
        $zostaje = $this->pozycja($ja, '2026-10-05', tekst: 'Kolacja');
        $zrodloPrzed = [$zupa->refresh()->getAttributes(), $glowne->refresh()->getAttributes()];
        $kolacjaPrzed = $zostaje->refresh()->getAttributes();

        $this->skopiuj($ja, '2026-10-04', '2026-10-18')
            ->assertRedirect(route('planer.show', ['tydzien' => '2026-10-12']).'#dzien-2026-10-18')
            ->assertSessionHas('status', 'Skopiowane na niedzielę, 18 października: 2 pozycje. Dzień, z którego kopiowano, został bez zmian.');

        $this->assertSame($zrodloPrzed[0], $zupa->refresh()->getAttributes());
        $this->assertSame($zrodloPrzed[1], $glowne->refresh()->getAttributes());
        $this->assertSame($kolacjaPrzed, $zostaje->refresh()->getAttributes());

        $kopie = MealPlanEntry::query()->where('day', '2026-10-18')->get();
        $this->assertCount(2, $kopie);
        $this->assertEqualsCanonicalizing([$zupa->recipe_id, null], $kopie->pluck('recipe_id')->all());
        $this->assertEqualsCanonicalizing([null, 'Obiad u mamy'], $kopie->pluck('label')->all());
        foreach ($kopie as $kopia) {
            $this->assertNotContains($kopia->getKey(), [$zupa->getKey(), $glowne->getKey()]);
            $this->assertNull($kopia->done_at, 'Kopia nie udaje wykonania.');
        }
        $this->assertSame(2, MealPlanEntry::query()->where('day', '2026-10-04')->count());
        $this->assertSame(5, MealPlanEntry::query()->count());
    }

    public function test_podglad_pokazuje_nowe_duplikaty_i_niedostepne_bez_tytulow_niedostepnych(): void
    {
        $ja = $this->user('planujaca');
        $autorka = $this->user('kucharka');
        $zupa = $this->przepis($ja, ['title' => 'Zupa ogorkowa']);
        $sekret = $this->przepis($autorka, ['title' => 'Sekretny bigos']);
        $zniszczony = $this->przepis($autorka, ['title' => 'Kasza skasowana twardo']);
        $this->pozycja($ja, '2026-10-04', $zupa);
        $this->pozycja($ja, '2026-10-04', tekst: 'Obiad u mamy');
        $this->pozycja($ja, '2026-10-04', tekst: 'Ciasto');
        $this->pozycja($ja, '2026-10-04', $sekret);
        $this->pozycja($ja, '2026-10-04', $zniszczony);
        $sekret->forceFill(['visibility' => 'private'])->save();
        $zniszczony->forceDelete();
        // Cel ma już zupę — ten przepis nie powstanie drugi raz.
        $this->pozycja($ja, '2026-10-11', $zupa);

        $html = (string) $this->actingAs($ja)
            ->get(route('planer.copyday', ['dzien' => '2026-10-04', 'cel' => '2026-10-11']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Nowych pozycji: <strong>2</strong>', $html);
        $this->assertStringContainsString('Już w dniu docelowym: 1', $html);
        $this->assertStringContainsString('Pominięte (przepis niedostępny): 2', $html);
        $this->assertStringContainsString('Zupa ogorkowa', $html);
        $this->assertStringContainsString('Obiad u mamy', $html);
        $this->assertStringNotContainsString('Sekretny bigos', $html);
        $this->assertStringNotContainsString('Kasza skasowana', $html);
        $this->assertStringContainsString('Skopiuj na niedzielę, 11 października', $html);
        $this->assertMatchesRegularExpression('~<form[^>]*novalidate[^>]*>~', $html);
        $this->assertStringContainsString('<label for="f-cel">', $html);
        // Sam podgląd niczego nie zapisuje.
        $this->assertSame(1, MealPlanEntry::query()->where('day', '2026-10-11')->count());

        $this->skopiuj($ja, '2026-10-04', '2026-10-11');
        $this->assertSame(['Ciasto', 'Obiad u mamy', null], MealPlanEntry::query()->where('day', '2026-10-11')->orderBy('label')->pluck('label')->all());
        $this->assertSame(1, MealPlanEntry::query()->where('day', '2026-10-11')->where('recipe_id', $zupa->getKey())->count());
    }

    public function test_pusty_dzien_ten_sam_dzien_i_dzien_bez_nowych_pozycji_niczego_nie_zapisuja(): void
    {
        $ja = $this->user('planujaca');
        $this->pozycja($ja, '2026-10-04', tekst: 'Obiad u mamy');
        $this->pozycja($ja, '2026-10-11', tekst: 'Obiad u mamy');
        $przed = MealPlanEntry::query()->count();

        // Pusty dzień źródłowy.
        $this->skopiuj($ja, '2026-10-06', '2026-10-11', 'x')
            ->assertSessionHas('status', 'Ten dzień jest pusty — nie ma czego skopiować.');
        // Ten sam dzień — przy podglądzie i przy zapisie.
        $html = (string) $this->actingAs($ja)->get(route('planer.copyday', ['dzien' => '2026-10-04', 'cel' => '2026-10-04']))->getContent();
        $this->assertStringContainsString('To ten sam dzień', $html);
        $this->assertStringNotContainsString('name="odcisk"', $html);
        $this->skopiuj($ja, '2026-10-04', '2026-10-04', 'x')
            ->assertSessionHas('status', 'To ten sam dzień, z którego kopiujesz. Wybierz inny dzień — nic nie zostało skopiowane.');
        // Wszystko już jest w dniu docelowym.
        $this->skopiuj($ja, '2026-10-04', '2026-10-11')
            ->assertSessionHas('status', 'Nic nowego do skopiowania: wszystko z tego dnia już jest w planie na niedzielę, 11 października albo przepis jest niedostępny.');

        $this->assertSame($przed, MealPlanEntry::query()->count());
    }

    public function test_brak_miejsca_odrzuca_cala_kopie_bez_czesciowego_zestawu(): void
    {
        $ja = $this->user('planujaca');
        $limit = (int) config('kuking.planer.wpisow_na_dzien');
        for ($i = 1; $i <= 3; $i++) {
            $this->pozycja($ja, '2026-10-04', tekst: 'Danie '.$i);
        }
        for ($i = 1; $i <= $limit - 2; $i++) {
            $this->pozycja($ja, '2026-10-11', tekst: 'Inne '.$i);
        }
        $przed = MealPlanEntry::query()->count();

        $this->skopiuj($ja, '2026-10-04', '2026-10-11')
            ->assertRedirect(route('planer.copyday', ['dzien' => '2026-10-04']))
            ->assertSessionHasErrors('cel');
        $this->assertStringContainsString('zostało miejsca na 2, a kopia dodałaby 3', $this->bladCelu());
        $this->assertSame($przed, MealPlanEntry::query()->count(), 'Nie wolno zapisać części zestawu.');

        $html = (string) $this->actingAs($ja)->get(route('planer.copyday', ['dzien' => '2026-10-04', 'cel' => '2026-10-11']))->getContent();
        $this->assertStringContainsString('Nie kopiujemy części zestawu', $html);
        $this->assertStringNotContainsString('name="odcisk"', $html, 'Bez miejsca nie ma przycisku zatwierdzenia.');
    }

    public function test_zla_data_i_zakres_wracaja_przy_polu_i_w_podsumowaniu_z_zachowanym_wyborem(): void
    {
        $ja = $this->user('planujaca');
        $this->pozycja($ja, '2026-10-04', tekst: 'Obiad u mamy');

        foreach (['2027-10-02', '2026-08-01'] as $dzien) {
            $this->skopiuj($ja, '2026-10-04', $dzien, 'x')->assertSessionHasErrors('cel');
            $this->assertStringContainsString('Wybierz dzień w zakresie planera', $this->bladCelu());
            $html = (string) $this->followRedirects($this->actingAs($ja)->post(route('planer.copyday.store'), ['dzien' => '2026-10-04', 'cel' => $dzien, 'odcisk' => 'x']))->getContent();
            $this->assertStringContainsString('error-summary', $html);
            $this->assertStringContainsString('field-error', $html);
            $this->assertStringContainsString('value="'.$dzien.'"', $html);

            // To samo przy samym podglądzie z adresu.
            $podglad = (string) $this->actingAs($ja)->get(route('planer.copyday', ['dzien' => '2026-10-04', 'cel' => $dzien]))->getContent();
            $this->assertStringContainsString('Wybierz dzień w zakresie planera', $podglad);
            $this->assertStringNotContainsString('name="odcisk"', $podglad);
        }

        $this->actingAs($ja)->post(route('planer.copyday.store'), ['dzien' => '2026-10-04', 'cel' => 'jutro', 'odcisk' => 'x'])
            ->assertSessionHasErrors(['cel' => 'Wybierz dzień z kalendarza albo wpisz go w formacie rrrr-mm-dd, np. 2026-10-02.']);
        $this->assertSame(1, MealPlanEntry::query()->count());
    }

    public function test_zmiana_zrodla_lub_dostepnosci_po_podgladzie_wymaga_nowego_podgladu(): void
    {
        $ja = $this->user('planujaca');
        $autorka = $this->user('kucharka');
        $przepis = $this->przepis($autorka, ['title' => 'Sekretny bigos']);
        $this->pozycja($ja, '2026-10-04', tekst: 'Obiad u mamy');
        $this->pozycja($ja, '2026-10-04', $przepis);
        $odcisk = $this->odcisk($ja, '2026-10-04', '2026-10-11');

        // Przepis przestaje być widoczny między podglądem a zapisem.
        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->skopiuj($ja, '2026-10-04', '2026-10-11', $odcisk)
            ->assertRedirect(route('planer.copyday', ['dzien' => '2026-10-04', 'cel' => '2026-10-11']))
            ->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(0, MealPlanEntry::query()->where('day', '2026-10-11')->count(), 'Brak cichej podmiany zestawu.');

        // Zmiana samego źródła (nowa pozycja) też unieważnia podgląd.
        $odcisk = $this->odcisk($ja, '2026-10-04', '2026-10-11');
        $this->pozycja($ja, '2026-10-04', tekst: 'Deser');
        $this->skopiuj($ja, '2026-10-04', '2026-10-11', $odcisk)->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(0, MealPlanEntry::query()->where('day', '2026-10-11')->count());

        // Z nowym podglądem zapis przechodzi.
        $this->skopiuj($ja, '2026-10-04', '2026-10-11')->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame(2, MealPlanEntry::query()->where('day', '2026-10-11')->count());
    }

    public function test_powtorzone_wyslanie_nie_mnozy_kopii(): void
    {
        $ja = $this->user('planujaca');
        $this->pozycja($ja, '2026-10-04', tekst: 'Obiad u mamy');
        $this->pozycja($ja, '2026-10-04', tekst: 'Deser');
        $odcisk = $this->odcisk($ja, '2026-10-04', '2026-10-11');

        $this->skopiuj($ja, '2026-10-04', '2026-10-11', $odcisk)->assertSessionHas('status_rodzaj', 'sukces');
        $this->skopiuj($ja, '2026-10-04', '2026-10-11', $odcisk)->assertSessionHas('status_rodzaj', 'informacja');

        $this->assertSame(2, MealPlanEntry::query()->where('day', '2026-10-11')->count());
        $this->assertSame(4, MealPlanEntry::query()->count());
    }

    public function test_kopia_dotyka_wylacznie_planu_wlasciciela_a_cudzy_dzien_jest_dla_niego_pusty(): void
    {
        $ja = $this->user('planujaca');
        $obca = $this->user('obca');
        $this->pozycja($ja, '2026-10-04', tekst: 'Tajny obiad');
        $zakup = new ShoppingListItem(['text' => 'mleko']);
        $zakup->user_id = $ja->getKey();
        $zakup->source = ShoppingListItem::SOURCE_MANUAL;
        $zakup->position = 0;
        $zakup->save();
        $zakupPrzed = $zakup->refresh()->getAttributes();

        // Obca osoba widzi ten sam dzień jako pusty i nic nie kopiuje.
        $html = (string) $this->actingAs($obca)->get(route('planer.copyday', ['dzien' => '2026-10-04', 'cel' => '2026-10-11']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Tajny obiad', $html);
        $this->assertStringContainsString('Ten dzień jest pusty', $html);
        $this->skopiuj($obca, '2026-10-04', '2026-10-11', 'x')->assertSessionHas('status', 'Ten dzień jest pusty — nie ma czego skopiować.');
        $this->assertSame(0, MealPlanEntry::query()->where('user_id', $obca->getKey())->count());

        // Gość jest odsyłany do logowania.
        auth()->logout();
        $this->get(route('planer.copyday', ['dzien' => '2026-10-04']))->assertRedirect(route('login'));
        $this->post(route('planer.copyday.store'), ['dzien' => '2026-10-04', 'cel' => '2026-10-11', 'odcisk' => 'x'])->assertRedirect(route('login'));

        $this->skopiuj($ja, '2026-10-04', '2026-10-11');
        $this->assertSame($zakupPrzed, $zakup->refresh()->getAttributes());
        $this->assertSame(1, ShoppingListItem::query()->count());
    }

    public function test_przycisk_przy_dniu_prowadzi_do_ekranu_kopiowania_tylko_gdy_dzien_ma_pozycje(): void
    {
        $ja = $this->user('planujaca');
        $this->pozycja($ja, '2026-10-01', tekst: 'Obiad u mamy');

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        preg_match('~<section[^>]*aria-labelledby="dzien-2026-10-01"[^>]*>(.*?)</section>~s', $html, $czwartek);
        preg_match('~<section[^>]*aria-labelledby="dzien-2026-10-02"[^>]*>(.*?)</section>~s', $html, $piatek);
        $this->assertStringContainsString('href="'.route('planer.copyday', ['dzien' => '2026-10-01']).'"', $czwartek[1]);
        $this->assertStringContainsString('Skopiuj ten dzień', $czwartek[1]);
        $this->assertStringNotContainsString('Skopiuj ten dzień', $piatek[1]);
    }

    public function test_zapis_bierze_blokade_konta_przed_odczytem_zrodla_i_celu(): void
    {
        $ja = $this->user('planujaca');
        $this->pozycja($ja, '2026-10-04', tekst: 'Obiad u mamy');
        $odcisk = $this->odcisk($ja, '2026-10-04', '2026-10-11');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->skopiuj($ja, '2026-10-04', '2026-10-11', $odcisk);
        $zapytania = array_map(fn (array $q): string => strtolower($q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $blokada = null;
        $odczyt = null;
        foreach ($zapytania as $i => $sql) {
            if ($blokada === null && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
                $blokada = $i;
            }
            if ($blokada !== null && $odczyt === null && str_contains($sql, 'from "meal_plan_entries"')) {
                $odczyt = $i;
            }
        }

        $this->assertNotNull($blokada, 'Brak blokady wiersza konta.');
        $this->assertNotNull($odczyt, 'Brak odczytu pozycji po blokadzie.');
        $this->assertLessThan($odczyt, $blokada);
    }
}
