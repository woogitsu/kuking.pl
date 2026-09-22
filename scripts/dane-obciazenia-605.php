<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Zbiór danych do mieszanego testu obciążeniowego (#605)
|--------------------------------------------------------------------------
|
| Ten skrypt jest rozszerzeniem mechanizmu z `scripts/dane-pomiaru-feedu.php`
| (#585), a nie drugim mechanizmem danych: konta powstają fabrykami
| (`User::factory()`, `Profile` z `configure()`), tagi istniejącym
| `TagSeeder`-em, a wolumen — wsadowymi `insert`-ami, tak samo jak tam.
|
| RÓŻNICA WOBEC #585 JEST CELOWA I JEST SENSEM TEGO ZADANIA: tamten zbiór był
| równomierną siatką (50 000 wpisów, kilku widzów, zero komentarzy i mediów).
| Tutaj rozkład ma być NIERÓWNY, bo nierówność jest tym, co psuje plany zapytań
| i zapełnia strony: garść autorów pisze większość wpisów, garść przepisów ma
| setki komentarzy, garść tagów zbiera większość przypięć, a konta obserwują
| 10 / 100 / 500 / 1200 osób, nie „po równo”.
|
| Skrypt NIE tworzy plików zdjęć. Prawdziwe zdjęcia 12/24/48 Mpx wchodzą
| ścieżką produktową — przez HTTP na `/dodaj/zdjecie`, z prawdziwą kolejką
| i prawdziwymi wariantami (patrz `scripts/generator-obciazenia-605.mjs`,
| faza `rozgrzewka-mediow`). Metadanych mediów bez plików świadomie tu nie ma:
| `/zdjecia/*` musi oddawać prawdziwy bajt, inaczej pomiar tej trasy jest
| pomiarem 404.
|
| Uruchomienie (zawsze z PGTZ=UTC, nigdy na porcie 5432):
|
|   DB_DATABASE=kuking_b605_obciazenie DB_PORT=55439 PGTZ=UTC \
|     php scripts/dane-obciazenia-605.php
|
*/

use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\TagSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Generator danych #605 przerwany: '.$error->getMessage().PHP_EOL);
    exit(1);
});

$db = DB::connection();

// Bezpiecznik jak w #585: tylko lokalna, własna baza na własnym porcie.
// Port 5432 jest w tym środowisku zabroniony, a nazwa bazy jest przypisana
// temu zadaniu — bez tego łatwo zasypać cudzy pomiar.
if (
    $app->environment('production')
    || $db->getDriverName() !== 'pgsql'
    || $db->getConfig('host') !== '127.0.0.1'
    || (string) $db->getConfig('port') !== '55439'
    || ! str_starts_with($db->getDatabaseName(), 'kuking_b605_')
) {
    throw new RuntimeException('Generator wymaga lokalnej bazy kuking_b605_* na 127.0.0.1:55439.');
}

if (User::where('email', 'like', 'b605-%')->exists()) {
    throw new RuntimeException('Dane #605 już istnieją. Generator ich nie zastępuje — usuń bazę albo użyj innej.');
}

$ile = static fn (string $zmienna, int $domyslnie): int => max(1, (int) (getenv($zmienna) ?: $domyslnie));

$autorow = $ile('B605_AUTORZY', 2000);
$wpisow = $ile('B605_WPISY', 200000);
$przepisow = $ile('B605_PRZEPISY', 20000);
$komentarzy = $ile('B605_KOMENTARZE', 200000);
$ugotowanych = $ile('B605_UGOTOWANE', 25000);

$start = hrtime(true);
$zegar = static fn (): float => round((hrtime(true) - $GLOBALS['start']) / 1e9, 1);
$log = static function (string $tekst): void {
    fwrite(STDERR, '['.date('H:i:s').'] '.$tekst.PHP_EOL);
};

/**
 * Wsadowy zapis — jedyny sposób, żeby setki tysięcy wierszy powstały
 * w minutach, a nie w godzinach. 1000 wierszy na `insert` mieści się
 * w limicie parametrów Postgresa dla tych tabel.
 */
$wsad = static function (string $tabela, array &$bufor, bool $domknij = false) use ($db): void {
    if ($bufor === [] || (! $domknij && count($bufor) < 1000)) {
        return;
    }
    foreach (array_chunk($bufor, 1000) as $kawalek) {
        $db->table($tabela)->insert($kawalek);
    }
    $bufor = [];
};

