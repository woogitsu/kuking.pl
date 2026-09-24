<?php

declare(strict_types=1);

/*
 * Dane do pomiaru wydruku przepisu (issue #765) — WYŁĄCZNIE lokalna baza.
 *
 *   php scripts/fixtures/wydruk-765.php utworz   → JSON ze ścieżkami przepisów
 *   php scripts/fixtures/wydruk-765.php usun     → zdejmuje długi przepis
 *
 * Krótki przepis to rosół z DemoSeedera (bez grup). Długi przepis powstaje
 * tutaj, bo DemoSeeder nie ma żadnego z grupami „Ciasto”/„Farsz”, uwagą,
 * „bez ilości” ani z krokiem na pół strony — a dokładnie te rzeczy łatwo
 * zgubić albo przeciąć na papierze.
 */

use App\Models\Profile;
use App\Models\Recipe;
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

$connection = DB::connection();
if (! $app->environment(['local', 'testing']) || $connection->getDriverName() !== 'pgsql'
    || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Pomiar wydruku #765 wymaga lokalnej bazy PostgreSQL z danymi DemoSeedera.');
}

const SLUG_DLUGI = 'wydruk-765-pierogi-z-kapusta';
// Własne konto pomiaru: hasła kont DemoSeedera są losowe, a to konto ma hasło
// z UserFactory — i jest AUTOREM długiego przepisu, więc widać wszystkie
// przyciski autora, które na papier iść nie mogą.
const KONTO = 'wydruk765';

function usun(): void
{
    Recipe::withTrashed()->where('slug', SLUG_DLUGI)->get()->each->forceDelete();
    Profile::where('username', KONTO)->first()?->user?->forceDelete();
}

if (($argv[1] ?? '') === 'usun') {
    DB::transaction(usun(...));
    exit;
}

$krotki = Recipe::where('slug', 'rosol-babci-zofii')->first()
    ?? throw new RuntimeException('Brak przepisu z DemoSeedera — najpierw: php artisan db:seed --class=DemoSeeder');

DB::transaction(function (): void {
    usun();
    $autor = User::factory()->create();
    $autor->load('profile')->profile->forceFill(['username' => KONTO])->save();
    $przepis = Recipe::factory()->create([
        'author_id' => $autor->getKey(),
        'title' => 'Pierogi z kapustą i grzybami na Wigilię',
        'slug' => SLUG_DLUGI,
        'summary' => 'Długi przepis do pomiaru wydruku: dwie grupy składników, uwagi i długi krok.',
        'servings' => 6,
        'source_type' => Recipe::SOURCE_EXTERNAL,
        'source_url' => 'https://example.test/ksiazka-kucharska/pierogi',
    ]);
    $skladniki = [
        ['Ciasto', '500 g mąki pszennej', 'typ 450', false],
        ['Ciasto', '1 jajko', null, false],
        ['Ciasto', '250 ml ciepłej wody', null, false],
        ['Ciasto', 'Sól', null, true],
        ['Farsz', '1 kg kiszonej kapusty', 'odciśniętej', false],
        ['Farsz', '30 g suszonych grzybów', 'namoczonych na noc', false],
        ['Farsz', '2 cebule', null, false],
        ['Farsz', 'Pieprz', 'do smaku', true],
        ['Do podania', '1 cebula na okrasę', null, false],
        ['Do podania', 'Masło', null, true],
    ];
    foreach ($skladniki as $pozycja => [$grupa, $tekst, $uwaga, $bezIlosci]) {
        $przepis->ingredients()->create(['group_name' => $grupa, 'ingredient_text' => $tekst,
            'note' => $uwaga, 'no_amount' => $bezIlosci, 'position' => $pozycja]);
    }
    $dlugi = str_repeat('Farsz nakładaj łyżeczką na środek krążka, składaj na pół i dokładnie sklejaj brzegi palcami, tak żeby nie zostało powietrze. ', 14);
    $kroki = [
        'Grzyby gotuj w wodzie z namaczania przez 30 minut, odcedź i drobno posiekaj. Wywar zachowaj.',
        'Kapustę przepłucz, jeśli jest bardzo kwaśna, posiekaj i gotuj w wywarze z grzybów do miękkości.',
        'Cebulę pokrój w kostkę i zeszklij na maśle. Połącz z kapustą i grzybami, dopraw pieprzem i ostudź.',
        'Z mąki, jajka, soli i ciepłej wody zagnieć gładkie, elastyczne ciasto. Przykryj miską na 20 minut.',
        'Ciasto rozwałkuj cienko na posypanym mąką blacie i wykrawaj szklanką krążki.',
        $dlugi,
        'Gotuj partiami w dużym garnku osolonej wody, 2 minuty od wypłynięcia.',
        'Podawaj z cebulką podsmażoną na maśle.',
    ];
    foreach (array_merge($kroki, $kroki) as $pozycja => $tekst) {
        $przepis->steps()->create(['position' => $pozycja, 'instruction' => $tekst]);
    }
});

echo json_encode([
    'konto' => KONTO,
    'krotki' => route('recipes.show', $krotki->slug, false),
    'dlugi' => route('recipes.show', SLUG_DLUGI, false),
], JSON_THROW_ON_ERROR);
