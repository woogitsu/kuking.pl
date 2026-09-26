<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Rollback `moderation_actions.appeal_id` odmawia tylko wtedy, gdy jest co
 * stracić (#989, D-088).
 *
 * Bez tej kolumny decyzja po uznaniu odwołania ma puste `report_id`
 * i wygląda jak decyzja z urzędu — autor czytałby „Nikt tego nie zgłosił”
 * o sprawie, która zaczęła się od zgłoszenia. Kontrola dodatnia: bez takich
 * decyzji rollback przechodzi, a schemat wraca po ponownym `migrate`.
 */
class CofniecieMigracjiDecyzjiPoOdwolaniuTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_24_120000_add_appeal_id_to_moderation_actions.php';

    public function test_cofniecie_odmawia_gdy_istnieje_decyzja_po_odwolaniu(): void
    {
        $this->decyzjaPoOdwolaniu();

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Rollback przeszedł mimo decyzji powiązanej z odwołaniem.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba takich decyzji: 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('moderation_actions', 'appeal_id'));
        $this->assertSame(1, ModerationAction::query()->whereNotNull('appeal_id')->count());
    }

    public function test_cofniecie_przechodzi_bez_decyzji_po_odwolaniu(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertFalse(Schema::hasColumn('moderation_actions', 'appeal_id'), 'Rollback nie przeszedł, choć nie było czego stracić.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumn('moderation_actions', 'appeal_id'));
    }

    public function test_baza_nie_przyjmie_decyzji_po_odwolaniu_z_numerem_zgloszenia_ani_dwoch_na_jedno_odwolanie(): void
    {
        $decyzja = $this->decyzjaPoOdwolaniu();
        $appealId = (string) $decyzja->appeal_id;

        // Druga decyzja po tym samym odwołaniu.
        $this->assertOdrzucone(fn () => DB::table('moderation_actions')->insert([
            'id' => (string) Str::uuid7(),
            'moderator_id' => $decyzja->moderator_id,
            'target_type' => 'post',
            'target_id' => $decyzja->target_id,
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'cudze-zdjecie',
            'appeal_id' => $appealId,
            'created_at' => now(),
        ]));

        // Decyzja po odwołaniu podpięta pod zgłoszenie.
        $this->assertOdrzucone(fn () => DB::table('moderation_actions')
            ->where('id', $decyzja->getKey())
            ->update(['report_id' => $decyzja->sourceAppeal->report_id]));
    }

    private function assertOdrzucone(\Closure $zapis): void
    {
        DB::beginTransaction();

        try {
            $zapis();
            $this->fail('Baza przyjęła zapis, który miała odrzucić.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    private function decyzjaPoOdwolaniu(): ModerationAction
    {
        $moderator = $this->admin();
        $wpis = Post::factory()->create();

        $zgloszenie = Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza moje prawa autorskie.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'status' => Report::STATUS_RESOLVED,
            'resolved_by' => $moderator->getKey(),
            'resolved_at' => now(),
        ]);

        $pierwotna = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'brak_naruszenia',
        ]);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $pierwotna->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'appellant' => Appeal::APPELLANT_REPORTER,
            'body' => 'Proszę o ponowne sprawdzenie.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        $decyzja = new ModerationAction([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'action' => ModerationAction::ACTION_REMOVE,
            'reason_code' => 'cudze-zdjecie',
        ]);
        $decyzja->forceFill(['appeal_id' => $odwolanie->getKey()])->save();

        return $decyzja;
    }
}
