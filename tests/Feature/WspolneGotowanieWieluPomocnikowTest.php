<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookingSession;
use App\Models\CookingSessionInvitation;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Wspólne gotowanie z WIELU pomocnikami (#2385; decyzja właściciela z 1.10.2026:
 * gospodarz i do trzech pomocników). Projekt:
 * `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`, sekcje 3, 8 i 9.
 *
 * Wyścigi na dwóch połączeniach: `tests/Dwa/WspolneGotowanieNaDwochPolaczeniachTest.php`.
 */
class WspolneGotowanieWieluPomocnikowTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /** @return array{0: User, 1: CookingSession, 2: list<RecipeStep>} */
    private function sesjaGospodarza(): array
    {
        $gospodarz = $this->user('gospodyni'.substr(md5(uniqid('', true)), 0, 8), ['display_name' => 'Gospodyni']);
        $recipe = Recipe::factory()->create(['author_id' => $gospodarz->getKey(), 'visibility' => 'public']);
        $kroki = [];
        foreach ([0, 1, 2] as $i) {
            $kroki[] = RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $i,
                'instruction' => 'Krok numer '.($i + 1).'.',
            ]);
        }

        return [$gospodarz, app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe), $kroki];
    }

    private function token(User $gospodarz, CookingSession $sesja): string
    {
        return app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja)[1];
    }

    /** Dokłada pomocnika zwykłą drogą: nowy link i przyjęcie przez HTTP. */
    private function dolacz(User $gospodarz, CookingSession $sesja, string $nazwa): User
    {
        $pomocnik = $this->user(strtolower($nazwa).substr(md5(uniqid('', true)), 0, 8), ['display_name' => $nazwa]);
        $this->actingAs($pomocnik)
            ->post(route('wspolne-gotowanie.link.accept', $this->token($gospodarz, $sesja)))
            ->assertRedirect(route('wspolne-gotowanie.show', $sesja));

        return $pomocnik;
    }

    /** @return list<string> */
    private function idPomocnikow(CookingSession $sesja): array
    {
        $id = DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->pluck('user_id')->map(fn ($v): string => (string) $v)->all();
        sort($id);

        return $id;
    }

    /**
     * @param  list<User>  $osoby
     * @return list<string>
     */
    private function posortowaneId(array $osoby): array
    {
        $id = array_map(fn (User $u): string => (string) $u->getKey(), $osoby);
        sort($id);

        return $id;
    }

    private function krok(User $osoba, CookingSession $sesja, RecipeStep $krok, bool $zrobiono = true)
    {
        return $this->actingAs($osoba)->post(route('wspolne-gotowanie.krok', $sesja), [
            'krok_id' => $krok->getKey(),
            'zrobiono' => $zrobiono ? '1' : '0',
        ]);
    }

    // ---------------------------------------------------------------------
    // Limit i zaproszenia
    // ---------------------------------------------------------------------

    public function test_limit_domyslnie_to_trzech_pomocnikow_a_czwarty_link_nie_powstaje(): void
    {
        $this->assertSame(3, config('kuking.wspolne_gotowanie.max_pomocnikow'));

        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        $obserwacje = [];
        foreach (['Basia', 'Cezary', 'Dorota'] as $nazwa) {
            $this->dolacz($gospodarz, $sesja, $nazwa);
            $obserwacje[$nazwa] = count($this->idPomocnikow($sesja));
        }

        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.link.store', $sesja))->assertSessionHasErrors('link');
        $obserwacje['linki po czwartej próbie'] = CookingSessionInvitation::query()->where('status', 'pending')->count();

        // Żywy link z ostatniego dołączenia zostaje (wielorazowy), ale nowy się nie pojawił.
        $this->assertSame(['Basia' => 1, 'Cezary' => 2, 'Dorota' => 3, 'linki po czwartej próbie' => 1], $obserwacje);
    }

    public function test_czwarta_osoba_z_linkiem_wystawionym_przed_obnizeniem_limitu_nie_wchodzi(): void
    {
        // Obrona w głębi: sama akcja przyjęcia pilnuje limitu, nie tylko tworzenie linku.
        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        config(['kuking.wspolne_gotowanie.max_pomocnikow' => 4]);
        foreach (['Basia', 'Cezary', 'Dorota'] as $nazwa) {
            $this->dolacz($gospodarz, $sesja, $nazwa);
        }
        $token = $this->token($gospodarz, $sesja);
        config(['kuking.wspolne_gotowanie.max_pomocnikow' => 3]);

        $czwarta = $this->user('ewa');
        $obserwacje = [];
        $this->actingAs($czwarta)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $obserwacje['przy limicie 3'] = count($this->idPomocnikow($sesja));

        // Kontrola dodatnia: ten sam link przy limicie 4 wpuszcza.
        config(['kuking.wspolne_gotowanie.max_pomocnikow' => 4]);
        $this->actingAs($czwarta)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect();
        $obserwacje['przy limicie 4'] = count($this->idPomocnikow($sesja));

        $this->assertSame(['przy limicie 3' => 3, 'przy limicie 4' => 4], $obserwacje);
    }

    public function test_jeden_link_wpuszcza_wiele_osob_a_nowy_uniewaznia_poprzedni(): void
    {
        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        $pierwszy = $this->token($gospodarz, $sesja);
        $drugi = $this->token($gospodarz, $sesja);   // unieważnia $pierwszy
        $obserwacje = [];

        $this->actingAs($this->user('ala'))->post(route('wspolne-gotowanie.link.accept', $pierwszy))->assertStatus(410);
        $obserwacje['po unieważnionym linku'] = count($this->idPomocnikow($sesja));

        $this->actingAs($this->user('basia'))->post(route('wspolne-gotowanie.link.accept', $drugi))->assertRedirect();
        $obserwacje['po przyjęciu drugiego'] = count($this->idPomocnikow($sesja));

        // Ten sam link wpuszcza kolejną osobę (wielorazowy).
        $this->actingAs($this->user('cezary'))->post(route('wspolne-gotowanie.link.accept', $drugi))->assertRedirect();
        $obserwacje['po drugiej osobie z tego samego linku'] = count($this->idPomocnikow($sesja));

        // Nowy link unieważnia drugi, także dla tych, którzy jeszcze nie weszli.
        $trzeci = $this->token($gospodarz, $sesja);
        $dorota = $this->user('dorota');
        $this->actingAs($dorota)->post(route('wspolne-gotowanie.link.accept', $drugi))->assertStatus(410);
        $obserwacje['po unieważnionym drugim linku'] = count($this->idPomocnikow($sesja));
        $this->actingAs($dorota)->post(route('wspolne-gotowanie.link.accept', $trzeci))->assertRedirect();
        $obserwacje['po nowym linku'] = count($this->idPomocnikow($sesja));

        $this->assertSame([
            'po unieważnionym linku' => 0,
            'po przyjęciu drugiego' => 1,
            'po drugiej osobie z tego samego linku' => 2,
            'po unieważnionym drugim linku' => 2,
            'po nowym linku' => 3,
        ], $obserwacje);
    }

    public function test_usuniecie_jednego_pomocnika_zwalnia_miejsce_a_pozostali_zostaja(): void
    {
        [$gospodarz, $sesja, $kroki] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $cezary = $this->dolacz($gospodarz, $sesja, 'Cezary');
        $dorota = $this->dolacz($gospodarz, $sesja, 'Dorota');
        $this->krok($cezary, $sesja, $kroki[0])->assertRedirect();

        $this->actingAs($gospodarz)->delete(route('wspolne-gotowanie.pomocnik.destroy', ['cookingSession' => $sesja, 'user' => $cezary->getKey()]))->assertRedirect();

        $this->actingAs($cezary)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
        $this->actingAs($basia)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $this->actingAs($dorota)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $this->assertSame(1, DB::table('cooking_session_steps')->count(), 'odhaczenie usuniętego pomocnika zostaje');

        $ewa = $this->dolacz($gospodarz, $sesja, 'Ewa');
        $this->assertSame($this->posortowaneId([$basia, $dorota, $ewa]), $this->idPomocnikow($sesja));
    }

    public function test_pomocnik_nie_tworzy_linku_gdy_jest_wielu_pomocnikow(): void
    {
        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $this->dolacz($gospodarz, $sesja, 'Cezary');

        $this->actingAs($basia)->post(route('wspolne-gotowanie.link.store', $sesja))->assertForbidden();
        // Nie powstał nowy link (zostaje wyłącznie żywy z ostatniego dołączenia Cezarego).
        $this->assertSame(1, CookingSessionInvitation::query()->where('status', 'pending')->count());
    }

    // ---------------------------------------------------------------------
    // Blokady między pomocnikami
    // ---------------------------------------------------------------------

    public function test_zablokowani_wzajemnie_pomocnicy_nie_siedza_w_jednej_sesji_w_zadna_strone(): void
    {
        foreach (['obecny-blokuje-nowego', 'nowy-blokuje-obecnego'] as $kierunek) {
            [$gospodarz, $sesja] = $this->sesjaGospodarza();
            $obecny = $this->dolacz($gospodarz, $sesja, 'Obecny');
            $token = $this->token($gospodarz, $sesja);
            $nowy = $this->user('nowy'.substr(md5($kierunek), 0, 8), ['display_name' => 'Nowy']);

            $kierunek === 'obecny-blokuje-nowego'
                ? app(BlockUser::class)->handle($obecny, $nowy)
                : app(BlockUser::class)->handle($nowy, $obecny);

            $this->actingAs($nowy)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
            $poOdmowie = $this->idPomocnikow($sesja);

            // Kontrola dodatnia: ten sam link wpuszcza osobę bez blokady.
            $this->actingAs($this->user('inna'.substr(md5($kierunek), 0, 8)))->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect();

            $this->assertSame([[(string) $obecny->getKey()], 2], [$poOdmowie, count($this->idPomocnikow($sesja))], $kierunek);
        }
    }

    public function test_odmowa_przy_blokadzie_miedzy_pomocnikami_ma_to_samo_zdanie_co_kazda_inna(): void
    {
        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        $obecny = $this->dolacz($gospodarz, $sesja, 'Obecny');
        $token = $this->token($gospodarz, $sesja);
        $zablokowany = $this->user('kandydatka');
        app(BlockUser::class)->handle($obecny, $zablokowany);

        $zBlokada = $this->actingAs($zablokowany)->post(route('wspolne-gotowanie.link.accept', $token));
        $zNieznanym = $this->actingAs($this->user('nieznany'))->post(route('wspolne-gotowanie.link.accept', str_repeat('a', 40)));

        $this->assertSame([410, 410], [$zBlokada->getStatusCode(), $zNieznanym->getStatusCode()]);
        foreach ([$zBlokada, $zNieznanym] as $odpowiedz) {
            $this->assertStringContainsString(e(ZaproszenieDoGotowania::NIEAKTUALNE), (string) $odpowiedz->getContent());
        }
        $this->assertStringNotContainsString('zablok', mb_strtolower((string) $zBlokada->getContent()));
    }

    public function test_blokada_w_trakcie_miedzy_pomocnikami_usuwa_zablokowanego_a_blokujacy_i_reszta_zostaja(): void
    {
        [$gospodarz, $sesja, $kroki] = $this->sesjaGospodarza();
        $blokujaca = $this->dolacz($gospodarz, $sesja, 'Blokujaca');
        $zablokowany = $this->dolacz($gospodarz, $sesja, 'Zablokowany');
        $trzeci = $this->dolacz($gospodarz, $sesja, 'Trzeci');
        $this->krok($zablokowany, $sesja, $kroki[0])->assertRedirect();
        $rewizja = $sesja->fresh()->revision;

        app(BlockUser::class)->handle($blokujaca, $zablokowany);

        $this->assertSame($this->posortowaneId([$blokujaca, $trzeci]), $this->idPomocnikow($sesja));
        $this->assertSame($rewizja + 1, $sesja->fresh()->revision, 'gospodarz i pozostali zobaczą zmianę składu');
        $this->actingAs($zablokowany)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
        $this->krok($zablokowany, $sesja, $kroki[1])->assertNotFound();
        $this->actingAs($blokujaca)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $this->actingAs($trzeci)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $this->assertSame(1, DB::table('cooking_session_steps')->count(), 'praca zablokowanego zostaje w sesji');
    }

    public function test_blokada_gospodarza_z_jednym_z_pomocnikow_nie_rusza_pozostalych(): void
    {
        foreach (['gospodarz-blokuje', 'pomocnik-blokuje'] as $kierunek) {
            [$gospodarz, $sesja] = $this->sesjaGospodarza();
            $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
            $cezary = $this->dolacz($gospodarz, $sesja, 'Cezary');

            $kierunek === 'gospodarz-blokuje'
                ? app(BlockUser::class)->handle($gospodarz, $basia)
                : app(BlockUser::class)->handle($basia, $gospodarz);

            $this->assertSame([(string) $cezary->getKey()], $this->idPomocnikow($sesja), $kierunek);
        }
    }

    public function test_blokada_osoby_spoza_sesji_nie_rusza_nikogo(): void
    {
        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $this->dolacz($gospodarz, $sesja, 'Cezary');
        $rewizja = $sesja->fresh()->revision;

        app(BlockUser::class)->handle($basia, $this->user('postronna'));

        $this->assertSame([2, $rewizja], [count($this->idPomocnikow($sesja)), $sesja->fresh()->revision]);
    }

    // ---------------------------------------------------------------------
    // Widok: uczestnicy, kto odhaczył, postęp, rewizje
    // ---------------------------------------------------------------------

    public function test_ekran_pokazuje_wszystkich_uczestnikow_i_kto_odhaczyl_ktory_krok(): void
    {
        [$gospodarz, $sesja, $kroki] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $cezary = $this->dolacz($gospodarz, $sesja, 'Cezary');
        $this->dolacz($gospodarz, $sesja, 'Dorota');
        $this->krok($basia, $sesja, $kroki[0])->assertRedirect();
        $this->krok($cezary, $sesja, $kroki[1])->assertRedirect();

        $ekran = (string) $this->actingAs($cezary)->get(route('wspolne-gotowanie.show', $sesja))->assertOk()->getContent();

        foreach (['Gospodyni', 'Basia', 'Cezary', 'Dorota'] as $nazwa) {
            $this->assertStringContainsString($nazwa, $ekran, $nazwa);
        }
        $this->assertStringContainsString('Cezary</strong> — pomocnik (to Ty)', $ekran);
        $this->assertStringContainsString('Zrobione kroki: <strong>2 z 3</strong>', $ekran);
        $this->assertMatchesRegularExpression('/odhaczone przez: Basia, \d\d:\d\d/', $ekran);
        $this->assertMatchesRegularExpression('/odhaczone przez: Cezary, \d\d:\d\d/', $ekran);
        // Pomocnik nie dostaje przycisków gospodarza.
        $this->assertStringNotContainsString('Odbierz dostęp pomocnikowi', $ekran);
        $this->assertStringNotContainsString('Utwórz link', $ekran);
    }

    public function test_gospodarz_widzi_przycisk_odebrania_dostepu_przy_kazdym_pomocniku(): void
    {
        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        foreach (['Basia', 'Cezary', 'Dorota'] as $nazwa) {
            $this->dolacz($gospodarz, $sesja, $nazwa);
        }

        $ekran = (string) $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk()->getContent();

        $this->assertSame(3, substr_count($ekran, 'Odbierz dostęp pomocnikowi'));
        $this->assertStringContainsString('Zaproś pomocnika (3 z 3)', $ekran);
        $this->assertStringContainsString('W sesji jest już komplet pomocników.', $ekran);
        $this->assertStringNotContainsString('Utwórz link', $ekran);
    }

    public function test_rewizja_rosnie_o_jeden_przy_kazdej_realnej_zmianie_od_roznych_osob(): void
    {
        [$gospodarz, $sesja, $kroki] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $cezary = $this->dolacz($gospodarz, $sesja, 'Cezary');
        $start = $sesja->fresh()->revision;
        $rewizje = [];

        $this->krok($basia, $sesja, $kroki[0])->assertRedirect();
        $rewizje['Basia odhacza 1'] = $sesja->fresh()->revision - $start;
        $this->krok($cezary, $sesja, $kroki[0])->assertRedirect();   // ten sam krok: bez zmiany
        $rewizje['Cezary ten sam krok'] = $sesja->fresh()->revision - $start;
        $this->krok($cezary, $sesja, $kroki[1])->assertRedirect();
        $rewizje['Cezary odhacza 2'] = $sesja->fresh()->revision - $start;
        $this->krok($gospodarz, $sesja, $kroki[2])->assertRedirect();
        $rewizje['Gospodarz odhacza 3'] = $sesja->fresh()->revision - $start;
        $this->krok($basia, $sesja, $kroki[1], false)->assertRedirect();   // pomocnik cofa cudze odhaczenie
        $rewizje['Basia cofa 2'] = $sesja->fresh()->revision - $start;

        $this->assertSame([
            'Basia odhacza 1' => 1,
            'Cezary ten sam krok' => 1,
            'Cezary odhacza 2' => 2,
            'Gospodarz odhacza 3' => 3,
            'Basia cofa 2' => 4,
        ], $rewizje);
        $this->assertSame(2, DB::table('cooking_session_steps')->count());
    }

    public function test_osoba_ktora_widziala_starsza_rewizje_dostaje_jedno_zdanie_o_zmianie(): void
    {
        [$gospodarz, $sesja, $kroki] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $cezary = $this->dolacz($gospodarz, $sesja, 'Cezary');
        $widziana = $sesja->fresh()->revision;

        $this->krok($basia, $sesja, $kroki[0])->assertRedirect();
        $this->actingAs($cezary)->post(route('wspolne-gotowanie.krok', $sesja), [
            'krok_id' => $kroki[1]->getKey(),
            'zrobiono' => '1',
            'rewizja' => $widziana,
        ])->assertRedirect();

        $this->assertStringContainsString('Ktoś z sesji zmienił postęp', (string) session('status'));
        $this->assertSame(2, DB::table('cooking_session_steps')->count(), 'kliknięcie Cezarego zostało zapisane');
    }

    // ---------------------------------------------------------------------
    // Eksport i wymazanie konta
    // ---------------------------------------------------------------------

    public function test_paczka_kazdego_uczestnika_ma_tylko_jego_odhaczenia_i_nikogo_z_nazwy(): void
    {
        [$gospodarz, $sesja, $kroki] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $cezary = $this->dolacz($gospodarz, $sesja, 'Cezary');
        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();
        $this->krok($basia, $sesja, $kroki[1])->assertRedirect();
        $this->krok($cezary, $sesja, $kroki[2])->assertRedirect();

        $uczestnicy = [
            'Gospodyni' => [$gospodarz, ['Basia', 'Cezary']],
            'Basia' => [$basia, ['Gospodyni', 'Cezary']],
            'Cezary' => [$cezary, ['Gospodyni', 'Basia']],
        ];
        $wyniki = [];
        $wycieki = [];
        foreach ($uczestnicy as $nazwa => [$osoba, $cudzeNazwy]) {
            $paczka = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::now());
            $sekcja = $paczka['wspolne_gotowanie'];
            $wyniki[$nazwa] = [count($sekcja), $sekcja[0]['rola'], $sekcja[0]['kroki_odhaczone_przeze_mnie']];
            $json = json_encode($sekcja, JSON_THROW_ON_ERROR);
            $wycieki[$nazwa] = array_values(array_filter($cudzeNazwy, fn (string $cudza): bool => str_contains($json, $cudza)));
            $skroty = DB::table('cooking_session_invitations')->whereNotNull('token_hash')->pluck('token_hash')->all();
            $this->assertNotSame([], $skroty, 'jest żywy link ze skrótem, więc kontrola nie jest pusta');
            foreach ($skroty as $skrot) {
                $this->assertStringNotContainsString((string) $skrot, json_encode($paczka, JSON_THROW_ON_ERROR));
            }
        }

        $this->assertSame([
            'Gospodyni' => [1, 'gospodarz', [1]],
            'Basia' => [1, 'pomocnik', [2]],
            'Cezary' => [1, 'pomocnik', [3]],
        ], $wyniki);
        $this->assertSame(['Gospodyni' => [], 'Basia' => [], 'Cezary' => []], $wycieki, 'żadna paczka nie zawiera nazw innych uczestników');
    }

    public function test_wymazanie_jednego_z_trzech_pomocnikow_zdejmuje_tylko_jego_udzial_i_podpis(): void
    {
        [$gospodarz, $sesja, $kroki] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $cezary = $this->dolacz($gospodarz, $sesja, 'Cezary');
        $dorota = $this->dolacz($gospodarz, $sesja, 'Dorota');
        $this->krok($basia, $sesja, $kroki[0])->assertRedirect();
        $this->krok($cezary, $sesja, $kroki[1])->assertRedirect();
        $this->krok($dorota, $sesja, $kroki[2])->assertRedirect();

        $cezary->markForDeletion();
        app(EraseAccountData::class)->handle($cezary->fresh());

        $podpisy = [];
        foreach ($kroki as $numer => $krok) {
            $wartosc = DB::table('cooking_session_steps')->where('step_id', $krok->getKey())->value('done_by_id');
            $podpisy[$numer] = $wartosc === null ? null : (string) $wartosc;
        }
        $this->assertSame([(string) $basia->getKey(), null, (string) $dorota->getKey()], $podpisy);
        $this->assertSame($this->posortowaneId([$basia, $dorota]), $this->idPomocnikow($sesja));
        $this->assertNotNull(CookingSession::query()->find($sesja->getKey()));

        $ekran = (string) $this->actingAs($basia)->get(route('wspolne-gotowanie.show', $sesja))->assertOk()->getContent();
        $this->assertStringContainsString('osobę, która usunęła konto', $ekran);
    }

    public function test_wymazanie_gospodarza_konczy_sesje_ze_wszystkimi_pomocnikami(): void
    {
        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        $basia = $this->dolacz($gospodarz, $sesja, 'Basia');
        $this->dolacz($gospodarz, $sesja, 'Cezary');

        $gospodarz->markForDeletion();
        app(EraseAccountData::class)->handle($gospodarz->fresh());

        $this->assertSame(0, DB::table('cooking_session_participants')->count());
        $this->actingAs($basia)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
    }

    public function test_akcja_domenowa_odmawia_powyzej_limitu_i_nie_zuzywa_linku(): void
    {
        [$gospodarz, $sesja] = $this->sesjaGospodarza();
        config(['kuking.wspolne_gotowanie.max_pomocnikow' => 1]);
        $this->dolacz($gospodarz, $sesja, 'Basia');
        config(['kuking.wspolne_gotowanie.max_pomocnikow' => 2]);
        $token = $this->token($gospodarz, $sesja);
        config(['kuking.wspolne_gotowanie.max_pomocnikow' => 1]);

        try {
            app(ZaproszenieDoGotowania::class)->dolacz($this->user('cezary'), $token);
            $this->fail('Przyjęcie powyżej limitu się udało.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(ZaproszenieDoGotowania::NIEAKTUALNE, $e->getMessage());
        }

        $this->assertSame(1, CookingSessionInvitation::query()->where('status', 'pending')->count(), 'link nie został zużyty ani odwołany po nieudanej próbie');
    }
}
