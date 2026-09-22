<?php

declare(strict_types=1);

/**
 * Pomiar liczby zapytań i czasu głównych ścieżek Kuking (issue: N+1).
 *
 * Fixture powstaje W TRANSAKCJI i jest WYCOFYWANY po pomiarze — baza wraca
 * do stanu sprzed uruchomienia. Ten sam wzorzec co przy #369.
 *
 * Użycie:
 *   php scripts/pomiar-n1.php maly  > wynik-maly.json
 *   php scripts/pomiar-n1.php duzy  > wynik-duzy.json
 *
 * Wynik: JSON na STDOUT, postęp na STDERR.
 */

use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Database\Factories\MediaFactory;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Pomiar przerwany: '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);
    exit(1);
});

// ---------------------------------------------------------------------------
// Bezpiecznik: wyłącznie izolowana baza lokalna.
// ---------------------------------------------------------------------------
$connection = DB::connection();
if ($app->environment('production')
    || $connection->getDriverName() !== 'pgsql'
    || $connection->getConfig('host') !== '127.0.0.1'
    || (string) $connection->getConfig('port') !== '55439'
    || $connection->getDatabaseName() !== 'kuking_perf_claude') {
    throw new RuntimeException('Pomiar wymaga lokalnej bazy kuking_perf_claude na 127.0.0.1:55439.');
}

$rozmiar = $argv[1] ?? 'maly';
if (! in_array($rozmiar, ['maly', 'duzy'], true)) {
    throw new InvalidArgumentException('Rozmiar: maly albo duzy.');
}

// Skala zgodna z #369 w wariancie „duzy”: 10 000 wpisów, 30 000 zdjęć, 1000 autorów.
$skala = $rozmiar === 'duzy'
    ? ['autorow' => 1000, 'wpisow' => 10000, 'zdjecNaWpis' => 3, 'przepisow' => 1000,
        'komentarzy' => 200, 'wykonan' => 300, 'obserwowanych' => 60, 'tagow' => 20]
    : ['autorow' => 100, 'wpisow' => 1000, 'zdjecNaWpis' => 3, 'przepisow' => 100,
        'komentarzy' => 20, 'wykonan' => 30, 'obserwowanych' => 6, 'tagow' => 20];

$log = static fn (string $t) => fwrite(STDERR, '['.date('H:i:s').'] '.$t.PHP_EOL);

// ---------------------------------------------------------------------------
// Licznik zapytań.
// ---------------------------------------------------------------------------
$liczenie = false;
$zapytania = [];
DB::listen(function ($q) use (&$liczenie, &$zapytania): void {
    if ($liczenie) {
        $zapytania[] = $q->sql;
    }
});

$teraz = now();
$uuid = static fn (): string => (string) Str::uuid();
$wstaw = static function (string $tabela, array $wiersze) use ($connection): void {
    if ($wiersze === []) {
        return;
    }
    // Wszystkie wiersze muszą mieć TEN SAM zestaw kolumn — inaczej Postgres
    // odmawia („VALUES lists must all be the same length").
    $kolumny = [];
    foreach ($wiersze as $wiersz) {
        foreach (array_keys($wiersz) as $kolumna) {
            $kolumny[$kolumna] = null;
        }
    }
    foreach (array_chunk($wiersze, 1000) as $paczka) {
        $connection->table($tabela)->insert(array_map(
            static fn (array $wiersz) => array_replace($kolumny, $wiersz),
            $paczka,
        ));
    }
};

DB::beginTransaction();

