<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2165: kara po odwołaniu i edycja przepisu blokują users przed recipes.
 *
 * B trzyma users FOR KEY SHARE i czeka na barierze. A dochodzi do następnej
 * blokady: przed naprawą bierze recipes FOR UPDATE i czeka na users; po
 * naprawie czeka na users, nie trzymając przepisu. Zwolnienie bariery
 * odtwarza dawny cykl 40P01 albo pozwala B skończyć przed A.
 *
 * Kontrola ujemna: przeniesienie blokady przepisu przed blokadę konta w
 * DecyzjaPoOdwolaniu::handle() powoduje 40P01 w tym teście.
 */
#[Group('dwa-polaczenia')]
final class OdwolanieNieZakleszczaEdycjiPrzepisuTest extends TestDwochPolaczen
{
    private ?string $przepisId = null;

    private ?string $zgloszenieId = null;

    private ?string $odwolanieId = null;

    protected function tearDown(): void
    {
        try {
            if ($this->odwolanieId !== null) {
                DB::table('moderation_actions')->where('appeal_id', $this->odwolanieId)->delete();
                DB::table('appeals')->where('id', $this->odwolanieId)->delete();
            }

            if ($this->zgloszenieId !== null) {
                DB::table('moderation_actions')->where('report_id', $this->zgloszenieId)->delete();
                DB::table('reports')->where('id', $this->zgloszenieId)->delete();
            }

            if ($this->przepisId !== null) {
                DB::table('recipe_versions')->where('recipe_id', $this->przepisId)->delete();
                DB::table('recipe_slug_redirects')->where('recipe_id', $this->przepisId)->delete();
                DB::table('recipes')->where('id', $this->przepisId)->delete();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nNie udało się posprzątać wyścigu #2165: ".$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_ban_po_odwolaniu_i_edycja_tego_samego_przepisu_nie_zakleszczaja_sie(): void
    {
        $pierwotnyModerator = $this->konto(['role' => User::ROLE_MODERATOR]);
        $rozpatrujacy = $this->konto(['role' => User::ROLE_ADMIN]);
        $autor = $this->konto();

        $znacznik = bin2hex(random_bytes(5));
        $przepis = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosół przed odwołaniem '.$znacznik,
            'slug' => 'rosol-odwolanie-'.$znacznik,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $this->przepisId = (string) $przepis->getKey();

        RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $autor->getKey(),
            'version_number' => 1,
            'change_note' => 'Pierwsza publikacja',
            'snapshot' => ['title' => $przepis->title],
        ]);

        $zgloszenie = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'recipe',
            'target_id' => $przepis->getKey(),
            'reason' => 'illegal',
            'illegality_explanation' => 'Przepis narusza moje prawa autorskie.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'status' => Report::STATUS_REJECTED,
            'resolved_by' => $pierwotnyModerator->getKey(),
            'resolved_at' => now(),
        ]);
        $this->zgloszenieId = (string) $zgloszenie->getKey();

        $pierwotna = ModerationAction::create([
            'moderator_id' => $pierwotnyModerator->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => 'recipe',
            'target_id' => $przepis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'brak_naruszenia',
            'user_message' => 'Nie znaleźliśmy naruszenia.',
        ]);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $pierwotna->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'appellant' => Appeal::APPELLANT_REPORTER,
            'body' => 'Proszę o ponowne rozpatrzenie sprawy.',
            'status' => Appeal::STATUS_OPEN,
        ]);
        $this->odwolanieId = (string) $odwolanie->getKey();
        $this->assertTrue($odwolanie->wymagaNowejDecyzji());

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2165, 1)', []);

        $edycja = $this->wTle('edytuj-przepis', [
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'tytul' => 'Rosół zapisany obok odwołania',
            'skladnik' => 'lubczyk',
            'bariera_2165' => '1',
        ]);
        $this->czekajNaZablokowane(1);

        $decyzja = $this->wTle('rozpatrz-odwolanie', [
            'kto' => (string) $rozpatrujacy->getKey(),
            'odwolanie' => (string) $odwolanie->getKey(),
            'wynik' => Appeal::STATUS_OVERTURNED,
            'uzasadnienie' => 'Zgłoszenie po ponownym sprawdzeniu jest zasadne.',
            'nowa_akcja' => ModerationAction::ACTION_BAN,
            'podstawa' => 'niezgodne-z-prawem',
            'wiadomosc' => 'Przepis narusza prawa autora.',
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikEdycji = $edycja->wynik();
        $wynikDecyzji = $decyzja->wynik();

        $this->assertBezZakleszczenia($wynikEdycji, 'edycja przepisu po blokadzie konta');
        $this->assertBezZakleszczenia($wynikDecyzji, 'nowa kara po odwołaniu');
        $this->assertTrue($wynikEdycji['ok'], $wynikEdycji['komunikat']);
        $this->assertSame('Rosół zapisany obok odwołania', $wynikEdycji['wartosc']);
        $this->assertTrue($wynikDecyzji['ok'], $wynikDecyzji['komunikat']);
        $this->assertSame(Appeal::STATUS_OVERTURNED, $wynikDecyzji['wartosc']);

        $this->assertSame('Rosół zapisany obok odwołania', $przepis->fresh()?->title);
        $this->assertSame(User::STATUS_BANNED, $autor->fresh()?->status);
        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->fresh()?->status);
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->fresh()?->status);
        $this->assertSame(1, DB::table('moderation_actions')->where('appeal_id', $odwolanie->getKey())->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_MODERATION)->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'moderation.after_appeal')
            ->where('subject_id', DB::table('moderation_actions')->where('appeal_id', $odwolanie->getKey())->value('id'))
            ->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'appeal.resolved')
            ->where('subject_id', $odwolanie->getKey())->count());
    }
}
