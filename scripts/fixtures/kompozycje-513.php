<?php

declare(strict_types=1);

use App\Domain\Moderation\Actions\NotifyModerationDecision;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
if ($app->environment('production') || ! str_starts_with((string) config('database.connections.pgsql.database'), 'kuking_port')) {
    throw new RuntimeException('Fixture513 wymaga wyłącznej bazy kuking_port*.');
}
$owner = Profile::where('username', 'ania')->firstOrFail()->user;
if (($argv[1] ?? '') === 'przywroc') {
    $state = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    foreach ($owner->notifications()->get() as $n) {
        if (array_key_exists((string) $n->id, $state['read'])) {
            // Zachowaj offset ISO dla timestamptz. Cast Eloquent przy zapisie
            // usuwa strefę, więc PostgreSQL interpretuje czas w strefie sesji.
            DB::table($n->getTable())->where('id', $n->id)->update([
                'read_at' => $state['read'][(string) $n->id],
            ]);
        }
    }
    $owner->followedTags()->sync($state['tags']);
    exit;
}
if (($argv[1] ?? '') === 'stan') {
    echo json_encode(['read' => $owner->notifications()->orderBy('id')->get()->mapWithKeys(fn ($n) => [(string) $n->id => $n->read_at?->toISOString()]), 'tags' => $owner->followedTags()->orderBy('tags.id')->pluck('tags.id')], JSON_THROW_ON_ERROR);
    exit;
}
// Wyłącznie dane izolowanej bazy pomiarowej, kopia poza repo na czas stanów pustych.
if (($argv[1] ?? '') === 'zapisz-listy') {
    file_put_contents($argv[2], json_encode(['notifications' => $owner->notifications()->get()->map(fn ($n) => $n->getAttributes()), 'promotions' => TagPromotion::all()->map(fn ($p) => $p->getAttributes())], JSON_THROW_ON_ERROR));
    exit;
}
if (($argv[1] ?? '') === 'przywroc-listy') {
    $saved = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    DB::transaction(function () use ($owner, $saved) {
        $owner->notifications()->delete();
        TagPromotion::query()->delete();
        if ($saved['notifications']) {
            DB::table((new Notification)->getTable())->insert($saved['notifications']);
        }
        if ($saved['promotions']) {
            DB::table((new TagPromotion)->getTable())->insert($saved['promotions']);
        }
    });
    $restored = $owner->notifications()->get()->map(fn ($n) => $n->getAttributes())->keyBy('id')->all();
    $restoredPromotions = TagPromotion::all()->map(fn ($p) => $p->getAttributes())->keyBy('id')->all();
    if ($restored != collect($saved['notifications'])->keyBy('id')->all() || $restoredPromotions != collect($saved['promotions'])->keyBy('id')->all()) {
        throw new RuntimeException('Nie odtworzono list pomiarowych513.');
    }
    exit;
}
if (($argv[1] ?? '') === 'puste-listy') {
    $owner->notifications()->delete();
    TagPromotion::query()->delete();
    exit;
}
if (($argv[1] ?? '') === 'paginacja') {
    $sample = $owner->notifications()->where('data->pomiar', '513')->where('type', Notification::TYPE_FOLLOW)->firstOrFail();
    for ($i = 0; $i < 31; $i++) {
        $owner->notifications()->create(['actor_id' => $sample->actor_id, 'type' => $sample->type, 'data' => [...$sample->data, 'pomiar' => '513-page']]);
    }
    exit;
}
$actor = Profile::where('username', 'basia')->firstOrFail()->user;
if (! $owner->notifications()->where('data->pomiar', '513')->exists()) {
    $recipe = Recipe::factory()->create(['author_id' => $owner->id, 'title' => 'Rodzinne przepisy '.str_repeat('na wspólny obiad ', 6).'KONIEC513']);
    $post = Post::factory()->create(['author_id' => $owner->id]);
    $comment = Comment::factory()->create(['author_id' => $actor->id, 'post_id' => $post->id]);
    $event = CookedEvent::factory()->create(['user_id' => $actor->id, 'recipe_id' => $recipe->id]);
    foreach ([Notification::TYPE_COOKED, Notification::TYPE_COMMENT, Notification::TYPE_REPLY, Notification::TYPE_FOLLOW, Notification::TYPE_SAVED] as $type) {
        $owner->notifications()->create(['actor_id' => $actor->id, 'type' => $type, 'data' => ['pomiar' => '513', 'cooked_event_id' => $event->id, 'url' => route('posts.show', $post->id, false).'#komentarz-'.$comment->id, 'recipe_title' => $recipe->title, 'recipe_slug' => $recipe->slug, 'comment_id' => $comment->id, 'excerpt' => str_repeat('Długi komentarz o wspólnym gotowaniu. ', 5).'KONIEC513', 'username' => 'basia']]);
    }
    $owner->notifications()->create(['actor_id' => null, 'type' => Notification::TYPE_SAVED, 'data' => ['pomiar' => '513', 'recipe_title' => 'Bez autora i celu513']]);
    $read = $owner->notifications()->create(['actor_id' => $actor->id, 'type' => Notification::TYPE_FOLLOW, 'data' => ['pomiar' => '513', 'username' => 'basia']]);
    $read->read_at = now();
    $read->save();
    $decision = ModerationAction::create(['moderator_id' => $actor->id, 'target_type' => 'post', 'target_id' => $post->id, 'action' => ModerationAction::ACTION_HIDE, 'reason_code' => 'spam']);
    $notice = app(NotifyModerationDecision::class)->handle($owner, ModerationAction::ACTION_HIDE, str_repeat('Treść decyzji pomiarowej z pełnym wyjaśnieniem przyczyny i drogi odwołania. ', 5), null, $decision);
    $notice->data = [...$notice->data, 'pomiar' => '513'];
    $notice->save();
}
for ($i = 1; $i <= 7; $i++) {
    $name = $i === 7 ? str_repeat('D', 30) : 'Pomiar513 temat '.$i;
    $tag = Tag::firstOrCreate(['slug' => 'pomiar513-'.$i], ['name' => $name, 'normalized_name' => mb_strtolower($name)]);
    TagPromotion::firstOrCreate(['tag_id' => $tag->id], ['position' => 100 + $i]);
}
echo json_encode(['sciezki513' => ['/witaj/zainteresowania', '/powiadomienia'], 'tagi513' => TagPromotion::count(), 'bezCelu513' => $owner->notifications()->where('data->pomiar', '513')->whereNull('actor_id')->firstOrFail()->id, 'akcje513' => $owner->notifications()->where('data->pomiar', '513')->whereIn('type', [Notification::TYPE_COOKED, Notification::TYPE_COMMENT, Notification::TYPE_REPLY, Notification::TYPE_FOLLOW, Notification::TYPE_SAVED])->with(['actor.profile', 'user'])->get()->filter(fn ($n) => $n->actor_id !== null && $n->read_at === null)->map(fn ($n) => ['id' => $n->id, 'url' => $n->adresDocelowy()])->values()], JSON_THROW_ON_ERROR);
