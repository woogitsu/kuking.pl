<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Moderation\Actions\ZabezpieczDowodCsam;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

/** #2437: stan dowodu i rzeczywisty wiersz database queue są jedną transakcją. */
#[Group('dwa-polaczenia')]
final class ZabezpieczenieDowoduIKolejkaWJednejTransakcjiTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $zdjecia = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.connections.database.after_commit' => false,
        ]);
        Storage::fake('oryginal_dowodu_2437');
        Storage::fake('warianty_dowodu_2437');
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach ($this->zdjecia as $id) {
            DB::table('jobs')->where('payload', 'like', '%'.$id.'%')->delete();
        }
        DB::table('zabezpieczenia_dowodow')->where('target_type', 'media')->whereIn('target_id', $this->zdjecia)->delete();
        DB::table('notifications')->whereIn('user_id', $this->konta)->delete();
        DB::table('moderation_actions')->whereIn('subject_user_id', $this->konta)->delete();
        DB::table('media')->whereIn('id', $this->zdjecia)->delete();

        parent::tearDown();
    }

    public function test_drugi_worker_widzi_stan_i_job_dopiero_razem_po_commicie(): void
    {
        [$moderator, $autor, $zdjecie] = $this->fixture();
        $id = (string) $zdjecie->getKey();

        DB::beginTransaction();
        try {
            app(ZabezpieczDowodCsam::class)->handle($moderator, 'media', $id);

            $this->assertSame(Media::STATUS_SECURED, DB::table('media')->where('id', $id)->value('status'));
            $this->assertSame(1, $this->dowody($id));
            $this->assertSame(1, $this->zadania($id), 'CSAM_OUTBOX_JOB_IN_TRANSACTION');
            $this->assertObserwator($id, Media::STATUS_READY, 0, 0);

            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertObserwator($id, Media::STATUS_SECURED, 1, 1);
        $this->assertSame(User::STATUS_BANNED, $autor->fresh()->status);
        // Test nie uruchamia workera: syntetyczny wariant i oryginał zostają nietknięte.
        $this->assertTrue(Storage::disk('oryginal_dowodu_2437')->exists($zdjecie->object_key));
    }

    public function test_awaria_wstawiania_zadania_cofa_status_rejestr_i_decyzje(): void
    {
        [$moderator, $autor, $zdjecie] = $this->fixture();
        $id = (string) $zdjecie->getKey();

        // Trigger zostaje założony PRZED akcją, osobnym zatwierdzonym DDL.
        // Mutacja ->afterCommit() wywoła go dopiero po commicie decyzji:
        // właśnie wtedy test musi zobaczyć niedopuszczalny stan secured bez joba.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION kuking_odmow_job_2437() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.queue = 'media' AND position(current_setting('kuking.test_media_2437', true) IN NEW.payload) > 0 THEN
                    RAISE EXCEPTION 'CSAM_QUEUE_INSERT_DENIED_2437' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('CREATE TRIGGER kuking_odmow_job_2437 BEFORE INSERT ON jobs FOR EACH ROW EXECUTE FUNCTION kuking_odmow_job_2437()');
        DB::statement('SELECT set_config(?, ?, false)', ['kuking.test_media_2437', $id]);

        try {
            try {
                app(ZabezpieczDowodCsam::class)->handle($moderator, 'media', $id);
                $this->fail('CSAM_OUTBOX_ENQUEUE_FAILURE: brak odmowy zapisu joba.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('CSAM_QUEUE_INSERT_DENIED_2437', $e->getMessage());
            }

            $this->assertSame(
                Media::STATUS_READY,
                DB::table('media')->where('id', $id)->value('status'),
                'CSAM_OUTBOX_STATE_AFTER_ENQUEUE_FAILURE',
            );
            $this->assertSame(0, $this->dowody($id));
            $this->assertSame(0, $this->zadania($id));
            $this->assertSame(0, DB::table('moderation_actions')->where('target_type', 'media')->where('target_id', $id)->count());
            $this->assertObserwator($id, Media::STATUS_READY, 0, 0);
            $this->assertSame(User::STATUS_ACTIVE, $autor->fresh()->status);
        } finally {
            DB::statement('SELECT set_config(?, ?, false)', ['kuking.test_media_2437', '']);
            DB::statement('DROP TRIGGER kuking_odmow_job_2437 ON jobs');
            DB::statement('DROP FUNCTION kuking_odmow_job_2437()');
        }

        $this->assertObserwator($id, Media::STATUS_READY, 0, 0);
    }

    public function test_inna_kolejka_odmawia_zanim_powstanie_decyzja(): void
    {
        [$moderator, , $zdjecie] = $this->fixture();
        $id = (string) $zdjecie->getKey();
        config(['queue.default' => 'sync']);

        try {
            app(ZabezpieczDowodCsam::class)->handle($moderator, 'media', $id);
            $this->fail('Kolejka sync nie może potwierdzić decyzji CSAM.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('kolejki database', $e->getMessage());
        }

        $this->assertSame(Media::STATUS_READY, $zdjecie->fresh()->status);
        $this->assertSame(0, $this->dowody($id));
        $this->assertSame(0, $this->zadania($id));
    }

    /** @return array{User, User, Media} */
    private function fixture(): array
    {
        $totp = app(TwoFactorAuthenticator::class);
        $moderator = $this->konto([
            'role' => User::ROLE_MODERATOR,
            'two_factor_secret' => $totp->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_backup_codes' => $totp->hashBackupCodes(['abcd-efgh']),
        ]);
        $autor = $this->konto();
        $zdjecie = Media::factory()->create([
            'owner_id' => $autor->getKey(),
            'disk' => 'oryginal_dowodu_2437',
            'variants_disk' => 'warianty_dowodu_2437',
        ]);
        $this->zdjecia[] = (string) $zdjecie->getKey();

        // 1×1 PNG, nieszkodliwy syntetyczny fixture; żaden job nie jest wykonywany.
        $piksel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WZkAAAAASUVORK5CYII=', true);
        if ($piksel === false) {
            throw new LogicException('Syntetyczny obraz testowy jest niepoprawny.');
        }
        Storage::disk('oryginal_dowodu_2437')->put($zdjecie->object_key, $piksel);
        foreach ($zdjecie->metadata['variants'] as $wariant) {
            Storage::disk('warianty_dowodu_2437')->put($wariant['key'], $piksel);
        }

        return [$moderator, $autor, $zdjecie];
    }

    private function dowody(string $id): int
    {
        return DB::table('zabezpieczenia_dowodow')->where('target_type', 'media')->where('target_id', $id)->count();
    }

    private function zadania(string $id): int
    {
        return DB::table('jobs')->where('queue', 'media')->where('payload', 'like', '%'.$id.'%')->count();
    }

    private function assertObserwator(string $id, string $status, int $dowody, int $zadania): void
    {
        $pytanie = $this->obserwator->prepare('SELECT status FROM media WHERE id = ?');
        $pytanie->execute([$id]);
        $this->assertSame($status, $pytanie->fetchColumn(), 'CSAM_OUTBOX_WORKER_VISIBILITY');

        $pytanie = $this->obserwator->prepare("SELECT count(*) FROM zabezpieczenia_dowodow WHERE target_type = 'media' AND target_id = ?");
        $pytanie->execute([$id]);
        $this->assertSame($dowody, (int) $pytanie->fetchColumn(), 'CSAM_OUTBOX_WORKER_VISIBILITY');

        $pytanie = $this->obserwator->prepare("SELECT count(*) FROM jobs WHERE queue = 'media' AND payload LIKE ?");
        $pytanie->execute(['%'.$id.'%']);
        $this->assertSame($zadania, (int) $pytanie->fetchColumn(), 'CSAM_OUTBOX_WORKER_VISIBILITY');
    }
}
