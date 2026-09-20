<?php

declare(strict_types=1);

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $id, $directory, $label, $mode] = $argv;
DB::statement("SET lock_timeout = '20s'");
DB::statement("SET statement_timeout = '25s'");
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
file_put_contents($directory.'/pid-'.$label, (string) $pid);
RecipeVersion::creating(function () use ($directory, $label): void {
    touch($directory.'/ready-'.$label);
    $deadline = microtime(true) + 20;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Nie zwolniono bariery migawki.');
        }
        usleep(20_000);
    }
});
try {
    $recipe = Recipe::findOrFail($id);
    if ($mode === 'snapshot') {
        app(SnapshotRecipeVersion::class)->handle($recipe, $recipe->author);
    } else {
        app(PublishRecipe::class)->handle($recipe->author, ['title' => 'Publikacja '.$label], [['text' => 'Składnik '.$label]], [['instruction' => 'Krok '.$label]], true, $recipe);
    }
    echo json_encode(['pid' => $pid, 'os_pid' => getmypid(), 'ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['pid' => $pid, 'os_pid' => getmypid(), 'ok' => false, 'message' => $e->getMessage()]);
    exit(1);
}
