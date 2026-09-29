<?php

declare(strict_types=1);

/**
 * Pomiar #870: czy i jak tanio da się odnaleźć wcześniejsze pytanie z Poradźcie
 * po słowach z tytułu.
 *
 * TO JEST POMIAR, NIE FUNKCJA. Aplikacja się nie zmienia. Skrypt zakłada dane
 * SYNTETYCZNE w lokalnej bazie pomiarowej i porównuje:
 *
 *   • OBECNĄ drogę — lista `/pytania` (najnowsze na górze, `QuestionList`),
 *     bez pola szukania: ile stron trzeba obejrzeć, żeby dojść do pytania;
 *   • trzy warianty wyszukiwania po TYTULE zbudowane na TYM SAMYM zapytaniu
 *     `QuestionList::query()` (więc widoczność, blokady, status autora i flaga
 *     działu są dokładnie te co na liście — kryterium odbioru z issue):
 *       S1  `title ILIKE %fraza%`                       — ciągły fragment, wrażliwe na ogonki
 *       S2  każde słowo `kuking_normalize(title) LIKE`  — słowa niekoniecznie obok siebie, bez ogonków
 *       S3  `fraza <% kuking_normalize(title)`          — trigramy (`pg_trgm`), tolerancja literówek,
 *                                                         próg 0,5 jak w `ProgPodobienstwa`
 *     bez indeksu, a potem z indeksem trigramowym założonym TYLKO w tej bazie
 *     pomiarowej (issue: „pomiar zapytań i czasu przed doborem indeksów”).
 *
 * Dla każdego wariantu i rodzaju frazy (słowa z tytułu, bez ogonków, literówka,
 * inna forma wyrazu) mierzy powodzenie — czy właściwe pytanie jest na PIERWSZEJ
 * stronie wyników — oraz medianę czasu i liczbę zapytań. Osobno sprawdza, czy
 * pytania niedostępne (ukryte, usunięte, autor zablokowany, widoczne tylko dla
 * obserwujących, zablokowany przez widza) nie przeciekają przez tytuł ani licznik.
 *
 * CZEGO TEN POMIAR NIE ROBI: nie mierzy ludzi (to jest badanie z 5–8 osobami,
 * krok właściciela: `docs/pomiary/870-pytania-wyszukiwanie.md`), nie ocenia
 * retencji ani liczby duplikatów, nie dotyka produkcji ani API AI.
 *
 * BEZ PROFILOWANIA I BEZ PRODUKCJI: odmawia pracy poza lokalną bazą
 * `kuking_pomiar_870*`, nic nie loguje w aplikacji, w wyniku są tylko liczby.
 *
 * Użycie (`APP_BASE_PATH` gdy `vendor/` jest dowiązaniem):
 *   createdb kuking_pomiar_870 && DB_DATABASE=kuking_pomiar_870 php artisan migrate --force
 *   DB_DATABASE=kuking_pomiar_870 php scripts/pomiar-870.php [pytan=2000] [powtorzen=5] > wynik.json
 *
 * Dane deterministyczne (ziarno 870); skrypt czyści własne wiersze przed startem.
 * Wynik: JSON na STDOUT, postęp na STDERR.
 */

use App\Domain\Questions\QuestionList;
use App\Models\User;
use App\Support\FrazaWyszukiwania;
use App\Support\ProgPodobienstwa;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const DOMENA = 'pomiar870.example.test';

$korzen = dirname(__DIR__);
$liczba = max(200, (int) ($argv[1] ?? 2000));
$powtorzen = max(3, (int) ($argv[2] ?? 5));

require $korzen.'/vendor/autoload.php';
$app = require $korzen.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

// Bezpiecznik: wyłącznie lokalna baza pomiarowa (wzorzec z pomiar-1309.php).
$polaczenie = DB::connection();
if ($app->environment('production')
    || $polaczenie->getDriverName() !== 'pgsql'
    || ! in_array($polaczenie->getConfig('host'), ['127.0.0.1', 'localhost'], true)
    || ! str_starts_with((string) $polaczenie->getDatabaseName(), 'kuking_pomiar_870')) {
    // Kod wyjścia 1 jawnie: wyjątek złapany przez obsługę błędów Laravela kończy skrypt kodem 0.
    fwrite(STDERR, 'Pomiar wymaga lokalnej bazy kuking_pomiar_870*. Odmowa, nic nie zmieniono.'.PHP_EOL);
    exit(1);
}

