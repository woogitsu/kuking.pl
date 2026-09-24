<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rollback `users.session_generation` nie przywraca dostępu odwołanej
 * sesji (#1046).
 *
 * Cykl down/up zeruje licznik. Sesja odrzucona przed rollbackiem (starsza
 * generacja) byłaby po ponownym `up()` znowu zgodna — dlatego `down()`
 * kasuje wiersze `sessions` kont z generacją > 0. Konta z generacją 0 nie
 * mają czego stracić i zostają zalogowane.
 */
class WycofanieGeneracjiSesjiTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): Migration
    {
        /** @var Migration $migracja */
        $migracja = require base_path('database/migrations/2026_09_24_100000_add_session_generation_to_users.php');

        return $migracja;
    }

    private function sesja(string $id, string $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    public function test_down_kasuje_sesje_kont_z_generacja_i_zostawia_pozostale(): void
    {
        $odwolane = $this->user('odwolane');
        $nietkniete = $this->user('nietkniete');
        DB::table('users')->whereKey($odwolane->getKey())->update(['session_generation' => 2]);

        $this->sesja('stara-sesja-odwolanego', $odwolane->getKey());
        $this->sesja('sesja-nietknietego', $nietkniete->getKey());

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('users', 'session_generation'));
        $this->assertDatabaseMissing('sessions', ['id' => 'stara-sesja-odwolanego']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesja-nietknietego']);

        $this->migracja()->up();

        $this->assertTrue(Schema::hasColumn('users', 'session_generation'));
        $this->assertSame(0, (int) DB::table('users')->whereKey($odwolane->getKey())->value('session_generation'));
    }

    public function test_down_jest_bezpieczny_przy_powtorzeniu(): void
    {
        $this->migracja()->down();
        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('users', 'session_generation'));

        $this->migracja()->up();

        $this->assertTrue(Schema::hasColumn('users', 'session_generation'));
    }
}
