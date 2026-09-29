<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Moderation\Actions\RestoreContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Degradacja aktora przed `RozstrzygnijZgloszenie` i `RestoreContent` (#2086,
 * ponowne otwarcie po audycie ról).
 *
 * ── CO BYŁO ──
 *
 * PR #2095 objął `ZamekUprzywilejowanegoAktora` trzy akcje (`ResolveAppeal`,
 * `ZdejmijZUrzedu`, `WyslijOdpowiedz`). Dwie kolejne przyjmowały moderatora
 * z początku żądania i pytały Policy o jego STARĄ rolę:
 *
 *  - `RozstrzygnijZgloszenie` — po degradacji moderator → użytkownik stare
 *    żądanie nadal zamykało zgłoszenie; po admin → moderator stary model
 *    administratora zachowywał rangę przy sankcji wobec moderatora;
 *  - `RestoreContent` (także `ModerationController::restore()`) — nie pytało
 *    o rolę wcale, a regułę rangi (`wolnoCofnac()`) liczyło na starym modelu.
 *
 * ── PRZEPLOT ──
 *
 * Bariera trzyma wspólny zamek ról (1016, 1). Pierwsza w kolejce stoi
 * degradacja (`ChangeUserRole`), druga — akcja z JUŻ WCZYTANYM starym modelem
 * aktora. Zwolnienie bariery: degradacja zatwierdza się pierwsza, a akcja
 * odczytuje aktora pod blokadą i widzi nową rolę. Drugi układ odwraca
 * kolejność: przyjęta akcja kończy się, degradacja czeka.
 *
 * Kontrola ujemna (zmierzona 28.09.2026), dwa poziomy:
 *  - kod z BAZY (bez `wykonaj()` w obu akcjach): wszystkie 7 testów oblewa —
 *    akcje nie ustawiają się w kolejce po zamek ról albo zapisują skutek;
 *  - `ZamekUprzywilejowanegoAktora` oddaje starego aktora zamiast świeżego
 *    (`$operacja($aktor)`): oblewa 5 testów „degradacja wyprzedza…” z
 *    komunikatem „Uprzywilejowany skutek przeszedł po degradacji”; dwa
 *    testy „przyjęta akcja kończy się przed degradacją” zostają zielone,
 *    bo przy tej kolejności stary model jest jeszcze prawdziwy.
 */