$chwila = static fn (int $sekundyWstecz): string => gmdate('Y-m-d H:i:s', 1758000000 - $sekundyWstecz);

// ---------------------------------------------------------------------------
// 1. Tagi — istniejącym seederem, nie własną listą.
// ---------------------------------------------------------------------------
$log('tagi (TagSeeder)...');
$app->make(TagSeeder::class)->run();
$tagi = Tag::where('status', Tag::STATUS_ACTIVE)->pluck('id')->all();
if ($tagi === []) {
    throw new RuntimeException('TagSeeder nie zostawił żadnego aktywnego tagu.');
}

// ---------------------------------------------------------------------------
// 2. Konta. Jedno hasło policzone RAZ — `Hash::make` z 12 rundami bcrypt
//    kosztuje ok. 0,25 s, więc 2000 osobnych wywołań to 8 minut samego
//    hashowania i zero wartości pomiarowej.
// ---------------------------------------------------------------------------
$log('konta autorów...');
$hasloJawne = 'b605-obciazenie-'.bin2hex(random_bytes(8));
$hash = Hash::make($hasloJawne);

$autorzy = [];
for ($i = 0; $i < $autorow; $i++) {
    $autorzy[] = User::factory()->create([
        'email' => "b605-autor-$i@example.test",
        'password' => $hash,
        'wants_weekly_digest' => false,
    ])->id;
    if ($i % 250 === 0) {
        $log("  autorzy $i/$autorow (".$zegar().' s)');
    }
}

/*
 * Widzowie — to ich sesjami chodzi generator ruchu. Rozkład obserwowanych
 * jest NIERÓWNY z rozmysłem: konto z 1200 obserwowanymi i konto z 10 czytają
 * ten sam feed zupełnie innym planem zapytania, a średnia z równej siatki
 * nie pokazuje ani jednego, ani drugiego.
 */
$log('konta widzów i obserwacje...');
/*
 * Widzów jest 60, a nie trzydziestu, i to nie jest „więcej dla samego więcej”.
 * Limity z `config/kuking.php` liczą się NA UŻYTKOWNIKA dla ruchu zalogowanego
 * (`comment` 10/min, `post` 20/10 min, `search` 60/min, `zdjecie` 600/min).
 * Przy trzydziestu kontach sufitem ścieżek zapisu i wyszukiwania byłby throttle,
 * nie aplikacja — i cały pomiar nasycenia pokazałby wyłącznie własną
 * konfigurację limitów.
 *
 * Górną granicę wyznacza z drugiej strony koszt PRZYGOTOWANIA: trasa
 * `POST /login` ma limit 5/min liczony po adresie, a generator stoi na jednym
 * adresie, więc zalogowanie jednego konta kosztuje ok. 13 sekund zegara.
 * 60 kont to ok. 13 minut jednorazowo — 120 kont to już pół godziny.
 */
$profilObserwacji = array_merge(...array_fill(0, 2, [10, 10, 10, 10, 10, 10, 25, 25, 25, 25, 50, 50, 50, 50, 100, 100, 100, 100, 100, 100, 250, 250, 250, 500, 500, 500, 500, 800, 1200, 1200]));
$widzowie = [];
foreach ($profilObserwacji as $nr => $ilu) {
    $widz = User::factory()->create([
        'email' => "b605-widz-$nr@example.test",
        'password' => $hash,
        'wants_weekly_digest' => false,
    ]);

    /*
     * Kogo obserwuje: zawsze pierwszą dwudziestkę (to są „gwiazdy”, autorzy
     * 60 % wpisów), a resztę z ogona. Dzięki temu feed każdego widza ma
     * i gorące konta, i długi ogon — tak jak prawdziwy.
     */
    $obserwowani = array_slice($autorzy, 0, min(20, $ilu));
    $pozostalo = $ilu - count($obserwowani);
    if ($pozostalo > 0) {
        $krok = max(1, intdiv(count($autorzy) - 20, $pozostalo));
        for ($j = 0; $j < $pozostalo; $j++) {
            $obserwowani[] = $autorzy[20 + (($j * $krok) % (count($autorzy) - 20))];
        }
    }
    $obserwowani = array_values(array_unique($obserwowani));

    $bufor = [];
    foreach ($obserwowani as $kogo) {
        $bufor[] = ['follower_id' => $widz->id, 'followed_id' => $kogo, 'created_at' => $chwila(86400 * 30)];
    }
    $wsad('follows', $bufor, true);

    $widzowie[] = ['id' => $widz->id, 'email' => $widz->email, 'obserwuje' => count($obserwowani)];
}

