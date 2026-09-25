<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Dwa procesy stoją za tą samą barierą; poczta jest atrapą w obu. */
#[Group('dwa-polaczenia')]
class OdpowiedzKontaktuNiePowielaSieTest extends TestCase
{
    public function test_dwa_procesy_wysylaja_jeden_list(): void
    {
        $db = config('database.connections.pgsql');
        $this->assertTrue(str_starts_with($db['database'], 'kuking_flota_') || str_starts_with($db['database'], 'kuking_race'));
        $operator = User::factory()->create();
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $observer = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}", $db['username'], $db['password'], [PDO::ATTR_PERSISTENT => false]);
        $this->assertNotEquals(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $observer->query('SELECT pg_backend_pid()')->fetchColumn());
        $tag = 'kontakt-'.Str::uuid();
        $processes = [];
        try {
            DB::beginTransaction();
            DB::select('SELECT id FROM contact_messages WHERE id = ? FOR UPDATE', [$message->id]);
            $key = (string) Str::uuid();
            foreach ([1, 2] as $i) {
                $process = new Process([PHP_BINARY, base_path('tests/Dwa/bin/kontakt.php'), $message->id, $operator->id, $key, $tag], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                    'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
                    'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
                    'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null',
                ]);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $query = $observer->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
            $deadline = microtime(true) + 8;
            do {
                $query->execute([$tag]);
                $waiting = (int) $query->fetchColumn();
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Oba procesy muszą rzeczywiście czekać na blokadę.');
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertSame(1, $results[0]['sent'] + $results[1]['sent'], 'Dwa procesy wysłały więcej niż jeden list.');
            $this->assertSame(1, $message->odpowiedzi()->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::table('audit_log')->where('subject_id', $message->id)->delete();
            $message->delete();
            $operator->delete();
        }
    }
}
