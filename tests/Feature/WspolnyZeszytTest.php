<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rodzinny zeszyt — wspólne zapisywanie w gospodarstwie domowym (#1743, D-302).
 *
 * Każda granica z decyzji właściciela z 26.09.2026 ma tu test, który oblewa,
 * gdy ją zdjąć: zaproszenie po nazwie i linkiem, przyjęcie/odrzucenie,
 * odebranie dostępu, odejście, blokada w obie strony, widoczność bez zmian,
 * „kto dodał", eksport w granicach art. 15 ust. 4 i usunięcie konta.
 */
final class WspolnyZeszytTest extends TestCase
{
    use RefreshDatabase;

    private User $halina;

    private User $jurek;

    private Collection $zeszyt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->halina = $this->user('halina', ['display_name' => 'Halina']);
        $this->jurek = $this->user('jurek', ['display_name' => 'Jurek']);
        $this->zeszyt = Collection::create([
            'owner_id' => $this->halina->getKey(),
            'name' => 'Obiady rodzinne',
            'visibility' => 'private',
        ]);
    }

    private function zaproszenie(): CollectionInvitation
    {
        return app(ZaprosDoZeszytu::class)->poNazwie($this->halina, $this->zeszyt, 'jurek');
    }

    /** @return array<string, mixed> */
    private function paczka(User $user): array
    {
        return app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), Carbon::now());
    }

    private function dolaczJurka(): void
    {
        app(OdpowiedzNaZaproszenie::class)->przyjmij($this->jurek, $this->zaproszenie());
    }

    public function test_zaproszenie_po_nazwie_konta_powiadamia_raz_i_jest_idempotentne(): void
    {
        $this->actingAs($this->halina)
            ->post(route('collections.invitations.store', $this->zeszyt), ['nazwa' => '@Jurek'])
            ->assertRedirect(route('collections.sharing', $this->zeszyt));

        $this->actingAs($this->halina)
            ->post(route('collections.invitations.store', $this->zeszyt), ['nazwa' => 'jurek']);

        $this->assertSame(1, CollectionInvitation::query()->where('invitee_id', $this->jurek->getKey())->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $this->jurek->getKey())
            ->where('type', Notification::TYPE_COLLECTION_INVITED)
            ->count());

        $this->actingAs($this->jurek)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('zaprasza Cię do wspólnego zeszytu', false);
    }

    public function test_nieistniejaca_nazwa_i_blokada_daja_to_samo_zdanie_przy_polu(): void
    {
        $this->actingAs($this->halina)
            ->from(route('collections.sharing', $this->zeszyt))
            ->post(route('collections.invitations.store', $this->zeszyt), ['nazwa' => 'nie-ma-takiego'])
            ->assertSessionHasErrors(['nazwa' => ZaprosDoZeszytu::NIE_DA_SIE]);

        app(BlockUser::class)->handle($this->jurek, $this->halina);

        $this->actingAs($this->halina)
            ->from(route('collections.sharing', $this->zeszyt))
            ->post(route('collections.invitations.store', $this->zeszyt), ['nazwa' => 'jurek'])
            ->assertSessionHasErrors(['nazwa' => ZaprosDoZeszytu::NIE_DA_SIE])
            ->assertSessionHasInput('nazwa', 'jurek');

        $this->assertSame(0, CollectionInvitation::query()->count());
    }

    public function test_przyjecie_daje_dostep_raz_i_jedno_powiadomienie_wlascicielki(): void
    {
        $zaproszenie = $this->zaproszenie();

        $this->actingAs($this->jurek)->get(route('collections.invitations.show', $zaproszenie))
            ->assertOk()->assertSee('Obiady rodzinne')->assertSee('Dołączam');

        $this->actingAs($this->jurek)->post(route('collections.invitations.accept', $zaproszenie))
            ->assertRedirect(route('collections.show', $this->zeszyt));
        // Podwójne kliknięcie — ten sam skutek.
        $this->actingAs($this->jurek)->post(route('collections.invitations.accept', $zaproszenie))
            ->assertRedirect(route('collections.show', $this->zeszyt));

        $this->assertSame(1, DB::table('collection_members')->where('user_id', $this->jurek->getKey())->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $this->halina->getKey())
            ->where('type', Notification::TYPE_COLLECTION_JOINED)
            ->count());

        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt))->assertOk();
        $this->actingAs($this->jurek)->get(route('collections.index'))
            ->assertOk()->assertSee('Udostępnione Tobie')->assertSee('Obiady rodzinne');
    }

    public function test_odrzucenie_nie_daje_dostepu(): void
    {
        $zaproszenie = $this->zaproszenie();

        $this->actingAs($this->jurek)->post(route('collections.invitations.decline', $zaproszenie))
            ->assertRedirect(route('collections.index'));

        $this->assertSame(CollectionInvitation::STATUS_DECLINED, $zaproszenie->fresh()->status);
        $this->assertFalse($this->zeszyt->maCzlonka($this->jurek));
        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt))->assertForbidden();
    }

    public function test_uuid_zaproszenia_to_nie_autoryzacja(): void
    {
        $zaproszenie = $this->zaproszenie();
        $obcy = $this->user('obcy');

        $this->actingAs($obcy)->get(route('collections.invitations.show', $zaproszenie))->assertNotFound();
        $this->actingAs($obcy)->post(route('collections.invitations.accept', $zaproszenie))->assertNotFound();

        $this->assertFalse($this->zeszyt->maCzlonka($obcy));
    }

    public function test_link_jest_jednorazowy_wymaga_logowania_i_da_sie_go_odwolac(): void
    {
        [, $token] = app(ZaprosDoZeszytu::class)->linkiem($this->halina, $this->zeszyt);
        $obcy = $this->user('obcy');

        // Token nie leży w bazie — tylko jego skrót.
        $this->assertSame(0, DB::table('collection_invitations')->where('token_hash', $token)->count());

        $this->get(route('collections.link.show', $token))->assertRedirect(route('login'));

        $this->actingAs($this->jurek)->get(route('collections.link.show', $token))->assertOk()->assertSee('Dołączam');
        $this->actingAs($this->jurek)->post(route('collections.link.accept', $token))
            ->assertRedirect(route('collections.show', $this->zeszyt));

        // Drugi raz ten sam link nie działa dla nikogo.
        $this->actingAs($obcy)->post(route('collections.link.accept', $token))->assertStatus(410);
        $this->assertFalse($this->zeszyt->maCzlonka($obcy));

        [$drugi, $token2] = app(ZaprosDoZeszytu::class)->linkiem($this->halina, $this->zeszyt);
        $this->actingAs($this->halina)
            ->delete(route('collections.invitations.destroy', ['collection' => $this->zeszyt, 'invitation' => $drugi]))
            ->assertRedirect();
        $this->actingAs($obcy)->get(route('collections.link.show', $token2))->assertStatus(410);

        [, $token3] = app(ZaprosDoZeszytu::class)->linkiem($this->halina, $this->zeszyt);
        $this->travel(8)->days();
        $this->actingAs($obcy)->post(route('collections.link.accept', $token3))->assertStatus(410);
    }

    public function test_wspolpracownik_zapisuje_i_wyjmuje_a_widac_kto_dodal(): void
    {
        $this->dolaczJurka();
        $autor = $this->user('autor');
        $przepisHaliny = Recipe::factory()->for($autor, 'author')->create(['title' => 'Bigos Haliny']);
        $przepisJurka = Recipe::factory()->for($autor, 'author')->create(['title' => 'Pierogi Jurka']);

        $this->actingAs($this->halina)->post(route('collections.save', $przepisHaliny->slug), ['collection_id' => $this->zeszyt->getKey()])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->jurek)->post(route('collections.save', $przepisJurka->slug), ['collection_id' => $this->zeszyt->getKey()])
            ->assertSessionHasNoErrors();

        $this->assertSame((string) $this->jurek->getKey(), DB::table('collection_items')->where('recipe_id', $przepisJurka->getKey())->value('added_by_id'));

        $this->actingAs($this->halina)->get(route('collections.show', $this->zeszyt))
            ->assertOk()->assertSee('Dodane przez: Jurek')->assertSee('Dodane przez: Ty');

        // Wyjmuje także to, co dodała właścicielka.
        $this->actingAs($this->jurek)->delete(route('collections.unsave', $przepisHaliny->slug), ['collection_id' => $this->zeszyt->getKey()]);
        $this->assertSame(0, DB::table('collection_items')->where('recipe_id', $przepisHaliny->getKey())->count());

        // Wpis też.
        $wpis = Post::factory()->for($autor, 'author')->create();
        $this->actingAs($this->jurek)->post(route('collections.save-post', $wpis), ['collection_id' => $this->zeszyt->getKey()])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('collection_items')->where('collection_id', $this->zeszyt->getKey())->where('post_id', $wpis->getKey())->count());
    }

    public function test_obcy_nie_zapisze_do_cudzego_zeszytu_ani_nie_wyjmie(): void
    {
        $obcy = $this->user('obcy');
        $przepis = Recipe::factory()->create();
        $this->zeszyt->recipes()->attach($przepis->getKey(), ['created_at' => now(), 'added_by_id' => $this->halina->getKey()]);

        $this->actingAs($obcy)->post(route('collections.save', $przepis->slug), ['collection_id' => $this->zeszyt->getKey()])
            ->assertSessionHasErrors('collection_id');
        $this->actingAs($obcy)->delete(route('collections.unsave', $przepis->slug), ['collection_id' => $this->zeszyt->getKey()])
            ->assertSessionHasErrors('collection_id');

        $this->assertSame(1, DB::table('collection_items')->where('collection_id', $this->zeszyt->getKey())->count());
    }

    public function test_wspolpracownik_nie_zmienia_nazwy_widocznosci_ani_nie_usuwa_zeszytu(): void
    {
        $this->dolaczJurka();

        $this->actingAs($this->jurek)->get(route('collections.edit', $this->zeszyt))->assertForbidden();
        $this->actingAs($this->jurek)->patch(route('collections.update', $this->zeszyt), ['name' => 'Moje', 'visibility' => 'public'])->assertForbidden();
        $this->actingAs($this->jurek)->delete(route('collections.destroy', $this->zeszyt))->assertForbidden();
        $this->actingAs($this->jurek)->get(route('collections.sharing', $this->zeszyt))->assertForbidden();
        $this->actingAs($this->jurek)->post(route('collections.invitations.link', $this->zeszyt))->assertForbidden();

        $this->assertSame('private', $this->zeszyt->fresh()->visibility);
        $this->assertSame('Obiady rodzinne', $this->zeszyt->fresh()->name);
    }

    public function test_niedostepna_pozycja_nie_przecieka_wspolpracownikowi(): void
    {
        $this->dolaczJurka();
        $autor = $this->user('autor');
        $prywatny = Recipe::factory()->for($autor, 'author')->create(['title' => 'Tajny sernik', 'visibility' => 'private']);
        $this->zeszyt->recipes()->attach($prywatny->getKey(), ['created_at' => now(), 'note' => 'notatka o serniku', 'added_by_id' => $this->halina->getKey()]);

        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt))
            ->assertOk()
            ->assertDontSee('Tajny sernik')
            ->assertDontSee('notatka o serniku')
            ->assertDontSee('nie jest dla Ciebie dostępny');
    }

    public function test_widocznosc_zeszytu_sie_nie_zmienia_a_obcy_nie_widzi_kto_ma_dostep(): void
    {
        $this->dolaczJurka();
        $obcy = $this->user('obcy');

        $this->actingAs($obcy)->get(route('collections.show', $this->zeszyt))->assertForbidden();

        $this->zeszyt->forceFill(['visibility' => 'public'])->save();
        $przepis = Recipe::factory()->create(['title' => 'Rosół']);
        $this->zeszyt->recipes()->attach($przepis->getKey(), ['created_at' => now(), 'added_by_id' => $this->jurek->getKey()]);

        $this->actingAs($obcy)->get(route('collections.show', $this->zeszyt))
            ->assertOk()->assertSee('Rosół')->assertDontSee('Dodane przez')->assertDontSee('Jurek');
    }

    public function test_wlascicielka_odbiera_dostep_a_pozycje_zostaja(): void
    {
        $this->dolaczJurka();
        $przepis = Recipe::factory()->create();
        $this->actingAs($this->jurek)->post(route('collections.save', $przepis->slug), ['collection_id' => $this->zeszyt->getKey()]);

        $this->actingAs($this->halina)
            ->delete(route('collections.members.destroy', ['collection' => $this->zeszyt, 'member' => $this->jurek->getKey()]))
            ->assertRedirect(route('collections.sharing', $this->zeszyt));

        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt))->assertForbidden();
        $this->assertSame(1, DB::table('collection_items')->where('collection_id', $this->zeszyt->getKey())->count());
    }

    public function test_obcy_nie_odbierze_dostepu(): void
    {
        $this->dolaczJurka();
        $obcy = $this->user('obcy');

        $this->actingAs($obcy)
            ->delete(route('collections.members.destroy', ['collection' => $this->zeszyt, 'member' => $this->jurek->getKey()]))
            ->assertForbidden();
        $this->actingAs($this->jurek)
            ->delete(route('collections.members.destroy', ['collection' => $this->zeszyt, 'member' => $this->jurek->getKey()]))
            ->assertForbidden();

        $this->assertTrue($this->zeszyt->maCzlonka($this->jurek));
    }

    public function test_wspolpracownik_odchodzi_sam(): void
    {
        $this->dolaczJurka();

        $this->actingAs($this->jurek)->delete(route('collections.leave', $this->zeszyt))
            ->assertRedirect(route('collections.index'));

        $this->assertFalse($this->zeszyt->maCzlonka($this->jurek));
        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt))->assertForbidden();
    }

    /** @return array<string, array{0: bool}> */
    public static function kierunkiBlokady(): array
    {
        return ['właścicielka blokuje' => [true], 'współpracownik blokuje' => [false]];
    }

    #[DataProvider('kierunkiBlokady')]
    public function test_blokada_w_dowolna_strone_odbiera_dostep_i_odwoluje_zaproszenia(bool $blokujeWlascicielka): void
    {
        $this->dolaczJurka();
        $basia = $this->user('basia');
        $zaproszenieBasi = app(ZaprosDoZeszytu::class)->poNazwie($this->halina, $this->zeszyt, 'basia');

        [$kto, $kogo] = $blokujeWlascicielka ? [$this->halina, $this->jurek] : [$this->jurek, $this->halina];
        app(BlockUser::class)->handle($kto, $kogo);
        app(BlockUser::class)->handle($basia, $this->halina);

        $this->assertFalse($this->zeszyt->maCzlonka($this->jurek));
        $this->assertSame(CollectionInvitation::STATUS_REVOKED, $zaproszenieBasi->fresh()->status);
        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt))->assertForbidden();

        // Odblokowanie niczego nie przywraca.
        DB::table('blocks')->delete();
        $this->assertFalse($this->zeszyt->maCzlonka($this->jurek));
    }

    public function test_link_nie_da_sie_przyjac_przy_blokadzie(): void
    {
        [$zaproszenie] = app(ZaprosDoZeszytu::class)->linkiem($this->halina, $this->zeszyt);
        app(BlockUser::class)->handle($this->jurek, $this->halina);

        $this->expectException(BladDlaCzlowieka::class);
        app(OdpowiedzNaZaproszenie::class)->przyjmij($this->jurek, $zaproszenie);
    }

    public function test_ban_i_usuwanie_konta_wlascicielki_zamykaja_dostep_a_powrot_go_przywraca(): void
    {
        $this->dolaczJurka();

        $this->halina->ban();
        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt->fresh()))->assertForbidden();
        $this->assertSame(0, Collection::query()->dostepneDoZapisuDla($this->jurek)->whereKey($this->zeszyt->getKey())->count());

        $this->halina->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt->fresh()))->assertOk();

        $this->halina->fresh()->markForDeletion();
        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt->fresh()))->assertForbidden();
    }

    public function test_zawieszony_wspolpracownik_nie_dopisze_do_publicznego_zeszytu(): void
    {
        $this->dolaczJurka();
        $this->zeszyt->forceFill(['visibility' => 'public'])->save();
        $this->jurek->suspend(now()->addDay());

        $this->assertFalse($this->jurek->fresh()->can('addItem', $this->zeszyt->fresh()));
        $this->assertTrue($this->jurek->fresh()->can('removeItem', $this->zeszyt->fresh()));
    }

    public function test_domyslnego_zeszytu_nie_da_sie_udostepnic_i_baza_tego_pilnuje(): void
    {
        $domyslny = $this->halina->defaultCollection();

        $this->assertFalse($this->halina->can('share', $domyslny));
        $this->actingAs($this->halina)->post(route('collections.invitations.store', $domyslny), ['nazwa' => 'jurek'])->assertForbidden();

        $this->expectException(QueryException::class);
        DB::table('collection_members')->insert(['collection_id' => $domyslny->getKey(), 'user_id' => $this->jurek->getKey()]);
    }

    public function test_wlascicielka_nie_moze_byc_czlonkiem_wlasnego_zeszytu(): void
    {
        $this->expectException(QueryException::class);
        DB::table('collection_members')->insert(['collection_id' => $this->zeszyt->getKey(), 'user_id' => $this->halina->getKey()]);
    }

    public function test_limit_miejsc_liczy_czlonkow_i_oczekujace_zaproszenia(): void
    {
        config(['kuking.collections.max_members' => 2]);
        $this->dolaczJurka();
        app(ZaprosDoZeszytu::class)->linkiem($this->halina, $this->zeszyt);

        $this->expectExceptionMessage(ZaprosDoZeszytu::PELNY);
        app(ZaprosDoZeszytu::class)->linkiem($this->halina, $this->zeszyt);
    }

    public function test_lista_wyboru_pokazuje_wspolny_zeszyt_z_nazwa_wlascicielki(): void
    {
        $this->dolaczJurka();
        $przepis = Recipe::factory()->create();

        $this->actingAs($this->jurek)->get($przepis->url())
            ->assertOk()->assertSee('Obiady rodzinne')->assertSee('Wspólny zeszyt osoby Halina');
    }

    public function test_eksport_w_granicach_art_15_ust_4(): void
    {
        $this->dolaczJurka();
        $autor = $this->user('autor', ['display_name' => 'Autor']);
        $odHaliny = Recipe::factory()->for($autor, 'author')->create(['title' => 'Od Haliny']);
        $odJurka = Recipe::factory()->for($autor, 'author')->create(['title' => 'Od Jurka']);
        $this->zeszyt->recipes()->attach($odHaliny->getKey(), ['created_at' => now(), 'added_by_id' => $this->halina->getKey()]);
        $this->zeszyt->recipes()->attach($odJurka->getKey(), ['created_at' => now(), 'added_by_id' => $this->jurek->getKey(), 'note' => 'dla wnuków']);

        $paczkaHaliny = $this->paczka($this->halina->fresh());
        $wlasny = collect($paczkaHaliny['kolekcje'])->firstWhere('nazwa', 'Obiady rodzinne');
        $this->assertSame(['Jurek'], $wlasny['osoby_z_dostepem']);
        $this->assertEqualsCanonicalizing(['ja', 'Jurek'], array_column($wlasny['przepisy'], 'dodane_przez'));
        $this->assertSame('po nazwie konta', $paczkaHaliny['zaproszenia_do_zeszytow']['wyslane'][0]['sposob']);

        $paczkaJurka = $this->paczka($this->jurek->fresh());
        $this->assertCount(1, $paczkaJurka['zeszyty_udostepnione_mi']);
        $wspolny = $paczkaJurka['zeszyty_udostepnione_mi'][0];
        $this->assertSame('Halina', $wspolny['wlasciciel']);
        $this->assertSame(['Od Jurka'], array_column($wspolny['dodane_przeze_mnie']['przepisy'], 'tytul'));
        $this->assertSame('dla wnuków', $wspolny['dodane_przeze_mnie']['przepisy'][0]['notatka']);
        $this->assertStringNotContainsString('Od Haliny', (string) json_encode($paczkaJurka, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Halina', $paczkaJurka['zaproszenia_do_zeszytow']['otrzymane'][0]['od']);
    }

    public function test_usuniecie_konta_wspolpracownika_zostawia_pozycje_bez_podpisu(): void
    {
        $this->dolaczJurka();
        $przepis = Recipe::factory()->create();
        $this->zeszyt->recipes()->attach($przepis->getKey(), ['created_at' => now(), 'added_by_id' => $this->jurek->getKey()]);
        $jegoZeszyt = Collection::create(['owner_id' => $this->jurek->getKey(), 'name' => 'Jurka', 'visibility' => 'private']);
        DB::table('collection_members')->insert(['collection_id' => $jegoZeszyt->getKey(), 'user_id' => $this->halina->getKey(), 'created_at' => now()]);

        // Druga osoba z dostępem — zeszyt dalej jest wspólny, więc podpis widać.
        $basia = $this->user('basia');
        app(OdpowiedzNaZaproszenie::class)->przyjmij($basia, app(ZaprosDoZeszytu::class)->poNazwie($this->halina, $this->zeszyt, 'basia'));

        $this->jurek->markForDeletion();
        $this->assertTrue(app(EraseAccountData::class)->handle($this->jurek->fresh()));

        $this->assertSame(0, DB::table('collection_members')->where('user_id', $this->jurek->getKey())->count());
        $this->assertSame(0, DB::table('collection_members')->where('collection_id', $jegoZeszyt->getKey())->count());
        $this->assertTrue($this->zeszyt->maCzlonka($basia));
        $this->assertSame(0, DB::table('collection_invitations')->where('invitee_id', $this->jurek->getKey())->count());
        $this->assertSame(1, DB::table('collection_items')->where('collection_id', $this->zeszyt->getKey())->count());
        $this->assertNull(DB::table('collection_items')->where('collection_id', $this->zeszyt->getKey())->value('added_by_id'));

        $this->actingAs($this->halina)->get(route('collections.show', $this->zeszyt))
            ->assertOk()->assertSee('Dodane przez: osoba, która usunęła konto');
    }

    public function test_usuniecie_konta_wlascicielki_konczy_dostep_wspolpracownika(): void
    {
        $this->dolaczJurka();

        $this->halina->markForDeletion(User::DELETE_SCOPE_MINIMUM);
        app(EraseAccountData::class)->handle($this->halina->fresh());

        $this->assertFalse($this->zeszyt->maCzlonka($this->jurek));
        $this->actingAs($this->jurek)->get(route('collections.index'))->assertOk()->assertDontSee('Obiady rodzinne');
    }

    public function test_zaproszenie_nieaktualne_po_odwolaniu_mowi_to_wprost(): void
    {
        $zaproszenie = $this->zaproszenie();
        $this->actingAs($this->halina)
            ->delete(route('collections.invitations.destroy', ['collection' => $this->zeszyt, 'invitation' => $zaproszenie]));

        $this->actingAs($this->jurek)->post(route('collections.invitations.accept', $zaproszenie))
            ->assertRedirect(route('collections.index'))
            ->assertSessionHasErrors(['zaproszenie' => OdpowiedzNaZaproszenie::NIEAKTUALNE]);
        $this->assertFalse($this->zeszyt->maCzlonka($this->jurek));
        $this->assertTrue(Str::isUuid((string) $zaproszenie->getKey()));
    }
}