/*
 * Obserwacje między autorami — bez nich graf jest gwiazdą, a nie siecią,
 * i `follows` ma tyle wierszy, co lista widzów.
 */
$log('obserwacje między autorami...');
$bufor = [];
$krawedzi = 0;
foreach ($autorzy as $nr => $kto) {
    $ilu = min($nr < 50 ? 400 : ($nr < 300 ? 60 : 8), count($autorzy) - 1);
    // `$widziane` jest tu obowiązkowe, a nie ostrożnościowe: klucz główny
    // `follows` to para (follower_id, followed_id), a skok modulo powtarza
    // cel, gdy autorów jest mniej niż skoków. Bez tego generator wywraca się
    // na 23505 przy mniejszych nastawach — sprawdzone.
    $widziane = [];
    for ($j = 1; count($widziane) < $ilu && $j <= $ilu * 4; $j++) {
        $cel = $autorzy[($nr + $j * 7) % count($autorzy)];
        if ($cel === $kto || isset($widziane[$cel])) {
            continue;
        }
        $widziane[$cel] = true;
        $bufor[] = ['follower_id' => $kto, 'followed_id' => $cel, 'created_at' => $chwila(86400 * 60)];
        $krawedzi++;
    }
    $wsad('follows', $bufor);
}
$wsad('follows', $bufor, true);

// ---------------------------------------------------------------------------
// 3. Przepisy z krokami i składnikami.
// ---------------------------------------------------------------------------
$log('przepisy...');
$slowa = ['pierogi', 'rosol', 'schabowy', 'bigos', 'sernik', 'placki', 'golabki', 'zurek', 'kluski', 'szarlotka', 'kopytka', 'barszcz', 'flaki', 'makowiec', 'krokiety'];
$dodatki = ['babci', 'z grzybami', 'na niedziele', 'po staropolsku', 'z jablkami', 'bez cukru', 'w 30 minut', 'z piekarnika'];

// Ilu autorów tworzy „gorącą” czołówkę. Przy pełnej nastawie to 50 i 20;
// przy małej (kontrola przyrządu) musi zejść do liczby istniejących kont,
// inaczej modulo trafia poza tablicę.
$goracyPrzepisow = min(50, count($autorzy));
$goracyWpisow = min(20, count($autorzy));

$przepisyId = [];
$buforP = [];
$buforKroki = [];
$buforSkladniki = [];
for ($i = 0; $i < $przepisow; $i++) {
    $id = (string) Str::uuid();
    $przepisyId[] = $id;
    // 40 % przepisów pisze pierwsza pięćdziesiątka autorów — nierówność,
    // która realnie występuje i realnie zmienia plany zapytań o profil.
    $autor = $i % 10 < 4 ? $autorzy[$i % $goracyPrzepisow] : $autorzy[$i % count($autorzy)];
    $tytul = ucfirst($slowa[$i % count($slowa)]).' '.$dodatki[$i % count($dodatki)].' '.$i;
    $czas = $chwila(intdiv($i, 3) * 60);
    $buforP[] = [
        'id' => $id,
        'author_id' => $autor,
        'title' => $tytul,
        'slug' => Str::slug(Str::ascii($tytul)).'-b605',
        'summary' => 'Przepis pomiarowy #605 numer '.$i.'. Prosty, domowy, na jeden garnek.',
        'servings' => 2 + ($i % 7),
        'prep_minutes' => 5 + ($i % 40),
        'cook_minutes' => 10 + ($i % 90),
        'difficulty' => ['easy', 'medium', 'hard'][$i % 3],
        'visibility' => 'public',
        'status' => Recipe::STATUS_PUBLISHED,
        'source_type' => Recipe::SOURCE_OWN,
        'published_at' => $czas,
        'created_at' => $czas,
        'updated_at' => $czas,
    ];
    for ($k = 1; $k <= 3 + ($i % 4); $k++) {
        $buforKroki[] = [
            'id' => (string) Str::uuid(),
            'recipe_id' => $id,
            'position' => $k,
            'instruction' => 'Krok '.$k.': wymieszaj, odstaw, sprawdź palcem. Tekst pomiarowy #605.',
            'timer_seconds' => $k % 3 === 0 ? 600 : null,
        ];
    }
    for ($s = 1; $s <= 5 + ($i % 6); $s++) {
        $buforSkladniki[] = [
            'id' => (string) Str::uuid(),
            'recipe_id' => $id,
            'ingredient_text' => 'składnik pomiarowy '.$s,
            'quantity' => $s,
            'position' => $s,
            'no_amount' => false,
        ];
    }
    /*
     * KOLEJNOŚĆ JEST TU CAŁĄ TREŚCIĄ: kroki i składniki mają klucz obcy na
     * `recipes`, a same rosną szybciej niż przepisy (kilka wierszy na jeden).
     * Zwykły próg „1000 w buforze” osobno dla każdej tabeli wypycha dzieci
     * PRZED rodzicem i kończy się 23503. Dlatego domykamy całą trójkę naraz,
     * rodzic pierwszy.
     */
    if (count($buforP) >= 500) {
        $wsad('recipes', $buforP, true);
        $wsad('recipe_steps', $buforKroki, true);
        $wsad('recipe_ingredients', $buforSkladniki, true);
    }
    if ($i % 5000 === 0) {
        $log("  przepisy $i/$przepisow (".$zegar().' s)');
    }
}
$wsad('recipes', $buforP, true);
$wsad('recipe_steps', $buforKroki, true);
$wsad('recipe_ingredients', $buforSkladniki, true);