// Dział pytań jest za flagą; pomiar dotyczy stanu „włączony”.
config(['kuking.questions.enabled' => true]);
$naStrone = (int) config('kuking.feed.page_size');

// ---------------------------------------------------------------- dane

mt_srand(870);

DB::table('users')->where('email', 'like', '%@'.DOMENA)->delete(); // kaskada czyści wpisy i komentarze

$teraz = now();
$uzytkownicy = [];
$dodajKonto = function (string $nazwa, string $status) use (&$uzytkownicy, $teraz): string {
    $id = (string) Str::uuid7();
    $uzytkownicy[] = [
        'id' => $id, 'email' => $nazwa.'@'.DOMENA, 'password' => 'x', 'status' => $status,
        'is_seeded' => false, 'created_at' => $teraz, 'updated_at' => $teraz,
    ];

    return $id;
};
$aktywne = [];
foreach (range(1, 400) as $n) {
    $aktywne[] = $dodajKonto("aktywne{$n}", 'active');
}
$zbanowany = $dodajKonto('zbanowany', 'banned');
$widz = $dodajKonto('widz', 'active');
$blokowanyPrzezWidza = array_slice($aktywne, 0, 5);
$blokujacyWidza = array_slice($aktywne, 5, 5);
DB::table('users')->insert($uzytkownicy);
DB::table('blocks')->insert(array_merge(
    array_map(fn ($id) => ['blocker_id' => $widz, 'blocked_id' => $id], $blokowanyPrzezWidza),
    array_map(fn ($id) => ['blocker_id' => $id, 'blocked_id' => $widz], $blokujacyWidza),
));

$tematy = ['chleb', 'ciasto drożdżowe', 'sernik', 'rosół', 'pierogi', 'bigos', 'kotlety mielone', 'żurek', 'szarlotka', 'makowiec', 'barszcz', 'gołąbki', 'naleśniki', 'pączki', 'kluski śląskie', 'schabowy', 'ryba w galarecie', 'kapusta kiszona'];
$problemy = [
    ['opada po wyjęciu z piekarnika', 'opada'],
    ['wychodzi zakalcowaty', 'zakalcowaty'],
    ['jest za suchy', 'suchy'],
    ['pęka na wierzchu', 'pęka'],
    ['nie chce wyrosnąć', 'wyrosnąć'],
    ['ma gorzki posmak', 'gorzki'],
    ['rozpada się przy krojeniu', 'rozpada'],
    ['jest za słony', 'słony'],
    ['przywiera do formy', 'przywiera'],
    ['robi się gumowaty', 'gumowaty'],
];
$szablony = ['Dlaczego %s %s?', 'Co zrobić, gdy %s %s?', 'Czy to normalne, że %s %s?'];
$dopiski = ['', '', '', ' w piekarniku elektrycznym', ' na zakwasie', ' u mnie za każdym razem', ' po zmianie mąki', ' w zimie'];

$posty = [];
$komentarze = [];
$dodajPytanie = function (string $tytul, ?string $tresc, string $autor, string $status = 'published', string $widocznosc = 'public', ?string $usuniete = null, int $wiek = 0) use (&$posty, $teraz): string {
    $id = (string) Str::uuid7();
    $czas = $teraz->copy()->subMinutes($wiek);
    $posty[] = [
        'id' => $id, 'author_id' => $autor, 'body' => $tresc, 'visibility' => $widocznosc, 'status' => $status,
        'published_at' => $status === 'published' ? $czas : null, 'created_at' => $czas, 'updated_at' => $czas,
        'deleted_at' => $usuniete !== null ? $czas : null, 'kind' => 'question', 'title' => $tytul,
    ];

    return $id;
};