try {
    // -----------------------------------------------------------------------
    // FIXTURE
    // -----------------------------------------------------------------------
    $log("Fixture ({$rozmiar}): autorzy…");
    $haslo = bcrypt('haslo-testowe-123');
    $znacznik = substr(md5((string) microtime(true)), 0, 6);

    $autorzy = [];
    $uzytkownicy = [];
    $profile = [];
    for ($i = 0; $i < $skala['autorow']; $i++) {
        $id = $uuid();
        $autorzy[] = $id;
        $uzytkownicy[] = [
            'id' => $id, 'email' => "pomiar{$znacznik}-{$i}@example.test", 'password' => $haslo,
            'status' => 'active', 'role' => 'user', 'locale' => 'pl', 'text_scale' => 100,
            'wants_weekly_digest' => false, 'age_confirmed_at' => $teraz, 'email_verified_at' => $teraz,
            'created_at' => $teraz, 'updated_at' => $teraz,
        ];
        $profile[] = [
            'user_id' => $id, 'username' => "pomiar{$znacznik}{$i}", 'display_name' => "Kucharz {$i}",
            'bio' => 'Gotuję od lat.', 'created_at' => $teraz, 'updated_at' => $teraz,
        ];
    }

    // Widz — osoba, której oczami mierzymy.
    $widzId = $uuid();
    $uzytkownicy[] = [
        'id' => $widzId, 'email' => "pomiar{$znacznik}-widz@example.test", 'password' => $haslo,
        'status' => 'active', 'role' => 'user', 'locale' => 'pl', 'text_scale' => 100,
        'wants_weekly_digest' => false, 'age_confirmed_at' => $teraz, 'email_verified_at' => $teraz,
        'created_at' => $teraz, 'updated_at' => $teraz,
    ];
    $profile[] = [
        'user_id' => $widzId, 'username' => "widz{$znacznik}", 'display_name' => 'Widz',
        'created_at' => $teraz, 'updated_at' => $teraz,
    ];

    $wstaw('users', $uzytkownicy);
    $wstaw('profiles', $profile);

    $log('Fixture: tagi…');
    $tagi = [];
    $wierszeTagow = [];
    for ($i = 0; $i < $skala['tagow']; $i++) {
        $id = $uuid();
        $tagi[] = $id;
        $wierszeTagow[] = [
            'id' => $id, 'name' => "Temat {$znacznik} {$i}", 'normalized_name' => "temat {$znacznik} {$i}",
            'slug' => "temat-{$znacznik}-{$i}", 'status' => 'active', 'is_seeded' => true,
            'created_at' => $teraz, 'updated_at' => $teraz,
        ];
    }
    $wstaw('tags', $wierszeTagow);

    $log('Fixture: zdjęcia…');
    $fabrykaMediow = new MediaFactory;
    $zdjecia = [];
    $wierszeMediow = [];
    $ileZdjec = $skala['wpisow'] * $skala['zdjecNaWpis'];
    for ($i = 0; $i < $ileZdjec; $i++) {
        $atrybuty = $fabrykaMediow->definition();
        $id = $uuid();
        $zdjecia[] = $id;
        $wierszeMediow[] = [
            'id' => $id, 'owner_id' => $autorzy[$i % count($autorzy)], 'disk' => 'public',
            'object_key' => $atrybuty['object_key'], 'mime_type' => 'image/webp', 'bytes' => 120000,
            'width' => 1600, 'height' => 1200, 'status' => Media::STATUS_READY,
            'alt_text' => 'Danie na talerzu',
            'metadata' => json_encode($atrybuty['metadata'], JSON_THROW_ON_ERROR),
            'created_at' => $teraz, 'updated_at' => $teraz,
        ];
    }
    $wstaw('media', $wierszeMediow);
    unset($wierszeMediow);

    $log('Fixture: przepisy…');
    $przepisy = [];
    $wierszePrzepisow = [];
    for ($i = 0; $i < $skala['przepisow']; $i++) {
        $id = $uuid();
        $przepisy[] = $id;
        $wierszePrzepisow[] = [
            'id' => $id, 'author_id' => $autorzy[$i % max(1, intdiv(count($autorzy), 4))],
            'title' => "Pierogi pomiarowe numer {$i}", 'slug' => "pierogi-{$znacznik}-{$i}",
            'summary' => 'Rodzinny przepis na pierogi z dawnych lat.', 'servings' => 4,
            'prep_minutes' => 30, 'cook_minutes' => 20, 'difficulty' => 'easy',
            'visibility' => 'public', 'status' => 'published',
            'hero_media_id' => $zdjecia[$i % count($zdjecia)], 'source_type' => 'own',
            'published_at' => $teraz->copy()->subMinutes($i), 'created_at' => $teraz, 'updated_at' => $teraz,
        ];
    }
    $wstaw('recipes', $wierszePrzepisow);

    $log('Fixture: składniki i kroki…');
    $skladniki = [];
    $kroki = [];
    foreach ($przepisy as $indeks => $przepisId) {
        for ($p = 0; $p < 8; $p++) {
            $skladniki[] = [
                'id' => $uuid(), 'recipe_id' => $przepisId, 'ingredient_text' => "mąka pszenna {$p}",
                'quantity' => 250, 'position' => $p, 'no_amount' => false,
            ];
        }
        for ($p = 0; $p < 5; $p++) {
            $kroki[] = [
                'id' => $uuid(), 'recipe_id' => $przepisId, 'position' => $p,
                'instruction' => 'Wymieszaj składniki i odstaw na pół godziny.',
                'media_id' => $p === 0 ? $zdjecia[($indeks + $p) % count($zdjecia)] : null,
            ];
        }
    }
    $wstaw('recipe_ingredients', $skladniki);
    $wstaw('recipe_steps', $kroki);
    unset($skladniki, $kroki);

    $log('Fixture: wpisy…');
    $wpisy = [];
    $wierszeWpisow = [];
    $wierszePostMedia = [];
    $wierszePostTags = [];
    for ($i = 0; $i < $skala['wpisow']; $i++) {
        $id = $uuid();
        $wpisy[] = $id;
        $wierszeWpisow[] = [
            'id' => $id, 'author_id' => $autorzy[$i % max(1, intdiv(count($autorzy), 2))],
            'body' => 'Dziś ugotowałem coś, co pamiętam z domu rodzinnego.',
            'visibility' => 'public', 'status' => 'published',
            // Co dziesiąty wpis wskazuje przepis (issue #368).
            'recipe_id' => $i % 10 === 0 ? $przepisy[$i % count($przepisy)] : null,
            'published_at' => $teraz->copy()->subMinutes($i), 'display_mode' => 'normal',
            'hide_as_memory' => false, 'created_at' => $teraz, 'updated_at' => $teraz,
        ];
        // Co siodmy wpis nie ma WLASNYCH zdjec - karta wpisu siega wtedy po
        // zdjecie glowne PRZEPISU (post-card.blade.php). To jest realny stan,
        // nie wymysl: tak wyglada zapowiedz przepisu bez osobnej fotografii.
        if ($i % 7 !== 0) {
            for ($m = 0; $m < $skala['zdjecNaWpis']; $m++) {
                $wierszePostMedia[] = [
                    'post_id' => $id, 'media_id' => $zdjecia[$i * $skala['zdjecNaWpis'] + $m], 'position' => $m,
                ];
            }
        }
        foreach ([0, 1] as $t) {
            $wierszePostTags[] = ['post_id' => $id, 'tag_id' => $tagi[($i + $t) % count($tagi)], 'position' => $t];
        }
    }
    $wstaw('posts', $wierszeWpisow);
    $wstaw('post_media', $wierszePostMedia);
    $wstaw('post_tags', $wierszePostTags);
    unset($wierszeWpisow, $wierszePostMedia, $wierszePostTags);

    $log('Fixture: relacje widza…');
    $obserwowani = array_slice($autorzy, 0, $skala['obserwowanych']);
    $wstaw('follows', array_map(
        static fn (string $id) => ['follower_id' => $widzId, 'followed_id' => $id, 'created_at' => $teraz],
        $obserwowani,
    ));
    // Profil, który oglądamy, ma obserwujących — żeby liczniki miały co liczyć.
    $profilowy = $autorzy[0];
    $wstaw('follows', array_map(
        static fn (string $id) => ['follower_id' => $id, 'followed_id' => $profilowy, 'created_at' => $teraz],
        array_slice($autorzy, 1, min(200, count($autorzy) - 1)),
    ));
    $wstaw('tag_follows', array_map(
        static fn (string $id) => ['user_id' => $widzId, 'tag_id' => $id, 'created_at' => $teraz],
        array_slice($tagi, 0, 3),
    ));

    $zeszytId = $uuid();
    $wstaw('collections', [[
        'id' => $zeszytId, 'owner_id' => $widzId, 'name' => 'Mój zeszyt', 'visibility' => 'private',
        'is_default' => true, 'created_at' => $teraz, 'updated_at' => $teraz,
    ]]);
    $wstaw('collection_items', array_map(
        static fn (string $id) => ['collection_id' => $zeszytId, 'recipe_id' => $id, 'created_at' => $teraz],
        array_slice($przepisy, 0, 20),
    ));
    // Kilka cudzych zeszytów z tymi samymi przepisami — licznik „ile osób zapisało”.
    $cudzeZeszyty = [];
    $cudzePozycje = [];
    foreach (array_slice($autorzy, 0, min(30, count($autorzy))) as $nr => $autorId) {
        $id = $uuid();
        $cudzeZeszyty[] = [
            'id' => $id, 'owner_id' => $autorId, 'name' => 'Zeszyt', 'visibility' => 'private',
            'is_default' => true, 'created_at' => $teraz, 'updated_at' => $teraz,
        ];
        foreach (array_slice($przepisy, 0, 10) as $przepisId) {
            $cudzePozycje[] = ['collection_id' => $id, 'recipe_id' => $przepisId, 'created_at' => $teraz];
        }
        foreach (array_slice($wpisy, 0, 20) as $wpisId) {
            $cudzePozycje[] = ['collection_id' => $id, 'post_id' => $wpisId, 'created_at' => $teraz];
        }
    }
    $wstaw('collections', $cudzeZeszyty);
    $wstaw('collection_items', $cudzePozycje);
    unset($cudzeZeszyty, $cudzePozycje);

    $log('Fixture: komentarze i wykonania „gorącego” przepisu…');
    $goracyPrzepis = $przepisy[0];
    $komentarze = [];
    $rodzice = [];
    for ($i = 0; $i < $skala['komentarzy']; $i++) {
        $id = $uuid();
        $rodzice[] = $id;
        $komentarze[] = [
            'id' => $id, 'author_id' => $autorzy[$i % count($autorzy)], 'recipe_id' => $goracyPrzepis,
            'body' => 'Robiłam wczoraj, wyszło wyśmienicie. Dziękuję za przepis.',
            'status' => 'published', 'created_at' => $teraz->copy()->subMinutes($i), 'updated_at' => $teraz,
        ];
    }
    foreach ($rodzice as $nr => $rodzicId) {
        $komentarze[] = [
            'id' => $uuid(), 'author_id' => $autorzy[($nr + 1) % count($autorzy)], 'recipe_id' => $goracyPrzepis,
            'parent_id' => $rodzicId, 'body' => 'Dziękuję, to przepis mojej mamy.',
            'status' => 'published', 'created_at' => $teraz->copy()->subMinutes($nr), 'updated_at' => $teraz,
        ];
    }
    $wstaw('comments', $komentarze);
    unset($komentarze);

    $wykonania = [];
    $wykonaniaMedia = [];
    for ($i = 0; $i < $skala['wykonan'] * 2; $i++) {
        $id = $uuid();
        $wykonania[] = [
            'id' => $id, 'user_id' => $autorzy[$i % 5],
            // Polowa wykonan na jednym "goracym" przepisie (galeria "Komu wyszlo"),
            // polowa rozsypana - zeby zakladka "ugotowane" na profilu tez miala co pokazac.
            'recipe_id' => $i % 2 === 0 ? $goracyPrzepis : $przepisy[$i % count($przepisy)],
            'note' => 'Zrobione dokładnie według przepisu.', 'would_make_again' => $i % 3 !== 0,
            'perceived_difficulty' => 'easy', 'actual_minutes' => 45,
            'cooked_at' => $teraz->copy()->subHours($i), 'created_at' => $teraz->copy()->subHours($i),
        ];
        $wykonaniaMedia[] = ['cooked_event_id' => $id, 'media_id' => $zdjecia[$i % count($zdjecia)], 'position' => 0];
    }
    $wstaw('cooked_events', $wykonania);
    $wstaw('cooked_event_media', $wykonaniaMedia);
    unset($wykonania, $wykonaniaMedia);

    $log('Fixture gotowy.');

    // -----------------------------------------------------------------------
    // ŚCIEŻKI
    // -----------------------------------------------------------------------
    $widz = User::query()->findOrFail($widzId);
    $przepisSlug = Recipe::query()->findOrFail($goracyPrzepis)->slug;
    $profilUsername = DB::table('profiles')->where('user_id', $profilowy)->value('username');
    $tagSlug = DB::table('tags')->where('id', $tagi[0])->value('slug');

    $sciezki = [
        'strumien obserwowanych (/home)' => '/home',
        'strona przepisu (/przepisy/{slug})' => "/przepisy/{$przepisSlug}",
        'profil (/@{username})' => "/@{$profilUsername}",
        'profil - przepisy' => "/@{$profilUsername}?zakladka=przepisy",
        'profil - ugotowane' => "/@{$profilUsername}?zakladka=ugotowane",
        'wyszukiwarka (/szukaj?q=pierogi)' => '/szukaj?q=pierogi',
        'strona tagu (/tag/{slug})' => "/tag/{$tagSlug}",
        'odkrywanie (/odkryj)' => '/odkryj',
    ];

    $kernel = $app->make(HttpKernel::class);

    /**
     * Jedno żądanie przez pełne jądro HTTP.
     *
     * `$limitMs` to `statement_timeout` NA CZAS TEGO ŻĄDANIA i nie jest
     * ozdobnikiem. Bez świeżych statystyk planer potrafi na tym zbiorze
     * wybrać plan, który nie kończy się w rozsądnym czasie (zmierzone:
     * zapytanie szyny tagów na profilu chodziło 11 minut i nie skończyło).
     * Pomiar bez górnej granicy zamienia się wtedy w zawieszenie, a to, co
     * chcemy zapisać, brzmi „nie skończyło się w N sekund" — i tyle właśnie
     * zapisujemy.
     *
     * Żądanie idzie w PUNKCIE ZAPISU (`SAVEPOINT`), bo przekroczony limit
     * przewraca transakcję w stan przerwany, a z niego nie da się wyjść
     * inaczej niż wycofaniem.
     *
     * PUNKT ZAPISU ZDEJMUJEMY ZATWIERDZENIEM, NIE WYCOFANIEM — i to nie jest
     * szczegół. Pierwsza wersja wycofywała go ZAWSZE, „żeby każdy pomiar
     * zaczynał od tego samego stanu". Skutek był taki, że każde żądanie traciło
     * też zapisy aplikacji do tabeli `cache` (`CACHE_STORE=database`), więc
     * rozgrzewka niczego nie rozgrzewała i każde kolejne żądanie liczyło od
     * nowa to, co w produkcji liczy się raz. Zmierzone: ta sama strona profilu
     * na tym samym zbiorze dawała 88 ms przy zachowanym cache i nie kończyła
     * się w 10 s przy wycofywanym. Mierzylibyśmy wtedy własny przyrząd,
     * a nie aplikację.
     */
    $zmierz = function (string $url, ?int $limitMs = null) use ($kernel, $widz, &$liczenie, &$zapytania): array {
        Auth::guard('web')->setUser($widz);
        $zapytania = [];

        if ($limitMs !== null) {
            DB::unprepared('SET statement_timeout = '.$limitMs);
        }

        DB::beginTransaction();
        $liczenie = true;
        $start = hrtime(true);

        try {
            $response = $kernel->handle(Request::create($url, 'GET'));
            $czas = (hrtime(true) - $start) / 1e6;
            $status = $response->getStatusCode();
            $tresc = (string) $response->getContent();
        } catch (Throwable $blad) {
            $czas = (hrtime(true) - $start) / 1e6;
            $status = 0;
            $tresc = $blad->getMessage();
        }

        $liczenie = false;

        if ($status === 200) {
            DB::commit();
        } else {
            // Tylko tutaj: transakcja może być w stanie przerwanym.
            DB::rollBack();
        }

        if ($limitMs !== null) {
            DB::unprepared('SET statement_timeout = 0');
        }

        if ($status !== 200) {
            // Przekroczony limit poznajemy po CZASIE, nie po treści strony.
            //
            // Po komunikacie Postgresa nie da się: przerwane zapytanie
            // przewraca transakcję, więc strona błędu, którą jądro próbuje
            // wtedy wyrenderować, sama nie ma dostępu do bazy i wychodzi
            // z niej ogólny szablon bez śladu przyczyny. Zmierzone —
            // `str_contains($tresc, 'statement timeout')` nie trafiał ani razu
            // przy odpowiedzi, która przyszła po 21 s przy limicie 20 s.
            //
            // Próg 0,9 limitu, nie równość: `statement_timeout` ucina JEDNO
            // zapytanie, a żądanie mogło zdążyć wykonać wcześniej kilka
            // krótkich. Każda inna odpowiedź niż 200 to awaria pomiaru i ma
            // przerwać przebieg, zamiast wejść do tabeli.
            $przerwaneLimitem = $limitMs !== null && $czas >= $limitMs * 0.9;

            if (! $przerwaneLimitem) {
                throw new RuntimeException("{$url} oddał {$status}: ".substr(strip_tags($tresc), 0, 400));
            }

            return [
                'zapytan' => count($zapytania),
                'ms' => null,
                'przerwane_limitem_ms' => $limitMs,
                'bajtow' => 0,
                'sql' => $zapytania,
            ];
        }

        return ['zapytan' => count($zapytania), 'ms' => $czas, 'bajtow' => strlen($tresc), 'sql' => $zapytania];
    };

    // ROZSTRZYGAJACA OS POMIARU: liczba WIERSZY NA STRONIE.
    //
    // Sam rozmiar zbioru (10 000 wpisow zamiast 1 000) nie wykryje N+1 na
    // ekranie STRONICOWANYM - paginacja i tak odda 15 wierszy. Zapytanie
    // "na wiersz" rosnie z liczba wierszy WYRENDEROWANYCH, wiec drugim
    // zbiorem porownawczym jest ta sama baza przy innym rozmiarze strony.
    $przebieg = function (string $etykieta, int $rozmiarStrony, ?int $limitMs = null) use ($sciezki, $zmierz, $log): array {
        config([
            'kuking.feed.page_size' => $rozmiarStrony,
            'kuking.comments.page_size' => $rozmiarStrony,
        ]);
        $wynik = [];
        foreach ($sciezki as $nazwa => $url) {
            $rozgrzewka = $zmierz($url, $limitMs);   // kompilacja Blade, cache konfiguracji

            if ($rozgrzewka['ms'] === null) {
                // Nie skończyło się w limicie — trzy dalsze próbki nic nie dodadzą
                // poza trzema kolejnymi limitami czekania.
                $wynik[$nazwa] = [
                    'url' => $url,
                    'zapytan' => $rozgrzewka['zapytan'],
                    'ms_mediana' => null,
                    'ms_min' => null,
                    'ms_max' => null,
                    'przerwane_limitem_ms' => $limitMs,
                    'sql' => $rozgrzewka['sql'],
                ];
                $log(sprintf('%s | %-38s %4d zapytań, NIE SKOŃCZYŁO SIĘ w %d ms', $etykieta, $nazwa, $rozgrzewka['zapytan'], $limitMs));

                continue;
            }

            $probki = [];
            $ostatni = null;
            for ($i = 0; $i < 3; $i++) {
                $ostatni = $zmierz($url, $limitMs);
                $probki[] = $ostatni['ms'] ?? INF;
            }
            sort($probki);
            $wynik[$nazwa] = [
                'url' => $url,
                'zapytan' => $ostatni['zapytan'],
                'ms_mediana' => is_finite($probki[1]) ? round($probki[1], 1) : null,
                'ms_min' => is_finite($probki[0]) ? round($probki[0], 1) : null,
                'ms_max' => is_finite($probki[2]) ? round($probki[2], 1) : null,
                'sql' => $ostatni['sql'],
            ];

            // Rozgrzewka zmiescila sie w limicie, a ktoras z probek juz nie.
            // Limit musi wtedy zostac W WYNIKU, inaczej dowod mowi 'brak czasu'
            // i nie mowi, po jakim czasie przerwano.
            if (! is_finite($probki[2])) {
                $wynik[$nazwa]['przerwane_limitem_ms'] = $limitMs;
            }
            $log(sprintf('%s | %-38s %4d zapytań, %7.1f ms', $etykieta, $nazwa, $ostatni['zapytan'], $probki[1]));
        }

        return $wynik;
    };

    // Górna granica na przebieg BEZ świeżych statystyk. Po `ANALYZE` limitu
    // nie ma: tam wolna odpowiedź jest wynikiem, a nie zawieszeniem.
    $limitBezStatystyk = 10_000;

    $log('Pomiar PRZED ANALYZE…');
    // Przed `ANALYZE` mierzymy TYLKO domyslny rozmiar strony. Os 'wierszy na
    // stronie' sluzy do wykrywania N+1, czyli do liczby ZAPYTAN, a ta nie
    // zalezy od statystyk bazy. Trzy przebiegi bez statystyk kosztowalyby
    // kwadranse czekania na plany, ktore i tak nie koncza sie w limicie.
    $przed15 = $przebieg('przed ANALYZE, 15/str', 15, $limitBezStatystyk);
    $przed5 = [];
    $przed25 = [];

    $log('ANALYZE…');
    $startAnalyze = hrtime(true);
    DB::statement('ANALYZE');
    $czasAnalyze = (hrtime(true) - $startAnalyze) / 1e6;
    $log(sprintf('ANALYZE zajął %.0f ms', $czasAnalyze));

    $log('Pomiar PO ANALYZE…');
    $po15 = $przebieg('po ANALYZE, 15/str', 15);
    $po5 = $przebieg('po ANALYZE,  5/str', 5);
    $po25 = $przebieg('po ANALYZE, 25/str', 25);

    $wynik = [
        'rozmiar' => $rozmiar,
        'skala' => $skala + ['zdjec' => $ileZdjec],
        'analyze_ms' => round($czasAnalyze, 1),
        'przed_analyze' => ['strona_15' => $przed15, 'strona_5' => $przed5, 'strona_25' => $przed25],
        'po_analyze' => ['strona_15' => $po15, 'strona_5' => $po5, 'strona_25' => $po25],
    ];

    echo json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    DB::rollBack();
    $log('Transakcja wycofana — baza wróciła do stanu sprzed pomiaru.');
}
