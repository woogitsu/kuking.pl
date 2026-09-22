<?php

declare(strict_types=1);

use App\Models\Appeal;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// php scripts/fixtures/panel-stany.php /poza-repo/pelny.json /poza-repo/stany.json
// Wyłącznie nowe lokalne rekordy. Statusy listów są danymi poglądowymi,
// nie dowodem wysłania wiadomości. Nie uruchamiamy akcji pocztowych/moderacji.
umask(0077);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, 'PANEL_STANY: przerwano ('.get_class($error).'); bez publikacji danych wyjątku.'.PHP_EOL);
    exit(1);
});
$connection = DB::connection();
$database = $connection->getDatabaseName();
$port = (string) $connection->getConfig('port');
$ci = getenv('GITHUB_ACTIONS') === 'true';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing'])
    || realpath((string) getenv('APP_BASE_PATH')) !== realpath(__DIR__.'/../..')
    || $connection->getDriverName() !== 'pgsql' || $connection->getConfig('host') !== '127.0.0.1'
    || ! getenv('DB_PORT') || $port !== (string) getenv('DB_PORT')
    || (! $ci && ($port !== '55439' || ! in_array($database, ['kuking_581_browser', 'kuking_581_validation'], true)))
    || ($ci && $database !== 'kuking_port_panel')
    || config('mail.default') !== 'array' || config('filesystems.disks.public.driver') !== 'local') {
    throw new RuntimeException('Wymagana wydzielona baza panelu i lokalne środowisko.');
}
Http::preventStrayRequests();
$privatePath = static function (string $path): string {
    $parent = realpath(dirname($path));
    if ($path === '' || $parent === false || is_link($path) || ! is_writable($parent)) {
        throw new RuntimeException('Niepoprawna ścieżka prywatnego stanu.');
    }
    $application = realpath(base_path());
    if ($application === false || strcasecmp($parent, $application) === 0
        || str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR), strtolower($application.DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Stan musi pozostać poza aplikacją, także w kopii bez .git.');
    }
    for ($directory = $parent; dirname($directory) !== $directory; $directory = dirname($directory)) {
        if (file_exists($directory.DIRECTORY_SEPARATOR.'.git')) {
            throw new RuntimeException('Stan musi pozostać poza repozytoriami.');
        }
    }

    return $parent.DIRECTORY_SEPARATOR.basename($path);
};
$input = $privatePath($argv[1] ?? '');
$output = $privatePath($argv[2] ?? '');
if (! is_file($input) || file_exists($output) || $input === $output) {
    throw new RuntimeException('Wymagany pełny stan oraz nowy osobny plik wynikowy.');
}
$state = json_decode(file_get_contents($input), true, flags: JSON_THROW_ON_ERROR);
if (($state['phase'] ?? null) !== 'pelny' || ($state['database'] ?? null) !== $database
    || ! preg_match('/\Apanel581-[a-f0-9]{12}\z/', $state['namespace'] ?? '')) {
    throw new RuntimeException('Niepoprawna faza lub baza stanu.');
}
$namespace = $state['namespace'];
$host = User::whereKey($state['konto']['id'])->where('email', $namespace.'-konto@example.test')->firstOrFail();
$gate = User::whereKey($state['bramka']['id'])->where('email', $namespace.'-bramka@example.test')->firstOrFail();
$author = User::whereKey($state['dane']['author'])->where('email', $namespace.'-autor@example.test')->firstOrFail();
if ($host->role !== User::ROLE_ADMIN || ! $host->hasTwoFactorConfirmed() || $gate->role !== User::ROLE_MODERATOR) {
    throw new RuntimeException('Zmienione role kont fixture.');
}
$marker = 'panel-stany-'.$namespace;
$handle = fopen($output, 'x');
if ($handle === false) {
    throw new RuntimeException('Nie można zarezerwować nowego pliku wyniku.');
}
try {
    chmod($output, 0600);
    DB::transaction(function () use ($host, $gate, $author, $marker, $database, $handle): void {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$marker]);
        if (Post::withTrashed()->where('body', 'like', $marker.'%')->exists()) {
            throw new RuntimeException('Te stany już utworzono; odmowa ponowienia.');
        }
        $hidden = Post::factory()->create(['author_id' => $author->id, 'body' => $marker.' — lokalna schowana treść do odbioru przywrócenia.', 'status' => Post::STATUS_HIDDEN, 'published_at' => now()]);
        $openPost = Post::factory()->create(['author_id' => $author->id, 'body' => $marker.' — drugi lokalny wpis do izolacji błędów.', 'status' => Post::STATUS_PUBLISHED, 'published_at' => now()]);
        $resolved = Report::create(['reporter_id' => $host->id, 'target_type' => 'post', 'target_id' => $hidden->id, 'source' => Report::SOURCE_COMMUNITY, 'reason' => 'other', 'details' => 'Dane poglądowe: sprawa rozpatrzona.', 'status' => Report::STATUS_RESOLVED, 'resolved_by' => $gate->id, 'resolved_at' => now()]);
        $open = Report::create(['reporter_id' => $host->id, 'target_type' => 'post', 'target_id' => $openPost->id, 'source' => Report::SOURCE_COMMUNITY, 'reason' => 'other', 'details' => 'Drugi lokalny formularz do odbioru walidacji.', 'status' => Report::STATUS_OPEN]);
        $hide = ModerationAction::create(['moderator_id' => $gate->id, 'report_id' => $resolved->id, 'target_type' => 'post', 'target_id' => $hidden->id, 'subject_user_id' => $author->id, 'action' => ModerationAction::ACTION_HIDE, 'reason_code' => 'other', 'note' => 'Dane poglądowe, bez wykonania akcji moderacyjnej.', 'user_message' => 'Lokalny przykład uzasadnienia schowania treści.']);
        $warn = ModerationAction::create(['moderator_id' => $gate->id, 'target_type' => 'post', 'target_id' => $openPost->id, 'subject_user_id' => $author->id, 'action' => ModerationAction::ACTION_WARN, 'reason_code' => 'other', 'note' => 'Druga lokalna decyzja do odwołania.', 'user_message' => 'Poglądowe uzasadnienie bez powiadomienia.']);
        $closedAppeal = Appeal::create(['moderation_action_id' => $hide->id, 'user_id' => $author->id, 'appellant' => Appeal::APPELLANT_AUTHOR, 'body' => 'Lokalne zakończone odwołanie do odbioru widoku.', 'status' => Appeal::STATUS_UPHELD, 'decided_by' => $host->id, 'decided_at' => now(), 'decision_note' => 'Poglądowy stan podtrzymania decyzji, bez rzeczywistego rozpatrywania sprawy.']);
        $openAppeal = Appeal::create(['moderation_action_id' => $warn->id, 'user_id' => $author->id, 'appellant' => Appeal::APPELLANT_AUTHOR, 'body' => 'Drugie lokalne otwarte odwołanie do izolacji walidacji.', 'status' => Appeal::STATUS_OPEN]);
        $withoutAddress = ContactMessage::factory()->create(['user_id' => null, 'contact_email' => null, 'message' => $marker.' — lokalna wiadomość bez adresu odpowiedzi.', 'wydanie' => 'panel581-stany']);
        if ($withoutAddress->adresDoOdpowiedzi() !== null) {
            throw new RuntimeException('Wiadomość nie jest wariantem bez adresu.');
        }
        $history = ContactMessage::factory()->create(['user_id' => $author->id, 'contact_email' => null, 'message' => $marker.' — trzy poglądowe stany historii, żaden list nie był wysyłany.', 'wydanie' => 'panel581-stany']);
        $replyIds = [];
        foreach ([ContactMessageReply::STATUS_W_TOKU, ContactMessageReply::STATUS_NIEUDANA, ContactMessageReply::STATUS_WYSLANA] as $status) {
            $reply = ContactMessageReply::create(['contact_message_id' => $history->id, 'author_id' => $host->id, 'body' => 'Lokalna symulacja widoku statusu '.$status.'. Nie wykonano wysyłki poczty.']);
            if ($status === ContactMessageReply::STATUS_NIEUDANA) {
                $reply->oznaczNieudana('Poglądowy błąd lokalny; bez kontaktu z dostawcą.');
            } elseif ($status === ContactMessageReply::STATUS_WYSLANA) {
                $reply->oznaczWyslana();
            }
            $replyIds[$status] = (string) $reply->id;
        }
        $result = ['phase' => 'stany', 'database' => $database, 'marker' => $marker,
            'uwaga' => 'Wyłącznie poglądowe rekordy; zero wysyłek i powiadomień.',
            'ids' => ['hidden_post' => $hidden->id, 'open_post' => $openPost->id, 'resolved_report' => $resolved->id, 'second_open_report' => $open->id, 'closed_appeal' => $closedAppeal->id, 'second_open_appeal' => $openAppeal->id, 'without_address' => $withoutAddress->id, 'history_message' => $history->id, 'replies' => $replyIds],
            'paths' => ['/admin/zgloszenia?status=resolved', '/admin/zgloszenia?status=open', '/admin/odwolania?status=upheld', '/admin/odwolania?status=open', '/admin/wiadomosci/'.$withoutAddress->id, '/admin/wiadomosci/'.$history->id]];
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
            throw new RuntimeException('Zapis wyniku nieudany; transakcja zostanie wycofana.');
        }
    });
} catch (Throwable $error) {
    fclose($handle);
    unlink($output);
    throw $error;
}
fclose($handle);
echo 'PANEL_STANY: utworzono dodatkowe lokalne stany; wynik w osobnym prywatnym pliku.'.PHP_EOL;
