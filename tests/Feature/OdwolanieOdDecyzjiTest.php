<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Ścieżka odwołania od decyzji moderacyjnej (issue #10, DSA art. 17 i 20).
 *
 * CO SIĘ DZIAŁO
 * Powiadomienie o decyzji mówiło „możesz się odwołać: napisz na [adres]",
 * podręcznik moderacji obiecywał odpowiedź w 7 dni roboczych i termin 14 dni
 * na złożenie — a w produkcie nie było ani formularza, ani kolejki, ani
 * miejsca, w którym cokolwiek z tych terminów dałoby się policzyć. Odwołanie
 * ginęło w skrzynce pocztowej, a odpowiedzi nikt nie widział w serwisie.
 *
 * Najgorzej miał człowiek ZABLOKOWANY: nie wchodzi do serwisu, więc nie ma
 * jak niczego złożyć ani niczego przeczytać — a to jego dotyczy art. 20 DSA
 * najmocniej.
 *
 * CO SPRAWDZAJĄ TE TESTY
 * Nie „czy powstał wiersz w bazie", tylko czy człowiek ma jak złożyć
 * odwołanie i czy odpowiedź do niego DOCIERA — po HTTP, tam gdzie naprawdę
 * patrzy: w powiadomieniach albo na ekranie logowania.
 */
class OdwolanieOdDecyzjiTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(string $typ, string $celId): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => $typ,
            'target_id' => $celId,
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /**
     * Ukrycie przepisu przez moderatora, przejściem przez prawdziwy formularz.
     *
     * @return array{0: User, 1: User, 2: Recipe, 3: ModerationAction}
     */
    private function ukrytyPrzepis(string $status = Recipe::STATUS_PUBLISHED): array
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => $status,
        ]);

        $report = $this->zgloszenie('recipe', $recipe->getKey());

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'copyright',
                'user_message' => 'Przepis wygląda na skopiowany z bloga.',
            ])
            ->assertRedirect(route('admin.reports'));

        return [$moderator, $autor, $recipe->refresh(), ModerationAction::firstOrFail()];
    }

    // ------------------------------------------------------------------
    // Składanie odwołania
    // ------------------------------------------------------------------

    public function test_z_powiadomienia_da_sie_przejsc_do_odwolania_i_je_zlozyc(): void
    {
        [, $autor, , $decyzja] = $this->ukrytyPrzepis();

        $this->actingAs($autor)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Odwołanie od tej decyzji');

        // Ekran odwołania przypomina, czego dotyczy sprawa — człowiek, który
        // tu trafia, zwykle nie pamięta dokładnie, co dostał.
        $this->actingAs($autor)
            ->get(route('appeals.show', $decyzja))
            ->assertOk()
            ->assertSee('Przepis wygląda na skopiowany z bloga.')
            ->assertSee('Wyślij odwołanie');

        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), [
                'body' => 'To mój własny przepis, gotuję go od trzydziestu lat. Blog przepisał go ode mnie.',
            ])
            ->assertRedirect(route('appeals.show', $decyzja));

        $this->assertDatabaseCount('appeals', 1);
        $this->assertSame(Appeal::STATUS_OPEN, Appeal::firstOrFail()->status);
    }

    public function test_drugie_odwolanie_od_tej_samej_decyzji_nie_przechodzi(): void
    {
        // Bez limitu jedna sprawa potrafi zająć jedynego moderatora na
        // tydzień, a piąte pismo nie wnosi nowych faktów.
        [, $autor, , $decyzja] = $this->ukrytyPrzepis();

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To mój własny przepis, gotuję go od trzydziestu lat.',
        ]);

        $this->actingAs($autor)
            ->from(route('appeals.show', $decyzja))
            ->post(route('appeals.store', $decyzja), [
                'body' => 'Piszę jeszcze raz, bo nikt nie odpowiedział w ciągu godziny.',
            ])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('appeals', 1);
    }

    public function test_odwolanie_od_cudzej_decyzji_konczy_sie_odmowa(): void
    {
        // UUID decyzji w adresie NIE JEST autoryzacją (AGENTS.md §7).
        // Uzasadnienie moderacyjne to jedna z najbardziej wrażliwych rzeczy,
        // jakie mamy o człowieku.
        [, , , $decyzja] = $this->ukrytyPrzepis();

        $obca = $this->user('obca');

        $this->actingAs($obca)->get(route('appeals.show', $decyzja))->assertForbidden();
        $this->actingAs($obca)
            ->post(route('appeals.store', $decyzja), ['body' => 'Chcę zobaczyć cudzą sprawę.'])
            ->assertForbidden();

        $this->assertDatabaseCount('appeals', 0);
    }

    public function test_po_14_dniach_formularz_mowi_ze_termin_minal(): void
    {
        [, $autor, , $decyzja] = $this->ukrytyPrzepis();

        // Termin z docs/legal/MODERATION_PLAYBOOK.md §4 — każdy szablon
        // wiadomości obiecuje „w ciągu 14 dni".
        $decyzja->forceFill(['created_at' => now()->subDays(15)])->save();

        $this->actingAs($autor)
            ->get(route('appeals.show', $decyzja))
            ->assertOk()
            ->assertSee('Tej decyzji nie da się już zakwestionować tutaj')
            ->assertDontSee('Wyślij odwołanie');

        $this->actingAs($autor)
            ->from(route('appeals.show', $decyzja))
            ->post(route('appeals.store', $decyzja), ['body' => 'Odwołuję się po miesiącu.'])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('appeals', 0);
    }

    public function test_konto_zawieszone_moze_wyslac_odwolanie(): void
    {
        // `EnsureAccountIsActive` blokuje zawieszonemu KAŻDY zapis. Gdyby
        // blokował też ten, kara odbierałaby prawo do jej zakwestionowania —
        // czyli odwołanie istniałoby dla wszystkich poza tymi, których
        // dotyczy.
        $moderator = $this->moderator();
        $autor = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_SUSPEND,
                'reason_code' => 'harassment',
                'suspend_days' => '7',
                'user_message' => 'Komentarze pod cudzymi wpisami naruszały zasadę szacunku.',
            ]);

        $autor->refresh();
        $this->assertTrue($autor->isSuspended());

        $decyzja = ModerationAction::firstOrFail();

        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), [
                'body' => 'To nie były moje komentarze — konto miał wtedy mój wnuk.',
            ])
            ->assertRedirect(route('appeals.show', $decyzja));

        $this->assertDatabaseCount('appeals', 1);
    }

    // ------------------------------------------------------------------
    // Droga dla osoby zablokowanej — formularz przed logowaniem
    // ------------------------------------------------------------------

    public function test_osoba_zablokowana_sklada_odwolanie_bez_logowania(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia', ['password' => 'tajne-haslo-babci']);
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_BAN,
                'reason_code' => 'spam',
                'user_message' => 'Wielokrotne linki reklamowe mimo ostrzeżenia.',
            ]);

        $this->assertTrue($autor->refresh()->isBanned());

        // WAŻNE: wychodzimy z sesji moderatora. Bez tego `guest` przekierowuje
        // każde wejście na /login i /odwolanie na stronę główną, a test byłby
        // fałszywie zielony — sprawdzałby przekierowanie, nie logowanie.
        Auth::logout();

        // Ekran logowania to jedyne miejsce, które taka osoba zobaczy —
        // więc to na nim musi być droga do odwołania.
        $this->get(route('login'))->assertOk()->assertSee('Złóż odwołanie');

        $this->post(route('appeals.guest.store'), [
            'login' => 'basia',
            'password' => 'tajne-haslo-babci',
            'body' => 'To nie były reklamy, tylko linki do przepisów mojej córki.',
        ])->assertRedirect(route('appeals.guest'));

        $this->assertDatabaseCount('appeals', 1);
        $this->assertSame((string) $autor->getKey(), (string) Appeal::firstOrFail()->user_id);

        // Odwołanie NIE loguje nikogo i nie zdejmuje blokady.
        $this->assertGuest();
        $this->assertTrue($autor->refresh()->isBanned());
    }

    public function test_zle_haslo_w_formularzu_publicznym_nie_zdradza_czy_konto_istnieje(): void
    {
        $this->user('basia', ['password' => 'tajne-haslo-babci']);

        $this->from(route('appeals.guest'))
            ->post(route('appeals.guest.store'), [
                'login' => 'basia',
                'password' => 'zle-haslo',
                'body' => 'Próbuję odgadnąć hasło sąsiadki.',
            ])
            ->assertSessionHasErrors('login');

        $this->from(route('appeals.guest'))
            ->post(route('appeals.guest.store'), [
                'login' => 'nie-ma-takiego-konta',
                'password' => 'cokolwiek',
                'body' => 'Sprawdzam, czy takie konto istnieje.',
            ])
            ->assertSessionHasErrors('login');

        // Ten sam komunikat w obu przypadkach — inaczej formularz byłby
        // wygodnym sprawdzaczem, kto ma konto w Kuking.
        $this->assertDatabaseCount('appeals', 0);
    }

    // ------------------------------------------------------------------
    // Rozpatrywanie przez moderatora
    // ------------------------------------------------------------------

    public function test_moderator_widzi_odwolanie_wraz_z_trescia_ktora_wyslalismy(): void
    {
        [$moderator, $autor, , $decyzja] = $this->ukrytyPrzepis();

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To mój własny przepis, gotuję go od trzydziestu lat.',
        ]);

        $this->actingAs($moderator)
            ->get(route('admin.appeals'))
            ->assertOk()
            ->assertSee('To mój własny przepis')
            // Bez pokazania, co tej osobie wtedy napisaliśmy, nie da się
            // ocenić, czy pisze o tym samym.
            ->assertSee('Przepis wygląda na skopiowany z bloga.');
    }

    public function test_odpowiedz_bez_uzasadnienia_nie_przechodzi(): void
    {
        // DSA art. 20 wymaga rozpatrzenia skargi i UZASADNIONEJ odpowiedzi,
        // nie samego przycisku.
        [$moderator, $autor, , $decyzja] = $this->ukrytyPrzepis();

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To mój własny przepis, gotuję go od trzydziestu lat.',
        ]);

        $odwolanie = Appeal::firstOrFail();

        $this->actingAs($moderator)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => '',
            ])
            ->assertSessionHasErrors('decision_note');

        $this->assertTrue($odwolanie->refresh()->isOpen());
    }

    public function test_cofniecie_decyzji_przywraca_tresc_do_stanu_sprzed_ukrycia(): void
    {
        // Odwołanie, po którym nic nie da się zmienić, nie jest odwołaniem.
        // Szkic ma wrócić jako SZKIC — nie wolno opublikować go za autora.
        [$moderator, $autor, $recipe, $decyzja] = $this->ukrytyPrzepis(Recipe::STATUS_DRAFT);

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To mój własny przepis, nigdzie go nie publikowałam.',
        ]);

        $odwolanie = Appeal::firstOrFail();

        // Cofnięcie WŁASNEJ decyzji działa od razu — karencja dotyczy tylko
        // podtrzymania.
        $this->actingAs($moderator)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Sprawdziliśmy datę publikacji na blogu. Przepis był u Ciebie wcześniej.',
            ])
            ->assertRedirect(route('admin.appeals'));

        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->refresh()->status);
        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->refresh()->status);

        // Ślad w logu moderacji: cofnięcie też jest decyzją.
        $this->assertDatabaseHas('moderation_actions', [
            'action' => ModerationAction::ACTION_UNHIDE,
            'reason_code' => 'appeal_overturned',
        ]);

        // Odpowiedź DOCIERA do człowieka, nie zostaje w bazie.
        $this->actingAs($autor)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Cofamy decyzję.')
            ->assertSee('Sprawdziliśmy datę publikacji na blogu.');
    }

    public function test_cofniecie_blokady_konta_oddaje_dostep_i_mowi_o_tym_przy_logowaniu(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia', ['password' => 'tajne-haslo-babci']);
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_BAN,
                'reason_code' => 'spam',
                'user_message' => 'Wielokrotne linki reklamowe mimo ostrzeżenia.',
            ]);

        $this->post(route('appeals.guest.store'), [
            'login' => 'basia',
            'password' => 'tajne-haslo-babci',
            'body' => 'To nie były reklamy, tylko linki do przepisów mojej córki.',
        ]);

        $odwolanie = Appeal::firstOrFail();

        $this->actingAs($moderator)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Sprawdziliśmy linki. To rzeczywiście przepisy, nie reklama. Konto wraca.',
            ])
            ->assertRedirect(route('admin.appeals'));

        $autor->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $autor->status);

        // Wyjście z sesji moderatora — inaczej `guest` przekierowałby POST
        // na /login i test przechodziłby, nic nie sprawdzając.
        Auth::logout();

        // Konto wróciło, więc człowiek naprawdę się loguje.
        $this->post(route('login'), ['login' => 'basia', 'password' => 'tajne-haslo-babci'])
            ->assertRedirect(route('home'))
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($autor);
    }

    public function test_podtrzymana_blokada_dociera_do_zablokowanego_na_ekranie_logowania(): void
    {
        // Zablokowany człowiek nie zobaczy powiadomienia — leży ono
        // w serwisie, do którego go nie wpuszczamy. Ekran logowania jest
        // jedynym kanałem, którym cokolwiek do niego dotrze.
        $moderator = $this->moderator();
        $drugiModerator = $this->moderator();
        $autor = $this->user('basia', ['password' => 'tajne-haslo-babci']);
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_BAN,
                'reason_code' => 'spam',
                'user_message' => 'Wielokrotne linki reklamowe mimo ostrzeżenia.',
            ]);

        $this->post(route('appeals.guest.store'), [
            'login' => 'basia',
            'password' => 'tajne-haslo-babci',
            'body' => 'Uważam, że to pomyłka.',
        ]);

        $odwolanie = Appeal::firstOrFail();

        $this->actingAs($drugiModerator)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => 'Linki prowadziły do sklepu z suplementami. Blokada zostaje.',
            ])->assertRedirect(route('admin.appeals'));

        Auth::logout();

        $this->from(route('login'))
            ->post(route('login'), ['login' => 'basia', 'password' => 'tajne-haslo-babci'])
            ->assertSessionHasErrors('login');

        $this->from(route('login'))
            ->followingRedirects()
            ->post(route('login'), ['login' => 'basia', 'password' => 'tajne-haslo-babci'])
            ->assertSee('Linki prowadziły do sklepu z suplementami.');
    }

    public function test_moderator_nie_podtrzyma_wlasnej_decyzji_od_razu(): void
    {
        // MODERATION_PLAYBOOK §3: „jedna osoba nie powinna być jednocześnie
        // moderatorem i jedynym organem odwoławczym dla własnych decyzji".
        // Przy zespole 1-2 osób „ktoś inny" jest nie do wyegzekwowania,
        // więc egzekwujemy odczekanie.
        [$moderator, $autor, , $decyzja] = $this->ukrytyPrzepis();

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To mój własny przepis, gotuję go od trzydziestu lat.',
        ]);

        $odwolanie = Appeal::firstOrFail();

        $this->actingAs($moderator)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => 'Podtrzymuję, bo tak.',
            ])
            ->assertSessionHasErrors('outcome');

        $this->assertTrue($odwolanie->refresh()->isOpen());

        // Druga osoba z zespołu może zamknąć sprawę od razu.
        $this->actingAs($this->moderator())
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => 'Porównaliśmy tekst z blogiem. Zdania są identyczne. Decyzja zostaje.',
            ])
            ->assertRedirect(route('admin.appeals'));

        $this->assertSame(Appeal::STATUS_UPHELD, $odwolanie->refresh()->status);
    }

    public function test_ten_sam_moderator_podtrzymuje_po_odczekaniu_doby(): void
    {
        [$moderator, $autor, , $decyzja] = $this->ukrytyPrzepis();

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To mój własny przepis, gotuję go od trzydziestu lat.',
        ]);

        $decyzja->forceFill(['created_at' => now()->subHours(25)])->save();

        $odwolanie = Appeal::firstOrFail();

        $this->actingAs($moderator)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => 'Wróciłem do sprawy po dniu. Tekst jest identyczny z blogiem.',
            ])
            ->assertRedirect(route('admin.appeals'));

        $this->assertSame(Appeal::STATUS_UPHELD, $odwolanie->refresh()->status);
    }

    public function test_rozpatrzone_odwolanie_nie_przyjmuje_drugiej_odpowiedzi(): void
    {
        [, $autor, , $decyzja] = $this->ukrytyPrzepis();

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To mój własny przepis, gotuję go od trzydziestu lat.',
        ]);

        $odwolanie = Appeal::firstOrFail();
        $inny = $this->moderator();

        $this->actingAs($inny)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => 'Tekst jest identyczny z blogiem. Decyzja zostaje.',
            ]);

        $this->actingAs($inny)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Jednak nie, zmieniam zdanie drugim kliknięciem.',
            ])
            ->assertSessionHasErrors('outcome');

        $this->assertSame(Appeal::STATUS_UPHELD, $odwolanie->refresh()->status);
    }

    public function test_dwie_wiadomosci_moderacyjne_w_tej_samej_sekundzie_nie_mylą_kolejnosci(): void
    {
        // Znalezione przy #10. `notifications.created_at` ma w bazie typ
        // `timestamp(0)` — dokładność do sekundy. Odpowiedź na odwołanie
        // powstaje w tym samym żądaniu co przywrócenie treści, więc obie
        // wiadomości mają identyczny znacznik czasu, a `ORDER BY created_at
        // DESC` bez rozstrzygnięcia remisu oddawał STARSZĄ.
        //
        // Dla osoby zablokowanej to nie jest kosmetyka: ekran logowania
        // pokazuje dokładnie tę wiadomość i jest jedynym kanałem, którym
        // cokolwiek do niej dociera.
        $osoba = $this->user('basia');

        $chwila = now();

        Notification::create([
            'user_id' => $osoba->getKey(),
            'actor_id' => null,
            'type' => Notification::TYPE_MODERATION,
            'data' => ['title' => 'Stara decyzja.', 'message' => 'Pierwsza wiadomość.'],
        ])->forceFill(['created_at' => $chwila])->save();

        Notification::create([
            'user_id' => $osoba->getKey(),
            'actor_id' => null,
            'type' => Notification::TYPE_MODERATION,
            'data' => ['title' => 'Odpowiedź na odwołanie.', 'message' => 'Druga wiadomość.'],
        ])->forceFill(['created_at' => $chwila])->save();

        $this->assertSame('Druga wiadomość.', $osoba->refresh()->latestModerationMessage());
    }

    public function test_zwykly_uzytkownik_nie_widzi_kolejki_odwolan(): void
    {
        // 404, nie 403 — panel moderacji nie potwierdza, że istnieje.
        $this->actingAs($this->user('basia'))->get(route('admin.appeals'))->assertNotFound();
    }
}
