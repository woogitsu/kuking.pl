<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): never {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
$c = DB::connection();
if (! $app->environment(['local', 'testing']) || $c->getDriverName() !== 'pgsql' || ! str_starts_with($c->getDatabaseName(), 'kuking_port') || ! in_array($c->getConfig('host'), ['localhost', '127.0.0.1'], true) || (string) $c->getConfig('port') !== (string) getenv('DB_PORT') || config('mail.default') !== 'array') {
    throw new RuntimeException('434 wymaga własnej lokalnej bazy kuking_port i mailera array.');
}
$owner = Profile::where('username', 'ania')->firstOrFail()->user;
$snapshot = $argv[2] ?? throw new RuntimeException('Brak ścieżki kopii poza repo.');
if (($argv[1] ?? '') === 'przywroc') {
    $state = json_decode(file_get_contents($snapshot), true, flags: JSON_THROW_ON_ERROR);
    DB::transaction(function () use ($owner, $state) {
        $owner->notifications()->whereIn('id', $state['notifications'])->delete();
        foreach ($state['read_at'] as $id => $date) {
            $owner->notifications()->whereKey($id)->update(['read_at' => $date]);
        }
        $post = Post::whereKey($state['post'])->where('author_id', $owner->id)->where('body', 'Kontrolowany wpis do odbioru434.')->firstOrFail();
        Comment::where('post_id', $post->id)->forceDelete();
        $post->forceDelete();
        $owner->profile->getConnection()->table('profiles')->where('user_id', $owner->id)->update(['display_name' => $state['display_name'], 'updated_at' => $state['profile_updated_at']]);
    });
    foreach ($state['read_at'] as $id => $date) {
        if ($owner->notifications()->whereKey($id)->value('read_at')?->toISOString() !== $date) {
            throw new RuntimeException('434: nie odtworzono read_at.');
        }
    }
    if ($owner->profile->fresh()->display_name !== $state['display_name'] || $owner->profile->fresh()->getRawOriginal('updated_at') !== $state['profile_updated_at']) {
        throw new RuntimeException('434: nie odtworzono nazwy.');
    }
    if (is_file(public_path($state['sampleFile']))) {
        unlink(public_path($state['sampleFile']));
    }
    exit;
}
if (file_exists($snapshot)) {
    throw new RuntimeException('Kopia już istnieje; odmowa nadpisania.');
}
$sampleFile = 'audyt-awatar434-'.bin2hex(random_bytes(16)).'.html';
$sampleHandle = null;
try {
    $state = DB::transaction(function () use ($owner, $snapshot, $sampleFile, &$sampleHandle) {
        $state = ['sampleFile' => $sampleFile, 'samplePath' => '/'.$sampleFile, 'profile_updated_at' => $owner->profile->getRawOriginal('updated_at'), 'display_name' => $owner->profile->display_name, 'read_at' => $owner->notifications()->pluck('read_at', 'id')->map(fn ($d) => $d?->toISOString())->all()];
        $owner->notifications()->update(['read_at' => now()]);
        $state['notifications'] = [];
        foreach (['basia', 'marek'] as $name) {
            $state['notifications'][] = Notification::create(['user_id' => $owner->id, 'actor_id' => Profile::where('username', $name)->firstOrFail()->user_id, 'type' => Notification::TYPE_FOLLOW, 'data' => []])->id;
        }
        $owner->profile->update(['display_name' => 'Małgorzata Konstantynopolitańczykowianka']);
        $owner->refresh();
        if (! $owner->profile->zdjecieDoPokazania()) {
            throw new RuntimeException('Brak prawdziwego wariantu awatara demo.');
        }
        $post = Post::factory()->create(['author_id' => $owner->id, 'visibility' => Post::VISIBILITY_FOLLOWERS, 'status' => Post::STATUS_PUBLISHED, 'published_at' => now(), 'body' => 'Kontrolowany wpis do odbioru434.']);
        $comment = Comment::factory()->create(['post_id' => $post->id, 'author_id' => $owner->id, 'body' => 'Komentarz kontrolowanego odbioru434.']);
        Comment::factory()->create(['post_id' => $post->id, 'author_id' => $owner->id, 'parent_id' => $comment->id, 'body' => 'Odpowiedź kontrolowanego odbioru434.']);
        $state['post'] = $post->id;
        $state['postPath'] = route('posts.show', $post, false);
        $recipe = Recipe::where('status', 'published')->where('visibility', 'public')->firstOrFail();
        $state['recipePath'] = route('recipes.show', $recipe, false);
        Auth::setUser($owner);
        $initial = clone $owner;
        $profile = clone $owner->profile;
        $profile->avatar_media_id = null;
        $profile->unsetRelation('avatar');
        $initial->setRelation('profile', $profile);
        $html = Blade::render('<x-layout title="Kontrolowany pomiar awatarów434"><h1>Kontrolowana próbka komponentu — nie trasa produktu</h1>@foreach($users as $kind=>$person) @foreach($sizes as $size)<section class="card" data-proba="{{ $kind }}" data-size="{{ $size }}"><p>{{ $kind }} / {{ $size }}px</p><x-avatar :user="$person" :size="$size" /></section>@endforeach @endforeach</x-layout>', ['users' => ['zdjecie' => $owner, 'inicjal' => $initial], 'sizes' => [32, 40, 44, 48, 52, 56, 64, 88, 120, 128]]);
        $sampleHandle = fopen(public_path($sampleFile), 'x');
        if ($sampleHandle === false || fwrite($sampleHandle, $html) !== strlen($html)) {
            throw new RuntimeException('Nie zapisano kontrolowanej próbki.');
        }
        if (file_put_contents($snapshot, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Nie zapisano kopii, transakcja wycofana.');
        }

        return $state;
    });
} catch (Throwable $e) {
    if (is_resource($sampleHandle)) {
        fclose($sampleHandle);
        unlink(public_path($sampleFile));
    }
    if (is_file($snapshot)) {
        unlink($snapshot);
    }
    throw $e;
}
if (is_resource($sampleHandle)) {
    fclose($sampleHandle);
}
echo json_encode($state, JSON_THROW_ON_ERROR);
