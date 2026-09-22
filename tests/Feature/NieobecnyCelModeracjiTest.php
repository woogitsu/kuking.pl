<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Notification as NotificationModel;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NieobecnyCelModeracjiTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(User $reporter, Post $post): Report
    {
        return Report::create([
            'reporter_id' => $reporter->getKey(),
            'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /**
     * Każda akcja inna niż świadome „Bez działania” ma odmówić zamiast
     * zapisać sankcję, której nie wykonała. Pętla obejmuje decyzje dotyczące
     * treści i autora; dodatnia kontrola jest w osobnym teście poniżej.
     */
    public function test_znikniety_cel_odrzuca_wszystkie_pozorne_sankcje(): void
    {
        $moderator = $this->moderator();
        $reporter = $this->user('zglaszajaca');

        foreach ([
            ModerationAction::ACTION_HIDE,
            ModerationAction::ACTION_REMOVE,
            ModerationAction::ACTION_WARN,
            ModerationAction::ACTION_SUSPEND,
            ModerationAction::ACTION_BAN,
        ] as $index => $wybrana) {
            $autor = $this->user('autor_'.$index);
            $post = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'status' => Post::STATUS_PUBLISHED,
                'visibility' => 'public',
            ]);
            $zgloszenie = $this->zgloszenie($reporter, $post);
            $post->delete();

            $dane = ['action' => $wybrana, 'reason_code' => 'nekanie'];
            if ($wybrana === ModerationAction::ACTION_SUSPEND) {
                $dane['suspend_days'] = '7';
            }

            $this->actingAs($moderator)
                ->from(route('admin.reports'))
                ->post(route('admin.reports.decide', $zgloszenie), $dane)
                ->assertRedirect(route('admin.reports'))
                ->assertSessionHasErrors('action');

            $this->assertSame(Report::STATUS_OPEN, $zgloszenie->refresh()->status);
            $this->assertSame(0, ModerationAction::where('report_id', $zgloszenie->getKey())->count());
            $this->assertSame(User::STATUS_ACTIVE, $autor->refresh()->status);
        }
    }

    public function test_bez_dzialania_zapisuje_jawny_wynik_niedostepnosci_i_prawdziwa_odpowiedz(): void
    {
        $moderator = $this->moderator();
        $reporter = $this->user('zglaszajaca');
        $autor = $this->user('autor');
        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
        $zgloszenie = $this->zgloszenie($reporter, $post);
        $post->delete();

        $this->actingAs($moderator)->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'cel-niedostepny',
        ])->assertSessionHasNoErrors();

        $decyzja = ModerationAction::where('report_id', $zgloszenie->getKey())->sole();
        $powiadomienie = NotificationModel::where('user_id', $reporter->getKey())
            ->where('type', NotificationModel::TYPE_REPORT_DECIDED)
            ->sole();

        $this->assertSame(ModerationAction::ACTION_TARGET_UNAVAILABLE, $decyzja->action);
        $this->assertNull($decyzja->subject_user_id);
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->refresh()->status);
        $this->assertStringContainsString('Nie mogliśmy ocenić', $powiadomienie->data['naglowek']);
        $this->assertStringContainsString('nie była już dostępna', $powiadomienie->data['reszta']);
        $this->assertStringNotContainsString('zostaje w serwisie', $powiadomienie->data['reszta']);
        $this->assertSame(0, NotificationModel::where('user_id', $autor->getKey())->count());
    }

    public function test_trwale_brakujacy_rekord_ma_ten_sam_jawny_wynik_bez_zgadywania_autora(): void
    {
        $moderator = $this->moderator();
        $reporter = $this->user('zglaszajaca');
        $autor = $this->user('autor');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zgloszenie = $this->zgloszenie($reporter, $post);
        $post->forceDelete();

        $this->actingAs($moderator)->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'cel-niedostepny',
        ])->assertSessionHasNoErrors();

        $decyzja = ModerationAction::where('report_id', $zgloszenie->getKey())->sole();
        $this->assertSame(ModerationAction::ACTION_TARGET_UNAVAILABLE, $decyzja->action);
        $this->assertNull($decyzja->subject_user_id);
        $this->assertSame(User::STATUS_ACTIVE, $autor->refresh()->status);
    }

    public function test_nierozpoznany_adres_prawny_nie_udaje_oceny_tresci(): void
    {
        Notification::fake();

        $zgloszenie = Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_id' => null,
            'target_url' => 'adres opisany z pamięci',
            'reason' => 'other',
            'illegality_explanation' => 'Widziałam tam treść naruszającą prawo.',
            'good_faith_at' => now(),
            'notifier_name' => 'Anna Zgłaszająca',
            'notifier_email' => 'anna@example.test',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($this->moderator())->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'nierozpoznany-adres',
        ])->assertSessionHasNoErrors();

        $decyzja = ModerationAction::where('report_id', $zgloszenie->getKey())->sole();
        $this->assertSame(ModerationAction::ACTION_TARGET_UNAVAILABLE, $decyzja->action);
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->refresh()->status);
        $this->assertNotNull($zgloszenie->decision_sent_at);

        Notification::assertSentOnDemand(
            DecyzjaWSprawieZgloszenia::class,
            function (DecyzjaWSprawieZgloszenia $mail): bool {
                $wiadomosc = $mail->toMail((object) []);
                $tekst = implode(' ', [...$wiadomosc->introLines, ...$wiadomosc->outroLines]);

                return str_contains($tekst, 'Nie mogliśmy ocenić')
                    && ! str_contains($tekst, 'zostaje w serwisie');
            },
        );
    }

    public function test_istniejacy_cel_nadal_jest_rzeczywiscie_ukrywany(): void
    {
        $moderator = $this->moderator();
        $reporter = $this->user('zglaszajaca');
        $autor = $this->user('autor');
        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
        $zgloszenie = $this->zgloszenie($reporter, $post);

        $this->actingAs($moderator)->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'nekanie',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Post::STATUS_HIDDEN, $post->refresh()->status);
        $this->assertSame(
            ModerationAction::ACTION_HIDE,
            ModerationAction::where('report_id', $zgloszenie->getKey())->sole()->action,
        );
    }
}
