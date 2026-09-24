<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * UCHYLENIE STAREJ KARY RÓWNOLEGLE Z NOWĄ KARĄ (#933) — pomiar na dwóch
 * połączeniach.
 *
 * Sekwencyjne przypadki mierzy `tests/Feature/UchylenieKaryNieZdejmujePozniejszejTest.php`.
 * Tu jest przeplot, którego tamte nie widzą: nowa decyzja (ban B) już
 * zablokowała konto, ale jeszcze się nie zatwierdziła, gdy administrator
 * uznaje odwołanie od starego zawieszenia A.
 *
 * ── PRZEPLOT ──
 *
 * Bariera trzyma pod `FOR UPDATE` wiersz konta ZGŁASZAJĄCEGO. Decyzja B
 * banuje autora i staje przy powiadomieniu zgłaszającego (klucz obcy
 * `notifications.user_id` bierze `FOR KEY SHARE`) — z banem jeszcze
 * niezatwierdzonym.
 *
 *   dziś (blokada konta przed odczytem)    bez blokady konta
 *   ───────────────────────────────────    ────────────────────────────────
 *   B: banuje autora, czeka na barierę     B: banuje autora, czeka na barierę
 *   A: czeka na wiersz autora              A: czyta „obowiązuje zawieszenie A”
 *   zwolnienie bariery:                       (ban B niewidoczny), czeka na
 *   B zatwierdza ban                          UPDATE wiersza autora
 *   A widzi ban B → konto zostaje          zwolnienie bariery:
 *     zablokowane                          B zatwierdza, A odblokowuje konto
 *                                          → `active` przy obowiązującym banie
 *
 * Kontrola ujemna (24.09.2026): po zamianie `lockForUpdate()->first()` na
 * `first()` w `ResolveAppeal::zdejmijKareKonta()` test oblewa — konto `active`.
 */
#[Group('dwa-polaczenia')]
final class UchylenieKaryPrzyRownoleglejNowejKarzeTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $zgloszenia = [];

    protected function tearDown(): void
    {
        if ($this->zgloszenia !== []) {
            try {
                $polaczenie = $this->nowePolaczenie();
                $lista = '{'.implode(',', $this->zgloszenia).'}';
                $polaczenie->prepare('DELETE FROM moderation_actions WHERE report_id = ANY(?::uuid[])')->execute([$lista]);
                $polaczenie->prepare('DELETE FROM reports WHERE id = ANY(?::uuid[])')->execute([$lista]);
            } catch (PDOException $e) {
                fwrite(STDERR, "\nSprzątanie zgłoszeń nie powiodło się: ".$e->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    public function test_nowy_ban_zatwierdzony_w_trakcie_uchylenia_starego_zawieszenia_zostaje(): void
    {
        $admin = $this->konto(['role' => User::ROLE_ADMIN]);
        $zglaszajacy = $this->konto();
        $autor = $this->konto();

        // Decyzja A: zawieszenie, od którego autor się odwołał.
        $zgloszenieA = $this->zgloszenie($zglaszajacy, $autor, Report::STATUS_RESOLVED, $admin);
        $zawieszenie = ModerationAction::create([
            'moderator_id' => $admin->getKey(),
            'report_id' => $zgloszenieA->getKey(),
            'target_type' => 'post',
            'target_id' => $zgloszenieA->target_id,
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_SUSPEND,
            'reason_code' => 'harassment',
        ]);
        $autor->suspend(now()->addDays(7));

        $odwolanie = Appeal::create([
            'moderation_action_id' => $zawieszenie->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To nie były moje komentarze.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        // Zgłoszenie B, na którym zapadnie ban.
        $zgloszenieB = $this->zgloszenie($zglaszajacy, $autor, Report::STATUS_OPEN);

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $zglaszajacy->getKey()]);

        $ban = $this->wTle('decyzja-zgloszenia', [
            'kto' => (string) $admin->getKey(),
            'zgloszenie' => (string) $zgloszenieB->getKey(),
            'akcja' => ModerationAction::ACTION_BAN,
        ]);
        $this->czekajNaZablokowane(1);

        $uchylenie = $this->wTle('rozpatrz-odwolanie', [
            'kto' => (string) $admin->getKey(),
            'odwolanie' => (string) $odwolanie->getKey(),
            'wynik' => Appeal::STATUS_OVERTURNED,
            'uzasadnienie' => 'Pierwsze zawieszenie było niezasadne.',
        ]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikBana = $ban->wynik();
        $wynikUchylenia = $uchylenie->wynik();

        $this->assertBezZakleszczenia($wynikBana, 'nowa decyzja (ban)');
        $this->assertBezZakleszczenia($wynikUchylenia, 'uchylenie starego zawieszenia');

        // KONTROLA DODATNIA: obie operacje naprawdę się wykonały.
        $this->assertTrue($wynikBana['ok'], 'Decyzja o banie padła: '.$wynikBana['komunikat']);
        $this->assertSame('ok', $wynikBana['wartosc'], 'Decyzja o banie wróciła z błędem: '.json_encode($wynikBana['wartosc']));
        $this->assertTrue($wynikUchylenia['ok'], 'Uchylenie padło: '.$wynikUchylenia['komunikat']);
        $this->assertSame(Appeal::STATUS_OVERTURNED, $wynikUchylenia['wartosc']);

        $this->assertSame(1, ModerationAction::query()
            ->where('report_id', $zgloszenieB->getKey())
            ->where('action', ModerationAction::ACTION_BAN)
            ->count());

        $this->assertSame(
            User::STATUS_BANNED,
            $autor->fresh()?->status,
            'Uchylenie starego zawieszenia zdjęło ban zatwierdzony w jego trakcie.',
        );
    }

    private function zgloszenie(User $zglaszajacy, User $autor, string $status, ?User $rozstrzygajacy = null): Report
    {
        $wpis = Post::factory()->for($autor, 'author')->create();

        $zgloszenie = Report::create(array_filter([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'harassment',
            'status' => $status,
            'resolved_by' => $rozstrzygajacy?->getKey(),
            'resolved_at' => $rozstrzygajacy === null ? null : now(),
        ]));
        $this->zgloszenia[] = (string) $zgloszenie->getKey();

        return $zgloszenie;
    }
}
