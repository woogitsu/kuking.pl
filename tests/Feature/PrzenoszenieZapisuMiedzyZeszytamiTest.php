<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Przenieś do innego zeszytu" (issue #2430, decyzja właściciela z 2.10.2026,
 * D-333): jedna pozycja między dwoma WŁASNYMI, PRYWATNYMI zeszytami tej samej
 * osoby, z notatką, datą zapisu i dodającym; bez kopii, powiadomienia i
 * zmiany liczby zapisujących. Konflikt w celu niczego nie nadpisuje.
 */
class PrzenoszenieZapisuMiedzyZeszytamiTest extends TestCase
{
    use RefreshDatabase;

    private User $basia;

    private Collection $zrodlo;

    private Collection $cel;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basia = $this->user('basia');
        $this->zrodlo = $this->zeszyt($this->basia, 'Zapisane');
        $this->cel = $this->zeszyt($this->basia, 'Obiady');
        $this->przepis = Recipe::factory()->create(['title' => 'Zupa dnia', 'visibility' => 'public', 'status' => Recipe::STATUS_PUBLISHED]);
        $this->zrodlo->recipes()->attach($this->przepis->getKey(), [
            'note' => 'dla rodziny bez koperku',
            'created_at' => '2026-03-01 10:00:00',
            'added_by_id' => $this->basia->getKey(),
        ]);
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc = 'private'): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => $widocznosc]);
    }

    private function przenies(Collection $z, Collection|string $do, string $typ, string $id, ?User $kto = null): TestResponse
    {
        return $this->actingAs($kto ?? $this->basia)
            ->from(route('collections.move.form', ['collection' => $z, 'typ' => $typ, 'pozycja' => $id]))
            ->post(route('collections.move.store', ['collection' => $z, 'typ' => $typ, 'pozycja' => $id]), ['cel' => $do instanceof Collection ? $do->getKey() : $do]);
    }

    /** @return object{note: ?string, created_at: string, added_by_id: ?string, position: ?int}|null */
    private function wiersz(Collection $zeszyt, string $kolumna, string $id): ?object
    {
        return DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->where($kolumna, $id)->first();
    }

    public function test_przepis_przechodzi_z_notatka_data_i_dodajacym_a_w_zrodle_znika(): void
    {
        $przed = $this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey());
        $powiadomienia = Notification::query()->count();
        $audyt = DB::table('audit_log')->count();

        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())
            ->assertRedirect(route('collections.show', $this->cel))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey()));
        $po = $this->wiersz($this->cel, 'recipe_id', $this->przepis->getKey());
        $this->assertNotNull($po);
        $this->assertNotNull($przed);
        $this->assertSame('dla rodziny bez koperku', $po->note);
        $this->assertSame($przed->created_at, $po->created_at);
        $this->assertSame($przed->added_by_id, $po->added_by_id);
        $this->assertSame(1, DB::table('collection_items')->where('recipe_id', $this->przepis->getKey())->count(), 'bez kopii');
        $this->assertSame($powiadomienia, Notification::query()->count(), 'bez powiadomienia autora');
        $this->assertSame($audyt + 1, DB::table('audit_log')->count());
        $wpis = DB::table('audit_log')->where('action', 'collection_item.moved')->sole();
        $this->assertStringNotContainsString('Obiady', (string) $wpis->metadata);
        $this->assertStringNotContainsString('koperku', (string) $wpis->metadata);
    }

    public function test_wpis_tez_mozna_przeniesc(): void
    {
        $wpis = Post::factory()->create(['status' => Post::STATUS_PUBLISHED, 'visibility' => 'public']);
        $this->zrodlo->posts()->attach($wpis->getKey(), ['note' => 'na urodziny taty', 'created_at' => '2026-02-02 08:00:00', 'added_by_id' => $this->basia->getKey()]);

        $this->przenies($this->zrodlo, $this->cel, 'wpis', $wpis->getKey())->assertRedirect(route('collections.show', $this->cel));

        $this->assertNull($this->wiersz($this->zrodlo, 'post_id', $wpis->getKey()));
        $this->assertSame('na urodziny taty', $this->wiersz($this->cel, 'post_id', $wpis->getKey())?->note);
    }

    public function test_cel_z_ta_sama_pozycja_to_konflikt_bez_nadpisania_i_z_obiema_notatkami(): void
    {
        $this->cel->recipes()->attach($this->przepis->getKey(), ['note' => 'inna notatka w celu', 'created_at' => '2026-05-05 10:00:00', 'added_by_id' => $this->basia->getKey()]);

        $html = $this->followingRedirects()->actingAs($this->basia)
            ->from(route('collections.move.form', ['collection' => $this->zrodlo, 'typ' => 'przepis', 'pozycja' => $this->przepis->getKey()]))
            ->post(route('collections.move.store', ['collection' => $this->zrodlo, 'typ' => 'przepis', 'pozycja' => $this->przepis->getKey()]), ['cel' => $this->cel->getKey()])
            ->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('Nic nie zostało przeniesione', $html);
        $this->assertStringContainsString('dla rodziny bez koperku', $html);
        $this->assertStringContainsString('inna notatka w celu', $html);
        $this->assertSame('dla rodziny bez koperku', $this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey())?->note);
        $this->assertSame('inna notatka w celu', $this->wiersz($this->cel, 'recipe_id', $this->przepis->getKey())?->note);
    }

    public function test_ponowne_wyslanie_po_udanym_przeniesieniu_nie_dubluje(): void
    {
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())->assertRedirect();
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())
            ->assertRedirect(route('collections.show', $this->cel));

        $this->assertSame(1, DB::table('collection_items')->where('recipe_id', $this->przepis->getKey())->count());
        $this->assertSame('dla rodziny bez koperku', $this->wiersz($this->cel, 'recipe_id', $this->przepis->getKey())?->note);
    }

    public function test_pozycji_nie_ma_nigdzie_to_404(): void
    {
        $inny = Recipe::factory()->create();

        $this->przenies($this->zrodlo, $this->cel, 'przepis', $inny->getKey())->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function utrataDostepuPoPrzeniesieniu(): array
    {
        return [
            'prywatny przepis' => ['private'],
            'tylko obserwujący' => ['followers'],
            'blokada przez autora' => ['blokada_autora'],
            'blokada przez zapisującą osobę' => ['blokada_zapisujacej'],
            'ban autora' => ['ban'],
        ];
    }

    #[DataProvider('utrataDostepuPoPrzeniesieniu')]
    public function test_ponowienie_nie_ujawnia_nowego_niedostepnego_tytulu(string $zmiana): void
    {
        $przed = $this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey());
        $this->assertNotNull($przed);
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())->assertRedirect();

        $tytul = 'Prywatny przepis rodzinny 2809';
        $this->przepis->forceFill(['title' => $tytul])->save();
        if (in_array($zmiana, ['private', 'followers'], true)) {
            $this->przepis->forceFill(['visibility' => $zmiana])->save();
        } elseif ($zmiana === 'ban') {
            $this->przepis->author->ban();
        } else {
            DB::table('blocks')->insert([
                'blocker_id' => $zmiana === 'blokada_autora' ? $this->przepis->author_id : $this->basia->getKey(),
                'blocked_id' => $zmiana === 'blokada_autora' ? $this->basia->getKey() : $this->przepis->author_id,
                'created_at' => now(),
            ]);
        }

        $odpowiedz = $this->followingRedirects()->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())
            ->assertOk();
        $this->assertStringNotContainsString($tytul, (string) $odpowiedz->getContent(), 'PRZENIESIENIE_2809_TYTUL_POD_POLICY');
        $this->assertStringNotContainsString($tytul, (string) session('status'), 'PRZENIESIENIE_2809_FLASH_POD_POLICY');
        $odpowiedz->assertSee('Ta pozycja jest już w zeszycie');

        $po = $this->wiersz($this->cel, 'recipe_id', $this->przepis->getKey());
        $this->assertNotNull($po);
        $this->assertSame($przed->note, $po->note);
        $this->assertSame($przed->created_at, $po->created_at);
        $this->assertSame($przed->added_by_id, $po->added_by_id);
        $this->assertNull($this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey()));
        $this->assertSame(1, DB::table('collection_items')->where('recipe_id', $this->przepis->getKey())->count());
    }

    public function test_ponowienie_po_przywroceniu_dostepu_i_wlasny_prywatny_przepis_maja_tytul(): void
    {
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())->assertRedirect();
        $this->przepis->forceFill(['visibility' => 'private'])->save();
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())->assertRedirect();
        $this->assertStringNotContainsString('Zupa dnia', (string) session('status'));

        $this->przepis->forceFill(['visibility' => 'public'])->save();
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())->assertRedirect();
        $this->assertStringContainsString('Zupa dnia', (string) session('status'));

        $wlasny = Recipe::factory()->create(['author_id' => $this->basia->getKey(), 'title' => 'Mój prywatny przepis', 'visibility' => 'private']);
        $this->zrodlo->recipes()->attach($wlasny->getKey(), ['created_at' => now(), 'added_by_id' => $this->basia->getKey()]);
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $wlasny->getKey())->assertRedirect();
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $wlasny->getKey())->assertRedirect();
        $this->assertStringContainsString('Mój prywatny przepis', (string) session('status'));
    }

    public function test_cofniecie_to_przeniesienie_z_powrotem_z_pozniejsza_notatka(): void
    {
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey());
        DB::table('collection_items')->where('collection_id', $this->cel->getKey())->update(['note' => 'poprawiona potem']);

        $this->przenies($this->cel, $this->zrodlo, 'przepis', $this->przepis->getKey())->assertRedirect(route('collections.show', $this->zrodlo));

        $this->assertSame('poprawiona potem', $this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey())?->note, 'późniejsza edycja nie ginie');
        $this->assertNull($this->wiersz($this->cel, 'recipe_id', $this->przepis->getKey()));
    }

    public function test_komunikat_po_przeniesieniu_mowi_gdzie_i_jak_cofnac(): void
    {
        $html = $this->followingRedirects()->actingAs($this->basia)
            ->post(route('collections.move.store', ['collection' => $this->zrodlo, 'typ' => 'przepis', 'pozycja' => $this->przepis->getKey()]), ['cel' => $this->cel->getKey()])
            ->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('przeniesiono do zeszytu „Obiady” razem z notatką i datą zapisu', $html);
        $this->assertStringContainsString('wskaż zeszyt „Zapisane”', $html);
    }

    public function test_cel_publiczny_wspolny_cudzy_albo_nieistniejacy_jest_odrzucony(): void
    {
        $publiczny = $this->zeszyt($this->basia, 'Dla wszystkich', 'public');
        $wspolny = $this->zeszyt($this->basia, 'Wspólny');
        DB::table('collection_members')->insert(['collection_id' => $wspolny->getKey(), 'user_id' => $this->user('ola')->getKey(), 'created_at' => now()]);
        $obca = $this->user('obca');
        $cudzy = $this->zeszyt($obca, 'Cudzy');

        foreach ([$publiczny, $wspolny, $cudzy] as $zly) {
            $this->przenies($this->zrodlo, $zly, 'przepis', $this->przepis->getKey())->assertSessionHasErrors('cel');
        }

        $this->przenies($this->zrodlo, '00000000-0000-4000-8000-000000000000', 'przepis', $this->przepis->getKey())->assertSessionHasErrors('cel');

        $this->assertSame('dla rodziny bez koperku', $this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey())?->note, 'źródło bez zmian');
        $this->assertSame(0, DB::table('collection_items')->whereIn('collection_id', [$publiczny->getKey(), $wspolny->getKey(), $cudzy->getKey()])->count());
    }

    public function test_zrodlo_publiczne_wspolne_lub_cudze_daje_403(): void
    {
        $obca = $this->user('obca');

        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey(), $obca)->assertForbidden();
        $this->actingAs($obca)->get(route('collections.move.form', ['collection' => $this->zrodlo, 'typ' => 'przepis', 'pozycja' => $this->przepis->getKey()]))->assertForbidden();

        $this->zrodlo->forceFill(['visibility' => 'public'])->save();
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())->assertForbidden();

        $this->zrodlo->forceFill(['visibility' => 'private'])->save();
        DB::table('collection_members')->insert(['collection_id' => $this->zrodlo->getKey(), 'user_id' => $obca->getKey(), 'created_at' => now()]);
        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())->assertForbidden();

        $this->assertNotNull($this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey()));
    }

    public function test_przepis_ktory_przestal_byc_widoczny_nie_jest_przenoszony(): void
    {
        $this->przepis->forceFill(['visibility' => 'private'])->save();

        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey())->assertSessionHasErrors('cel');

        $this->assertNotNull($this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey()));
        $this->assertNull($this->wiersz($this->cel, 'recipe_id', $this->przepis->getKey()));
    }

    public function test_ten_sam_zeszyt_jako_cel_mowi_co_zrobic(): void
    {
        $this->przenies($this->zrodlo, $this->zrodlo, 'przepis', $this->przepis->getKey())
            ->assertSessionHasErrors(['cel' => 'To jest ten sam zeszyt. Wybierz inny zeszyt, do którego chcesz przenieść tę pozycję.']);
    }

    public function test_brak_wyboru_celu_daje_blad_przy_polu_a_zrodlo_zostaje(): void
    {
        $this->actingAs($this->basia)
            ->post(route('collections.move.store', ['collection' => $this->zrodlo, 'typ' => 'przepis', 'pozycja' => $this->przepis->getKey()]), [])
            ->assertSessionHasErrors(['cel' => 'Wybierz zeszyt, do którego chcesz przenieść tę pozycję.']);

        $this->assertNotNull($this->wiersz($this->zrodlo, 'recipe_id', $this->przepis->getKey()));
    }

    public function test_pozycja_w_ulozonym_celu_staje_na_koncu_a_w_nieulozonym_zostaje_bez_pozycji(): void
    {
        $inny = Recipe::factory()->create();
        $this->cel->recipes()->attach($inny->getKey(), ['created_at' => now(), 'added_by_id' => $this->basia->getKey(), 'position' => 1]);

        $this->przenies($this->zrodlo, $this->cel, 'przepis', $this->przepis->getKey());
        $this->assertSame(2, $this->wiersz($this->cel, 'recipe_id', $this->przepis->getKey())?->position);

        $pusty = $this->zeszyt($this->basia, 'Pusty');
        $this->przenies($this->cel, $pusty, 'przepis', $this->przepis->getKey());
        $this->assertNull($this->wiersz($pusty, 'recipe_id', $this->przepis->getKey())?->position);
    }

    public function test_formularz_pokazuje_tylko_wlasne_prywatne_zeszyty_bez_zrodla_i_nie_ujawnia_tytulu_niedostepnego(): void
    {
        $this->zeszyt($this->basia, 'Dla wszystkich', 'public');
        $wspolny = $this->zeszyt($this->basia, 'Wspólny');
        DB::table('collection_members')->insert(['collection_id' => $wspolny->getKey(), 'user_id' => $this->user('ola')->getKey(), 'created_at' => now()]);
        $this->zeszyt($this->user('obca'), 'Cudzy zeszyt');

        $html = $this->actingAs($this->basia)
            ->get(route('collections.move.form', ['collection' => $this->zrodlo, 'typ' => 'przepis', 'pozycja' => $this->przepis->getKey()]))
            ->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/<form[^>]*novalidate/', $html);
        $this->assertStringContainsString('Obiady', $html);
        $this->assertStringContainsString('Przenosisz „Zupa dnia”', $html);
        foreach (['Dla wszystkich', 'Wspólny', 'Cudzy zeszyt'] as $niechciany) {
            $this->assertStringNotContainsString($niechciany, $html);
        }
        $this->assertSame(1, substr_count($html, 'type="radio" name="cel"'), 'źródło nie jest celem');

        $this->przepis->forceFill(['visibility' => 'private'])->save();
        $ukryty = $this->actingAs($this->basia)
            ->get(route('collections.move.form', ['collection' => $this->zrodlo, 'typ' => 'przepis', 'pozycja' => $this->przepis->getKey()]))
            ->getContent();
        $this->assertIsString($ukryty);
        $this->assertStringNotContainsString('Zupa dnia', $ukryty);
    }

    public function test_przycisk_jest_przy_pozycji_tylko_dla_wlasciciela_prywatnego_zeszytu_bez_zaproszonych(): void
    {
        $this->actingAs($this->basia)->get(route('collections.show', $this->zrodlo))->assertOk()->assertSee('Przenieś do innego zeszytu');

        $this->zrodlo->forceFill(['visibility' => 'public'])->save();
        $this->actingAs($this->basia)->get(route('collections.show', $this->zrodlo))->assertOk()->assertDontSee('Przenieś do innego zeszytu');
        $this->actingAs($this->user('obca'))->get(route('collections.show', $this->zrodlo))->assertOk()->assertDontSee('Przenieś do innego zeszytu');
        auth()->logout();
        $this->get(route('collections.show', $this->zrodlo))->assertOk()->assertDontSee('Przenieś do innego zeszytu');
    }
}
