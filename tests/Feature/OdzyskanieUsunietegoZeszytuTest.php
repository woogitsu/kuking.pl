<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\Odzyskiwanie\OdzyskajUsunietyZeszyt;
use App\Domain\Collections\Odzyskiwanie\PrzedawnioneUsunieteZeszyty;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Http\Controllers\CollectionRecipeOrderController;
use App\Models\Collection;
use App\Models\DeletedCollection;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Odzyskanie omyłkowo usuniętego prywatnego zeszytu (#2567, D-333).
 *
 * Mierzy zachowanie: kopia powstaje tylko dla zeszytu prywatnego i
 * niewspółdzielonego; odzyskanie oddaje nazwę, opis, zapisy, dopiski i
 * oryginalne daty; nie wskrzesza usuniętych przepisów; respektuje termin,
 * moderację, nazwę, konto i własność; jest idempotentne; sprzątanie i
 * wymazanie konta kasują kopie.
 */
final class OdzyskanieUsunietegoZeszytuTest extends TestCase
{
    use RefreshDatabase;

    private User $basia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basia = $this->user('basia');
    }

    private function zeszyt(User $wlasciciel, string $nazwa = 'Obiady na święta', string $widocznosc = 'private'): Collection
    {
        return Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => $nazwa,
            'description' => 'Rzeczy od mamy',
            'visibility' => $widocznosc,
        ]);
    }

    private function zapisz(Collection $zeszyt, Recipe|Post $cel, ?string $dopisek, string $kiedy): void
    {
        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $cel instanceof Recipe ? $cel->getKey() : null,
            'post_id' => $cel instanceof Post ? $cel->getKey() : null,
            'note' => $dopisek,
            'created_at' => $kiedy,
            'added_by_id' => $zeszyt->owner_id,
        ]);
    }

    private function usun(User $kto, Collection $zeszyt): TestResponse
    {
        return $this->actingAs($kto)->delete(route('collections.destroy', $zeszyt));
    }

    private function wiadomosc(): string
    {
        return (string) session('status');
    }

    private function kopiaId(Collection $zeszyt): string
    {
        return DeletedCollection::query()->where('collection_id', $zeszyt->getKey())->firstOrFail()->getKey();
    }

    // ───────────── Usuwanie: kiedy powstaje kopia ─────────────

    public function test_usuniecie_prywatnego_zeszytu_zostawia_kopie_z_dopiskami_i_datami(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $przepis = Recipe::factory()->create();
        $this->zapisz($zeszyt, $przepis, 'bez cebuli, dla taty', '2026-03-04 10:11:12+00');

        $this->usun($this->basia, $zeszyt)->assertRedirect(route('collections.index'));

        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
        $this->assertSame(0, DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->count());

        $kopia = DeletedCollection::query()->where('collection_id', $zeszyt->getKey())->firstOrFail();
        $this->assertSame($this->basia->getKey(), $kopia->owner_id);
        $this->assertSame('Obiady na święta', $kopia->name);
        $this->assertSame(1, $kopia->items_count);
        $this->assertSame('bez cebuli, dla taty', $kopia->items[0]['note']);

        $dni = OdzyskajUsunietyZeszyt::dniOkna();
        $this->assertStringContainsString('przez '.$dni, $this->wiadomosc());
        $this->assertStringContainsString('Usunięte zeszyty', $this->wiadomosc());
    }

    public function test_publiczny_zeszyt_nie_zostawia_kopii_a_komunikat_mowi_prawde(): void
    {
        $zeszyt = $this->zeszyt($this->basia, 'Publiczny', 'public');

        $this->usun($this->basia, $zeszyt)->assertRedirect(route('collections.index'));

        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
        $this->assertSame(0, DeletedCollection::query()->count());
        $this->assertStringContainsString('nie da się odzyskać', $this->wiadomosc());
        $this->assertStringNotContainsString('Jeśli to pomyłka', $this->wiadomosc());
    }

    public function test_zeszyt_z_czlonkiem_albo_zaproszeniem_nie_zostawia_kopii(): void
    {
        $zZaproszeniem = $this->zeszyt($this->basia, 'Z zaproszeniem');
        $zCzlonkiem = $this->zeszyt($this->basia, 'Z członkiem');
        $zofia = $this->user('zofia');

        DB::table('collection_members')->insert([
            'collection_id' => $zCzlonkiem->getKey(),
            'user_id' => $zofia->getKey(),
            'created_at' => now(),
        ]);
        DB::table('collection_invitations')->insert([
            'collection_id' => $zZaproszeniem->getKey(),
            'inviter_id' => $this->basia->getKey(),
            'invitee_id' => $zofia->getKey(),
            'via_link' => false,
            'status' => 'pending',
            'expires_at' => now()->addDays(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->usun($this->basia, $zZaproszeniem);
        $this->usun($this->basia, $zCzlonkiem);

        $this->assertNull(Collection::query()->find($zZaproszeniem->getKey()));
        $this->assertNull(Collection::query()->find($zCzlonkiem->getKey()));
        $this->assertSame(0, DeletedCollection::query()->count());
    }

    public function test_zeszyt_ponad_limit_pozycji_nie_zostawia_kopii_i_mowi_dlaczego(): void
    {
        config(['kuking.collections.odzyskanie_max_pozycji' => 2]);
        $zeszyt = $this->zeszyt($this->basia);
        foreach (range(1, 3) as $i) {
            $this->zapisz($zeszyt, Recipe::factory()->create(), null, '2026-03-0'.$i.' 10:00:00+00');
        }

        $this->usun($this->basia, $zeszyt);

        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
        $this->assertSame(0, DeletedCollection::query()->count());
        $this->assertStringContainsString('ponad 2 zapisów', $this->wiadomosc());
    }

    public function test_limit_kopii_na_osobe_nie_kasuje_po_cichu_starszej(): void
    {
        config(['kuking.collections.odzyskanie_max_zeszytow' => 1]);

        $pierwszy = $this->zeszyt($this->basia, 'Pierwszy');
        $drugi = $this->zeszyt($this->basia, 'Drugi');

        $this->usun($this->basia, $pierwszy);
        $this->usun($this->basia, $drugi);

        $this->assertSame(['Pierwszy'], DeletedCollection::query()->pluck('name')->all());
        $this->assertStringContainsString('Masz już tyle usuniętych zeszytów', $this->wiadomosc());
    }

    public function test_zeszyt_w_sprawie_moderacyjnej_nie_zostawia_kopii(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->sprawaModeracyjna($zeszyt);

        $this->usun($this->basia, $zeszyt);

        $this->assertSame(0, DeletedCollection::query()->count());
    }

    private function sprawaModeracyjna(Collection $zeszyt): void
    {
        DB::table('moderation_actions')->insert([
            'id' => (string) Str::uuid(),
            'moderator_id' => $this->moderator()->getKey(),
            'target_type' => 'collection',
            'target_id' => $zeszyt->getKey(),
            'action' => 'remove',
            'reason_code' => 'test',
            'created_at' => now(),
        ]);
    }

    public function test_zawieszone_konto_usuwa_prywatny_zeszyt_ale_nie_dostaje_kopii(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->basia->suspend();

        $this->usun($this->basia->fresh(), $zeszyt);

        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
        $this->assertSame(0, DeletedCollection::query()->count());
    }

    // ───────────── Odzyskanie ─────────────

    public function test_odzyskanie_oddaje_zeszyt_z_dopiskami_i_oryginalnymi_datami(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $przepis = Recipe::factory()->create();
        $wpis = Post::factory()->create();
        $this->zapisz($zeszyt, $przepis, 'bez cebuli, dla taty', '2026-03-04 10:11:12+00');
        $this->zapisz($zeszyt, $wpis, null, '2026-04-05 08:00:00+00');
        $powiadomienPrzed = Notification::query()->count();

        $this->usun($this->basia, $zeszyt);

        $this->actingAs($this->basia)
            ->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)])
            ->assertRedirect(route('collections.show', $zeszyt->getKey()));

        $wrocil = Collection::query()->findOrFail($zeszyt->getKey());
        $this->assertSame('Obiady na święta', $wrocil->name);
        $this->assertSame('Rzeczy od mamy', $wrocil->description);
        $this->assertSame('private', $wrocil->visibility);
        $this->assertFalse($wrocil->is_default);
        $this->assertSame($this->basia->getKey(), $wrocil->owner_id);
        $this->assertSame($zeszyt->created_at->toIso8601String(), $wrocil->created_at->toIso8601String());

        $pozycja = DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->where('recipe_id', $przepis->getKey())->first();
        $this->assertNotNull($pozycja);
        $this->assertSame('bez cebuli, dla taty', $pozycja->note);
        $this->assertSame('2026-03-04 10:11:12', Carbon::parse($pozycja->created_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->where('post_id', $wpis->getKey())->count());

        $this->assertSame(0, DeletedCollection::query()->count(), 'Kopia po odzyskaniu zostaje w bazie.');
        $this->assertSame($powiadomienPrzed, Notification::query()->count(), 'Odzyskanie wysłało powiadomienie.');
        $this->assertSame(0, DB::table('collection_members')->where('collection_id', $zeszyt->getKey())->count());
        $this->assertStringContainsString('Wszystkie zapisy', $this->wiadomosc());
    }

    public function test_reczne_ulozenie_przezywa_usuniecie_i_odzyskanie_calego_zeszytu(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $deser = Recipe::factory()->create(['title' => 'Deser']);
        $danie = Recipe::factory()->create(['title' => 'Danie']);
        $zupa = Recipe::factory()->create(['title' => 'Zupa']);
        foreach ([$deser, $danie, $zupa] as $i => $przepis) {
            $this->zapisz($zeszyt, $przepis, $i === 1 ? 'Bez soli' : null, '2026-03-0'.($i + 1).' 10:00:00+00');
        }
        $wpis = Post::factory()->create();
        $this->zapisz($zeszyt, $wpis, 'Wpis zostaje', '2026-03-04 10:00:00+00');
        $this->assertSame([$zupa->id, $danie->id, $deser->id], KolejnoscPrzepisow::uklad($zeszyt));
        $powiadomienPrzed = Notification::query()->count();

        $this->actingAs($this->basia)->post(
            route('collections.recipes.move', ['collection' => $zeszyt, 'pozycja' => $deser->getKey()]),
            ['kierunek' => 'poczatek', CollectionRecipeOrderController::POLE_ODCISKU => KolejnoscPrzepisow::odcisk(KolejnoscPrzepisow::uklad($zeszyt))],
        )->assertRedirect();
        $oczekiwany = [$deser->id, $zupa->id, $danie->id];
        $this->assertSame($oczekiwany, KolejnoscPrzepisow::uklad($zeszyt));

        $this->usun($this->basia, $zeszyt)->assertRedirect();
        $kopia = DeletedCollection::query()->where('collection_id', $zeszyt->getKey())->firstOrFail();
        $pozycje = collect($kopia->items)->keyBy('recipe_id');
        $this->assertSame([1, 2, 3], array_map(fn (string $id) => $pozycje[$id]['position'], $oczekiwany), 'ODZYSKANIE_2816_RECZNA_KOLEJNOSC: kopia zgubiła ułożenie.');
        $this->assertNull(collect($kopia->items)->firstWhere('post_id', $wpis->getKey())['position']);
        $paczka = app(CollectUserExportData::class)
            ->handle($this->basia->fresh(), new ExportPhotoPlan($this->basia->fresh()), Carbon::now());
        $wyeksportowane = collect($paczka['usuniete_zeszyty'][0]['pozycje'])->where('rodzaj', 'przepis')->pluck('reczna_pozycja')->all();
        $this->assertSame([1, 3, 2], array_values($wyeksportowane)); // kopia jest uporządkowana po datach zapisu

        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)])
            ->assertRedirect(route('collections.show', $zeszyt->getKey()));
        $wrocil = Collection::query()->findOrFail($zeszyt->getKey());
        $this->assertSame($oczekiwany, KolejnoscPrzepisow::uklad($wrocil), 'ODZYSKANIE_2816_RECZNA_KOLEJNOSC: odtworzenie zgubiło ułożenie.');
        $this->actingAs($this->basia)->get(route('collections.show', $wrocil))->assertOk()->assertSeeInOrder(['Deser', 'Zupa', 'Danie']);
        $this->actingAs($this->basia)->get(route('collections.print', $wrocil))->assertOk()->assertSeeInOrder(['Deser', 'Zupa', 'Danie']);
        $this->assertSame('Bez soli', DB::table('collection_items')->where('recipe_id', $danie->id)->value('note'));
        $this->assertSame(1, DB::table('collection_items')->where('post_id', $wpis->id)->whereNull('position')->count());
        $this->assertSame($powiadomienPrzed, Notification::query()->count());
    }

    public function test_stara_kopia_bez_pozycji_i_nieulozony_zeszyt_nie_dostaja_zgadywanego_ukladu(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $pierwszy = Recipe::factory()->create();
        $drugi = Recipe::factory()->create();
        $this->zapisz($zeszyt, $pierwszy, null, '2026-03-01 10:00:00+00');
        $this->zapisz($zeszyt, $drugi, null, '2026-03-02 10:00:00+00');
        $this->usun($this->basia, $zeszyt);
        $kopia = DeletedCollection::query()->where('collection_id', $zeszyt->getKey())->firstOrFail();
        $this->assertSame([null, null], array_column($kopia->items, 'position'));
        DB::table('deleted_collections')->where('id', $kopia->getKey())->update([
            'items' => json_encode(array_map(static function (array $item): array {
                unset($item['position']);

                return $item;
            }, $kopia->items), JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)])->assertRedirect();
        $this->assertFalse(KolejnoscPrzepisow::jestUlozony($zeszyt));
        $this->assertSame([$drugi->id, $pierwszy->id], KolejnoscPrzepisow::uklad($zeszyt));
    }

    public function test_pominiecie_usunietego_przepisu_zostawia_dziure_w_recznych_numerach(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $przepisy = [Recipe::factory()->create(), Recipe::factory()->create(), Recipe::factory()->create()];
        foreach ($przepisy as $i => $przepis) {
            $this->zapisz($zeszyt, $przepis, null, '2026-03-0'.($i + 1).' 10:00:00+00');
            DB::table('collection_items')->where('collection_id', $zeszyt->id)->where('recipe_id', $przepis->id)->update(['position' => $i + 1]);
        }
        $this->usun($this->basia, $zeszyt);
        $przepisy[1]->delete();

        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->id), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)])->assertRedirect();
        $wiersze = DB::table('collection_items')->where('collection_id', $zeszyt->id)->orderBy('position')->pluck('position', 'recipe_id')->all();
        $this->assertSame([(string) $przepisy[0]->id => 1, (string) $przepisy[2]->id => 3], $wiersze);
        $this->assertSame([$przepisy[0]->id, $przepisy[2]->id], KolejnoscPrzepisow::uklad($zeszyt));
    }

    public function test_odzyskanie_nie_wskrzesza_usunietego_przepisu_i_mowi_ile_nie_wrocilo(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $zyje = Recipe::factory()->create();
        $miekko = Recipe::factory()->create();
        $twardo = Recipe::factory()->create();
        foreach ([$zyje, $miekko, $twardo] as $i => $r) {
            $this->zapisz($zeszyt, $r, 'dopisek '.$i, '2026-03-0'.($i + 1).' 10:00:00+00');
        }

        $this->usun($this->basia, $zeszyt);

        $miekko->delete();
        $twardo->forceDelete();

        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)]);

        $this->assertSame(
            [(string) $zyje->getKey()],
            DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->pluck('recipe_id')->all(),
        );
        $this->assertNotNull(Recipe::withTrashed()->find($miekko->getKey())->deleted_at, 'Odzyskanie zeszytu wskrzesiło usunięty przepis.');
        $this->assertStringContainsString('Nie wróciło 2 zapisy', $this->wiadomosc());
    }

    public function test_ekran_pokazuje_nazwe_termin_i_przycisk_tylko_wlascicielowi(): void
    {
        $zeszyt = $this->zeszyt($this->basia, 'Ciasta babci');
        $this->usun($this->basia, $zeszyt);
        $zofia = $this->user('zofia');
        $cudzy = $this->zeszyt($zofia, 'Sekretny zeszyt Zofii');
        $this->usun($zofia, $cudzy);

        $html = $this->actingAs($this->basia)->get(route('collections.deleted'))->assertOk()->getContent();

        $this->assertStringContainsString('Ciasta babci', $html);
        $this->assertStringContainsString('Odzyskaj zeszyt', $html);
        $this->assertStringContainsString('Możesz go odzyskać do', $html);
        $this->assertStringContainsString('data-usuniety-zeszyt="'.$zeszyt->getKey().'"', $html);
        $this->assertStringNotContainsString('data-usuniety-zeszyt="'.$cudzy->getKey().'"', $html);
    }

    public function test_obca_osoba_moderator_i_gosc_nie_odzyskaja_cudzego_zeszytu(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->usun($this->basia, $zeszyt);
        $adres = route('collections.deleted.recover', $zeszyt->getKey());

        $this->actingAs($this->user('zofia'))->post($adres)->assertForbidden();
        $this->actingAs($this->moderator())->post($adres)->assertForbidden();
        auth()->logout();
        $this->post($adres)->assertRedirect(route('login'));

        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
        $this->assertSame(1, DeletedCollection::query()->count());
    }

    public function test_po_terminie_kopia_nie_wraca_choc_nocne_sprzatanie_jeszcze_nie_poszlo(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->usun($this->basia, $zeszyt);
        DeletedCollection::query()->update(['deleted_at' => now()->subDays(OdzyskajUsunietyZeszyt::dniOkna())->subMinute()]);

        $this->actingAs($this->basia)->get(route('collections.deleted'))->assertOk()->assertDontSee('Odzyskaj zeszyt');
        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)])
            ->assertRedirect(route('collections.deleted'));

        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
        $this->assertSame(1, DeletedCollection::query()->count());
    }

    public function test_sprawa_moderacyjna_blokuje_odzyskanie_i_znika_z_listy(): void
    {
        $zeszyt = $this->zeszyt($this->basia, 'Do sprawy');
        $this->usun($this->basia, $zeszyt);
        $this->sprawaModeracyjna($zeszyt);

        $html = $this->actingAs($this->basia)->get(route('collections.deleted'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-usuniety-zeszyt="'.$zeszyt->getKey().'"', $html);

        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)])
            ->assertRedirect(route('collections.deleted'));

        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
    }

    public function test_zajeta_nazwa_nic_nie_kasuje_i_mowi_co_zrobic(): void
    {
        $zeszyt = $this->zeszyt($this->basia, 'Obiady');
        $this->usun($this->basia, $zeszyt);
        $nowy = $this->zeszyt($this->basia, 'obiady');

        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)])
            ->assertRedirect(route('collections.deleted'));

        $this->assertStringContainsString('Zmień nazwę tamtego zeszytu', $this->wiadomosc());
        $this->assertSame(1, DeletedCollection::query()->count(), 'Kopia zniknęła mimo odmowy.');
        $this->assertNotNull(Collection::query()->find($nowy->getKey()));

        // Po zmianie nazwy tamtego zeszytu odzyskanie działa.
        $nowy->update(['name' => 'Obiady codzienne']);
        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)])
            ->assertRedirect(route('collections.show', $zeszyt->getKey()));
        $this->assertSame(0, DeletedCollection::query()->count());
    }

    public function test_drugie_wyslanie_jest_idempotentne(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->usun($this->basia, $zeszyt);
        $adres = route('collections.deleted.recover', $zeszyt->getKey());
        $kopiaId = $this->kopiaId($zeszyt);

        $this->actingAs($this->basia)->post($adres, [OdzyskajUsunietyZeszyt::POLE_KOPII => $kopiaId]);
        $this->actingAs($this->basia)->post($adres, [OdzyskajUsunietyZeszyt::POLE_KOPII => $kopiaId])
            ->assertRedirect(route('collections.show', $zeszyt->getKey()));

        $this->assertStringContainsString('już odzyskany', $this->wiadomosc());
        $this->assertSame(1, Collection::query()->where('owner_id', $this->basia->getKey())->count());
    }

    public function test_stary_przycisk_nie_odzyskuje_nowej_kopii_tego_samego_zeszytu(): void
    {
        $zeszyt = $this->zeszyt($this->basia, 'Obiady');
        $adres = route('collections.deleted.recover', $zeszyt->getKey());
        $this->usun($this->basia, $zeszyt);
        $kopiaA = $this->kopiaId($zeszyt);
        $staryEkran = $this->actingAs($this->basia)->get(route('collections.deleted'))->assertOk()->getContent();
        $this->assertStringContainsString('name="'.OdzyskajUsunietyZeszyt::POLE_KOPII.'" value="'.$kopiaA.'"', $staryEkran);

        $this->actingAs($this->basia)->post($adres, [OdzyskajUsunietyZeszyt::POLE_KOPII => $kopiaA])
            ->assertRedirect(route('collections.show', $zeszyt->getKey()));
        $odnowiony = Collection::query()->findOrFail($zeszyt->getKey());
        $odnowiony->update(['name' => 'Obiady później', 'description' => 'Nowy dopisek']);
        $this->zapisz($odnowiony, Recipe::factory()->create(), 'nowa treść', '2026-03-04 10:11:12+00');
        $this->usun($this->basia, $odnowiony);
        $kopiaB = $this->kopiaId($zeszyt);
        $this->assertNotSame($kopiaA, $kopiaB);

        $odpowiedz = $this->actingAs($this->basia)->post($adres, [OdzyskajUsunietyZeszyt::POLE_KOPII => $kopiaA]);
        $this->assertSame($kopiaB, (string) DB::table('deleted_collections')->where('collection_id', $zeszyt->getKey())->value('id'),
            'KOPIA_2867_STARY_PRZYCISK: stary formularz zużył nową kopię.');
        $this->assertNull(Collection::query()->find($zeszyt->getKey()), 'KOPIA_2867_STARY_PRZYCISK: stary formularz odtworzył nową kopię.');
        $odpowiedz->assertRedirect(route('collections.deleted'));
        $this->assertStringContainsString('Otwórz ponownie „Usunięte zeszyty”', $this->wiadomosc());

        $swiezyEkran = $this->actingAs($this->basia)->get(route('collections.deleted'))->assertOk()->getContent();
        $this->assertStringContainsString('name="'.OdzyskajUsunietyZeszyt::POLE_KOPII.'" value="'.$kopiaB.'"', $swiezyEkran);
        $this->actingAs($this->basia)->post($adres, [OdzyskajUsunietyZeszyt::POLE_KOPII => $kopiaB])
            ->assertRedirect(route('collections.show', $zeszyt->getKey()));
        $this->assertSame('Obiady później', Collection::query()->findOrFail($zeszyt->getKey())->name);
        $this->assertSame('nowa treść', DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->value('note'));
    }

    public function test_formularz_sprzed_zmiany_bez_id_kopii_odmawia_i_zostawia_kopie(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->usun($this->basia, $zeszyt);
        $kopiaId = $this->kopiaId($zeszyt);

        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()))
            ->assertRedirect(route('collections.deleted'));
        $this->assertSame($kopiaId, $this->kopiaId($zeszyt), 'KOPIA_2867_STARY_PRZYCISK: formularz bez identyfikatora zużył kopię.');
    }

    public function test_zawieszone_konto_nie_odzyskuje(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->usun($this->basia, $zeszyt);
        $this->basia->suspend();

        $this->actingAs($this->basia->fresh())->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)]);

        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
        $this->assertSame(1, DeletedCollection::query()->count());
    }

    public function test_odzyskanie_nie_zmienia_innych_zeszytow_ani_skrotu(): void
    {
        $inny = $this->zeszyt($this->basia, 'Inny');
        $zeszyt = $this->zeszyt($this->basia, 'Do odzyskania');
        $this->zapisz($inny, Recipe::factory()->create(), 'zostaje', '2026-01-01 10:00:00+00');
        $this->usun($this->basia, $zeszyt);

        $this->actingAs($this->basia)->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $this->kopiaId($zeszyt)]);

        $this->assertSame(1, DB::table('collection_items')->where('collection_id', $inny->getKey())->count());
        $this->assertNull($this->basia->fresh()->ulubiony_zeszyt_id);
    }

    // ───────────── Sprzątanie, wymazanie konta, eksport ─────────────

    public function test_sprzatanie_kasuje_tylko_przedawnione_kopie_a_na_sucho_nic_nie_rusza(): void
    {
        $stary = $this->zeszyt($this->basia, 'Stary');
        $swiezy = $this->zeszyt($this->basia, 'Świeży');
        $this->usun($this->basia, $stary);
        $this->usun($this->basia, $swiezy);
        $dni = OdzyskajUsunietyZeszyt::dniOkna();
        DeletedCollection::query()->where('name', 'Stary')->update(['deleted_at' => now()->subDays($dni)->subHour()]);

        $this->assertSame(1, app(PrzedawnioneUsunieteZeszyty::class)->posprzataj($dni, naSucho: true));
        $this->assertSame(2, DeletedCollection::query()->count());

        Artisan::call('kuking:sprzataj-usuniete-tresci');

        $this->assertSame(['Świeży'], DeletedCollection::query()->pluck('name')->all());
    }

    public function test_wymazanie_konta_kasuje_kopie_a_spoznione_odzyskanie_nic_nie_odtwarza(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->zapisz($zeszyt, Recipe::factory()->create(), 'prywatny dopisek', '2026-03-04 10:00:00+00');
        $this->usun($this->basia, $zeszyt);
        $kopiaId = $this->kopiaId($zeszyt);

        $this->basia->markForDeletion();
        app(EraseAccountData::class)->handle($this->basia->fresh());

        $this->assertSame(0, DeletedCollection::query()->count());

        $this->actingAs($this->basia->fresh())->post(route('collections.deleted.recover', $zeszyt->getKey()), [OdzyskajUsunietyZeszyt::POLE_KOPII => $kopiaId]);
        $this->assertNull(Collection::query()->find($zeszyt->getKey()));
    }

    public function test_eksport_danych_zawiera_usuniete_zeszyty_z_dopiskami(): void
    {
        $zeszyt = $this->zeszyt($this->basia, 'Na urodziny');
        $this->zapisz($zeszyt, Recipe::factory()->create(['title' => 'Sernik jak u cioci']), 'mniej cukru', '2026-03-04 10:00:00+00');
        $this->usun($this->basia, $zeszyt);

        $paczka = app(CollectUserExportData::class)
            ->handle($this->basia->fresh(), new ExportPhotoPlan($this->basia->fresh()), Carbon::now());

        $this->assertCount(1, $paczka['usuniete_zeszyty']);
        $wiersz = $paczka['usuniete_zeszyty'][0];
        $this->assertSame('Na urodziny', $wiersz['nazwa']);
        $this->assertSame('mniej cukru', $wiersz['pozycje'][0]['moj_dopisek']);
        $this->assertNotNull($wiersz['mozna_odzyskac_do']);
    }

    public function test_przyciski_na_ekranach_maja_pelny_napis(): void
    {
        $this->actingAs($this->basia)->get(route('collections.index'))
            ->assertOk()
            ->assertSee('Usunięte zeszyty');
    }
}
