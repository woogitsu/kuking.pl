<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dostęp ZGŁASZAJĄCEGO do systemu skarg i odwołanie od decyzji „bez
 * działania" (issue #23, DSA art. 20 ust. 1).
 *
 * PRZED tą zmianą oba twierdzenia niżej były prawdą — potwierdzone testami
 * w `PomiarBrakowArt20Test`, uruchomionymi na kodzie SPRZED migracji
 * `appeals_open_to_reporters`:
 *  1. zgłaszający nie miał żadnej drogi do systemu skarg (`appeals.user_id`
 *     NOT NULL, wskazywało wyłącznie autora treści),
 *  2. od decyzji `no_action` nie dało się odwołać w ogóle.
 *
 * Te testy sprawdzają, że OBA twierdzenia przestały być prawdą — i że nowa
 * droga jest rzeczywiście autoryzowana, nie „UUID w adresie" (AGENTS.md §7).
 */
class ZglaszajacyMaDostepDoSkargTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszeniePrawne(array $atrybuty = []): Report
    {
        return Report::create(array_merge([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => null,
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo zawiera cudze dane osobowe.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'status' => Report::STATUS_OPEN,
        ], $atrybuty));
    }

    /** @return array{0: ModerationAction, 1: Report} */
    private function decyzjaNaZgloszeniuPrawnym(string $akcja, array $atrybutyZgloszenia = []): array
    {
        $moderator = $this->moderator();
        $zgloszenie = $this->zgloszeniePrawne($atrybutyZgloszenia);

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $akcja,
                'reason_code' => 'brak_naruszenia',
            ])
            ->assertRedirect(route('admin.reports'));

        return [ModerationAction::firstOrFail(), $zgloszenie->refresh()];
    }

    private function podpisanyLinkZMaila(Report $zgloszenie): string
    {
        $link = null;

        Notification::assertSentOnDemand(
            DecyzjaWSprawieZgloszenia::class,
            function (DecyzjaWSprawieZgloszenia $notification, array $channels) use (&$link): bool {
                $link = $notification->toMail((object) [])->actionUrl;

                return true;
            },
        );

        $this->assertNotNull($link, 'Mail z decyzją powinien nieść podpisany link do odwołania.');

        return $link;
    }

    /**
     * Mail z decyzją mówi o SZEŚCIU MIESIĄCACH — nigdy o czternastu dniach
     * (pkt 6 poleceń: `ModerationAction::appealDeadline()`).
     */
    public function test_mail_z_decyzja_mowi_o_szesciu_miesiacach_nie_o_czternastu_dniach(): void
    {
        Notification::fake();

        [$decyzja, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE);

        $tresc = null;

        Notification::assertSentOnDemand(
            DecyzjaWSprawieZgloszenia::class,
            function (DecyzjaWSprawieZgloszenia $notification) use (&$tresc): bool {
                $mail = $notification->toMail((object) []);
                $tresc = implode(' ', array_map(
                    fn ($linia): string => is_string($linia) ? $linia : '',
                    [...$mail->introLines, ...$mail->outroLines],
                ));

                return true;
            },
        );

        $this->assertStringContainsString('sześć miesięcy', (string) $tresc);
        $this->assertStringNotContainsString('14 dni', (string) $tresc);
        $this->assertStringContainsString(
            $decyzja->appealDeadline()->translatedFormat('j F Y'),
            (string) $tresc,
        );
    }

    // ------------------------------------------------------------------
    // TWIERDZENIE 1 (naprawione): zgłaszający dostaje drogę do skargi
    // ------------------------------------------------------------------

    public function test_zglaszajacy_dostaje_w_mailu_podpisany_link_i_moze_zlozyc_odwolanie(): void
    {
        Notification::fake();

        [, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE);

        $link = $this->podpisanyLinkZMaila($zgloszenie);

        // Formularz się otwiera. Termin (sześć miesięcy, nigdy czternaście
        // dni) jest w mailu, który dopiero co przyszedł — sprawdzone niżej.
        $this->get($link)
            ->assertOk()
            ->assertSee('Wyślij odwołanie');

        $this->post($link, [
            'body' => 'Zgłoszona treść nadal narusza moje prawa — proszę o ponowne sprawdzenie.',
        ])->assertRedirect();

        $odwolanie = Appeal::firstOrFail();
        $this->assertSame(Appeal::APPELLANT_REPORTER, $odwolanie->appellant);
        $this->assertSame($zgloszenie->getKey(), $odwolanie->report_id);
        $this->assertNull($odwolanie->user_id);
        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->status);
    }

    /**
     * TWIERDZENIE 2 (naprawione): od `no_action` da się dziś odwołać —
     * zgłaszający ma dokładnie tę drogę, której autor mieć nie może
     * (jemu ta decyzja niczego nie zrobiła).
     */
    public function test_od_decyzji_bez_dzialania_zglaszajacy_moze_sie_odwolac(): void
    {
        Notification::fake();

        [$decyzja, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE);

        $this->assertFalse($decyzja->isAppealable(), 'Autor nadal nie ma tu żadnej roli — to jest poprawne.');
        $this->assertTrue($decyzja->isAppealableByReporter());

        $link = $this->podpisanyLinkZMaila($zgloszenie);

        $this->post($link, ['body' => 'Uważam, że powinniście byli usunąć tę treść.'])
            ->assertRedirect();

        $this->assertDatabaseCount('appeals', 1);
        $this->assertSame(ModerationAction::ACTION_NONE, $decyzja->refresh()->action);
    }

    // ------------------------------------------------------------------
    // Autoryzacja: podpis, nie zgadywalny adres (AGENTS.md §7)
    // ------------------------------------------------------------------

    public function test_zgadywanie_adresu_bez_podpisu_nic_nie_daje(): void
    {
        Notification::fake();

        [, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE);

        // NEGATYWNA: identyfikator zgłoszenia jest w adresie, ale bez
        // podpisu — dokładnie to, przed czym ostrzega AGENTS.md §7.
        $this->get(route('appeals.reporter', ['report' => $zgloszenie]))->assertForbidden();
        $this->post(route('appeals.reporter', ['report' => $zgloszenie]), ['body' => 'Próba bez podpisu.'])
            ->assertForbidden();

        $this->assertDatabaseCount('appeals', 0);

        // POZYTYWNA, dla kontrastu: dokładnie ten sam adres, ale z prawdziwym
        // podpisem z maila, przechodzi.
        $link = $this->podpisanyLinkZMaila($zgloszenie);
        $this->get($link)->assertOk();
    }

    public function test_naruszony_podpis_nic_nie_daje(): void
    {
        Notification::fake();

        [, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE);

        $link = $this->podpisanyLinkZMaila($zgloszenie);

        // Zmieniamy jeden znak w podpisie — link ma wyglądać niemal
        // identycznie, ale nie przejść.
        //
        // Podmiana MUSI zależeć od tego, jaki znak tam stoi. Pierwsza wersja
        // tego testu wstawiała na sztywno „0" i padała raz na szesnaście
        // uruchomień: gdy podpis sam zaczynał się od zera, link wychodził
        // identyczny i zapalała się asercja kontrolna niżej. Test, który
        // pada losowo, jest gorszy niż jego brak — uczy ignorować czerwień.
        $zepsuty = (string) preg_replace_callback(
            '/signature=([0-9a-f])/',
            static fn (array $t): string => 'signature='.($t[1] === '0' ? '1' : '0'),
            $link,
            1,
        );
        $this->assertNotSame($link, $zepsuty, 'Test nie zmienił niczego w linku — sprawdź wzorzec.');

        $this->get($zepsuty)->assertForbidden();
    }

    public function test_link_przestaje_dzialac_po_uplywie_terminu(): void
    {
        Notification::fake();

        [, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE);
        $link = $this->podpisanyLinkZMaila($zgloszenie);

        $this->get($link)->assertOk();

        $this->travelTo(now()->addMonths(6)->addDay());

        $this->get($link)->assertForbidden();

        $this->travelBack();
    }

    public function test_drugie_odwolanie_zglaszajacego_od_tej_samej_decyzji_nie_przechodzi(): void
    {
        Notification::fake();

        [, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE);
        $link = $this->podpisanyLinkZMaila($zgloszenie);

        $this->post($link, ['body' => 'Pierwsze odwołanie, z konkretnym uzasadnieniem.']);

        $this->post($link, ['body' => 'Drugie odwołanie, bo nikt nie odpisał po godzinie.'])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('appeals', 1);
    }

    /**
     * AUTOR i ZGŁASZAJĄCY mogą odwołać się od TEJ SAMEJ decyzji niezależnie
     * — dokładnie ten scenariusz, dla którego `UNIQUE (moderation_action_id)`
     * zamieniło się na `UNIQUE (moderation_action_id, appellant)`
     * w migracji. Przed refaktorem `ModerationAction::appeal()` (`hasOne`)
     * odwołanie zgłaszającego potrafiłoby fałszywie „zająć" jedyne miejsce
     * i zablokować autorowi jego własne prawo.
     */
    public function test_autor_i_zglaszajacy_odwoluja_sie_od_tej_samej_decyzji_niezaleznie(): void
    {
        Notification::fake();

        $moderator = $this->moderator();
        $autor = $this->user('autorka');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $zgloszenie = $this->zgloszeniePrawne(['target_type' => 'post', 'target_id' => $post->getKey()]);

        $this->actingAs($moderator)->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'copyright',
            'user_message' => 'Ostrzeżenie za treść zgłoszoną jako niezgodną z prawem.',
        ]);

        $decyzja = ModerationAction::firstOrFail();
        $link = $this->podpisanyLinkZMaila($zgloszenie->refresh());

        // Zgłaszający uważa ostrzeżenie za zbyt łagodne.
        $this->post($link, ['body' => 'Samo ostrzeżenie to za mało, treść powinna zniknąć.'])
            ->assertRedirect();

        // Autor odwołuje się od TEJ SAMEJ decyzji, niezależnie.
        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), ['body' => 'To nie było naruszeniem niczyich praw.'])
            ->assertRedirect(route('appeals.show', $decyzja));

        $this->assertDatabaseCount('appeals', 2);
        $this->assertSame(1, Appeal::where('appellant', Appeal::APPELLANT_AUTHOR)->count());
        $this->assertSame(1, Appeal::where('appellant', Appeal::APPELLANT_REPORTER)->count());
    }

    // ------------------------------------------------------------------
    // Granica: zgłoszenie anonimowe (bez e-maila) nie ma dokąd wysłać linku
    // ------------------------------------------------------------------

    /**
     * Zgłoszenie prawne wolno złożyć bez żadnych danych (art. 16 ust. 2
     * lit. c, migracja `allow_anonymous_legal_notices`). Ta osoba NIE
     * DOSTAJE dostępu do systemu skarg — nie dlatego, że o niej
     * zapomnieliśmy, tylko dlatego, że nie ma dokąd wysłać linku. To jest
     * świadoma, opisana granica (patrz `FileReporterAppeal`), nie luka.
     */
    public function test_zgloszenie_bez_adresu_email_nie_dostaje_linku_i_nikt_obcy_go_nie_zgadnie(): void
    {
        Notification::fake();

        [, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE, [
            'notifier_name' => null,
            'notifier_email' => null,
        ]);

        $this->assertFalse($zgloszenie->maAdresDoOdpowiedzi());

        // Nie wysłano NIC — bo `ModerationController::decide()` wysyła tę
        // notyfikację wyłącznie, gdy jest adres.
        Notification::assertSentOnDemandTimes(DecyzjaWSprawieZgloszenia::class, 0);

        // I nawet znając prawdziwy UUID tego zgłoszenia (to jest dokładnie
        // sytuacja z AGENTS.md §7: UUID w adresie nie jest autoryzacją),
        // nikt — łącznie z samym zgłaszającym, gdyby zgadywał — nie wejdzie
        // bez podpisu, którego dla tego zgłoszenia nigdy nie wygenerowano.
        $this->get(route('appeals.reporter', ['report' => $zgloszenie]))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Schemat: CHECK pilnuje tożsamości niezależnie od aplikacji
    // ------------------------------------------------------------------

    /**
     * `appeals_appellant_identity_check` (migracja
     * `appeals_open_to_reporters`) ma odrzucić wiersz, w którym rola
     * i wypełnione pole tożsamości się rozjeżdżają — niezależnie od tego,
     * czy taki wiersz próbuje zapisać `FileAppeal`, `FileReporterAppeal`,
     * czy cokolwiek innego, co kiedyś napisze się w tej tabeli.
     */
    public function test_check_w_bazie_odrzuca_niespojna_tozsamosc_niezaleznie_od_aplikacji(): void
    {
        Notification::fake();

        [$decyzja] = $this->decyzjaNaZgloszeniuPrawnym(ModerationAction::ACTION_NONE);
        $ktoś = $this->user('ktos');

        $this->expectException(QueryException::class);

        // `appellant = 'reporter'`, ale z `user_id` zamiast `report_id` —
        // dokładnie kombinacja, przed którą ostrzega komentarz migracji przy
        // odrzuceniu `num_nonnulls(user_id, report_id) = 1` jako
        // niewystarczającego.
        DB::table('appeals')->insert([
            'id' => (string) Str::uuid(),
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $ktoś->getKey(),
            'report_id' => null,
            'appellant' => 'reporter',
            'body' => 'To nie powinno się zapisać.',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
