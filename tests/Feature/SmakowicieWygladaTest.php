<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reakcje\PowiadomOSmakowicie;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #1813 (D-280) — reakcja „Smakowicie wygląda": lżejsza niż
 * „Ugotowałem", bez licznika, zbiorcze powiadomienie raz dziennie.
 *
 * Kontrole ujemne (sprawdzone przy pisaniu):
 *  - `withExists(... czy_smakowicie)` zdjęte z `ZapisyWpisu::dolicz()` →
 *    `test_przycisk…` nie widzi stanu „— cofnij";
 *  - warunek blokad zdjęty z `PowiadomOSmakowicie` → test blokad liczy trzy osoby;
 *  - `throw` zdjęty z `down()` migracji → test odmowy rollbacku oblewa;
 *  - filtr blokad widza zdjęty z `Smakowicie::ktoDla()` →
 *    `test_kazdy_widzi_kto_napisal…` widzi osobę, która go zablokowała.
 */
class SmakowicieWygladaTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, string $tresc = 'Pierogi z kapustą', int $minutTemu = 5): Post
    {
        return Post::factory()->create(['author_id' => $autor->id, 'body' => $tresc, 'published_at' => now()->subMinutes($minutTemu)]);
    }

    public function test_przycisk_jest_drugorzedny_bez_licznika_i_cofa_sie_jednym_dotknieciem(): void
    {
        $widz = $this->user('widz');
        $autorka = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->id]);
        $wpis = Post::factory()->create(['author_id' => $autorka->id, 'recipe_id' => $przepis->id, 'published_at' => now()->subMinute()]);

        $html = (string) $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<button class="btn btn-secondary" type="submit" data-rola="smakowicie">Smakowicie wygląda</button>~', $html);
        // „Ugotowałem" dalej pierwsze i jedyne w kolorze marki.
        $this->assertLessThan(strpos($html, 'data-rola="smakowicie"'), strpos($html, 'Ugotowałem'));
        $this->assertStringNotContainsString('btn btn-primary" type="submit" data-rola="smakowicie"', $html);

        $this->from(route('discover'))->post(route('posts.smakowicie', $wpis))->assertSessionHasNoErrors();
        $this->assertSame(1, PostReaction::query()->count());
        // Drugie kliknięcie — ten sam stan, bez błędu i bez drugiego wiersza.
        $this->from(route('discover'))->post(route('posts.smakowicie', $wpis))->assertSessionHasNoErrors();
        $this->assertSame(1, PostReaction::query()->count());

        $html = (string) $this->get(route('discover'))->getContent();
        $this->assertStringContainsString('Smakowicie wygląda — cofnij', $html);
        // Bez licznika — nigdzie żadnej liczby reakcji.
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(osob|osoby|osób)[^<]{0,40}smakowicie/iu', $html);

        $this->from(route('discover'))->delete(route('posts.smakowicie.cofnij', $wpis))->assertSessionHasNoErrors();
        $this->assertSame(0, PostReaction::query()->count());

        // Pod własnym wpisem przycisku nie ma, a zapis odmawia.
        $this->actingAs($autorka)->get(route('posts.show', $wpis))->assertDontSee('data-rola="smakowicie"', false);
        $this->from(route('discover'))->post(route('posts.smakowicie', $wpis))->assertSessionHasErrors('smakowicie');
    }

    /**
     * Decyzja właściciela 26.09 (D-280, dopisek): listę widzi każdy pod
     * wpisem, bez liczby; blokady autora ORAZ widza odcinają osobę z listy.
     */
    public function test_kazdy_widzi_kto_napisal_bez_liczby_a_blokady_autora_i_widza_odcinaja(): void
    {
        $autorka = $this->user('autorka');
        $wpis = $this->wpis($autorka);
        $anna = $this->user('anna');
        $zablokowana = $this->user('zablokowana');
        $bartek = $this->user('bartek');
        $inna = $this->user('inna');
        foreach ([$anna, $zablokowana, $bartek] as $kto) {
            $this->actingAs($kto)->post(route('posts.smakowicie', $wpis))
                ->assertSessionHas('status', 'Zapisane: „Smakowicie wygląda”. Twoja nazwa jest teraz pod tym wpisem — widzą ją wszyscy. Autor dostanie powiadomienie raz dziennie, razem z innymi.');
        }
        DB::table('blocks')->insert(['blocker_id' => $autorka->id, 'blocked_id' => $zablokowana->id, 'created_at' => now()]);
        // Bartek zablokował „inną" — ona nie zobaczy go na liście, autorka tak.
        DB::table('blocks')->insert(['blocker_id' => $bartek->id, 'blocked_id' => $inna->id, 'created_at' => now()]);

        $profil = fn (User $u): string => route('profile.show', $u->profile->username);

        $this->actingAs($autorka)->get(route('posts.show', $wpis))->assertOk()
            ->assertSee('Kto napisał „Smakowicie wygląda”:')
            ->assertSee($anna->displayName())
            ->assertSee($profil($anna), false)
            ->assertSee($profil($bartek), false)
            ->assertDontSee($profil($zablokowana), false);

        // Inna zalogowana osoba: lista jest, bez blokady autorki i bez osoby z blokadą widza.
        $html = (string) $this->actingAs($inna)->get(route('posts.show', $wpis))->assertOk()
            ->assertSee('Kto napisał „Smakowicie wygląda”:')
            ->assertSee($profil($anna), false)
            ->assertDontSee($profil($bartek), false)
            ->assertDontSee($profil($zablokowana), false)
            ->getContent();
        // Bez licznika — także na liście.
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(osob|osoby|osób|innych)/iu', strip_tags((string) preg_replace('~.*data-rola="kto-smakowicie"(.*?)</p>.*~s', '$1', $html)));

        // Niezalogowany też widzi listę (bez filtra widza).
        auth()->logout();
        $this->get(route('posts.show', $wpis))->assertOk()
            ->assertSee('Kto napisał „Smakowicie wygląda”:')
            ->assertSee($profil($anna), false)
            ->assertSee($profil($bartek), false)
            ->assertDontSee($profil($zablokowana), false);

        // Zablokowana nie zareaguje ponownie (Policy wpisu albo akcja odmawia).
        // Reakcja sprzed blokady zostaje w bazie, ale nigdzie jej nie widać.
        PostReaction::query()->where('user_id', $zablokowana->id)->delete();
        $odpowiedz = $this->actingAs($zablokowana)->from(route('discover'))->post(route('posts.smakowicie', $wpis));
        $this->assertTrue(in_array($odpowiedz->getStatusCode(), [403, 404], true) || session()->has('errors'));
        $this->assertSame(0, PostReaction::query()->where('user_id', $zablokowana->id)->count());
    }

    public function test_zbiorcze_powiadomienie_raz_dziennie_liczy_rozne_osoby_bez_zablokowanych(): void
    {
        Mail::fake();
        $autorka = $this->user('autorka');
        $pierwszy = $this->wpis($autorka, 'Pierwszy', 10);
        $drugi = $this->wpis($autorka, 'Drugi', 5);
        $anna = $this->user('anna');
        $basia = $this->user('basia');
        $zablokowana = $this->user('zablokowana');
        $this->actingAs($anna)->post(route('posts.smakowicie', $pierwszy));
        $this->actingAs($anna)->post(route('posts.smakowicie', $drugi));
        $this->actingAs($basia)->post(route('posts.smakowicie', $drugi));
        $this->actingAs($zablokowana)->post(route('posts.smakowicie', $drugi));
        DB::table('blocks')->insert(['blocker_id' => $zablokowana->id, 'blocked_id' => $autorka->id, 'created_at' => now()]);

        // Nic od razu — „Ugotowałem" ma zostać jedyną natychmiastową wiadomością.
        $this->assertSame(0, Notification::query()->where('user_id', $autorka->id)->count());

        Artisan::call('kuking:powiadom-smakowicie');

        $powiadomienia = Notification::query()->where('user_id', $autorka->id)->where('type', Notification::TYPE_SMAKOWICIE)->get();
        $this->assertCount(1, $powiadomienia);
        $this->assertSame('2 osoby napisały: Smakowicie wygląda', $powiadomienia->first()->naglowekSmakowicie());
        $this->assertNull($powiadomienia->first()->actor_id);
        $this->assertSame(0, PostReaction::query()->whereNull('notified_at')->count(), 'Czekające reakcje zostały po przebiegu.');

        // Drugi przebieg tego samego dnia nic nie dokłada.
        Artisan::call('kuking:powiadom-smakowicie');
        $this->assertSame(1, Notification::query()->where('user_id', $autorka->id)->count());

        $this->actingAs($autorka)->get(route('notifications.index'))->assertOk()->assertSee('2 osoby napisały: Smakowicie wygląda');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_odmiana_naglowka(): void
    {
        foreach ([1 => 'Jedna osoba napisała', 3 => '3 osoby napisały', 5 => '5 osób napisało', 22 => '22 osoby napisały'] as $osob => $poczatek) {
            $n = new Notification(['type' => Notification::TYPE_SMAKOWICIE]);
            $n->data = ['osob' => $osob];
            $this->assertSame($poczatek.': Smakowicie wygląda', $n->naglowekSmakowicie());
        }
    }

    public function test_harmonogram_ma_zbiorcze_powiadomienie_raz_dziennie(): void
    {
        $zdarzenia = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => ($e->description ?? '') === 'kuking:powiadom-smakowicie');
        $this->assertCount(1, $zdarzenia);
        $this->assertSame('47 17 * * *', $zdarzenia->first()->expression);
        // Przegląd #1781: 17:47 czasu polskiego, nie UTC.
        $this->assertSame('Europe/Warsaw', (string) $zdarzenia->first()->timezone);
    }

    public function test_eksport_ma_reakcje_dane_i_otrzymane(): void
    {
        $autorka = $this->user('autorka');
        $anna = $this->user('anna');
        $wpis = $this->wpis($autorka);
        $wpisAnny = $this->wpis($anna, 'Wpis Anny');
        $this->actingAs($anna)->post(route('posts.smakowicie', $wpis));
        $this->actingAs($autorka)->post(route('posts.smakowicie', $wpisAnny));

        $paczka = app(CollectUserExportData::class)
            ->handle($anna, new ExportPhotoPlan($anna), now());
        $this->assertCount(1, $paczka['moje_reakcje']);
        $this->assertSame(route('posts.show', $wpis), $paczka['moje_reakcje'][0]['wpis']);
        $this->assertCount(1, $paczka['reakcje_otrzymane']);
        $this->assertSame('autorka', $paczka['reakcje_otrzymane'][0]['od']);
    }

    /**
     * Przegląd #1781: gołe `join posts` omijało `SoftDeletes` — reakcja pod
     * wpisem usuniętym przez autora dalej dawała powiadomienie.
     * Kontrola ujemna: `whereNull('posts.deleted_at')` zdjęte → powstaje
     * powiadomienie o usuniętym wpisie.
     */
    public function test_reakcja_pod_usunietym_wpisem_nie_powiadamia(): void
    {
        $autorka = $this->user('autorka');
        $usuniety = $this->wpis($autorka, 'Usunięty');
        $anna = $this->user('anna');
        $this->actingAs($anna)->post(route('posts.smakowicie', $usuniety))->assertSessionHasNoErrors();
        $usuniety->delete();

        app(PowiadomOSmakowicie::class)->wyslij();

        $this->assertSame(0, Notification::query()->where('user_id', $autorka->id)->count());
        $this->assertSame(0, PostReaction::query()->whereNull('notified_at')->count(), 'Odsiana reakcja ma być oznaczona, żeby nie wracała.');

        // Kontrola dodatnia: reakcja pod istniejącym wpisem dalej powiadamia.
        $zostaje = $this->wpis($autorka, 'Zostaje');
        $this->actingAs($anna)->post(route('posts.smakowicie', $zostaje));
        app(PowiadomOSmakowicie::class)->wyslij();
        $this->assertSame(1, Notification::query()->where('user_id', $autorka->id)->where('type', Notification::TYPE_SMAKOWICIE)->count());
    }

    /**
     * Przegląd #1781: eksport nazywał wszystkich reagujących, także osoby
     * z blokadą i konta zamknięte — których autor przy wpisie nie widzi.
     */
    public function test_eksport_nie_nazywa_reagujacych_ktorych_autor_nie_widzi(): void
    {
        $autorka = $this->user('autorka');
        $wpis = $this->wpis($autorka);
        $anna = $this->user('anna');
        $blokujaca = $this->user('blokujaca');
        $zbanowana = $this->user('zbanowana');
        foreach ([$anna, $blokujaca, $zbanowana] as $osoba) {
            $this->actingAs($osoba)->post(route('posts.smakowicie', $wpis))->assertSessionHasNoErrors();
        }
        DB::table('blocks')->insert(['blocker_id' => $blokujaca->id, 'blocked_id' => $autorka->id, 'created_at' => now()]);
        $zbanowana->forceFill(['status' => User::STATUS_BANNED])->save();

        $paczka = app(CollectUserExportData::class)->handle($autorka, new ExportPhotoPlan($autorka), now());

        $this->assertSame(['anna'], array_column($paczka['reakcje_otrzymane'], 'od'));
        $this->assertSame(2, $paczka['reakcje_otrzymane_od_osob_niewidocznych']);
        $this->assertStringNotContainsString('blokujaca', (string) json_encode($paczka['reakcje_otrzymane']));
        $this->assertStringNotContainsString('zbanowana', (string) json_encode($paczka['reakcje_otrzymane']));
    }

    /** Przegląd #1781: błąd z worka `smakowicie` nie był widoczny na strumieniu. */
    public function test_odmowa_reakcji_widac_na_strumieniu(): void
    {
        $autorka = $this->user('autorka');
        $wpis = $this->wpis($autorka);

        $this->actingAs($autorka)->from(route('home'))->followingRedirects()
            ->post(route('posts.smakowicie', $wpis))
            ->assertOk()
            ->assertSee('data-blad-akcji="smakowicie"', false)
            ->assertSee('Pod własnym wpisem tego nie piszesz.');
    }

    public function test_rollback_odmawia_gdy_sa_reakcje_i_przechodzi_na_pustej(): void
    {
        $sciezka = 'database/migrations/2026_09_26_110000_create_post_reactions_table.php';
        $wpis = $this->wpis($this->user('autorka'));
        $this->actingAs($this->user('anna'))->post(route('posts.smakowicie', $wpis));

        try {
            Artisan::call('migrate:rollback', ['--path' => $sciezka]);
            $this->fail('Rollback przeszedł mimo reakcji w tabeli.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }
        $this->assertSame(1, PostReaction::query()->count());

        PostReaction::query()->delete();
        Artisan::call('migrate:rollback', ['--path' => $sciezka]);
        $this->assertFalse(Schema::hasTable('post_reactions'));
        Artisan::call('migrate', ['--path' => $sciezka]);
        $this->assertTrue(Schema::hasTable('post_reactions'));
    }
}