// Cele: tytuły z RZADKIM tematem (nie występuje w tle), rozłożone równomiernie w czasie.
$tematyCelow = [['zakwas', 'zakwasu'], ['chałka', 'chałki'], ['kisiel', 'kisielu'], ['kaszanka', 'kaszanki'], ['faworki', 'faworków'], ['pasztet', 'pasztetu'], ['kompot', 'kompotu'], ['śledzie', 'śledzi'], ['marcepan', 'marcepanu'], ['pierniczki', 'pierniczków'], ['budyń', 'budyniu'], ['powidła', 'powideł']];
$cele = [];
foreach ($tematyCelow as $i => [$temat, $odmiana]) {
    [$tekstProblemu, $slowoProblemu] = $problemy[$i % count($problemy)];
    $wiek = (int) floor(($i + 0.5) * ($liczba * 6) / count($tematyCelow)); // co ~6 minut jedno pytanie w tle
    $id = $dodajPytanie(sprintf('Dlaczego %s %s?', $temat, $tekstProblemu), $i % 2 === 0 ? null : 'Robię od lat według tego samego przepisu, a teraz nagle nie wychodzi.', $aktywne[100 + $i], wiek: $wiek);
    $slowa = $temat.' '.$slowoProblemu;
    $zamiana = mb_substr($temat, 0, 2).mb_substr($temat, 3, 1).mb_substr($temat, 2, 1).mb_substr($temat, 4); // przestawione 3. i 4. litera
    $cele[] = [
        'id' => $id,
        'tytul' => sprintf('Dlaczego %s %s?', $temat, $tekstProblemu),
        'body_null' => $i % 2 === 0,
        'frazy' => [
            'slowa_z_tytulu' => $slowa,
            'bez_ogonkow' => Str::ascii($slowa),
            'literowka' => $zamiana.' '.Str::ascii($slowoProblemu),
            'inna_forma_wyrazu' => $odmiana.' '.$slowoProblemu,
        ],
    ];
}

// Tło: pytania podobne do celów (wspólne słowa o problemach), ale o innych potrawach.
for ($i = 0; $i < $liczba; $i++) {
    $tytul = sprintf($szablony[$i % 3], $tematy[$i % count($tematy)], $problemy[intdiv($i, 3) % count($problemy)][0]).$dopiski[$i % count($dopiski)];
    $tytul = mb_strlen($tytul) > 12 ? $tytul : $tytul.'??';
    $tresc = $i % 2 === 0 ? null : 'Robię to od kilku lat i nie wiem, co robię nie tak.';
    $id = $dodajPytanie($tytul, $tresc, $aktywne[$i % count($aktywne)], wiek: $i * 6 + 3);
    // ~1,2 odpowiedzi na pytanie od innej osoby: koszt `withCount` z QuestionList jest realny.
    if ($i % 5 !== 0) {
        $komentarze[] = ['id' => (string) Str::uuid7(), 'author_id' => $aktywne[($i + 7) % count($aktywne)], 'post_id' => $id, 'body' => 'Sprawdź temperaturę piekarnika.', 'status' => 'published', 'created_at' => $teraz, 'updated_at' => $teraz];
    }
}

// Niedostępne dla gościa/widza — z UNIKALNYM słowem „tajemnicze<n>”, którego szukamy.
$niedostepne = [
    'ukryte_przez_moderatora' => $dodajPytanie('Dlaczego tajemnicze1 opada po wyjęciu?', null, $aktywne[20], status: 'hidden'),
    'usuniete' => $dodajPytanie('Dlaczego tajemnicze2 opada po wyjęciu?', null, $aktywne[21], usuniete: $teraz->toDateTimeString()),
    'autor_zbanowany' => $dodajPytanie('Dlaczego tajemnicze3 opada po wyjęciu?', null, $zbanowany),
    'tylko_dla_obserwujacych' => $dodajPytanie('Dlaczego tajemnicze4 opada po wyjęciu?', null, $aktywne[22], widocznosc: 'followers'),
    'prywatne' => $dodajPytanie('Dlaczego tajemnicze5 opada po wyjęciu?', null, $aktywne[23], widocznosc: 'private'),
    'szkic' => $dodajPytanie('Dlaczego tajemnicze6 opada po wyjęciu?', null, $aktywne[24], status: 'draft'),
];
// Widoczne dla gościa, ale nie dla widza (blokada w obie strony) — osobno, bo dotyczy tylko widza.
$niedostepneDlaWidza = [
    'autor_blokowany_przez_widza' => $dodajPytanie('Dlaczego tajemnicze7 opada po wyjęciu?', null, $blokowanyPrzezWidza[0]),
    'autor_blokujacy_widza' => $dodajPytanie('Dlaczego tajemnicze8 opada po wyjęciu?', null, $blokujacyWidza[0]),
];