#[Group('dwa-polaczenia')]
final class DegradacjaAktoraPrzedRozstrzygnieciemTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $zgloszenia = [];

    /** @var list<string> */
    private array $wpisy = [];

    protected function tearDown(): void
    {
        // `moderation_actions.moderator_id` ma `ON DELETE RESTRICT` — decyzje
        // (także `unhide` bez zgłoszenia) muszą zniknąć przed kontami.
        if ($this->zgloszenia !== [] || $this->wpisy !== []) {
            try {
                $polaczenie = $this->nowePolaczenie();
                $zgloszenia = '{'.implode(',', $this->zgloszenia).'}';
                $wpisy = '{'.implode(',', $this->wpisy).'}';
                $polaczenie->prepare('DELETE FROM moderation_actions WHERE report_id = ANY(?::uuid[]) OR target_id = ANY(?::uuid[])')
                    ->execute([$zgloszenia, $wpisy]);
                $polaczenie->prepare('DELETE FROM reports WHERE id = ANY(?::uuid[])')->execute([$zgloszenia]);
            } catch (PDOException $e) {
                fwrite(STDERR, "\nSprzątanie moderacji nie powiodło się: ".$e->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    // ── RozstrzygnijZgloszenie ──

    public function test_degradacja_moderatora_wyprzedza_rozstrzygniecie_zgloszenia(): void
    {
        $aktor = $this->konto(['role' => User::ROLE_MODERATOR]);
        $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();
        $zgloszenie = $this->otwarteZgloszenie($this->konto(), $autor);

        // Kontrola ujemna: model wczytany przed degradacją przechodzi Policy.
        $stary = User::query()->findOrFail($aktor->getKey());

        [$wynikAkcji] = $this->degradacjaPrzedAkcja($aktor, User::ROLE_USER, 'decyzja-zgloszenia', [
            'zgloszenie' => (string) $zgloszenie->getKey(),
            'akcja' => ModerationAction::ACTION_HIDE,
        ]);

        $this->assertTrue(Gate::forUser($stary)->allows('moderate', User::class), 'Kontrola ujemna: stary model już nie jest moderatorem.');
        $this->assertSame(AuthorizationException::class, $wynikAkcji['wyjatek'], $wynikAkcji['komunikat']);
        $swiezeZgloszenie = $zgloszenie->fresh();
        $this->assertInstanceOf(Report::class, $swiezeZgloszenie, 'Zgłoszenie zniknęło z bazy.');
        $this->assertSame(Report::STATUS_OPEN, $swiezeZgloszenie->status);
        $this->assertNull($swiezeZgloszenie->resolved_by);
        $this->assertSame(0, ModerationAction::query()->where('report_id', $zgloszenie->getKey())->count());
        $this->assertSame(Post::STATUS_PUBLISHED, Post::query()->find($zgloszenie->target_id)?->status);
        $this->assertSame(0, Notification::query()->where('user_id', $autor->getKey())->count());
        $this->assertSame(0, DB::table('audit_log')->where('action', 'moderation.decided')
            ->where('subject_id', $zgloszenie->getKey())->count());
    }

    public function test_przyjete_rozstrzygniecie_konczy_sie_przed_oczekujaca_degradacja(): void
    {
        $aktor = $this->konto(['role' => User::ROLE_MODERATOR]);
        $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();
        $zgloszenie = $this->otwarteZgloszenie($this->konto(), $autor);

        [$wynikAkcji, $wynikDegradacji] = $this->akcjaPrzedDegradacja($aktor, User::ROLE_USER, 'decyzja-zgloszenia', [
            'zgloszenie' => (string) $zgloszenie->getKey(),
            'akcja' => ModerationAction::ACTION_HIDE,
        ]);

        $this->assertTrue($wynikAkcji['ok'], $wynikAkcji['komunikat']);
        $this->assertSame('ok', $wynikAkcji['wartosc']);
        $this->assertTrue($wynikDegradacji['ok'], $wynikDegradacji['komunikat']);
        $this->assertSame(User::ROLE_USER, $aktor->fresh()?->role);
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->fresh()?->status);
        $this->assertSame(1, ModerationAction::query()->where('report_id', $zgloszenie->getKey())->count());
        $this->assertSame(Post::STATUS_HIDDEN, Post::query()->find($zgloszenie->target_id)?->status);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'moderation.decided')
            ->where('subject_id', $zgloszenie->getKey())->count());
    }

    public function test_admin_zdegradowany_do_moderatora_nie_zawiesza_konta_moderatora(): void
    {
        $aktor = $this->konto(['role' => User::ROLE_ADMIN]);
        $this->konto(['role' => User::ROLE_ADMIN]); // degradacja nie dotyczy ostatniego administratora
        $celSankcji = $this->konto(['role' => User::ROLE_MODERATOR]);
        $zgloszenie = $this->otwarteZgloszenie($this->konto(), $celSankcji);

        $stary = User::query()->findOrFail($aktor->getKey());

        [$wynikAkcji] = $this->degradacjaPrzedAkcja($aktor, User::ROLE_MODERATOR, 'decyzja-zgloszenia', [
            'zgloszenie' => (string) $zgloszenie->getKey(),
            'akcja' => ModerationAction::ACTION_BAN,
        ]);

        $this->assertTrue(Gate::forUser($stary)->allows('sanctionAccount', $celSankcji), 'Kontrola ujemna: stary model stracił rangę.');
        $this->assertSame(ValidationException::class, $wynikAkcji['wyjatek'], $wynikAkcji['komunikat']);
        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->fresh()?->status);
        $this->assertSame(0, ModerationAction::query()->where('report_id', $zgloszenie->getKey())->count());
        $this->assertSame(User::STATUS_ACTIVE, $celSankcji->fresh()?->status);
        $this->assertSame(0, Notification::query()->where('user_id', $celSankcji->getKey())->count());
        $this->assertSame(0, DB::table('audit_log')->where('action', 'moderation.decided')
            ->where('subject_id', $zgloszenie->getKey())->count());
    }

    // ── RestoreContent ──

    public function test_degradacja_moderatora_wyprzedza_bezposrednie_przywrocenie_tresci(): void
    {
        $aktor = $this->konto(['role' => User::ROLE_MODERATOR]);
        $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();
        [$wpis] = $this->schowanaTresc($this->konto(['role' => User::ROLE_MODERATOR]), $autor);

        $stary = User::query()->findOrFail($aktor->getKey());

        [$wynikAkcji] = $this->degradacjaPrzedAkcja($aktor, User::ROLE_USER, 'przywroc-tresc', [
            'wpis' => (string) $wpis->getKey(),
        ]);

        $this->assertTrue(Gate::forUser($stary)->allows('moderate', User::class), 'Kontrola ujemna: stary model już nie jest moderatorem.');
        $this->assertSame(AuthorizationException::class, $wynikAkcji['wyjatek'], $wynikAkcji['komunikat']);
        $this->assertSame(Post::STATUS_HIDDEN, $wpis->fresh()?->status);
        $this->assertSame(0, $this->ileUnhide($wpis));
        $this->assertSame(0, Notification::query()->where('user_id', $autor->getKey())->count());
    }

    public function test_degradacja_moderatora_wyprzedza_przywrocenie_z_panelu(): void
    {
        $aktor = $this->konto(['role' => User::ROLE_MODERATOR]);
        $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();
        [$wpis, $zgloszenie] = $this->schowanaTresc($this->konto(['role' => User::ROLE_MODERATOR]), $autor);

        [$wynikAkcji] = $this->degradacjaPrzedAkcja($aktor, User::ROLE_USER, 'przywroc-z-panelu', [
            'zgloszenie' => (string) $zgloszenie->getKey(),
        ]);

        $this->assertSame(AuthorizationException::class, $wynikAkcji['wyjatek'], $wynikAkcji['komunikat']);
        $this->assertSame(Post::STATUS_HIDDEN, $wpis->fresh()?->status);
        $this->assertSame(0, $this->ileUnhide($wpis));
    }

    public function test_admin_zdegradowany_do_moderatora_nie_cofa_decyzji_administratora(): void
    {
        $aktor = $this->konto(['role' => User::ROLE_ADMIN]);
        $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();
        [$wpis, , $decyzja] = $this->schowanaTresc($this->konto(['role' => User::ROLE_ADMIN]), $autor);

        $stary = User::query()->findOrFail($aktor->getKey());

        [$wynikAkcji] = $this->degradacjaPrzedAkcja($aktor, User::ROLE_MODERATOR, 'przywroc-tresc', [
            'wpis' => (string) $wpis->getKey(),
        ]);

        $this->assertTrue(RestoreContent::wolnoCofnac($stary, $decyzja), 'Kontrola ujemna: stary model stracił rangę.');
        $this->assertSame(BladDlaCzlowieka::class, $wynikAkcji['wyjatek'], $wynikAkcji['komunikat']);
        $this->assertSame(Post::STATUS_HIDDEN, $wpis->fresh()?->status);
        $this->assertSame(0, $this->ileUnhide($wpis));
    }

    public function test_przyjete_przywrocenie_konczy_sie_przed_oczekujaca_degradacja(): void
    {
        $aktor = $this->konto(['role' => User::ROLE_MODERATOR]);
        $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();
        [$wpis] = $this->schowanaTresc($this->konto(['role' => User::ROLE_MODERATOR]), $autor);

        [$wynikAkcji, $wynikDegradacji] = $this->akcjaPrzedDegradacja($aktor, User::ROLE_USER, 'przywroc-tresc', [
            'wpis' => (string) $wpis->getKey(),
        ]);

        $this->assertTrue($wynikAkcji['ok'], $wynikAkcji['komunikat']);
        $this->assertTrue($wynikDegradacji['ok'], $wynikDegradacji['komunikat']);
        $this->assertSame(User::ROLE_USER, $aktor->fresh()?->role);
        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->fresh()?->status);
        $this->assertSame(1, $this->ileUnhide($wpis));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'moderation.restored')
            ->where('actor_id', $aktor->getKey())->count());
    }

    // ── przyrząd ──

    /**
     * Degradacja staje w kolejce po zamek ról pierwsza, akcja — druga, z już
     * wczytanym starym modelem aktora.
     *
     * @param  array<string, string>  $argumenty
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function degradacjaPrzedAkcja(User $aktor, string $nowaRola, string $scenariusz, array $argumenty): array
    {
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(1016, 1)', []);
        $degradacja = $this->wTle('zmien-role', ['kto' => (string) $aktor->getKey(), 'rola' => $nowaRola]);
        $this->czekajNaZablokowane(1);
        $akcja = $this->wTle($scenariusz, ['kto' => (string) $aktor->getKey(), ...$argumenty]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikDegradacji = $degradacja->wynik();
        $wynikAkcji = $akcja->wynik();
        $this->assertBezZakleszczenia($wynikDegradacji, 'degradacja');
        $this->assertBezZakleszczenia($wynikAkcji, $scenariusz);
        $this->assertTrue($wynikDegradacji['ok'], $wynikDegradacji['komunikat']);
        $this->assertTrue($wynikDegradacji['wartosc']);
        $this->assertSame($nowaRola, $aktor->fresh()?->role);
        $this->assertFalse($wynikAkcji['ok'], 'Uprzywilejowany skutek przeszedł po degradacji.');

        return [$wynikAkcji, $wynikDegradacji];
    }

    /**
     * Odwrotna kolejność: akcja zajmuje zamek pierwsza, degradacja czeka.
     *
     * @param  array<string, string>  $argumenty
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function akcjaPrzedDegradacja(User $aktor, string $nowaRola, string $scenariusz, array $argumenty): array
    {
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(1016, 1)', []);
        $akcja = $this->wTle($scenariusz, ['kto' => (string) $aktor->getKey(), ...$argumenty]);
        $this->czekajNaZablokowane(1);
        $degradacja = $this->wTle('zmien-role', ['kto' => (string) $aktor->getKey(), 'rola' => $nowaRola]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikAkcji = $akcja->wynik();
        $wynikDegradacji = $degradacja->wynik();
        $this->assertBezZakleszczenia($wynikAkcji, $scenariusz);
        $this->assertBezZakleszczenia($wynikDegradacji, 'oczekująca degradacja');

        return [$wynikAkcji, $wynikDegradacji];
    }

    private function otwarteZgloszenie(User $zglaszajacy, User $autor): Report
    {
        $wpis = Post::factory()->for($autor, 'author')->create();
        $this->wpisy[] = (string) $wpis->getKey();

        $zgloszenie = Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);
        $this->zgloszenia[] = (string) $zgloszenie->getKey();

        return $zgloszenie;
    }

    /**
     * Wpis schowany decyzją `$rozstrzygajacy`.
     *
     * @return array{0: Post, 1: Report, 2: ModerationAction}
     */
    private function schowanaTresc(User $rozstrzygajacy, User $autor): array
    {
        $wpis = Post::factory()->for($autor, 'author')->create(['status' => Post::STATUS_HIDDEN]);
        $this->wpisy[] = (string) $wpis->getKey();

        $zgloszenie = Report::create([
            'reporter_id' => $this->konto()->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_RESOLVED,
            'resolved_by' => $rozstrzygajacy->getKey(),
            'resolved_at' => now(),
        ]);
        $this->zgloszenia[] = (string) $zgloszenie->getKey();

        $decyzja = ModerationAction::create([
            'moderator_id' => $rozstrzygajacy->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'previous_status' => Post::STATUS_PUBLISHED,
            'reason_code' => 'spam',
            'user_message' => 'Linki reklamowe.',
        ]);

        return [$wpis, $zgloszenie, $decyzja];
    }

    private function ileUnhide(Post $wpis): int
    {
        return ModerationAction::query()->where('target_id', $wpis->getKey())
            ->where('action', ModerationAction::ACTION_UNHIDE)->count();
    }
}
