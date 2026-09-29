<?php

declare(strict_types=1);

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * #713 D5 — prawdziwe paczki danych do oglądu w przeglądarce.
 *
 *   php scripts/fixtures/eksport-692.php /katalog/wyjsciowy
 *
 * Buduje PRZEZ `GenerateUserExport` (jak człowiek, który prosi o paczkę) trzy
 * konta: 112 zdjęć odrzuconych obok dwóch gotowych (liczebnik setkowy w
 * ostrzeżeniu), mieszane (odrzucone, skasowane i w drodze naraz) oraz komplet
 * bez braków. Każde archiwum rozpakowuje do `<katalog>/<scena>/` i wypisuje
 * JSON z listą scen. Skrypt tylko DOPISUJE konta do lokalnej bazy demo —
 * odmawia pracy na bazie `kuking` i `kuking_test` oraz na środowisku innym niż
 * lokalne.
 */
$wyjscie = $argv[1] ?? '';
if ($wyjscie === '') {
    fwrite(STDERR, "Podaj katalog wyjściowy.\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$baza = DB::connection()->getDatabaseName();
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || in_array($baza, ['kuking', 'kuking_test'], true)
    || DB::connection()->getConfig('host') !== '127.0.0.1') {
    fwrite(STDERR, "Wymagana lokalna baza pomiarowa (nie kuking ani kuking_test) i APP_ENV=local.\n");
    exit(1);
}

$losowy = bin2hex(random_bytes(3));

// Prawdziwy, malutki WebP: paczka pokazuje zdjęcia gotowe, a zepsuta ikona
// obrazka zaburzałaby pomiar układu.
$webp = (function (): string {
    $obraz = imagecreatetruecolor(600, 400);
    imagefilledrectangle($obraz, 0, 0, 600, 400, imagecolorallocate($obraz, 190, 120, 70));
    ob_start();
    imagewebp($obraz, null, 80);

    return (string) ob_get_clean();
})();

$konto = function (string $alias) use ($losowy): User {
    $user = User::factory()->create(['email' => "eksport692-{$alias}-{$losowy}@example.test"]);
    $user->refresh()->profile->update([
        'username' => 'eksport692_'.str_replace('-', '_', $alias)."_{$losowy}",
        'display_name' => "Eksport {$alias}",
    ]);

    return $user;
};

$zdjecie = function (User $wlasciciel, string $nazwa, string $status) use ($webp): void {
    $klucz = "media/{$wlasciciel->getKey()}/{$nazwa}.webp";
    Storage::disk('public')->put($klucz, $webp);
    Media::factory()->create([
        'owner_id' => $wlasciciel->getKey(),
        'disk' => 'public',
        'object_key' => $klucz,
        'status' => $status,
    ]);
};

$sceny = [
    'setki-odrzuconych' => function (User $u) use ($zdjecie): void {
        $zdjecie($u, 'gotowe-1', Media::STATUS_READY);
        $zdjecie($u, 'gotowe-2', Media::STATUS_READY);
        for ($i = 0; $i < 112; $i++) {
            $zdjecie($u, "odrzucone-{$i}", Media::STATUS_REJECTED);
        }
    },
    'mieszane' => function (User $u) use ($zdjecie): void {
        $zdjecie($u, 'gotowe-1', Media::STATUS_READY);
        for ($i = 0; $i < 12; $i++) {
            $zdjecie($u, "odrzucone-{$i}", Media::STATUS_REJECTED);
        }
        for ($i = 0; $i < 3; $i++) {
            $zdjecie($u, "skasowane-{$i}", Media::STATUS_DELETED);
        }
        for ($i = 0; $i < 2; $i++) {
            $zdjecie($u, "w-drodze-{$i}", Media::STATUS_PENDING);
        }
    },
    'komplet' => function (User $u) use ($zdjecie): void {
        $zdjecie($u, 'gotowe-1', Media::STATUS_READY);
        $zdjecie($u, 'gotowe-2', Media::STATUS_READY);
    },
];

@mkdir($wyjscie, 0700, true);
$wynik = [];
foreach ($sceny as $nazwa => $przygotuj) {
    $user = $konto($nazwa);
    $przygotuj($user);

    $export = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);
    (new GenerateUserExport((string) $export->getKey()))->handle();
    $export->refresh();

    $zip = new ZipArchive;
    $sciezka = Storage::disk((string) $export->disk)->path((string) $export->object_key);
    if ($zip->open($sciezka) !== true) {
        fwrite(STDERR, "Nie udało się otworzyć paczki sceny {$nazwa}.\n");
        exit(1);
    }
    $cel = rtrim($wyjscie, '/').'/'.$nazwa;
    $zip->extractTo($cel);
    $zip->close();
    $wynik[$nazwa] = ['index' => $cel.'/index.html', 'czytaj' => $cel.'/CZYTAJ-TO-NAJPIERW.txt'];
}

echo json_encode($wynik, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