foreach (array_chunk($posty, 1000) as $porcja) {
    DB::table('posts')->insert($porcja);
}
foreach (array_chunk($komentarze, 1000) as $porcja) {
    DB::table('comments')->insert($porcja);
}
DB::statement('ANALYZE posts');
DB::statement('ANALYZE comments');
fwrite(STDERR, 'Dane: '.count($posty).' pytań, '.count($komentarze).' odpowiedzi, '.count($cele).' celów'.PHP_EOL);

// ---------------------------------------------------------------- narzędzia

$biezace = [];
DB::listen(function (QueryExecuted $q) use (&$biezace): void {
    $biezace[] = ['sql' => $q->sql, 'bindings' => $q->bindings, 'ms' => $q->time];
});

$widzModel = User::query()->findOrFail($widz);
$lista = new QuestionList;

$mediana = function (array $x): float {
    sort($x);
    $n = count($x);

    return round($n % 2 ? $x[intdiv($n, 2)] : ($x[$n / 2 - 1] + $x[$n / 2]) / 2, 2);
};

$explain = function (string $sql, array $bindings): array {
    $wiersze = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql, $bindings);
    $tekst = implode("\n", array_map(fn ($w) => $w->{'QUERY PLAN'}, $wiersze));
    preg_match('/Execution Time: ([\d.]+) ms/', $tekst, $czas);
    preg_match('/Planning Time: ([\d.]+) ms/', $tekst, $plan);
    preg_match('/shared hit=(\d+)/', $tekst, $bufory);
    preg_match_all('/(?:Index (?:Only )?Scan|Bitmap Index Scan) (?:Backward )?using (\S+)/', $tekst, $indeksy);

    return [
        'planowanie_ms' => (float) ($plan[1] ?? 0),
        'wykonanie_ms' => (float) ($czas[1] ?? 0),
        'bufory_hit' => (int) ($bufory[1] ?? 0),
        'indeksy' => array_values(array_unique($indeksy[1])),
        'skan_sekwencyjny_posts' => str_contains($tekst, 'Seq Scan on posts'),
    ];
};

/**
 * Strategie: każda zwraca zawężenie tytułu dla podanej frazy. Bazą jest ZAWSZE
 * `QuestionList::query($widz)`; strategia dokłada tylko warunek na tytule.
 *
 * @return array<string, Closure(Builder, string): void>
 */
$strategie = [
    'S1 ILIKE %fraza%' => function (Builder $q, string $fraza): void {
        $q->whereRaw('posts.title ilike ?', ['%'.FrazaWyszukiwania::doLike($fraza).'%']);
    },
    'S2 słowa AND, kuking_normalize LIKE' => function (Builder $q, string $fraza): void {
        foreach (preg_split('/\s+/', FrazaWyszukiwania::normalizuj($fraza), -1, PREG_SPLIT_NO_EMPTY) as $slowo) {
            $q->whereRaw('public.kuking_normalize(posts.title) like ?', ['%'.FrazaWyszukiwania::doLike($slowo).'%']);
        }
    },
    'S3 trigramy <% (próg 0,5)' => function (Builder $q, string $fraza): void {
        $norma = FrazaWyszukiwania::normalizuj($fraza);
        $q->whereRaw('? <% public.kuking_normalize(posts.title)', [$norma])
            ->reorder()
            ->orderByRaw('word_similarity(?, public.kuking_normalize(posts.title)) desc', [$norma])
            ->orderByDesc('published_at')->orderByDesc('id');
    },
];