// ---------------------------------------------------------------------------
// 4. Wpisy. Rozkład autorstwa i widoczności nierówny.
// ---------------------------------------------------------------------------
$log('wpisy...');
$wpisyId = [];
$bufor = [];
$buforTagi = [];
for ($i = 0; $i < $wpisow; $i++) {
    $id = (string) Str::uuid();
    $wpisyId[] = $id;
    $autor = $i % 10 < 6 ? $autorzy[$i % $goracyWpisow] : $autorzy[$i % count($autorzy)];
    $czas = $chwila(intdiv($i, 4) * 30);
    // 80 % publiczne, 15 % dla obserwujących, 5 % prywatne — widoczność
    // jest częścią kosztu feedu, bo zmienia warunek zapytania.
    $widocznosc = match (true) {
        $i % 20 === 0 => Post::VISIBILITY_PRIVATE,
        $i % 20 < 4 => Post::VISIBILITY_FOLLOWERS,
        default => Post::VISIBILITY_PUBLIC,
    };
    $bufor[] = [
        'id' => $id,
        'author_id' => $autor,
        'body' => 'Wpis pomiarowy #605 numer '.$i.'. Dziś '.$slowa[$i % count($slowa)].' '.$dodatki[$i % count($dodatki)].'.',
        'visibility' => $widocznosc,
        'status' => Post::STATUS_PUBLISHED,
        'recipe_id' => $i % 7 === 0 ? $przepisyId[$i % count($przepisyId)] : null,
        'published_at' => $czas,
        'created_at' => $czas,
        'updated_at' => $czas,
        'display_mode' => Post::DISPLAY_NORMAL,
    ];
    /*
     * Tagi po Zipfie: pierwsze dziesięć tagów zbiera większość przypięć.
     * Równomierne rozsypanie tagów po wpisach daje idealny, nieprawdziwy
     * indeks — a strona `/tag/{tag}` boli dokładnie na tagach gorących.
     */
    $ileTagow = $i % 5;
    $uzyteTagi = [];
    for ($t = 0; $t < $ileTagow; $t++) {
        $indeks = $t === 0 ? ($i % 10) : (($i * ($t + 3)) % count($tagi));
        // Klucz główny `post_tags` to para (post_id, tag_id) — dwa skoki modulo
        // potrafią trafić w ten sam tag i wywrócić wsad.
        if (isset($uzyteTagi[$indeks])) {
            continue;
        }
        $uzyteTagi[$indeks] = true;
        $buforTagi[] = ['post_id' => $id, 'tag_id' => $tagi[$indeks], 'position' => count($uzyteTagi), 'dodany_recznie' => false];
    }
    // Ta sama zasada co przy przepisach: `post_tags` wisi na `posts`.
    if (count($bufor) >= 1000) {
        $wsad('posts', $bufor, true);
        $wsad('post_tags', $buforTagi, true);
    }
    if ($i % 25000 === 0) {
        $log("  wpisy $i/$wpisow (".$zegar().' s)');
    }
}
$wsad('posts', $bufor, true);
$wsad('post_tags', $buforTagi, true);

