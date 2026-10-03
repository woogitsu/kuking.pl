<?php

declare(strict_types=1);

/*
 * Dane do pomiaru dostępności ekranów paczek S i N (spiżarnia z terminami,
 * „Wydrukuj zeszyt”, karta z kodem QR, wspomnienia z wykonań, historia wersji,
 * strony sobotniego listu, własne rozmowy i zapamiętane gotowanie) — WYŁĄCZNIE
 * lokalna baza pomiarowa.
 *
 *   php scripts/fixtures/nowe-ekrany-s.php przygotuj             → JSON ze ścieżkami
 *   php scripts/fixtures/nowe-ekrany-s.php link <wypisz|wygasly> <adres serwera>
 *   php scripts/fixtures/nowe-ekrany-s.php zwolnij-limity        → czyści liczniki throttle
 *
 * PO CO. `DemoSeeder` nie daje kontu pomiarowemu ani jednego produktu w „Co mam
 * w domu”, zeszytu z przepisami, wersji przepisu ani wykonania sprzed roku.
 * Ekran bez treści (pusta lista, brak wspomnienia) przechodzi każdy audyt,
 * nie sprawdzając niczego — więc dane powstają tu, a automat sprawdza potem,
 * że ekran naprawdę je pokazuje (`wymaga:` w `scripts/dostepnosc.mjs`).
 *
 * Idempotentne: ponowne uruchomienie nie dokłada drugiej kopii.
 */

use App\Domain\Pantry\OdnosnikWypisaniaZPrzypomnienia;
use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});

$connection = DB::connection();
if (! $app->environment(['local', 'testing']) || $connection->getDriverName() !== 'pgsql'
    || ! str_starts_with((string) $connection->getDatabaseName(), 'kuking_')
    || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Fixture ekranów paczki S wymaga lokalnej bazy pomiarowej z danymi DemoSeedera.');
}

const KONTO = 'ania';

$ania = Profile::where('username', KONTO)->first()?->user
    ?? throw new RuntimeException('Brak konta pomiarowego „ania” — najpierw: php artisan db:seed --class=DemoSeeder');

if (($argv[1] ?? '') === 'zwolnij-limity') {
    // Liczniki `throttle` leżą w pamięci podręcznej; baza jest lokalna i pomiarowa (blokada wyżej).
    Cache::flush();
    echo 'ok';
    exit;
}

if (($argv[1] ?? '') === 'link') {
    $adres = rtrim((string) ($argv[3] ?? ''), '/');
    if ($adres === '') {
        throw new RuntimeException('Podaj adres serwera pomiarowego.');
    }
    URL::forceRootUrl($adres);
    echo match ($argv[2] ?? '') {
        'wypisz' => OdnosnikWypisaniaZPrzypomnienia::dla($ania),
        // Poprawnie podpisany, ale już nieważny — strona „Link wygasł”.
        'wygasly' => URL::temporarySignedRoute('spizarnia.wracam', now()->subMinute(), ['user' => $ania->getKey()]),
        default => throw new RuntimeException('Nieznany rodzaj linku.'),
    };
    exit;
}

$rosol = Recipe::where('slug', 'rosol-babci-zofii')->first()
    ?? throw new RuntimeException('Brak przepisu z DemoSeedera.');

