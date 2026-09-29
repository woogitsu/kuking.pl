<?php

declare(strict_types=1);

/**
 * Pomiar #841: czy i jak tanio da się odnaleźć wcześniejszą wiadomość „Napisz do nas".
 *
 * TO JEST POMIAR, NIE FUNKCJA. Nic w aplikacji się nie zmienia: skrypt zakłada
 * dane SYNTETYCZNE w lokalnej bazie pomiarowej i mierzy trzy zadania z issue:
 *
 *   A. znaleźć zgłoszenie po fragmencie treści,
 *   B. znaleźć wcześniejszą wiadomość danego konta,
 *   C. znaleźć wiadomość gościa po adresie.
 *
 * Dla każdego zadania porównuje OBECNĄ drogę (przewijanie listy po 25, od
 * najstarszej — dokładnie zapytanie `WiadomosciController::index`) z wąskim
 * wariantem z issue (jedno pole „Znajdź wiadomość", PostgreSQL, bez nowego
 * silnika). Mierzy: liczbę zapytań na żądanie (przez `paginate()` i eager load,
 * tak jak zrobiłby to kontroler), czas, powodzenie (czy cel jest w wyniku) oraz
 * `EXPLAIN (ANALYZE, BUFFERS)`. Osobno sprawdza, czy `%` i `_` z frazy są
 * dosłowne (kryterium odbioru z issue).
 *
 * BEZ PROFILOWANIA I BEZ PRODUKCJI: skrypt odmawia pracy poza lokalną bazą
 * o nazwie `kuking_pomiar_841*`, niczego nie loguje w aplikacji i nie zapisuje
 * surowych fraz — w wyniku są tylko liczby i identyfikatory syntetycznych
 * wierszy. Rzeczywistą liczbę powrotów do starych spraw na produkcji liczy
 * właściciel osobnym zapytaniem `scripts/pomiar-841-powroty.sql` (same
 * agregaty, bez treści) — patrz `docs/pomiary/841-wiadomosci-wyszukiwanie.md`.
 *
 * Użycie (katalog aplikacji = katalog roboczy, `APP_BASE_PATH` gdy `vendor/` jest dowiązaniem):
 *   createdb kuking_pomiar_841 && DB_DATABASE=kuking_pomiar_841 php artisan migrate --force
 *   DB_DATABASE=kuking_pomiar_841 php scripts/pomiar-841.php [wiadomosci=100] [powtorzen=7] > wynik.json
 *
 * Wynik: JSON na STDOUT, postęp na STDERR. Dane są deterministyczne (ziarno 841),
 *
 * a skrypt sam czyści `contact_messages` i konta `*@pomiar841.example.test`
 * przed każdym przebiegiem — wolno go uruchamiać wielokrotnie.
 */

use App\Models\ContactMessage;
use App\Models\User;
use App\Support\FrazaWyszukiwania;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const DOMENA = 'pomiar841.example.test';
const NA_STRONE = 25;

$korzen = dirname(__DIR__);
$liczba = max(20, (int) ($argv[1] ?? 100));
$powtorzen = max(3, (int) ($argv[2] ?? 7));

require $korzen.'/vendor/autoload.php';
$app = require $korzen.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

