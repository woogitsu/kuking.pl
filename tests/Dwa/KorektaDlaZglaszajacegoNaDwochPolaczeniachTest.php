<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Moderation\Actions\NotifyReporterDecisionChanged;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * JEDNA KOREKTA DLA ZGŁASZAJĄCEGO PRZY DWÓCH RÓWNOCZESNYCH WYWOŁANIACH (#2380).
 *
 * ── CO BYŁO ──
 *
 * `NotifyReporterDecisionChanged` sprawdzał „korekta już jest” zwykłym
 * `exists()` bez blokady i bez ograniczenia w bazie. Dwa wywołania dla tego
 * samego cofniętego odwołania oba widziały „nie ma” i oba zapisywały wpis —
 * zgłaszający dostawał dwie identyczne korekty. Przy zgłoszeniu prawnym
 * z adresem nie było nawet sprawdzenia: każde wywołanie wysyłało list.
 *
 * `ResolveAppeal` woła tę akcję pod blokadą wiersza odwołania (#950), więc
 * przez formularz dwóch korekt nie było. Obietnica stała jednak na tym, KTO
 * woła — każda inna droga (komenda, ponowienie, przyszłe zadanie) jej nie
 * miała.
 *
 * ── PRZEPLOT ──
 *
 * Bariera trzyma wiersz `appeals` pod `FOR UPDATE`. Odwołanie jest już
 * `overturned`, a korekty jeszcze nie ma (stan po rozpatrzeniu, zanim ktoś
 * ją wysłał).
 *
 *   dziś (blokada w akcji)                 bez blokady (stan sprzed zmiany)
 *   ─────────────────────────────────      ─────────────────────────────────
 *   A: czeka na wiersz odwołania           A i B nie czekają na nic,
 *   B: czeka na wiersz odwołania           oba widzą „nie ma korekty”
 *   zwolnienie bariery:                    i oba ją zapisują
 *   A zapisuje korektę i zatwierdza
 *   B zastaje korektę → nic nie dokłada
 *
 * Kontrola ujemna (zmierzona 1.10.2026): po usunięciu `lockForUpdate()`
 * z akcji oba testy oblewają — procesy nie czekają na barierze.
 *
 * ── CZEGO NIE DOWODZI ──
 *
 * Że kolejka nie ponowi raz wysłanego listu. Mierzy dwa wywołania akcji.
 */
#[Group('dwa-polaczenia')]
final class KorektaDlaZglaszajacegoNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $zgloszenia = [];

    /** @var list<string> */
    private array $odwolania = [];

    protected function tearDown(): void
    {
        // `moderation_actions.moderator_id` ma `ON DELETE RESTRICT` — bez tego
        // sprzątanie kont w klasie bazowej by się zatrzymało. Odwołania idą
        // kaskadą z decyzji; znacznik listu siedzi w dzienniku.
        if ($this->zgloszenia !== []) {
            try {
                $polaczenie = $this->nowePolaczenie();
                $lista = '{'.implode(',', $this->zgloszenia).'}';
                $polaczenie->prepare('DELETE FROM audit_log WHERE subject_id = ANY(?::uuid[])')
                    ->execute(['{'.implode(',', $this->odwolania).'}']);
                $polaczenie->prepare('DELETE FROM moderation_actions WHERE report_id = ANY(?::uuid[]) OR target_id IN (SELECT target_id FROM reports WHERE id = ANY(?::uuid[]))')
                    ->execute([$lista, $lista]);
                $polaczenie->prepare('DELETE FROM reports WHERE id = ANY(?::uuid[])')->execute([$lista]);
            } catch (PDOException $e) {
                fwrite(STDERR, "\nSprzątanie odwołań nie powiodło się: ".$e->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    public function test_dwa_rownoczesne_wywolania_daja_jedna_korekte_w_serwisie(): void
    {
        $zglaszajacy = $this->konto();
        $odwolanie = $this->cofnieteOdwolanie(['reporter_id' => $zglaszajacy->getKey()]);

        $this->dwaRownoczesneWywolania($odwolanie);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $zglaszajacy->getKey())
            ->where('type', Notification::TYPE_REPORT_DECIDED)
            ->where('data->zmiana_po_odwolaniu', (string) $odwolanie->getKey())
            ->count(), 'Jedno cofnięte odwołanie, dwie korekty dla zgłaszającego.');
    }

    public function test_dwa_rownoczesne_wywolania_daja_jeden_list_do_zglaszajacego_prawnego(): void
    {
        $odwolanie = $this->cofnieteOdwolanie([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo zawiera cudze dane osobowe.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'target_url' => 'https://kuking.pl/wpisy/cos',
        ]);

        $this->dwaRownoczesneWywolania($odwolanie);

        $this->assertSame(1, DB::table('audit_log')
            ->where('action', NotifyReporterDecisionChanged::ZNACZNIK_LISTU)
            ->where('subject_id', $odwolanie->getKey())
            ->count(), 'Jedno cofnięte odwołanie, dwa listy z korektą.');
    }

    private function dwaRownoczesneWywolania(Appeal $odwolanie): void
    {
        $bariera = $this->bariera('SELECT 1 FROM appeals WHERE id = ? FOR UPDATE', [(string) $odwolanie->getKey()]);

        $pierwszy = $this->wTle('skoryguj-zglaszajacemu', ['odwolanie' => (string) $odwolanie->getKey()]);
        $this->czekajNaZablokowane(1);

        $drugi = $this->wTle('skoryguj-zglaszajacemu', ['odwolanie' => (string) $odwolanie->getKey()]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        foreach (['pierwsze wywołanie' => $pierwszy->wynik(), 'drugie wywołanie' => $drugi->wynik()] as $ktoTo => $wynik) {
            $this->assertBezZakleszczenia($wynik, $ktoTo);
            $this->assertTrue($wynik['ok'], ucfirst($ktoTo).' padło: '.$wynik['komunikat']);
        }
    }

    /**
     * Odwołanie autora od zdjęcia wpisu — już `overturned`, treść odkryta,
     * a korekty dla zgłaszającego jeszcze nie ma.
     *
     * @param  array<string, mixed>  $zgloszenieAtrybuty
     */
    private function cofnieteOdwolanie(array $zgloszenieAtrybuty): Appeal
    {
        $moderator = $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();
        $wpis = Post::factory()->for($autor, 'author')->create();

        $zgloszenie = Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_RESOLVED,
            'resolved_by' => $moderator->getKey(),
            'resolved_at' => now(),
            ...$zgloszenieAtrybuty,
        ]);
        $this->zgloszenia[] = (string) $zgloszenie->getKey();

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
            'user_message' => 'Linki reklamowe.',
        ]);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To były linki do przepisów mojej córki.',
            'status' => Appeal::STATUS_OVERTURNED,
            'decided_by' => $moderator->getKey(),
            'decision_note' => 'Linki prowadziły do przepisów.',
            'decided_at' => now(),
        ]);
        $this->odwolania[] = (string) $odwolanie->getKey();

        // Ostatni stan treści to odkrycie — tak liczy `ZmianaDecyzjiPoOdwolaniu`.
        $odkrycie = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_UNHIDE,
            'reason_code' => 'spam',
            'user_message' => 'Odwołanie uznane.',
        ]);
        $odkrycie->forceFill(['created_at' => now()->addSecond()])->save();

        return $odwolanie;
    }
}
