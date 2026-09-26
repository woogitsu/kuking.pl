<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\ZbierzTresciDigestu;
use App\Domain\Feed\DailyBoard;
use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\DailyPick;
use App\Models\Hide;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1810 (D-278) — „Ukryj ten wpis" i „Ukryj tę osobę": prywatne
 * ukrycie na 30 dni, lista w Ustawieniach, bez agregacji.
 *
 * Kontrole ujemne (sprawdzone przy pisaniu, każda czerwieni wskazany test):
 *  - `bezUkrytychWpisow()` zdjęte z `DiscoverFeed` → `test_ukryty_wpis_znika_ze_strumieni…`;
 *  - warunek `hides` zdjęty z `ZbierzTresciDigestu` → to samo (część listu);
 *  - `ukryteOsobyDla()` zdjęte z `DailyBoard::wykluczeniOsob()` → `test_ukryta_osoba…`;
 *  - `scopeAktywne()` bez warunku terminu → `test_po_terminie_wraca…`;
 *  - `bezUkrytychOsob()` zdjęte z gałęzi tagów `FollowingFeed` →
 *    `test_ukryta_osoba_znika_ze_startu_takze_przez_obserwowany_tag`;
 *    przeniesione na całe zapytanie (obie gałęzie) → ten sam test (znika
 *    wpis osoby obserwowanej wprost).
 */
class UkryjWpisIOsobeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function wpis(User $autor, string $tresc, int $minutTemu = 5): Post
    {
        return Post::factory()->create(['author_id' => $autor->getKey(), 'body' => $tresc, 'published_at' => now()->subMinutes($minutTemu)]);
    }

    private function obserwuj(User $kto, User $kogo): void
    {
        DB::table('follows')->insert(['follower_id' => $kto->id, 'followed_id' => $kogo->id, 'created_at' => now()]);
    }

    /** @return list<string> */
    private function odkrywanie(User $widz): array
    {
        return (new DiscoverFeed)->paginate($widz, 50)->getCollection()->pluck('body')->all();
    }

    public function test_ukryty_wpis_znika_ze_strumieni_i_zwija_sie_tam_gdzie_widz_przyszedl_sam(): void
    {
        $widz = $this->user('widz');
        $autorka = $this->user('autorka');
        $this->obserwuj($widz, $autorka);
        $ukryty = $this->wpis($autorka, 'Kapusta do ukrycia', 2);
        $this->wpis($autorka, 'Zupa zostaje', 3);
        DailyPick::create([
            'shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $ukryty->getKey(), 'position' => 0, 'curator_id' => $this->user('gospodarz')->getKey(),
        ]);

        $odpowiedz = $this->actingAs($widz)->from(route('home'))->post(route('posts.hide', $ukryty))->assertRedirect(route('home'));
        $data = Czas::data(now()->addDays(30), 'j F Y');
        $odpowiedz->assertSessionHas('status', "Ukryliśmy ten wpis tylko dla Ciebie do {$data}. Inni widzą go jak dotąd.");
        $powrot = $odpowiedz->getSession()->get('status_powrot');
        $this->assertSame('Cofnij', $powrot['etykieta']);

        // Strumienie z kartą: Start (obserwowani), Odkrywanie, tablica z wyborem gospodarza, list.
        $this->assertSame(['Zupa zostaje'], collect(app(FollowingFeed::class)->paginate($widz)->items())->pluck('body')->all());
        $this->assertSame(['Zupa zostaje'], $this->odkrywanie($widz));
        $tablica = app(DailyBoard::class)->forViewer($widz);
        $this->assertNotContains($ukryty->getKey(), $tablica['posts']->modelKeys());
        $list = app(ZbierzTresciDigestu::class)->dlaJednej($widz->fresh(), now()->subDays(7));
        $this->assertSame(['Zupa zostaje'], array_map(fn (Post $p) => $p->body, $list->wpisyObserwowanych));

        // Inni widzą wpis jak dotąd — ukrycie jest tylko dla widza.
        $this->assertContains('Kapusta do ukrycia', $this->odkrywanie($this->user('inna')));

        // Pod linkiem karta się zwija; `?pokaz=1` rozwija ją na chwilę.
        $strona = $this->get(route('posts.show', $ukryty))->assertOk()->assertSee('Ten wpis ukrywasz tylko dla siebie.');
        $this->assertStringNotContainsString('Kapusta do ukrycia', $this->tekstRozwinietychKart((string) $strona->getContent()));
        $rozwinieta = $this->get(route('posts.show', $ukryty).'?pokaz=1')->assertOk()->getContent();
        $this->assertStringContainsString('Kapusta do ukrycia', $this->tekstRozwinietychKart((string) $rozwinieta));
        // Na profilu też zwinięty, a nie wycięty.
        $this->get(route('profile.show', $autorka->profile->username))->assertOk()
            ->assertSee('Ten wpis ukrywasz tylko dla siebie.')
            ->assertSee('Zupa zostaje');

        // „Cofnij" przywraca od razu.
        $this->from(route('home'))->post($powrot['akcja'], $powrot['pola'])->assertSessionHas('status', 'Ten wpis znów widzisz.');
        $this->assertContains('Kapusta do ukrycia', $this->odkrywanie($widz));
    }

    public function test_ukryta_osoba_znika_z_podsuwanych_miejsc_ale_nie_z_tych_gdzie_widz_przyszedl_sam(): void
    {
        $widz = $this->user('widz');
        $natretna = $this->user('natretna');
        $inna = $this->user('inna');
        $this->wpis($natretna, 'Kolejny wpis natrętnej', 1);
        $this->wpis($inna, 'Wpis innej', 2);

        // Ekran wyboru: nazwa dosłownie, zakres, bez powiadomienia, droga do blokady.
        $this->actingAs($widz)->get(route('social.hide.confirm', $natretna->profile->username))->assertOk()
            ->assertSee('Osoba: '.$natretna->displayName())
            ->assertSee('Nie powiadamiamy tej osoby.')
            ->assertSee('To działa tylko dla Ciebie.')
            ->assertSee('Ktoś Ci dokucza?');

        $this->post(route('social.hide', $natretna->profile->username), ['oczekiwany_id' => $natretna->getKey(), 'wroc' => '/odkryj'])
            ->assertRedirect('/odkryj')
            ->assertSessionHas('status', 'Ukryliśmy tę osobę tylko dla Ciebie do '.Czas::data(now()->addDays(30), 'j F Y').'. Nie powiadamiamy jej o tym.');

        $this->assertSame(['Wpis innej'], $this->odkrywanie($widz));
        $tablica = app(DailyBoard::class)->forViewer($widz);
        $this->assertNotContains($natretna->getKey(), $tablica['people']->modelKeys());
        $this->assertNotContains($natretna->getKey(), $tablica['posts']->pluck('author_id')->all());
        // Kontrola dodatnia: ta sama tablica ma drugą osobę.
        $this->assertContains($inna->getKey(), $tablica['posts']->pluck('author_id')->all());

        // Profil i wpis pod linkiem — dalej otwarte.
        $this->get(route('profile.show', $natretna->profile->username))->assertOk()->assertSee('Kolejny wpis natrętnej');

        // Nikt nie dostał powiadomienia.
        $this->assertSame(0, Notification::query()->where('user_id', $natretna->id)->count());

        // Po zaobserwowaniu Obserwowani pokazują wpisy — ukrycie osoby tam nie działa.
        $this->obserwuj($widz, $natretna);
        $this->assertContains('Kolejny wpis natrętnej', collect(app(FollowingFeed::class)->paginate($widz)->items())->pluck('body')->all());
    }

    /**
     * Decyzja właściciela 26.09 (D-278, dopisek): tag podsuwa autora, którego
     * widz nie wybrał, więc ukryta osoba znika też z wpisów „Z tagu: …".
     * Osoba obserwowana wprost zostaje — nawet gdy jej wpis ma ten sam tag.
     */
    public function test_ukryta_osoba_znika_ze_startu_takze_przez_obserwowany_tag(): void
    {
        $widz = $this->user('widz');
        $natretna = $this->user('natretna');
        $obca = $this->user('obca');
        $znajoma = $this->user('znajoma');
        $this->obserwuj($widz, $znajoma);
        $zupy = Tag::create(['slug' => 'zupy', 'name' => 'Zupy', 'normalized_name' => 'zupy']);
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);
        foreach ([[$natretna, 'Zupa natrętnej', 1], [$obca, 'Zupa obcej', 2], [$znajoma, 'Zupa znajomej', 3]] as [$autor, $tresc, $minut]) {
            $zupy->posts()->attach($this->wpis($autor, $tresc, $minut)->getKey(), ['position' => 0]);
        }

        $this->actingAs($widz)->post(route('social.hide', $natretna->profile->username), ['oczekiwany_id' => $natretna->getKey(), 'wroc' => '/'])
            ->assertRedirect('/');

        $feed = app(FollowingFeed::class);
        $this->assertSame(['Zupa obcej', 'Zupa znajomej'], collect($feed->paginate($widz)->items())->pluck('body')->all());

        // Znajomą też ukrywamy (obejście przez bazę — akcja odmawia przy
        // obserwowanej): obserwowana wprost dalej widoczna, mimo tagu.
        Hide::query()->forceCreate(['user_id' => $widz->getKey(), 'hidden_user_id' => $znajoma->getKey(), 'hidden_until' => now()->addDays(30)]);
        $this->assertSame(['Zupa obcej', 'Zupa znajomej'], collect($feed->paginate($widz)->items())->pluck('body')->all());

        // `isEmptyFor()` liczy te same źródła: sam wpis ukrytej z tagu to pustka.
        $samaUkryta = $this->user('sama');
        $samaUkryta->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);
        Hide::query()->forceCreate(['user_id' => $samaUkryta->getKey(), 'hidden_user_id' => $natretna->getKey(), 'hidden_until' => now()->addDays(30)]);
        Hide::query()->forceCreate(['user_id' => $samaUkryta->getKey(), 'hidden_user_id' => $obca->getKey(), 'hidden_until' => now()->addDays(30)]);
        Hide::query()->forceCreate(['user_id' => $samaUkryta->getKey(), 'hidden_user_id' => $znajoma->getKey(), 'hidden_until' => now()->addDays(30)]);
        $this->assertTrue($feed->isEmptyFor($samaUkryta));
        $this->assertFalse($feed->isEmptyFor($widz));
    }

    public function test_obserwowanej_osoby_sie_nie_ukrywa_menu_proponuje_przestan_obserwowac(): void
    {
        $widz = $this->user('widz');
        $znajoma = $this->user('znajoma');
        $obca = $this->user('obca');
        $this->obserwuj($widz, $znajoma);
        $this->wpis($znajoma, 'Wpis znajomej', 1);
        $this->wpis($obca, 'Wpis obcej', 2);

        $this->actingAs($widz)->from(route('home'))
            ->post(route('social.hide', $znajoma->profile->username))
            ->assertSessionHasErrors('ukrycie');
        $this->assertSame(0, Hide::query()->count());

        $html = (string) $this->get(route('discover'))->getContent();
        $this->assertStringContainsString(route('social.hide.confirm', $obca->profile->username), $html);
        $this->assertStringNotContainsString(route('social.hide.confirm', $znajoma->profile->username), $html);
        $this->assertStringContainsString('data-menu-akcja="przestan-obserwowac"', $html);
    }

    public function test_po_terminie_wraca_a_zostaw_ukryte_trzyma_bez_terminu(): void
    {
        $widz = $this->user('widz');
        $autorka = $this->user('autorka');
        $jeden = $this->wpis($autorka, 'Pierwszy do ukrycia', 1);
        $drugi = $this->wpis($autorka, 'Drugi do ukrycia', 2);
        $this->actingAs($widz)->post(route('posts.hide', $jeden));
        $this->post(route('posts.hide', $drugi));

        $lista = $this->get(route('settings.hidden'))->assertOk();
        $lista->assertSee('Ukryte wpisy')->assertSee('Ukryte osoby')
            ->assertSee('Ukryte do '.Czas::data(now()->addDays(30), 'j F Y'))
            ->assertSee('Zostaw ukryte')->assertSee('Przywróć');

        $ukrycieDrugiego = Hide::query()->where('post_id', $drugi->id)->firstOrFail();
        $this->patch(route('settings.hidden.keep', $ukrycieDrugiego))->assertSessionHasNoErrors();
        $this->assertNull($ukrycieDrugiego->fresh()->hidden_until);

        $this->travel(31)->days();
        $widoczne = $this->odkrywanie($widz);
        $this->assertContains('Pierwszy do ukrycia', $widoczne, 'Ukrycie po 30 dniach nie wygasło.');
        $this->assertNotContains('Drugi do ukrycia', $widoczne, '„Zostaw ukryte" nie trzyma bez terminu.');

        $this->delete(route('settings.hidden.restore', $ukrycieDrugiego))->assertSessionHasNoErrors();
        $this->assertContains('Drugi do ukrycia', $this->odkrywanie($widz));
    }

    public function test_cudzego_ukrycia_nie_da_sie_zmienic(): void
    {
        $wlascicielka = $this->user('wlascicielka');
        $wpis = $this->wpis($this->user('autorka'), 'Cudzy wpis');
        $this->actingAs($wlascicielka)->post(route('posts.hide', $wpis));
        $ukrycie = Hide::query()->firstOrFail();

        $obca = $this->user('obca');
        $this->actingAs($obca)->patch(route('settings.hidden.keep', $ukrycie))->assertForbidden();
        $this->actingAs($obca)->delete(route('settings.hidden.restore', $ukrycie))->assertForbidden();
        $this->assertNotNull($ukrycie->fresh());
    }

    public function test_wlasnego_ani_niewidocznego_wpisu_nie_ukryjesz(): void
    {
        $widz = $this->user('widz');
        $wlasny = $this->wpis($widz, 'Mój wpis');
        $prywatny = Post::factory()->create(['author_id' => $this->user('autorka')->id, 'visibility' => Post::VISIBILITY_PRIVATE]);

        $this->actingAs($widz)->from(route('home'))->post(route('posts.hide', $wlasny))->assertSessionHasErrors('ukrycie');
        $this->post(route('posts.hide', $prywatny))->assertForbidden();
        $this->assertSame(0, Hide::query()->count());
    }

    public function test_ugotowalem_dalej_powiadamia_autora_ukrytego_przez_kucharza(): void
    {
        // Ukrycie nie jest czwartym wyjątkiem od „Ugotowałem zawsze powiadamia" (AGENTS.md §1).
        $kucharz = $this->user('kucharz');
        $autorka = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->id]);
        $this->actingAs($kucharz)->get(route('social.hide.confirm', $autorka->profile->username));
        $this->post(route('social.hide', $autorka->profile->username));
        $this->assertSame(1, Hide::query()->count());

        app(RecordCookedEvent::class)->handle($kucharz, $przepis);

        $this->assertSame(1, Notification::query()->where('user_id', $autorka->id)->where('type', Notification::TYPE_COOKED)->count());
    }

    public function test_ostrzezenie_gdy_ukryte_osoby_to_trzecia_czesc_aktywnych(): void
    {
        $widz = $this->user('widz');
        $osoby = [];
        foreach (range(1, 3) as $i) {
            $osoby[$i] = $this->user("osoba{$i}");
            $this->wpis($osoby[$i], "Wpis {$i}", $i);
        }

        $this->actingAs($widz)->post(route('social.hide', $osoby[1]->profile->username));
        $this->get(route('settings.hidden'))->assertSee('Ukrywasz już co najmniej jedną trzecią osób');

        // Kontrola dodatnia: próg wyżej niż 1/3 → bez ostrzeżenia.
        config(['kuking.ukrycia.prog_ostrzezenia' => 0.5]);
        $this->get(route('settings.hidden'))->assertDontSee('Ukrywasz już co najmniej jedną trzecią osób');
    }

    public function test_bez_agregacji_moderacja_i_analityka_nie_czytaja_ukryc(): void
    {
        $zakazane = [
            ...glob(app_path('Domain/Moderation/*.php')) ?: [],
            ...glob(app_path('Domain/Analytics/*.php')) ?: [],
            ...glob(app_path('Http/Controllers/Admin/*.php')) ?: [],
        ];
        $this->assertGreaterThan(5, count($zakazane), 'Skan nie widzi plików moderacji i analityki — nic nie mierzy.');

        foreach ($zakazane as $plik) {
            $kod = (string) file_get_contents($plik);
            $this->assertDoesNotMatchRegularExpression('/\bhides\b|Models\\\\Hide\b|\bUkrycia\b/', $kod, "Ukrycia widza czytane w {$plik} — D-278 zabrania agregacji.");
        }
    }

    public function test_eksport_ma_liste_ukryc_a_ukryta_osoba_nie_widzi_kto_ja_ukryl(): void
    {
        $widz = $this->user('widz');
        $ukrywana = $this->user('ukrywana');
        $wpis = $this->wpis($this->user('autorka'), 'Wpis do eksportu');
        $this->actingAs($widz)->post(route('posts.hide', $wpis));
        $this->post(route('social.hide', $ukrywana->profile->username));

        $paczka = $this->paczka($widz->fresh());
        $this->assertCount(2, $paczka['ukryte']);
        $this->assertEqualsCanonicalizing(['wpis', 'osoba'], array_column($paczka['ukryte'], 'co'));
        $this->assertContains('ukrywana', array_column($paczka['ukryte'], 'osoba'));

        $jejPaczka = $this->paczka($ukrywana->fresh());
        $this->assertSame([], $jejPaczka['ukryte']);
        $this->assertStringNotContainsString('widz', json_encode($jejPaczka['ukryte']));
    }

    /** @return array<string, mixed> */
    private function paczka(User $user): array
    {
        return app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), now());
    }

    /** Karty wpisów na stronie, które NIE są zwinięte — ich tekst. */
    private function tekstRozwinietychKart(string $html): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $tekst = '';
        foreach ((new \DOMXPath($dom))->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' post-card ') and not(@data-wpis-ukryty)]") as $karta) {
            $tekst .= $karta->textContent;
        }

        return $tekst;
    }
}
