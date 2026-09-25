<?php

declare(strict_types=1);

/**
 * Karta B z issue #982: prawdziwa `EditComment` w osobnym procesie, więc na
 * własnym połączeniu do PostgreSQL. Melduje jednym wierszem JSON-a, jak
 * `scenariusz.php` — wynik czyta się z JSON-a, nie z kodu wyjścia.
 */

use App\Domain\Comments\Actions\EditComment;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '5s'");
DB::statement("SET statement_timeout = '8s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    $comment = Comment::query()->findOrFail($args['comment']);
    $author = User::query()->findOrFail($comment->author_id);
    $poprawiony = app(EditComment::class)->handle($author, $comment, $args['body'], $args['wersja']);
    if ($poprawiony === null) {
        // Odmowa Policy pod zamkiem (#1337) — to nie jest wynik wyścigu #982.
        echo json_encode(['ok' => false, 'wartosc' => null, 'sqlstate' => null, 'komunikat' => 'Policy odmówiła poprawki.', 'wyjatek' => null]);
    } else {
        echo json_encode(['ok' => true, 'wartosc' => $poprawiony->body, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
    }
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
