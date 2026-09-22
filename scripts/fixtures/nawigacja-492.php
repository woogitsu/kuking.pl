<?php

declare(strict_types=1);

use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
$connection = DB::connection();
if (! $app->environment(['local', 'testing']) || $connection->getDriverName() !== 'pgsql' || ! str_starts_with($connection->getDatabaseName(), 'kuking_port') || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true) || (string) $connection->getConfig('port') !== (string) getenv('DB_PORT')) {
    throw new RuntimeException('Nawigacja492 wymaga lokalnej bazy kuking_port*.');
}
$owner = Profile::where('username', 'ania')->firstOrFail()->user;
if (($argv[1] ?? '') === 'przywroc') {
    $state = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::transaction(function () use ($owner, $state): void {
        $owner->notifications()->whereIn('id', $state['notifications'])->delete();
        foreach ($state['read_at'] as $id => $readAt) {
            $owner->notifications()->whereKey($id)->update(['read_at' => $readAt]);
        }
        ModerationAction::whereKey($state['action'])->where('subject_user_id', $owner->id)->where('target_id', $state['post'])->delete();
        Post::whereKey($state['post'])->where('author_id', $owner->id)->where('body', 'Wyłącznie lokalny pomiar nawigacji492.')->firstOrFail()->forceDelete();
    });
    foreach ($state['read_at'] as $id => $readAt) {
        if ($owner->notifications()->whereKey($id)->value('read_at')?->toISOString() !== $readAt) {
            throw new RuntimeException('Nawigacja492: nie odtworzono stanu przeczytania.');
        }
    }
    exit;
}
$moderator = Profile::where('username', 'moderacja')->firstOrFail()->user;
$snapshot = $argv[2] ?? throw new RuntimeException('Nawigacja492 wymaga ścieżki kopii stanu poza repo.');
$result = DB::transaction(function () use ($owner, $moderator, $snapshot): array {
    $readAt = $owner->notifications()->pluck('read_at', 'id')->map(fn ($date) => $date?->toISOString())->all();
    $owner->notifications()->update(['read_at' => now()]);
    $notifications = [];
    foreach ([1, 2] as $number) {
        $notifications[] = Notification::create(['user_id' => $owner->id, 'actor_id' => null, 'type' => Notification::TYPE_MODERATION, 'data' => ['title' => 'Lokalny pomiar492 '.$number, 'message' => 'Wyłącznie lokalny pomiar licznika.', 'appeal' => false]])->id;
    }
    $post = Post::factory()->create(['author_id' => $owner->id, 'status' => Post::STATUS_HIDDEN, 'body' => 'Wyłącznie lokalny pomiar nawigacji492.']);
    $action = ModerationAction::create(['moderator_id' => $moderator->id, 'target_type' => 'post', 'target_id' => $post->id, 'subject_user_id' => $owner->id, 'action' => ModerationAction::ACTION_HIDE, 'previous_status' => Post::STATUS_PUBLISHED, 'reason_code' => 'spam-reklama', 'user_message' => 'Lokalna decyzja do pomiaru dostępności odwołania.']);

    $state = ['read_at' => $readAt, 'notifications' => $notifications, 'action' => $action->id, 'post' => $post->id, 'path' => route('appeals.show', $action, false)];
    if (file_put_contents($snapshot, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Nawigacja492: nie zapisano kopii stanu, transakcja wycofana.');
    }

    return $state;
});
echo json_encode($result, JSON_THROW_ON_ERROR);
