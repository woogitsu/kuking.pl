<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Domain\Moderation\UzasadnienieDecyzji;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Notification as Powiadomienie;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Notifications\OdpowiedzNaOdwolanieZglaszajacego;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Uznane odwołanie zgłaszającego od „Bez działania” wykonuje NOWĄ decyzję
 * (#989, DSA art. 20 ust. 4).
 *
 * Do 24.09.2026 administrator zaznaczał „Cofam”, odwołanie dostawało
 * `overturned`, zgłaszający czytał „Zmieniamy naszą decyzję” — a treść
 * stała dalej, zgłoszenie zostawało `rejected`, a jedyną decyzją w rejestrze
 * było `no_action`. Teraz uznanie wymaga wyboru nowej decyzji w formularzu
 * i wykonuje ją w tej samej transakcji.
 *
 * Kontrola ujemna (24.09.2026): po zastąpieniu w `ResolveAppeal` wywołania
 * `DecyzjaPoOdwolaniu` starym `cofnij()` oblewają cztery testy: wpisu,
 * konta, odmowy w domenie i znikniętej treści — odwołanie zamyka się jako
 * `overturned`, treść zostaje, decyzji z `appeal_id` nie ma. Test odmowy
 * przez formularz przechodzi wtedy dalej, bo tam odmawia już walidacja
 * kontrolera — dlatego domena ma osobny test.
 */
class UznaneOdwolanieOdBezDzialaniaWykonujeDecyzjeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = $this->admin();
    }

    public function test_uznanie_z_nowa_decyzja_usuwa_wpis_i_wiaze_decyzje_z_odwolaniem(): void
    {
        $autor = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        [$pierwotna, $zgloszenie, $odwolanie] = $this->odwolanieOdBezDzialania('post', (string) $wpis->getKey());

        $this->rozpatrz($odwolanie, [
            'outcome' => Appeal::STATUS_OVERTURNED,
            'decision_note' => 'Sprawdziliśmy jeszcze raz. Zgłoszenie było zasadne.',
            'nowa_decyzja' => ModerationAction::ACTION_REMOVE,
            'reason_code' => 'niezgodne-z-prawem',
            'user_message' => 'Wpis zawierał cudze zdjęcia bez zgody autora.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->fresh()->status);
        $this->assertTrue(Post::withTrashed()->findOrFail($wpis->getKey())->trashed(), 'Wpis dalej stoi po uznaniu odwołania.');

        // Nowa decyzja w rejestrze, jednoznacznie powiązana.
        $nowa = ModerationAction::query()->where('appeal_id', $odwolanie->getKey())->sole();
        $this->assertSame(ModerationAction::ACTION_REMOVE, $nowa->action);
        $this->assertNull($nowa->report_id, 'Decyzja po odwołaniu nie jest drugą decyzją pierwszej instancji.');
        $this->assertSame((string) $autor->getKey(), (string) $nowa->subject_user_id);
        $this->assertSame((string) $pierwotna->getKey(), (string) $nowa->sourceAppeal->moderation_action_id);
        $this->assertSame((string) $nowa->getKey(), (string) $odwolanie->fresh()->decisionAfterAppeal->getKey());

        // Pierwotna decyzja nietknięta, zgłoszenie uznane za zasadne.
        $this->assertSame(ModerationAction::ACTION_NONE, $pierwotna->fresh()->action);
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->fresh()->status);

        // Autor dostaje uzasadnienie nowej decyzji i własną drogę odwołania.
        $powiadomienie = Powiadomienie::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Powiadomienie::TYPE_MODERATION)
            ->sole();
        $this->assertSame(ModerationAction::ACTION_REMOVE, $powiadomienie->data['decision'] ?? null);
        $this->assertTrue($nowa->isAppealable());
        $this->assertContains(
            'Sprawa zaczęła się od zgłoszenia, które dostaliśmy od innej osoby. '
            .'Najpierw nie podjęliśmy działania, a po odwołaniu tej osoby sprawdziliśmy sprawę jeszcze raz. '
            .'Nie podajemy, kto je złożył.',
            UzasadnienieDecyzji::zdania($nowa),
        );

        // Dziennik: wynik odwołania i nowa decyzja, z identyfikatorami obu.
        $wpisOdwolania = AuditLogEntry::query()->where('action', 'appeal.resolved')->sole();
        $this->assertSame((string) $nowa->getKey(), $wpisOdwolania->metadata['new_action_id']);
        $this->assertSame(ModerationAction::ACTION_REMOVE, $wpisOdwolania->metadata['new_decision']);
        $wpisDecyzji = AuditLogEntry::query()->where('action', 'moderation.after_appeal')->sole();
        $this->assertSame((string) $pierwotna->getKey(), $wpisDecyzji->metadata['original_action_id']);
        $this->assertSame((string) $odwolanie->getKey(), $wpisDecyzji->metadata['appeal_id']);

        // Zgłaszający czyta, co stało się z treścią — bez rodzaju kary.
        Notification::assertSentOnDemand(OdpowiedzNaOdwolanieZglaszajacego::class, function (OdpowiedzNaOdwolanieZglaszajacego $list): bool {
            $tresc = implode(' ', array_filter($list->toMail((object) [])->introLines, 'is_string'));

            return str_contains($tresc, 'Zmieniamy naszą decyzję')
                && str_contains($tresc, 'Zgłoszona treść nie jest już dostępna w serwisie.');
        });
    }

    public function test_uznanie_przy_zgloszonym_koncie_blokuje_konto(): void
    {
        $zgloszony = $this->user('marek');
        [, , $odwolanie] = $this->odwolanieOdBezDzialania('user', (string) $zgloszony->getKey());

        $this->rozpatrz($odwolanie, [
            'outcome' => Appeal::STATUS_OVERTURNED,
            'decision_note' => 'Profil rzeczywiście służył do oszustw.',
            'nowa_decyzja' => ModerationAction::ACTION_BAN,
            'reason_code' => 'niezgodne-z-prawem',
            'user_message' => 'Profil podszywał się pod sklep i zbierał wpłaty.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_BANNED, $zgloszony->fresh()->status);
        $this->assertSame(ModerationAction::ACTION_BAN, $odwolanie->fresh()->decisionAfterAppeal?->action);
    }

    public function test_ban_po_odwolaniu_na_koncie_w_trakcie_usuwania_trafia_do_kary_odlozonej(): void
    {
        // Nowa decyzja idzie przez przejścia #980: konto zostaje pod
        // egzekucją karencji, a kara czeka w `punishment_status`.
        $zgloszony = $this->user('marek');
        [, , $odwolanie] = $this->odwolanieOdBezDzialania('user', (string) $zgloszony->getKey());
        $zgloszony->fresh()->markForDeletion();
        $zgloszone = $zgloszony->fresh()->delete_requested_at;

        $this->rozpatrz($odwolanie, [
            'outcome' => Appeal::STATUS_OVERTURNED,
            'decision_note' => 'Profil rzeczywiście służył do oszustw.',
            'nowa_decyzja' => ModerationAction::ACTION_BAN,
            'reason_code' => 'niezgodne-z-prawem',
            'user_message' => 'Profil podszywał się pod sklep i zbierał wpłaty.',
        ])->assertSessionHasNoErrors();

        $konto = $zgloszony->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $konto->status, 'Ban po odwołaniu zatrzymał usuwanie konta.');
        $this->assertTrue($zgloszone->equalTo($konto->delete_requested_at), 'Ban po odwołaniu przesunął karencję.');
        $this->assertSame(User::STATUS_BANNED, $konto->punishment_status);
        $this->assertNull($konto->punishment_expires_at);

        $konto->cancelDeletion();
        $this->assertSame(User::STATUS_BANNED, $zgloszony->fresh()->status);
    }

    public function test_zawieszenie_po_odwolaniu_na_koncie_w_trakcie_usuwania_odklada_kare_z_terminem(): void
    {
        $zgloszony = $this->user('marek');
        [, , $odwolanie] = $this->odwolanieOdBezDzialania('user', (string) $zgloszony->getKey());
        $zgloszony->fresh()->markForDeletion();

        $this->rozpatrz($odwolanie, [
            'outcome' => Appeal::STATUS_OVERTURNED,
            'decision_note' => 'Profil rzeczywiście nękał innych.',
            'nowa_decyzja' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => '7',
            'reason_code' => 'obrazanie-nekanie',
            'user_message' => 'Komentarze naruszały zasadę szacunku.',
        ])->assertSessionHasNoErrors();

        $konto = $zgloszony->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $konto->status);
        $this->assertNull($konto->status_expires_at);
        $this->assertSame(User::STATUS_SUSPENDED, $konto->punishment_status);
        $this->assertNotNull($konto->punishment_expires_at, 'Zawieszenie z terminem zgubiło termin w karze odłożonej.');

        $konto->cancelDeletion();
        $wrocone = $zgloszony->fresh();
        $this->assertSame(User::STATUS_SUSPENDED, $wrocone->status);
        $this->assertTrue($konto->punishment_expires_at->equalTo($wrocone->status_expires_at));
    }

    public function test_uznanie_bez_nowej_decyzji_nie_przechodzi_i_nic_nie_zmienia(): void
    {
        $autor = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        [, $zgloszenie, $odwolanie] = $this->odwolanieOdBezDzialania('post', (string) $wpis->getKey());

        $this->rozpatrz($odwolanie, [
            'outcome' => Appeal::STATUS_OVERTURNED,
            'decision_note' => 'Zgłoszenie było zasadne, zmieniamy decyzję.',
        ])->assertSessionHasErrors(['nowa_decyzja' => ResolveAppeal::WYBIERZ_NOWA_DECYZJE]);

        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->fresh()->status);
        $this->assertFalse(Post::withTrashed()->findOrFail($wpis->getKey())->trashed());
        $this->assertSame(Report::STATUS_REJECTED, $zgloszenie->fresh()->status);
        Notification::assertSentOnDemandTimes(OdpowiedzNaOdwolanieZglaszajacego::class, 0);
    }

    public function test_domena_tez_odmawia_bez_nowej_decyzji(): void
    {
        // Ta sama reguła poza formularzem — droga inna niż kontroler nie
        // zamknie odwołania pustym „cofam”.
        $autor = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        [, , $odwolanie] = $this->odwolanieOdBezDzialania('post', (string) $wpis->getKey());

        $this->expectExceptionMessage(ResolveAppeal::WYBIERZ_NOWA_DECYZJE);

        app(ResolveAppeal::class)->handle($this->admin, $odwolanie, Appeal::STATUS_OVERTURNED, 'Zgłoszenie było zasadne.');
    }

    public function test_nie_da_sie_uznac_gdy_tresci_juz_nie_ma(): void
    {
        $autor = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        [, , $odwolanie] = $this->odwolanieOdBezDzialania('post', (string) $wpis->getKey());
        $wpis->forceDelete();

        $this->rozpatrz($odwolanie, [
            'outcome' => Appeal::STATUS_OVERTURNED,
            'decision_note' => 'Zgłoszenie było zasadne, zmieniamy decyzję.',
            'nowa_decyzja' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'cudze-zdjecie',
        ])->assertSessionHasErrors('outcome');

        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->fresh()->status);
        $this->assertSame(0, ModerationAction::query()->whereNotNull('appeal_id')->count());
    }

    public function test_akcja_spoza_macierzy_dla_celu_nie_przechodzi(): void
    {
        // `remove` przy zgłoszonym KONCIE nie istnieje (ModerationAction::DOZWOLONE).
        $zgloszony = $this->user('marek');
        [, , $odwolanie] = $this->odwolanieOdBezDzialania('user', (string) $zgloszony->getKey());

        $this->rozpatrz($odwolanie, [
            'outcome' => Appeal::STATUS_OVERTURNED,
            'decision_note' => 'Zgłoszenie było zasadne, zmieniamy decyzję.',
            'nowa_decyzja' => ModerationAction::ACTION_REMOVE,
            'reason_code' => 'cudze-zdjecie',
        ])->assertSessionHasErrors('nowa_decyzja');

        $this->assertSame(User::STATUS_ACTIVE, $zgloszony->fresh()->status);
        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->fresh()->status);
    }

    public function test_podtrzymanie_nie_wymaga_nowej_decyzji(): void
    {
        // KONTROLA DODATNIA: „Podtrzymuję” działa jak dawniej, bez nowych pól.
        $autor = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        [, $zgloszenie, $odwolanie] = $this->odwolanieOdBezDzialania('post', (string) $wpis->getKey());

        $this->rozpatrz($odwolanie, [
            'outcome' => Appeal::STATUS_UPHELD,
            'decision_note' => 'Po ponownym sprawdzeniu treść nie narusza prawa.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Appeal::STATUS_UPHELD, $odwolanie->fresh()->status);
        $this->assertFalse(Post::withTrashed()->findOrFail($wpis->getKey())->trashed());
        $this->assertSame(Report::STATUS_REJECTED, $zgloszenie->fresh()->status);
        $this->assertNull($odwolanie->fresh()->decisionAfterAppeal);
    }

    public function test_formularz_pokazuje_pola_nowej_decyzji_tylko_przy_takim_odwolaniu(): void
    {
        $autor = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $this->odwolanieOdBezDzialania('post', (string) $wpis->getKey());

        $this->actingAs($this->admin)
            ->get(route('admin.appeals'))
            ->assertOk()
            ->assertSee('Nowa decyzja — jeśli uznajesz odwołanie')
            ->assertSee('name="nowa_decyzja" value="remove"', false)
            ->assertDontSee('name="nowa_decyzja" value="no_action"', false)
            ->assertDontSee('system nie umie sam podjąć nowej decyzji');
    }

    /** @return array{0: ModerationAction, 1: Report, 2: Appeal} */
    private function odwolanieOdBezDzialania(string $typ, string $celId): array
    {
        $zgloszenie = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => $typ,
            'target_id' => $celId,
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza moje prawa autorskie.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'status' => Report::STATUS_OPEN,
        ]);

        // Pierwsza instancja: inny moderator, przez prawdziwy formularz.
        $this->actingAs($this->moderator())
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_NONE,
                'reason_code' => 'brak_naruszenia',
            ])
            ->assertSessionHasNoErrors();

        $pierwotna = ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
        $this->assertSame(Report::STATUS_REJECTED, $zgloszenie->fresh()->status);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $pierwotna->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'appellant' => Appeal::APPELLANT_REPORTER,
            'body' => 'Ta treść dalej narusza moje prawa, proszę o ponowne sprawdzenie.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        $this->assertTrue($odwolanie->wymagaNowejDecyzji());

        return [$pierwotna, $zgloszenie->fresh(), $odwolanie];
    }

    /** @param  array<string, string>  $dane */
    private function rozpatrz(Appeal $odwolanie, array $dane): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), $dane);
    }
}
