<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * „Zgłoś” przy „Ugotowałem” i przy publicznym zeszycie (#2279, audyt 30.09 Z3).
 *
 * Regulamin §7 obiecuje przycisk „Zgłoś” przy każdej treści. Zdjęcie
 * i notatka wykonania przepisu oraz nazwa i opis publicznego zeszytu to
 * treści widoczne dla wszystkich, a żaden ekran nie prowadził do zgłoszenia:
 * backend przyjmował `cooked_event`, ale karta nie miała odnośnika, a zeszytu
 * nie dało się zgłosić w ogóle (brak celu w kontrolerze i w CHECK-u bazy).
 *
 * Asercje o obecności odnośnika czytają WYCINEK karty albo `<main>`, nie cały
 * dokument (`docs/PULAPKI_TESTOW.md` §1); każda asercja „nie ma” ma obok
 * kontrolę dodatnią (§4).
 */
class ZglosPrzyUgotowanymIZeszycieTest extends TestCase
{
    use RefreshDatabase;

    public function test_karta_wykonania_ma_zglos_dla_obcego_i_goscia_a_nie_dla_kucharza(): void
    {
        $kucharz = $this->user('kucharz');
        $widz = $this->user('widz');
        $wykonanie = $this->wykonanie($kucharz);
        $adres = route('reports.create', ['type' => 'cooked_event', 'id' => $wykonanie->getKey()]);

        $this->assertStringContainsString($adres, $this->karta($this->actingAs($widz), $wykonanie));
        $this->assertStringNotContainsString($adres, $this->karta($this->actingAs($kucharz), $wykonanie), 'Kucharz widzi „Zgłoś” przy własnym wykonaniu.');

        auth()->logout();
        $karta = $this->karta($this, $wykonanie);
        $this->assertStringContainsString($adres, $karta);
        $this->assertStringContainsString('Zgłoś (po zalogowaniu)', $karta);
    }

    public function test_karta_wykonania_na_stronie_przepisu_ma_zglos(): void
    {
        $kucharz = $this->user('kucharz');
        $wykonanie = $this->wykonanie($kucharz);
        $adres = route('reports.create', ['type' => 'cooked_event', 'id' => $wykonanie->getKey()]);

        $html = (string) $this->actingAs($this->user('widz'))
            ->get(route('recipes.show', $wykonanie->recipe->slug))->assertOk()->getContent();

        $this->assertStringContainsString($adres, $this->wycinekKarty($html, $wykonanie));
    }

    public function test_publiczny_zeszyt_ma_zglos_a_formularz_i_zapis_zgloszenia_dzialaja(): void
    {
        $wlasciciel = $this->user('wlascicielka', ['display_name' => 'Basia']);
        $widz = $this->user('widz');
        $zeszyt = $this->zeszyt($wlasciciel, 'public', 'Najlepsze zupy', 'Opis, który ktoś chce zgłosić.');
        $adres = route('reports.create', ['type' => 'collection', 'id' => $zeszyt->getKey()]);

        $this->assertStringContainsString($adres, $this->trescZeszytu($this->actingAs($widz), $zeszyt));
        $this->assertStringNotContainsString($adres, $this->trescZeszytu($this->actingAs($wlasciciel), $zeszyt), 'Właściciel widzi „Zgłoś” przy własnym zeszycie.');

        $this->actingAs($widz)->get($adres)->assertOk()
            ->assertSee('Zgłoś: zeszyt «Najlepsze zupy»')
            ->assertSee('Opis, który ktoś chce zgłosić.');

        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'collection', 'id' => $zeszyt->getKey()]), ['reason' => 'spam'])
            ->assertSessionHasNoErrors();

        $zgloszenie = Report::query()->sole();
        $this->assertSame('collection', $zgloszenie->target_type);
        $this->assertSame($zeszyt->getKey(), $zgloszenie->target_id);
        $this->assertSame('zeszyt', $zgloszenie->targetLabel());

        auth()->logout();
        $this->assertStringContainsString($adres, $this->trescZeszytu($this, $zeszyt));
    }

    public function test_prywatnego_zeszytu_obcy_nie_zglosi(): void
    {
        $zeszyt = $this->zeszyt($this->user('wlascicielka'), 'private', 'Tylko moje', null);

        $this->actingAs($this->user('widz'))
            ->get(route('reports.create', ['type' => 'collection', 'id' => $zeszyt->getKey()]))
            ->assertNotFound();

        // Kontrola dodatnia: ten sam zeszyt ustawiony jako publiczny daje formularz.
        $zeszyt->forceFill(['visibility' => 'public'])->save();
        $this->actingAs($this->user('drugi_widz'))
            ->get(route('reports.create', ['type' => 'collection', 'id' => $zeszyt->getKey()]))
            ->assertOk();
    }

    public function test_moderator_ostrzega_wlasciciela_zeszytu_bez_ukrywania_i_usuwania(): void
    {
        $this->assertSame(
            [ModerationAction::ACTION_NONE, ModerationAction::ACTION_WARN, ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN],
            ModerationAction::DOZWOLONE['collection'],
        );

        $wlasciciel = $this->user('wlascicielka');
        $zeszyt = $this->zeszyt($wlasciciel, 'public', 'Zupy', 'Opis');
        $this->actingAs($this->user('widz'))
            ->post(route('reports.store', ['type' => 'collection', 'id' => $zeszyt->getKey()]), ['reason' => 'spam']);
        $zgloszenie = Report::query()->sole();

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), ['action' => ModerationAction::ACTION_REMOVE, 'reason_code' => 'spam'])
            ->assertSessionHasErrors('action');

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), ['action' => ModerationAction::ACTION_WARN, 'reason_code' => 'spam'])
            ->assertSessionHasNoErrors();

        $decyzja = ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
        $this->assertSame($wlasciciel->getKey(), $decyzja->subject_user_id);
        $this->assertNotNull($zeszyt->fresh(), 'Ostrzeżenie skasowało zeszyt.');
    }

    public function test_rollback_odmawia_gdy_jest_zgloszenie_zeszytu_a_bez_niego_przechodzi(): void
    {
        $migracja = 'database/migrations/2026_09_30_163500_zeszyt_jako_cel_zgloszenia.php';

        // Kontrola dodatnia: bez zgłoszeń zeszytów rollback przechodzi i wraca.
        Artisan::call('migrate:rollback', ['--path' => $migracja]);
        Artisan::call('migrate', ['--path' => $migracja]);

        $zeszyt = $this->zeszyt($this->user('wlascicielka'), 'public', 'Zupy', null);
        $this->actingAs($this->user('widz'))
            ->post(route('reports.store', ['type' => 'collection', 'id' => $zeszyt->getKey()]), ['reason' => 'spam'])
            ->assertSessionHasNoErrors();

        try {
            Artisan::call('migrate:rollback', ['--path' => $migracja]);
            $this->fail('Rollback przeszedł, choć w bazie jest zgłoszenie zeszytu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba zgłoszeń zeszytów', $e->getMessage());
        }

        $this->assertSame(1, Report::query()->where('target_type', 'collection')->count());
    }

    private function wykonanie(User $kucharz): CookedEvent
    {
        $przepis = Recipe::factory()->create(['title' => 'Rosół', 'visibility' => 'public', 'status' => 'published']);

        return CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'note' => 'Wyszło pyszne, dodałam lubczyk.',
        ]);
    }

    private function zeszyt(User $wlasciciel, string $widocznosc, string $nazwa, ?string $opis): Collection
    {
        return Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => $nazwa,
            'description' => $opis,
            'visibility' => $widocznosc,
        ]);
    }

    private function karta(TestCase $kto, CookedEvent $wykonanie): string
    {
        $html = (string) $kto->get(route('cooked.show', $wykonanie))->assertOk()->getContent();

        return $this->wycinekKarty($html, $wykonanie);
    }

    private function wycinekKarty(string $html, CookedEvent $wykonanie): string
    {
        $od = strpos($html, 'data-klucz="wykonanie-'.$wykonanie->getKey().'"');
        $this->assertNotFalse($od, 'Kontrola: na stronie nie ma karty tego wykonania.');
        $do = strpos($html, '</article>', $od);
        $this->assertNotFalse($do);

        $karta = substr($html, $od, $do - $od);
        $this->assertStringContainsString('Wyszło pyszne, dodałam lubczyk.', $karta, 'Kontrola: wycinek nie zawiera notatki wykonania.');

        return $karta;
    }

    private function trescZeszytu(TestCase $kto, Collection $zeszyt): string
    {
        $html = (string) $kto->get(route('collections.show', $zeszyt))->assertOk()->getContent();
        $od = strpos($html, '<main');
        $this->assertNotFalse($od, 'Kontrola: strona zeszytu nie ma <main>.');
        $tresc = substr($html, $od);
        $this->assertStringContainsString($zeszyt->name, $tresc);

        return $tresc;
    }
}
