<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Moderation\Actions\ResolveAppeal;
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
 * DWA RÓWNOLEGŁE ROZPATRZENIA JEDNEGO ODWOŁANIA (#950) — pomiar na dwóch
 * połączeniach, nie rozumowanie.
 *
 * ── CO BYŁO ──
 *
 * `ResolveAppeal` sprawdzał `isOpen()` na obiekcie z wiązania trasy i zapisywał
 * skutek, wynik, odpowiedź i wpis w dzienniku osobno, bez transakcji. Dwa
 * rozpatrzenia widziały `open`: pierwsze cofało blokadę konta, drugie
 * „podtrzymywało” i nadpisywało wynik. Zostawało odwołanie `upheld` przy
 * koncie odblokowanym, dwie sprzeczne odpowiedzi i dwa wpisy `appeal.resolved`.
 *
 * ── PRZEPLOT ──
 *
 * Bariera trzyma wiersz `appeals` pod `FOR UPDATE`. Oba procesy mają
 * w pamięci odwołanie `open` (czytane przed akcją, jak przy wiązaniu trasy).
 *
 *   dziś (blokada + ponowne sprawdzenie)   bez blokady (stan sprzed zmiany)
 *   ─────────────────────────────────      ─────────────────────────────────
 *   A: czeka na wiersz odwołania           A: cofa blokadę konta, czeka na
 *   B: czeka na wiersz odwołania              UPDATE wiersza odwołania
 *   zwolnienie bariery:                    B: czeka na UPDATE tego wiersza
 *   A cofa decyzję i zatwierdza            zwolnienie bariery:
 *   B widzi `overturned` → „już            A zapisuje `overturned`,
 *     rozpatrzone”, bez skutku             B nadpisuje na `upheld`
 *
 * Kontrola ujemna (zmierzona 24.09.2026): po usunięciu `lockForUpdate()`
 * i ponownego sprawdzenia z `ResolveAppeal` test oblewa — oba procesy kończą
 * się sukcesem, odwołanie zostaje `upheld` przy koncie `active`.
 *
 * ── CZEGO NIE DOWODZI ──
 *
 * Że rozpatrywanie odwołań jest wolne od wyścigów w ogóle. Mierzy jeden
 * przeplot: dwa rozpatrzenia tego samego odwołania.
 */
#[Group('dwa-polaczenia')]
final class RozpatrzenieOdwolaniaNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $zgloszenia = [];

    protected function tearDown(): void
    {
        // `moderation_actions.moderator_id` ma `ON DELETE RESTRICT` — bez tego
        // sprzątanie kont w klasie bazowej by się zatrzymało. Odwołania idą
        // kaskadą z decyzji.
        if ($this->zgloszenia !== []) {
            try {
                $polaczenie = $this->nowePolaczenie();
                $lista = '{'.implode(',', $this->zgloszenia).'}';
                $polaczenie->prepare('DELETE FROM moderation_actions WHERE report_id = ANY(?::uuid[])')->execute([$lista]);
                $polaczenie->prepare('DELETE FROM reports WHERE id = ANY(?::uuid[])')->execute([$lista]);
            } catch (PDOException $e) {
                fwrite(STDERR, "\nSprzątanie odwołań nie powiodło się: ".$e->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    public function test_dwa_rownolegle_rozpatrzenia_daja_jeden_wynik_i_jeden_skutek(): void
    {
        $moderator = $this->konto(['role' => User::ROLE_ADMIN]);
        $cofajacy = $this->konto(['role' => User::ROLE_ADMIN]);
        $podtrzymujacy = $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();

        $odwolanie = $this->odwolanieOdBlokady($moderator, $autor);

        $bariera = $this->bariera('SELECT 1 FROM appeals WHERE id = ? FOR UPDATE', [(string) $odwolanie->getKey()]);

        $pierwszy = $this->wTle('rozpatrz-odwolanie', [
            'kto' => (string) $cofajacy->getKey(),
            'odwolanie' => (string) $odwolanie->getKey(),
            'wynik' => Appeal::STATUS_OVERTURNED,
            'uzasadnienie' => 'Linki prowadziły do przepisów, nie do reklam. Konto wraca.',
        ]);
        $this->czekajNaZablokowane(1);

        $drugi = $this->wTle('rozpatrz-odwolanie', [
            'kto' => (string) $podtrzymujacy->getKey(),
            'odwolanie' => (string) $odwolanie->getKey(),
            'wynik' => Appeal::STATUS_UPHELD,
            'uzasadnienie' => 'Linki były reklamą, blokada zostaje.',
        ]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikPierwszego = $pierwszy->wynik();
        $wynikDrugiego = $drugi->wynik();

        $this->assertBezZakleszczenia($wynikPierwszego, 'pierwsze rozpatrzenie');
        $this->assertBezZakleszczenia($wynikDrugiego, 'drugie rozpatrzenie');

        // KONTROLA DODATNIA: pierwsze rozpatrzenie naprawdę się wykonało —
        // inaczej „jeden wynik” mógłby znaczyć „żadnego wyniku”.
        $this->assertTrue($wynikPierwszego['ok'], 'Pierwsze rozpatrzenie padło: '.$wynikPierwszego['komunikat']);
        $this->assertSame(Appeal::STATUS_OVERTURNED, $wynikPierwszego['wartosc']);

        // Drugie dostaje czytelne „już rozpatrzone”, a nie 500 i nie sukces.
        $this->assertFalse($wynikDrugiego['ok'], 'Drugie rozpatrzenie tego samego odwołania przeszło.');
        $this->assertSame(ResolveAppeal::JUZ_ROZPATRZONE, $wynikDrugiego['komunikat']);

        $swieze = Appeal::query()->findOrFail($odwolanie->getKey());
        $this->assertSame(Appeal::STATUS_OVERTURNED, $swieze->status, 'Spóźnione rozpatrzenie nadpisało wynik.');
        $this->assertSame((string) $cofajacy->getKey(), (string) $swieze->decided_by);

        // Skutek zgodny z wynikiem: odwołanie uznane, konto wróciło.
        $this->assertSame(User::STATUS_ACTIVE, $autor->fresh()?->status);

        $this->assertSame(1, DB::table('audit_log')
            ->where('action', 'appeal.resolved')
            ->where('subject_id', $odwolanie->getKey())
            ->count(), 'Jedno odwołanie, dwa wpisy `appeal.resolved`.');

        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_MODERATION)
            ->count(), 'Autor dostał dwie odpowiedzi na jedno odwołanie.');
    }

    private function odwolanieOdBlokady(User $moderator, User $autor): Appeal
    {
        $wpis = Post::factory()->for($autor, 'author')->create();

        $zgloszenie = Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_RESOLVED,
            'resolved_by' => $moderator->getKey(),
            'resolved_at' => now(),
        ]);
        $this->zgloszenia[] = (string) $zgloszenie->getKey();

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_BAN,
            'reason_code' => 'spam',
            'user_message' => 'Linki reklamowe mimo ostrzeżenia.',
        ]);

        $autor->ban();

        return Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To były linki do przepisów mojej córki.',
            'status' => Appeal::STATUS_OPEN,
        ]);
    }
}
