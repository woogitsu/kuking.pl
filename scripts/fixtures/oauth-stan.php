<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/oauth-bootstrap.php';
$path = $argv[2] ?? throw new RuntimeException('Brak zewnętrznego pliku stanu.');
if (($argv[1] ?? '') === 'usun') {
    $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $user = User::whereKey($state['id'])->where('email', $state['email'])->firstOrFail();
    if ($user->google_sub !== null || $user->tozsamosciZewnetrzne()->exists()) {
        throw new RuntimeException('OAUTH345: sam odbiór ekranu utworzył powiązanie.');
    }
    $user->forceDelete();
    echo "OAUTH345_CLEANUP_OK\n";
    exit;
}
if (file_exists($path)) {
    throw new RuntimeException('OAUTH345: odmowa nadpisania stanu.');
}
DB::transaction(function () use ($path) {
    $id = bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(24));
    $user = User::factory()->create(['email' => 'oauth345-'.$id.'@example.test', 'password' => Hash::make($password), 'wants_weekly_digest' => false]);
    $user->refresh()->profile->update(['display_name' => 'Małgorzata Konstantynopolitańczykowianka']);
    $state = ['id' => $user->id, 'email' => $user->email, 'password' => $password, 'suffix' => $id];
    if (file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('OAUTH345: nie zapisano stanu, wycofano fixture.');
    }
});