$zbuduj = function (?User $widz, Closure $strategia, string $fraza) use ($lista, $naStrone): Builder {
    $q = $lista->query($widz);
    $strategia($q, $fraza);

    return $q->limit($naStrone);
};

/** Jedno uruchomienie: id wyników (pierwsza strona), czas, liczba zapytań, SQL do EXPLAIN. */
$uruchom = function (Builder $q) use (&$biezace): array {
    $biezace = [];
    $t = hrtime(true);
    $ids = $q->pluck('posts.id')->all();
    $ms = (hrtime(true) - $t) / 1e6;
    $glowne = $biezace[0] ?? ['sql' => null, 'bindings' => []];

    return ['ids' => $ids, 'ms' => $ms, 'zapytan' => count($biezace), 'sql' => $glowne['sql'], 'bindings' => $glowne['bindings']];
};

/**
 * Mierzy strategię na wszystkich celach i rodzajach fraz.
 *
 * @return array<string, mixed>
 */
$zmierzStrategie = function (Closure $strategia, ?User $widz) use ($cele, $zbuduj, $uruchom, $mediana, $explain, $powtorzen): array {
    ProgPodobienstwa::ustaw();
    $rodzaje = [];
    $czasy = [];
    $zapytan = 0;
    $plan = null;
    foreach (array_keys($cele[0]['frazy']) as $rodzaj) {
        $trafione = 0;
        foreach ($cele as $j => $cel) {
            $fraza = $cel['frazy'][$rodzaj];
            $uruchom($zbuduj($widz, $strategia, $fraza)); // rozgrzewka
            $ms = [];
            for ($i = 0; $i < $powtorzen; $i++) {
                $r = $uruchom($zbuduj($widz, $strategia, $fraza));
                $ms[] = $r['ms'];
            }
            $czasy[] = $mediana($ms);
            $zapytan = $r['zapytan'];
            $trafione += in_array($cel['id'], $r['ids'], true) ? 1 : 0;
            if ($plan === null && $rodzaj === 'slowa_z_tytulu' && $r['sql'] !== null) {
                $plan = $explain($r['sql'], $r['bindings']);
            }
        }
        $rodzaje[$rodzaj] = $trafione.'/'.count($cele);
    }

    return [
        'na_pierwszej_stronie' => $rodzaje,
        'czas_ms_mediana' => $mediana($czasy),
        'czas_ms_max' => max($czasy),
        'zapytan_na_zadanie' => $zapytan,
        'explain_slowa_z_tytulu' => $plan,
    ];
};

$wynik = [
    'wersja' => trim((string) shell_exec('git -C '.escapeshellarg($korzen).' rev-parse --short HEAD')),
    'baza' => 'PostgreSQL '.DB::selectOne('SHOW server_version')->server_version,
    'pytan_w_tle' => $liczba,
    'cele' => count($cele),
    'na_strone' => $naStrone,
    'powtorzen' => $powtorzen,
];

// ---------------------------------------------------------------- obecna droga

$pozycje = [];
$porzadek = $lista->query(null)->pluck('posts.id')->all();
foreach ($cele as $cel) {
    $pozycje[] = array_search($cel['id'], $porzadek, true) + 1;
}
$strony = array_map(fn ($p) => (int) ceil($p / $naStrone), $pozycje);
$czasStrony = [];
for ($i = 0; $i < $powtorzen; $i++) {
    $czasStrony[] = $uruchom($lista->query(null)->limit($naStrone))['ms'];
}
$wynik['obecna_droga'] = [
    'opis' => 'Lista /pytania (najnowsze, gość): ile stron do obejrzenia, by dojść do pytania.',
    'pytan_widocznych_dla_goscia' => count($porzadek),
    'strony_do_celu_srednio' => round(array_sum($strony) / count($strony), 1),
    'strony_do_celu_max' => max($strony),
    'czas_jednej_strony_ms_mediana' => $mediana($czasStrony),
    'uwaga' => 'To czas ZAPYTAŃ, nie czytania. Czas człowieka mierzy się osobno (protokół w docs/pomiary/870-pytania-wyszukiwanie.md).',
];
fwrite(STDERR, 'Obecna droga: średnio '.$wynik['obecna_droga']['strony_do_celu_srednio'].' str., najwyżej '.$wynik['obecna_droga']['strony_do_celu_max'].PHP_EOL);

