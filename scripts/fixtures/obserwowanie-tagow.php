<?php

declare(strict_types=1);

use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
$db = DB::connection();
if (! $app->environment(['local', 'testing']) || $db->getConfig('host') !== '127.0.0.1'
    || (string) $db->getConfig('port') !== '55439' || $db->getDatabaseName() !== 'kuking_flota_gpt_tagi_a11y') {
    throw new RuntimeException('Fixture wymaga własnej bazy kuking_flota_gpt_tagi_a11y na 127.0.0.1:55439.');
}
$count = (int) ($argv[1] ?? 10);
if (! in_array($count, [10, 40, 100], true)) {
    throw new InvalidArgumentException('Podaj 10, 40 albo 100 tagów.');
}
$user = User::where('email', 'tagi-pomiar@example.test')->first()
    ?? User::factory()->create(['email' => 'tagi-pomiar@example.test', 'wants_weekly_digest' => false]);
$user->fresh()->profile->update(['display_name' => 'Pomiar tagów']);
Tag::where('slug', 'like', 'pomiar-tagi-%')->delete();
foreach (range(1, $count) as $i) {
    $name = sprintf('Temat kulinarny %03d', $i);
    $tag = Tag::create(['slug' => sprintf('pomiar-tagi-%03d', $i), 'name' => $name, 'normalized_name' => mb_strtolower($name)]);
    TagPromotion::create(['tag_id' => $tag->id, 'position' => $i]);
    if ($i % 3 === 0) {
        $user->followedTags()->attach($tag->id, ['created_at' => now()]);
    }
}
echo 'Utworzono '.$count.' syntetycznych tagów.'.PHP_EOL;