DB::transaction(function () use ($ania, $rosol): void {
    // Ekrany paczki N: rzeczywista własna wypowiedź i włączony na koncie
    // postęp. Oba adresy mają być mierzone z treścią, a nie z pustym stanem.
    Comment::firstOrCreate([
        'author_id' => $ania->getKey(),
        'recipe_id' => $rosol->getKey(),
        'body' => 'Pomiar N: pytanie o rosół w moich rozmowach',
    ], ['status' => Comment::STATUS_PUBLISHED]);
    $kroki = $rosol->steps()->orderBy('position')->pluck('id')->map(fn ($id): string => (string) $id)->all();
    if ($kroki === []) {
        throw new RuntimeException('Przepis pomiarowy nie ma kroków do zapamiętania.');
    }
    app(PostepGotowania::class)->wlacz($ania, $rosol, $kroki, []);

    // Spiżarnia: produkt po terminie, pilny, mrożony, z odległym terminem i bez terminu.
    $dzis = Carbon::now('Europe/Warsaw')->startOfDay();
    $produkty = [
        ['mleko', 'use_by', $dzis->copy()->subDays(2), false, '1 litr'],
        ['marchewki', 'use_by', $dzis->copy()->addDays(2), false, '6 sztuk'],
        ['cebula', 'use_by', $dzis->copy()->addDays(5), false, null],
        ['pierogi ruskie', 'best_before', $dzis->copy()->addMonths(3), true, '2 opakowania'],
        ['ryż', 'best_before', $dzis->copy()->addMonths(8), false, null],
        ['kasza gryczana', null, null, false, null],
    ];
    foreach ($produkty as [$nazwa, $rodzaj, $termin, $mrozone, $ilosc]) {
        $produkt = $ania->pantryItems()->where('name', $nazwa)->first()
            ?? $ania->pantryItems()->create(['name' => $nazwa, 'quantity_note' => $ilosc]);
        $produkt->forceFill(['expiry_kind' => $rodzaj, 'expires_on' => $termin?->toDateString(), 'frozen' => $mrozone])->save();
    }
    $ania->forceFill(['wants_pantry_reminder' => true])->save();

    // Zeszyt z dwoma przepisami demo (do ekranu „Wydrukuj zeszyt”).
    $zeszyt = Collection::firstOrCreate(['owner_id' => $ania->getKey(), 'name' => 'Pomiar paczki S — zeszyt do druku'], [
        'description' => 'Zeszyt danych pomiarowych dla strony do druku.',
        'visibility' => 'private', 'is_default' => false,
    ]);
    $przepisy = Recipe::where('status', 'published')->where('visibility', 'public')->orderBy('id')->limit(2)->get();
    foreach ($przepisy as $i => $przepis) {
        $zeszyt->recipes()->syncWithoutDetaching([$przepis->getKey() => ['created_at' => now()->addMinutes($i + 1)]]);
    }

    // Historia wersji rosołu: TRZY wersje — lista, porównanie, a ekran „jedna
    // wersja” mierzy wersję 2, czyli starszą niż najnowsza (3), bo tylko taka
    // ma przycisk „Zgłoś wersję 2" (najnowsza to sam przepis, bez zgłoszenia).
    foreach ([1 => 'Pierwsza publikacja', 2 => 'Mniej soli, dłuższe gotowanie', 3 => 'Dodany liść laurowy'] as $numer => $opis) {
        RecipeVersion::firstOrCreate(['recipe_id' => $rosol->getKey(), 'version_number' => $numer], [
            'editor_id' => $rosol->author_id,
            'change_note' => $opis,
            'snapshot' => [
                'title' => $rosol->title,
                'summary' => $numer === 1 ? 'Rosół na kurze, jak u babci.' : 'Rosół na kurze, jak u babci, gotowany dłużej.'.($numer === 3 ? ' Z liściem laurowym.' : ''),
                'servings' => 4,
                'ingredients' => [
                    ['group_name' => null, 'text' => '1 kurczak', 'quantity' => 1, 'unit' => null, 'note' => null, 'substitutes' => null, 'no_amount' => false, 'position' => 0],
                    ['group_name' => null, 'text' => $numer === 1 ? 'Sól' : 'Sól do smaku', 'quantity' => null, 'unit' => null, 'note' => null, 'substitutes' => null, 'no_amount' => true, 'position' => 1],
                ],
                'steps' => [
                    ['position' => 0, 'instruction' => match ($numer) {
                        1 => 'Gotuj dwie godziny.',
                        2 => 'Gotuj trzy godziny na małym ogniu.',
                        default => 'Gotuj trzy godziny na małym ogniu, z liściem laurowym.',
                    }, 'timer_seconds' => null],
                ],
            ],
        ]);
    }

    // Dwie rocznice podtrzymują realny stan ekranu, gdy audyt przechodzi
    // przez północ w Polsce. Każdego dnia produkt nadal pokazuje tylko jedną.
    /** @var Closure(): array{0: Carbon, 1: Carbon} $rocznice */
    $rocznice = require __DIR__.'/rocznice-wykonania-s.php';
    foreach ($rocznice() as $dzien => $rok) {
        $notatka = $dzien === 0
            ? 'Pomiar paczki S: rosół sprzed roku'
            : 'Pomiar paczki S: rosół sprzed roku, następny dzień';
        $wykonanie = CookedEvent::where('user_id', $ania->getKey())->where('recipe_id', $rosol->getKey())
            ->where('note', $notatka)->first()
            ?? new CookedEvent([
                'user_id' => $ania->getKey(), 'recipe_id' => $rosol->getKey(),
                'note' => $notatka, 'would_make_again' => true,
                'perceived_difficulty' => 'easy', 'actual_minutes' => 120,
            ]);
        $wykonanie->cooked_at = $rok;
        $wykonanie->hide_as_memory = false;
        $wykonanie->save();
    }
});