// ---------------------------------------------------------------- warianty bez indeksu i z indeksem

DB::statement('DROP INDEX IF EXISTS pomiar_posts_title_trgm_idx');
foreach ([['gosc', null], ['zalogowany', $widzModel]] as [$etykieta, $osoba]) {
    foreach ($strategie as $nazwa => $strategia) {
        $wynik['bez_indeksu'][$etykieta][$nazwa] = $zmierzStrategie($strategia, $osoba);
        fwrite(STDERR, "bez indeksu, {$etykieta}, {$nazwa}: ".json_encode($wynik['bez_indeksu'][$etykieta][$nazwa]['na_pierwszej_stronie']).PHP_EOL);
    }
}

// Indeks trigramowy tylko dla pytań, na wyrażeniu zapytania — TYLKO w tej bazie pomiarowej.
// W kodzie aplikacji odpowiednikiem byłaby kolumna generowana `title_search` + GIN
// (jak `recipes.title_search`); to osobna migracja po decyzji, nie część tego pomiaru.
DB::statement("CREATE INDEX pomiar_posts_title_trgm_idx ON posts USING gin (public.kuking_normalize(title) gin_trgm_ops) WHERE kind = 'question'");
DB::statement('ANALYZE posts');
$wynik['rozmiar_indeksu_kb'] = (int) round(DB::selectOne("SELECT pg_relation_size('pomiar_posts_title_trgm_idx') AS b")->b / 1024);
foreach ([['gosc', null], ['zalogowany', $widzModel]] as [$etykieta, $osoba]) {
    foreach (array_slice($strategie, 1, null, true) as $nazwa => $strategia) {
        $wynik['z_indeksem_trigramowym'][$etykieta][$nazwa] = $zmierzStrategie($strategia, $osoba);
        fwrite(STDERR, "z indeksem, {$etykieta}, {$nazwa}: ".json_encode($wynik['z_indeksem_trigramowym'][$etykieta][$nazwa]['na_pierwszej_stronie']).PHP_EOL);
    }
}
DB::statement('DROP INDEX IF EXISTS pomiar_posts_title_trgm_idx');

// ---------------------------------------------------------------- niedostępne nie przeciekają

// Szukamy słowa „tajemniczeN” (jest tylko w pytaniach niedostępnych) każdą strategią.
$wyciek = [];
$slowaTajemne = ['tajemnicze1', 'tajemnicze2', 'tajemnicze3', 'tajemnicze4', 'tajemnicze5', 'tajemnicze6', 'tajemnicze7', 'tajemnicze8'];
foreach ([['gosc', null, $niedostepne], ['zalogowany', $widzModel, array_merge($niedostepne, $niedostepneDlaWidza)]] as [$etykieta, $osoba, $zakazane]) {
    $zakazaneIds = array_values($zakazane);
    $znalezione = 0;
    foreach ($strategie as $strategia) {
        foreach ($slowaTajemne as $slowo) {
            $ids = $uruchom($zbuduj($osoba, $strategia, $slowo))['ids'];
            $znalezione += count(array_intersect($ids, $zakazaneIds));
        }
    }
    // Licznik: łączna liczba widocznych trafień nie może uwzględniać zakazanych.
    $licznik = $lista->query($osoba)->whereRaw("posts.title ilike '%tajemnicze%'")->count();
    $wyciek[$etykieta] = ['zakazane_w_wynikach' => $znalezione, 'licznik_trafien_slowa_tajemnicze' => $licznik, 'oczekiwane_zakazane' => 0];
}
$wyciek['uwaga'] = 'Widz (zalogowany) nie widzi tajemnicze7/8 (blokady w obie strony); gość widzi je jako zwykłe publiczne pytania, więc licznik gościa to 2, zalogowanego 0.';
$wynik['niedostepne_nie_przeciekaja'] = $wyciek;

echo json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