// Bezpiecznik: wyłącznie lokalna baza pomiarowa (ten sam wzorzec co pomiar-1309.php).
$polaczenie = DB::connection();
if ($app->environment('production')
    || $polaczenie->getDriverName() !== 'pgsql'
    || ! in_array($polaczenie->getConfig('host'), ['127.0.0.1', 'localhost'], true)
    || ! str_starts_with((string) $polaczenie->getDatabaseName(), 'kuking_pomiar_841')) {
    // Kod wyjścia 1 jawnie: wyjątek złapany przez obsługę błędów Laravela kończy skrypt kodem 0.
    fwrite(STDERR, 'Pomiar wymaga lokalnej bazy kuking_pomiar_841*. Odmowa, nic nie zmieniono.'.PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------- dane

mt_srand(841);
fake()->seed(841);

DB::table('contact_messages')->delete();
DB::table('users')->where('email', 'like', '%@'.DOMENA)->delete();

$operator = User::factory()->moderator()->create(['email' => 'operator@'.DOMENA]);
$konta = [];
foreach (range(1, 12) as $n) {
    $konta[] = User::factory()->create(['email' => "konto{$n}@".DOMENA]);
}

// Zdania z polskimi znakami; cele mają słowo unikalne w całym zbiorze.
$tlo = [
    'Nie mogę wgrać zdjęcia z telefonu, po kliknięciu Opublikuj nic się nie dzieje.',
    'Chciałabym przypiąć przepis do kolekcji, ale nie widzę przycisku.',
    'Powiadomienia przychodzą dwa razy, proszę o sprawdzenie ustawień.',
    'Mam pomysł, żeby dodać zamienniki składników przy przepisie.',
    'Litery na ekranie są za małe, choć zwiększyłam czcionkę w ustawieniach.',
    'Zapomniałam hasła i list z odnośnikiem nie dochodzi na skrzynkę.',
    'Dziękuję za ten serwis, gotuję z niego codziennie.',
    'Strona ładuje się bardzo długo na starszym tablecie.',
];
$cele = [
    // [rodzaj zadania, unikalne słowo w treści (z ogonkiem), to samo bez ogonków]
    ['tresc', 'żółtko', 'zoltko'],
    ['tresc', 'łódeczka', 'lodeczka'],
    ['tresc', 'źródełko', 'zrodelko'],
    ['tresc', 'ćwikła', 'cwikla'],
    ['tresc', 'gęsina', 'gesina'],
];

$statusy = [ContactMessage::STATUS_NOWA, ContactMessage::STATUS_W_TOKU, ContactMessage::STATUS_ZALATWIONA, ContactMessage::STATUS_ZALATWIONA, ContactMessage::STATUS_ZALATWIONA];
$rodzaje = array_keys(ContactMessage::RODZAJE);
$start = now()->subDays(300);
$wiersze = [];
$celeTresci = [];   // słowo => id
$cialoCelu = [];    // indeks wiadomości => słowo

// Pięć wiadomości-celów rozłożonych równomiernie w kolejce (początek, środek, koniec).
foreach ($cele as $i => [, $slowo]) {
    $cialoCelu[(int) floor(($i + 0.5) * $liczba / count($cele))] = $slowo;
}

for ($i = 0; $i < $liczba; $i++) {
    $goscZAdresem = $i % 5 >= 3; // 40% gości
    $autor = $goscZAdresem ? null : $konta[$i % count($konta)];
    $status = $statusy[$i % count($statusy)];
    $utworzona = $start->copy()->addSeconds((int) ($i * 300 * 86400 / $liczba));
    $tresc = $tlo[$i % count($tlo)];
    if (isset($cialoCelu[$i])) {
        $tresc .= ' Chodzi mi o ekran z napisem: '.$cialoCelu[$i].'.';
    }
    // Jedna wiadomość z literalnymi `%` i `_` — do próby ucieczki metaznaków.
    if ($i === 3) {
        $tresc .= ' Ładowanie stoi na 100% i plik_zdjecia.jpg nie chce się wgrać.';
    }
    $wiersze[] = [
        'id' => (string) Str::uuid7(),
        'user_id' => $autor?->getKey(),
        'kind' => $rodzaje[$i % count($rodzaje)],
        'message' => $tresc,
        'contact_email' => $goscZAdresem ? 'gosc'.$i.'@'.DOMENA : null,
        'status' => $status,
        'handled_by' => $status === ContactMessage::STATUS_NOWA ? null : $operator->getKey(),
        'handled_at' => $status === ContactMessage::STATUS_NOWA ? null : $utworzona->copy()->addDays(2),
        'created_at' => $utworzona,
        'updated_at' => $utworzona,
    ];
}
foreach (array_chunk($wiersze, 500) as $porcja) {
    DB::table('contact_messages')->insert($porcja);
}
foreach ($cialoCelu as $i => $slowo) {
    $celeTresci[$slowo] = $wiersze[$i]['id'];
}
DB::statement('ANALYZE contact_messages');
fwrite(STDERR, "Dane: {$liczba} wiadomości, ".count($konta).' kont, cele treści: '.count($celeTresci).PHP_EOL);

// ---------------------------------------------------------------- narzędzia

$biezace = [];
DB::listen(function (QueryExecuted $q) use (&$biezace): void {
    $biezace[] = ['sql' => $q->sql, 'bindings' => $q->bindings, 'ms' => $q->time];
});

/** Wspólna baza zapytania — dokładnie jak w `WiadomosciController::index`. */
$lista = fn (): Builder => ContactMessage::query()
    ->with(['author.profile', 'handler.profile'])
    ->orderBy('created_at')
    ->orderBy('id');

/** Uruchamia budowę zapytania (paginate) i oddaje: id wyników, liczba zapytań, czas. */
$uruchom = function (Closure $budowa, int $strona = 1) use (&$biezace): array {
    $biezace = [];
    $t = hrtime(true);
    $wynik = $budowa()->paginate(NA_STRONE, ['*'], 'page', $strona);
    $ms = (hrtime(true) - $t) / 1e6;

    return [
        'ids' => $wynik->pluck('id')->all(),
        'lacznie' => $wynik->total(),
        'zapytan' => count($biezace),
        'ms' => $ms,
        'sql_glowne' => collect($biezace)->first(fn ($z) => str_contains($z['sql'], 'from "contact_messages"') && str_contains($z['sql'], 'limit'))['sql'] ?? null,
        'bindings' => collect($biezace)->first(fn ($z) => str_contains($z['sql'], 'from "contact_messages"') && str_contains($z['sql'], 'limit'))['bindings'] ?? [],
    ];
};

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

    return [
        'planowanie_ms' => (float) ($plan[1] ?? 0),
        'wykonanie_ms' => (float) ($czas[1] ?? 0),
        'bufory_hit' => (int) ($bufory[1] ?? 0),
        'skan_sekwencyjny' => str_contains($tekst, 'Seq Scan on contact_messages'),
        'uzyty_indeks' => (bool) preg_match('/Index (Only )?Scan|Bitmap Index Scan/', $tekst),
    ];
};

/**
 * Mierzy jeden wariant: rozgrzewka, potem $powtorzen przebiegów; powodzenie
 * to obecność WSZYSTKICH celów w PIERWSZEJ stronie wyniku.
 */
$zmierz = function (string $nazwa, Closure $budowa, array $celeIds) use ($uruchom, $mediana, $explain, $powtorzen): array {
    $uruchom($budowa);
    $czasy = [];
    for ($i = 0; $i < $powtorzen; $i++) {
        $r = $uruchom($budowa);
        $czasy[] = $r['ms'];
    }
    $trafione = count(array_intersect($celeIds, $r['ids']));
    $wynik = [
        'wariant' => $nazwa,
        'czas_ms_mediana' => $mediana($czasy),
        'zapytan_na_zadanie' => $r['zapytan'],
        'wynikow_lacznie' => $r['lacznie'],
        'cele_znalezione' => $trafione.'/'.count($celeIds),
        'sukces' => $celeIds !== [] ? $trafione === count($celeIds) : $r['lacznie'] === 0,
    ];
    if ($r['sql_glowne'] !== null) {
        $wynik['explain'] = $explain($r['sql_glowne'], $r['bindings']);
    }

    return $wynik;
};

$wynik = [
    'wersja' => trim((string) shell_exec('git -C '.escapeshellarg($korzen).' rev-parse --short HEAD')),
    'baza' => 'PostgreSQL '.DB::selectOne('SHOW server_version')->server_version,
    'wiadomosci' => $liczba,
    'na_strone' => NA_STRONE,
    'powtorzen' => $powtorzen,
];

// ---------------------------------------------------------------- obecna droga

// Ile stron trzeba obejrzeć, żeby dojść do celu w „Wszystkie" (najgorszy przypadek:
// operator nie pamięta stanu) i w zakładce stanu celu. Każda strona to realne
// zapytanie kontrolera; bierzemy medianę czasu pojedynczej strony.
$obecna = [];
$pozycja = function (string $id, ?string $status) use ($lista): int {
    $q = $lista();
    if ($status !== null) {
        $q->where('status', $status);
    }

    return array_search($id, $q->pluck('id')->all(), true) + 1;
};
$czasStrony = [];
for ($i = 0; $i < $powtorzen; $i++) {
    $czasStrony[] = $uruchom($lista)['ms'];
}
$obecna['czas_jednej_strony_ms_mediana'] = $mediana($czasStrony);
$obecna['zapytan_na_strone'] = $uruchom($lista)['zapytan'];
foreach ($celeTresci as $slowo => $id) {
    $status = ContactMessage::find($id)->status;
    $obecna['cele'][] = [
        'stron_w_wszystkich' => (int) ceil($pozycja($id, null) / NA_STRONE),
        'stron_w_zakladce_stanu' => (int) ceil($pozycja($id, $status) / NA_STRONE),
        'pozycja_w_wszystkich' => $pozycja($id, null),
    ];
}
$stron = array_column($obecna['cele'], 'stron_w_wszystkich');
$obecna['podsumowanie'] = [
    'stron_srednio' => round(array_sum($stron) / count($stron), 2),
    'stron_max' => max($stron),
    'orientacyjny_czas_ms' => round(max($stron) * $obecna['czas_jednej_strony_ms_mediana'], 2),
    'uwaga' => 'To czas ZAPYTAŃ, nie czytania. Czas człowieka przy przewijaniu i czytaniu fragmentów mierzy się osobno, ze stoperem.',
];
$wynik['obecna_droga'] = $obecna;
fwrite(STDERR, 'Obecna droga: średnio '.$obecna['podsumowanie']['stron_srednio'].' str., najwyżej '.$obecna['podsumowanie']['stron_max'].PHP_EOL);

// ---------------------------------------------------------------- warianty

$idsCelu = array_values($celeTresci);
$zad = [];

// Zadanie A: fragment treści. Dla każdego celu fraza Z ogonkami i BEZ ogonków.
foreach (['z_ogonkami' => 1, 'bez_ogonkow' => 2] as $etykieta => $kol) {
    foreach ([
        'ILIKE na treści (wrażliwe na ogonki)' => fn (string $f) => fn () => $lista()->whereRaw('message ilike ?', ['%'.FrazaWyszukiwania::doLike($f).'%']),
        'kuking_normalize(treść) LIKE (bez ogonków, bez indeksu)' => fn (string $f) => fn () => $lista()->whereRaw('public.kuking_normalize(message) like ?', ['%'.FrazaWyszukiwania::doLike(FrazaWyszukiwania::normalizuj($f)).'%']),
    ] as $nazwa => $fabryka) {
        $pomiary = [];
        foreach ($cele as $i => [, $z, $bez]) {
            $fraza = $kol === 1 ? $z : $bez;
            $pomiary[] = $zmierz($nazwa, $fabryka($fraza), [$celeTresci[$z]]);
        }
        $zad['A_tresc'][$etykieta][$nazwa] = [
            'czas_ms_mediana' => $mediana(array_column($pomiary, 'czas_ms_mediana')),
            'zapytan_na_zadanie' => $pomiary[0]['zapytan_na_zadanie'],
            'sukces' => count(array_filter(array_column($pomiary, 'sukces'))).'/'.count($pomiary),
            'explain' => $pomiary[0]['explain'] ?? null,
        ];
    }
}

// Zadanie B: wcześniejsza wiadomość konta. Cel = wszystkie wiadomości konta nr 1 (<= 25).
$konto = $konta[0];
$celeKonta = DB::table('contact_messages')->where('user_id', $konto->getKey())->pluck('id')->all();
$celeKonta = array_slice($celeKonta, 0, NA_STRONE);
$nazwaProfilu = mb_strtolower((string) DB::table('profiles')->where('user_id', $konto->getKey())->value('display_name'));
$zad['B_konto'] = [
    'liczba_wiadomosci_konta' => count($celeKonta),
    'po_adresie_konta_dokladnie' => $zmierz('users.email = ? (dokładnie)', fn () => $lista()->whereHas('author', fn (Builder $u) => $u->whereRaw('lower(email) = ?', [mb_strtolower($konto->email)])), $celeKonta),
    'po_nazwie_profilu_fragment' => $zmierz('display_name ILIKE fragment', fn () => $lista()->whereHas('author.profile', fn (Builder $p) => $p->whereRaw('display_name ilike ?', ['%'.FrazaWyszukiwania::doLike(mb_substr($nazwaProfilu, 0, 4)).'%'])), $celeKonta),
];

// Zadanie C: wiadomość gościa po adresie.
$gosc = DB::table('contact_messages')->whereNotNull('contact_email')->orderBy('created_at')->skip(7)->first();
$zad['C_gosc'] = [
    'adres_dokladnie' => $zmierz('contact_email = ? (dokładnie, lower)', fn () => $lista()->whereRaw('lower(contact_email) = ?', [mb_strtolower($gosc->contact_email)]), [$gosc->id]),
    'adres_fragment' => $zmierz('contact_email ILIKE fragment', fn () => $lista()->whereRaw('contact_email ilike ?', ['%'.FrazaWyszukiwania::doLike(explode('@', $gosc->contact_email)[0]).'%']), [$gosc->id]),
];

// Metaznaki: `%` i `_` z frazy mają być dosłowne. Bez ucieczki „%" dopasowuje
// WSZYSTKO — to jest pułapka, którą issue każe wykluczyć.
$bezUcieczki = $uruchom(fn () => $lista()->whereRaw('message ilike ?', ['%%%']))['lacznie'];
$zUcieczka = $uruchom(fn () => $lista()->whereRaw('message ilike ?', ['%'.FrazaWyszukiwania::doLike('%').'%']))['lacznie'];
$podkreslnik = $uruchom(fn () => $lista()->whereRaw('message ilike ?', ['%'.FrazaWyszukiwania::doLike('_').'%']))['lacznie'];
$zad['metaznaki'] = [
    'fraza_procent_bez_ucieczki_wynikow' => $bezUcieczki,
    'fraza_procent_z_ucieczka_wynikow' => $zUcieczka,
    'fraza_podkreslnik_z_ucieczka_wynikow' => $podkreslnik,
    'oczekiwane' => 'z ucieczką: tylko wiadomość z literalnym „100%" (1) i z literalnym „_" (1)',
];

$wynik['zadania'] = $zad;

echo json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
