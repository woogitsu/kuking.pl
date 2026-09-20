<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/* #581: Read-only snapshot for the isolated browser regression.
 * php ten-plik.php /bezwzgledna/baza/aplikacji /prywatny/pelny.json /prywatny/stany.json
 * APP_BASE_PATH musi wskazywac te sama baze aplikacji. Uruchamiaj jako proces
 * potomny z stdout=PIPE, przechwytuj JSON WYLACZNIE w pamieci callbacka JS.
 * NIE uruchamiaj interaktywnie, nie zapisuj stdout/logow: pelne wiersze users
 * zawieraja poswiadczenia i inne prywatne dane. Callback nie wypisuje wyniku.
 * Kazde wywolanie tworzy nowy proces i swiezy snapshot calej izolowanej bazy.
 * SQL tylko odczyt, repeatable read, takze soft-deleted (DB::table, bez scopes).
 * Mapowanie potwierdzone w migracjach repo: users 0001_01_01_000001,
 * posts 2026_09_05_000500, notifications 2026_09_05_000900,
 * reports/moderation_actions/audit_log 2026_09_05_001000,
 * appeals 2026_09_06_100100; AuditLogEntry::$table = 'audit_log'.
 * Brak dowodu izolacji od innych procesow: runner odpowiada za wylacznosc DB.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
ob_start(); // Zadne przypadkowe wyjscie bootstrapu nie trafia do protokolu.
set_exception_handler(static function (Throwable $error): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, "SNAPSHOT_CANDIDATE_FAILED\n");
    exit(1);
});
set_error_handler(static function (int $severity): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new RuntimeException('SNAPSHOT_PHP_ERROR');
});
$check = static function (bool $ok): void {
    if (! $ok) {
        throw new RuntimeException('SNAPSHOT_GUARD');
    }
};
$ci = getenv('GITHUB_ACTIONS') === 'true';
$expectedDatabase = $ci ? 'kuking_port_panel' : 'kuking_581_validation';
$expectedPort = (string) getenv('DB_PORT');
$check(PHP_SAPI === 'cli' && $argc === 4 && ctype_digit($expectedPort)
    && (int) $expectedPort >= 1 && (int) $expectedPort <= 65535
    && ($ci || $expectedPort === '55439'));
$base = realpath($argv[1]);
$check($base !== false && realpath((string) getenv('APP_BASE_PATH')) === $base
    && in_array(getenv('APP_ENV'), ['local', 'testing'], true)
    && getenv('DB_HOST') === '127.0.0.1' && getenv('DB_PORT') === $expectedPort
    && getenv('DB_DATABASE') === $expectedDatabase && getenv('MAIL_MAILER') === 'array');
$readManifest = static function (string $path) use ($base, $check): array {
    $real = realpath($path);
    $check($real !== false && is_file($real) && ! is_link($path)
        && ! str_starts_with(strtolower($real), strtolower($base.DIRECTORY_SEPARATOR)));
    $value = json_decode(file_get_contents($real), true, flags: JSON_THROW_ON_ERROR);
    $check(is_array($value));

    return $value;
};
$full = $readManifest($argv[2]);
$states = $readManifest($argv[3]);
$check(($full['phase'] ?? null) === 'pelny' && ($states['phase'] ?? null) === 'stany'
    && ($full['database'] ?? null) === $expectedDatabase
    && ($states['database'] ?? null) === $expectedDatabase
    && preg_match('/\Apanel581-[a-f0-9]{12}\z/', $full['namespace'] ?? '') === 1
    && ($states['marker'] ?? null) === 'panel-stany-'.$full['namespace']);
$required = [
    'reports' => [$full['dane']['report'] ?? null, $states['ids']['second_open_report'] ?? null, $states['ids']['resolved_report'] ?? null],
    'appeals' => [$full['dane']['appeal'] ?? null, $states['ids']['second_open_appeal'] ?? null, $states['ids']['closed_appeal'] ?? null],
    'posts' => [$states['ids']['hidden_post'] ?? null, $states['ids']['open_post'] ?? null],
    'users' => [$full['konto']['id'] ?? null, $full['bramka']['id'] ?? null, $full['dane']['author'] ?? null],
];
foreach ($required as $ids) {
    foreach ($ids as $id) {
        $check(is_string($id) && preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/i', $id) === 1);
    }
    $check(count(array_unique($ids)) === count($ids));
}
$check(is_file($base.'/vendor/autoload.php') && is_file($base.'/bootstrap/app.php'));
try {
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, "SNAPSHOT_CANDIDATE_FAILED\n");
    exit(1);
}
// Bootstrap moze zmienic obsluge bledow; dalej nie drukujemy wyjatkow Laravela.
set_exception_handler(static function (Throwable $error): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, "SNAPSHOT_CANDIDATE_FAILED\n");
    exit(1);
});
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$connection = DB::connection();
$check(realpath(base_path()) === $base && $app->environment(['local', 'testing'])
    && $connection->getDriverName() === 'pgsql'
    && ! $connection->getConfig('read') && ! $connection->getConfig('write')
    && $connection->getConfig('host') === '127.0.0.1'
    && (string) $connection->getConfig('port') === $expectedPort
    && $connection->getDatabaseName() === $expectedDatabase
    && config('mail.default') === 'array');
$result = $connection->transaction(static function () use ($connection, $app, $check, $full, $states, $required, $expectedDatabase, $expectedPort): array {
    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $actual = $connection->selectOne('SELECT current_database() AS database');
    $check($actual->database === $expectedDatabase);
    // #914: kolumny `users` zapisywane PRZY OKAZJI każdego uwierzytelnionego
    // żądania przez globalny middleware `AktualizujOstatniaWizyte`
    // (`bootstrap/app.php`, grupa `web`) — NIE przez żadną z akcji panelu,
    // które ten skrypt ma obserwować (decyzje moderacyjne, odwołania).
    // Ten runner wykonuje dokładnie jedno dodatkowe uwierzytelnione GET
    // między migawką "przed" a "po" (`panel-validation.mjs`, `page.goto(list)`
    // po przekierowaniu z odrzuconej walidacji) — to żądanie samo w sobie,
    // niezależnie od tego, czy walidacja przeszła czy nie, potrafi dotknąć
    // te dwie kolumny:
    //   - `ostatnio_widziany_at` (`App\Domain\Analytics\ZanotujOstatniaWizyte`)
    //     — zapisywana throttlowane co
    //     `config('kuking.analytics.last_seen_throttle_minutes')` (domyślnie
    //     15 min); jeśli throttl akurat wygasł MIĘDZY migawkami, wartość się
    //     zmienia mimo braku jakiegokolwiek zapisu domenowego. Zmierzone
    //     i odtworzone deterministycznie w
    //     `tests/Feature/Sledztwo914PomiarPanoluOdwolanTest.php`.
    //   - `pwa_prompt_state` (`App\Domain\Pwa\InstallPrompt::qualify()`,
    //     wołane z tego samego middleware'u) — zapisywana, gdy poprzednia
    //     wizyta była sprzed więcej niż 24h i pole jest jeszcze puste. Ten
    //     sam mechanizm co wyżej, inny próg czasowy — nie zaobserwowaliśmy
    //     tego akurat w tym scenariuszu (próg 15 min, nie 24h), ale źródło
    //     zapisu jest identyczne, więc wykluczamy ją z tego samego powodu,
    //     zanim ktoś zmierzy się z tym samym fałszywym alarmem po 24h ciszy.
    //
    // WYKLUCZAMY WYŁĄCZNIE TE DWIE KOLUMNY, WYŁĄCZNIE W TABELI `users` —
    // reszta wiersza (w tym `status`, `role`, dane profilu w innych
    // tabelach) zostaje w migawce nietknięta. Nic poza tym nie jest
    // osłabiane: żadna tabela nie znika ze zrzutu, `isDeepStrictEqual`
    // w `panel-validation.mjs` zostaje bez zmian, nie ma tolerancji
    // czasowej. Kontrola dodatnia (prawdziwy zapis przy odrzuconej
    // walidacji nadal wywołuje `DOMAIN_CHANGED`) jest w tym samym teście.
    $klucePomijaneWUsers = ['ostatnio_widziany_at', 'pwa_prompt_state'];
    $domain = [];
    foreach (['reports', 'appeals', 'posts', 'users', 'moderation_actions', 'notifications', 'audit'] as $section) {
        $wiersze = $connection->table($section === 'audit' ? 'audit_log' : $section)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        if ($section === 'users') {
            $wiersze = array_map(static fn (array $row): array => array_diff_key($row, array_flip($klucePomijaneWUsers)), $wiersze);
        }
        $domain[$section] = $wiersze;
    }
    $find = static function (string $section, string $id) use ($domain, $check): array {
        foreach ($domain[$section] as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }
        $check(false);

        return [];
    };
    foreach ($required as $section => $ids) {
        foreach ($ids as $id) {
            $find($section, $id);
        }
    }
    foreach (['konto' => $full['konto']['id'], 'bramka' => $full['bramka']['id'], 'autor' => $full['dane']['author']] as $suffix => $id) {
        $user = $find('users', $id);
        $check($user['email'] === $full['namespace'].'-'.$suffix.'@example.test');
    }
    $check($find('users', $full['konto']['id'])['role'] === 'admin'
        && $find('users', $full['bramka']['id'])['role'] === 'moderator');
    foreach ($required['posts'] as $id) {
        $post = $find('posts', $id);
        $check($post['author_id'] === $full['dane']['author'] && str_starts_with($post['body'], $states['marker']));
    }
    foreach ($required['reports'] as $id) {
        $report = $find('reports', $id);
        $check($report['target_type'] === 'post');
        $check($find('posts', $report['target_id'])['author_id'] === $full['dane']['author']);
    }
    foreach ($required['appeals'] as $id) {
        $appeal = $find('appeals', $id);
        $check($appeal['user_id'] === $full['dane']['author']);
        $action = $find('moderation_actions', $appeal['moderation_action_id']);
        $check($action['subject_user_id'] === $full['dane']['author']);
    }

    return ['isolation' => ['host' => '127.0.0.1', 'port' => $expectedPort, 'database' => $actual->database, 'appEnv' => $app->environment(), 'mailer' => config('mail.default')], 'domain' => $domain];
});
$json = json_encode($result, JSON_THROW_ON_ERROR);
while (ob_get_level() > 0) {
    ob_end_clean();
}
fwrite(STDOUT, $json);