// ---------------------------------------------------------------------------
// 5. „Ugotowałem”.
// ---------------------------------------------------------------------------
$log('ugotowane...');
$ugotowaneId = [];
$bufor = [];
for ($i = 0; $i < $ugotowanych; $i++) {
    $id = (string) Str::uuid();
    $ugotowaneId[] = $id;
    $czas = $chwila(intdiv($i, 2) * 120);
    $bufor[] = [
        'id' => $id,
        'user_id' => $autorzy[$i % count($autorzy)],
        'recipe_id' => $przepisyId[$i % count($przepisyId)],
        'note' => $i % 3 === 0 ? 'Wyszło. Dałam mniej soli.' : null,
        'would_make_again' => $i % 4 !== 0,
        'cooked_at' => $czas,
        'created_at' => $czas,
    ];
    $wsad('cooked_events', $bufor);
}
$wsad('cooked_events', $bufor, true);

// ---------------------------------------------------------------------------
// 6. Komentarze — z grubym ogonem.
//    40 przepisów dostaje po 300–600 komentarzy, reszta rozkłada się cienko.
//    To jest ten przypadek, w którym strona przepisu przestaje być tania.
// ---------------------------------------------------------------------------
$log('komentarze...');
$goraceP = array_slice($przepisyId, 0, 40);
$bufor = [];
$policzone = 0;

foreach ($goraceP as $nr => $przepis) {
    $ile = 300 + ($nr * 8) % 300;
    for ($c = 0; $c < $ile; $c++) {
        $czas = $chwila(86400 * 20 - $c * 60);
        /*
         * `post_id => null` NIE jest ozdobnikiem. Wsadowy `insert` bierze
         * nazwy kolumn z PIERWSZEGO wiersza pakietu, więc wymieszanie w jednym
         * buforze wierszy z kluczem `post_id` i bez niego kończy się
         * „VALUES lists must all be the same length”. Obie pętle komentarzy
         * muszą mieć identyczny kształt wiersza.
         */
        $bufor[] = [
            'id' => (string) Str::uuid(),
            'author_id' => $autorzy[($nr * 31 + $c) % count($autorzy)],
            'post_id' => null,
            'recipe_id' => $przepis,
            'body' => 'Komentarz pomiarowy #605 nr '.$c.'. Robiłam wczoraj, wyszło.',
            'status' => 'published',
            'created_at' => $czas,
            'updated_at' => $czas,
        ];
        $policzone++;
        $wsad('comments', $bufor);
    }
}
// Domykamy bufor przed drugą pętlą — resztówka z pierwszej nie może
// wymieszać się z wierszami o innym kształcie.
$wsad('comments', $bufor, true);
$log('  gruby ogon: '.$policzone.' komentarzy na '.count($goraceP).' przepisach');

$ogon = max(0, $komentarzy - $policzone);
for ($c = 0; $c < $ogon; $c++) {
    $czas = $chwila(86400 * 10 - $c * 5);
    $doWpisu = $c % 3 !== 0;
    $bufor[] = [
        'id' => (string) Str::uuid(),
        'author_id' => $autorzy[($c * 17) % count($autorzy)],
        'post_id' => $doWpisu ? $wpisyId[($c * 13) % count($wpisyId)] : null,
        'recipe_id' => $doWpisu ? null : $przepisyId[($c * 11) % count($przepisyId)],
        'body' => 'Komentarz pomiarowy #605 ogon nr '.$c.'.',
        'status' => 'published',
        'created_at' => $czas,
        'updated_at' => $czas,
    ];
    $policzone++;
    $wsad('comments', $bufor);
    if ($c % 50000 === 0) {
        $log("  komentarze $c/$ogon (".$zegar().' s)');
    }
}
$wsad('comments', $bufor, true);

// ---------------------------------------------------------------------------
// 7. Zeszyty, zapisy, obserwowane tagi, blokady.
// ---------------------------------------------------------------------------
$log('zeszyty, zapisy, tagi obserwowane, blokady...');
$zeszyty = [];
$bufor = [];
foreach (array_merge(array_column($widzowie, 'id'), array_slice($autorzy, 0, 300)) as $nr => $wlasciciel) {
    $id = (string) Str::uuid();
    $zeszyty[] = ['id' => $id, 'owner' => $wlasciciel];
    $bufor[] = [
        'id' => $id,
        'owner_id' => $wlasciciel,
        'name' => 'Ulubione',
        'visibility' => 'private',
        'is_default' => true,
        'created_at' => $chwila(86400 * 40),
        'updated_at' => $chwila(86400 * 40),
    ];
    if ($nr % 3 === 0) {
        $drugi = (string) Str::uuid();
        $zeszyty[] = ['id' => $drugi, 'owner' => $wlasciciel];
        $bufor[] = [
            'id' => $drugi,
            'owner_id' => $wlasciciel,
            'name' => 'Na święta',
            'visibility' => 'public',
            'is_default' => false,
            'created_at' => $chwila(86400 * 35),
            'updated_at' => $chwila(86400 * 35),
        ];
    }
}
$wsad('collections', $bufor, true);

