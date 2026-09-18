<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StrefaPolaczeniaPostgresTest extends TestCase
{
    #[DataProvider('daty')]
    public function test_polaczenie_zachowuje_zapisywana_chwile(string $data): void
    {
        $db = $this->polaczenie();
        $teraz = Carbon::parse($data, 'UTC');
        $db->statement('CREATE TEMP TABLE czas693 (created_at timestamptz NOT NULL)');
        $db->table('czas693')->insert(['created_at' => $teraz]);
        $zapis = $db->selectOne('SELECT extract(epoch FROM created_at) AS sekundy FROM czas693');
        $this->assertSame($teraz->timestamp, (int) $zapis->sekundy, 'Zapis nie może zmieniać chwili wskazanej przez aplikację.');
    }

    #[DataProvider('daty')]
    public function test_token_dziala_przez_wlasciwy_czas(string $data): void
    {
        $db = $this->polaczenie();
        $db->statement('CREATE TEMP TABLE tokeny693 (email text PRIMARY KEY, token text NOT NULL, created_at timestamptz NOT NULL)');
        $teraz = Carbon::parse($data, 'UTC');
        $this->travelTo($teraz);
        try {
            $user = new User;
            $user->forceFill(['email' => 'strefa693@example.test']);
            $repo = new DatabaseTokenRepository($db, Hash::driver(), 'tokeny693', 'lokalny-klucz-testowy', 3600, 60);
            $token = $repo->create($user);
            $this->assertTrue($repo->exists($user, $token), 'Świeży token musi działać.');
            $this->travelTo($teraz->copy()->addMinutes(59));
            $this->assertTrue($repo->exists($user, $token));
            $this->travelTo($teraz->copy()->addMinutes(61));
            $this->assertFalse($repo->exists($user, $token), 'Naprawa nie może wydłużyć ważności tokenu.');
        } finally {
            $this->travelBack();
        }
    }

    public static function daty(): array
    {
        return ['lato' => ['2026-07-15 12:00:00'], 'zima' => ['2026-01-15 12:00:00']];
    }

    private function polaczenie(): PostgresConnection
    {
        // PGTZ jest ustawieniem klienta libpq przy NOWYM połączeniu. Nie zmienia
        // konfiguracji serwera ani innych sesji. Tabele TEMP znikają z sesją.
        $poprzedniaStrefa = getenv('PGTZ');
        putenv('PGTZ=Europe/Warsaw');

        try {
            $config = (new ConfigurationUrlParser)->parseConfiguration(config('database.connections.pgsql'));
            $connector = new PostgresConnector;
            $bezOchrony = $config;
            unset($bezOchrony['timezone']);
            $kontrola = $connector->connect($bezOchrony);
            $this->assertSame('Europe/Warsaw', $kontrola->query('SHOW timezone')->fetchColumn());
            $kontrola = null;

            $pdo = $connector->connect($config);

            return new PostgresConnection($pdo, $config['database'], '', $config);
        } finally {
            putenv($poprzedniaStrefa === false ? 'PGTZ' : 'PGTZ='.$poprzedniaStrefa);
        }
    }
}