$zeszyt = Collection::where('owner_id', $ania->getKey())->where('name', 'Pomiar paczki S — zeszyt do druku')->firstOrFail();
$produkt = $ania->pantryItems()->where('name', 'marchewki')->firstOrFail();

// Wspólne gotowanie (#2385). Dwie sesje, żeby zmierzyć OBA ekrany:
//  - ania jest GOSPODARZEM sesji rosołu z jednym pomocnikiem (basia) i żywym
//    linkiem — widać panel zapraszania, „Odwołaj link” i listę pomocników;
//  - basia jest gospodarzem sesji innego przepisu z żywym linkiem, a ania nie
//    jest jej członkiem — ekran linku (`Dołączam`) widzi właśnie ona.
// Jawny token istnieje tylko tutaj, więc adres linku trafia do JSON-a.
$basia = Profile::where('username', 'basia')->first()?->user
    ?? throw new RuntimeException('Brak konta pomiarowego „basia” — najpierw: php artisan db:seed --class=DemoSeeder');
$sesje = app(SesjaWspolnegoGotowania::class);
$zaproszenia = app(ZaproszenieDoGotowania::class);

$sesjaAni = $sesje->zaloz($ania, $rosol);
DB::table('cooking_session_participants')->insertOrIgnore([
    'session_id' => $sesjaAni->getKey(), 'user_id' => $basia->getKey(), 'role' => 'helper', 'joined_at' => now(),
]);
$zaproszenia->utworz($ania, $sesjaAni);

$innyPrzepis = Recipe::where('status', 'published')->where('visibility', 'public')
    ->where('id', '!=', $rosol->getKey())->whereHas('steps')->orderBy('id')->first()
    ?? throw new RuntimeException('Brak drugiego przepisu z krokami w danych DemoSeedera.');
$sesjaBasi = $sesje->zaloz($basia, $innyPrzepis);
[, $tokenBasi] = $zaproszenia->utworz($basia, $sesjaBasi);

echo json_encode([
    'wspolneGotowanie' => route('wspolne-gotowanie.show', $sesjaAni, false),
    'wspolneGotowanieLink' => route('wspolne-gotowanie.link.show', $tokenBasi, false),
    'zeszytDoDruku' => route('collections.print', $zeszyt, false),
    'terminProduktu' => route('pantry.edit', $produkt, false),
    'kartaPrzepisu' => route('recipes.qr-card', $rosol->slug, false),
    'kartaProfilu' => route('profile.qr-card', KONTO, false),
    'historia' => route('recipes.history', $rosol->slug, false),
    'wersja' => route('recipes.history.version', [$rosol->slug, 2], false),
    'zmiany' => route('recipes.history.changes', [$rosol->slug, 2], false),
], JSON_THROW_ON_ERROR);