$bufor = [];
$zapisow = 0;
foreach ($zeszyty as $nr => $zeszyt) {
    // Nierówno: co dziesiąty zeszyt jest „wypchany”, reszta ma po kilka pozycji.
    $ile = $nr % 10 === 0 ? 250 : 4 + ($nr % 9);
    $uzyte = [];
    for ($p = 0; $p < $ile; $p++) {
        $przepis = $przepisyId[($nr * 37 + $p * 3) % count($przepisyId)];
        if (isset($uzyte[$przepis])) {
            continue;
        }
        $uzyte[$przepis] = true;
        $bufor[] = ['collection_id' => $zeszyt['id'], 'recipe_id' => $przepis, 'created_at' => $chwila(86400 * 20)];
        $zapisow++;
    }
    $wsad('collection_items', $bufor);
}
$wsad('collection_items', $bufor, true);

$bufor = [];
$obserwowanychTagow = 0;
foreach ($widzowie as $nr => $widz) {
    $ile = 5 + ($nr % 26);
    for ($t = 0; $t < $ile && $t < count($tagi); $t++) {
        $bufor[] = ['user_id' => $widz['id'], 'tag_id' => $tagi[($nr * 5 + $t) % count($tagi)], 'created_at' => $chwila(86400 * 15)];
        $obserwowanychTagow++;
    }
}
$wsad('tag_follows', $bufor, true);

$bufor = [];
$blokad = 0;
$pary = [];
for ($i = 0; $i < 1500; $i++) {
    $kto = $autorzy[($i * 3) % count($autorzy)];
    $kogo = $autorzy[($i * 3 + 1 + $i) % count($autorzy)];
    if ($kto === $kogo || isset($pary[$kto.$kogo])) {
        continue;
    }
    $pary[$kto.$kogo] = true;
    $bufor[] = ['blocker_id' => $kto, 'blocked_id' => $kogo, 'created_at' => $chwila(86400 * 12)];
    $blokad++;
}
// Kilka blokad dotyczy bezpośrednio widzów — inaczej filtr blokad nigdy
// nie zadziała na trasie, którą naprawdę mierzymy.
foreach (array_slice($widzowie, 0, 10) as $nr => $widz) {
    $bufor[] = ['blocker_id' => $widz['id'], 'blocked_id' => $autorzy[$nr], 'created_at' => $chwila(86400 * 11)];
    $blokad++;
}
$wsad('blocks', $bufor, true);

$log('ANALYZE...');
$db->statement('ANALYZE');

$podsumowanie = [
    'baza' => $db->getDatabaseName(),
    'sekundy' => $zegar(),
    'haslo_widzow' => $hasloJawne,
    'liczby' => [
        'users' => (int) $db->table('users')->count(),
        'profiles' => (int) $db->table('profiles')->count(),
        'follows' => (int) $db->table('follows')->count(),
        'posts' => (int) $db->table('posts')->count(),
        'recipes' => (int) $db->table('recipes')->count(),
        'recipe_steps' => (int) $db->table('recipe_steps')->count(),
        'recipe_ingredients' => (int) $db->table('recipe_ingredients')->count(),
        'comments' => (int) $db->table('comments')->count(),
        'cooked_events' => (int) $db->table('cooked_events')->count(),
        'collections' => (int) $db->table('collections')->count(),
        'collection_items' => (int) $db->table('collection_items')->count(),
        'post_tags' => (int) $db->table('post_tags')->count(),
        'tags' => (int) $db->table('tags')->count(),
        'tag_follows' => (int) $db->table('tag_follows')->count(),
        'blocks' => (int) $db->table('blocks')->count(),
    ],
    'rozklad' => [
        'follows_miedzy_autorami' => $krawedzi,
        'komentarze_na_goracych_przepisach' => count($goraceP),
        'zapisy_w_zeszytach' => $zapisow,
        'obserwowane_tagi' => $obserwowanychTagow,
        'blokady' => $blokad,
    ],
    'widzowie' => $widzowie,
];

echo json_encode($podsumowanie, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
