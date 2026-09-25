<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kolejka\PolecenieZadania;
use App\Notifications\LinkDoLogowania;
use App\Notifications\UstawienieHaslaZamiastLinku;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `kuking:martwe-zadania` — co stoi w `failed_jobs`, ILU LUDZI to dotyczy
 * i co wolno stamtąd wyrzucić, bo ponowienie nikomu już nie pomoże.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ISTNIEJE OBOK `queue:failed` I `kuking:kto-nie-dostal-listu`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Trzy różne pytania, trzy różne komendy i żadna nie zastępuje pozostałych:
 *
 *  - `php artisan queue:failed` mówi, ŻE coś padło: uuid, klasa, godzina.
 *    Liczy WIERSZE i nie wie, czy pięć wierszy to pięć osób, czy jedna,
 *    która kliknęła pięć razy;
 *  - `kuking:kto-nie-dostal-listu` mówi, DO KOGO list nie doszedł — i tylko
 *    czyta, świadomie, bez jednego przełącznika, który by to zmieniał;
 *  - ta komenda odpowiada na pytanie trzecie, zadawane PO tamtych dwóch:
 *    „co z tym zrobić, żeby `/health` przestał mówić `degraded`, i czego
 *    wolno się przy tym pozbyć".
 *
 * Powód jest konkretny i ma datę. W `failed_jobs` leżą cztery zadania
 * `UstawienieNowegoHasla` z 9 września 2026 — z awarii SMTP na Railway,
 * naprawionej przez D-047 (`docs/infra/ZDARZENIE_2026-09-09_NIEWYSLANE_HASLA.md`).
 * `HealthController::sprawdzKolejke()` liczy WSZYSTKIE wiersze tej tabeli,
 * więc dopóki te cztery tam stoją, `/health` melduje `degraded`
 * i zewnętrzny monitoring dzwoni o awarii, której już nie ma.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE `queue:retry` — TO JEST CAŁE SEDNO TEJ KOMENDY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo odruch „skoro padło, to ponów" jest tu gorszy od nicnierobienia.
 * Żeton resetu hasła jest ważny `config('auth.passwords.users.expire')`
 * minut — dziś 60 — liczone od WYSTAWIENIA, a nie od wysłania. Ponowienie
 * zadania po dniach wysyła człowiekowi list z linkiem, który jest już
 * martwy: osoba, która o nic dziś nie prosiła, dostaje wiadomość o zmianie
 * hasła, klika i widzi „link wygasł". To jest gorsze niż cisza, bo kosztuje
 * zaufanie, a nie tylko czas.
 *
 * Dobrą odpowiedzią przy żywym żetonie jest `queue:retry`, przy martwym —
 * poproszenie tych osób, żeby jeszcze raz kliknęły „Nie pamiętam hasła".
 * Ta komenda rozdziela te dwa przypadki, zamiast kazać komuś liczyć minuty
 * w pamięci.
 *
 * ŻETONY I ICH WAŻNOŚĆ SĄ CZYTANE Z KONFIGURACJI, NIE WPISANE TUTAJ — patrz
 * `ZETONY` i `waznoscMinut()`. Gdy właściciel zmieni `expire` w
 * `config/auth.php`, próg tej komendy zmienia się razem z nim. Liczba
 * wpisana tu na sztywno rozjechałaby się przy pierwszej takiej zmianie
 * i kasowałaby żywe żetony albo trzymała martwe.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TRYBEM DOMYŚLNYM JEST `--na-sucho` I NIE DA SIĘ TEGO PRZEOCZYĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bez żadnej opcji komenda NICZEGO nie kasuje — wypisuje, co by zniknęło,
 * i mówi, czym to potwierdzić. Kasowanie wymaga jawnego `--skasuj`, a gdy
 * ktoś poda oba przełączniki naraz, wygrywa `--na-sucho`: przy sprzeczności
 * dwóch intencji wybieramy tę, którą da się cofnąć.
 *
 * Osobnego pytania „czy na pewno?" tu nie ma i to jest decyzja, nie
 * przeoczenie. Naturalnym miejscem uruchomienia jest konsola Railwaya, gdzie
 * wejścia bywa brak (`--no-interaction` w skryptach), a pytanie, na które
 * nikt nie odpowiada, albo blokuje przebieg, albo domyślnie przepuszcza
 * kasowanie. Jawny przełącznik jest tym potwierdzeniem — wpisanym ręką,
 * widocznym w historii powłoki.
 *
 * KASUJE TYLKO TO, CO POKAZAŁA. Kandydat musi spełnić OBA warunki naraz:
 * jego klasa jest na liście `ZETONY` i jest starszy niż ważność swojego
 * żetonu. Zadanie spoza tej listy — przetwarzanie zdjęcia, eksport danych,
 * podsumowanie tygodnia — zostaje w tabeli nietknięte, choćby leżało tam
 * rok: tam ponowienie nadal ma sens i decyzja należy do właściciela.
 * Od `queue:flush`, które kasuje wszystko bez patrzenia, różni tę komendę
 * dokładnie to jedno.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  LICZYMY LUDZI, NIE WIERSZE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Jedna osoba, której nie przyszedł list, klika „Nie pamiętam hasła"
 * jeszcze dwa razy — i w tabeli robią się trzy wiersze. Zdanie „trzy
 * nieudane zadania" każe wtedy myśleć o trzech ludziach, choć czeka jeden.
 * Dlatego kolumna „Osób" liczy RÓŻNE konta, a nie wiersze, a wiersze,
 * w których konta rozpoznać się nie da, są policzone osobno i nazwane —
 * zamiast wpaść do zera i zniknąć z rachunku.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TA KOMENDA NIE POKAZUJE NIGDY: ŻETONU
 * ────────────────────────────────────────────────────────────────────────
 *
 * W `failed_jobs.payload` klucz `data.command` to zserializowane
 * `SendQueuedNotifications`, a w nim obiekt powiadomienia z polem `token`
 * — od audytu A5-10 zaszyfrowane kluczem aplikacji, ale PO odszyfrowaniu
 * (`PolecenieZadania`) znów jawne (zmierzone przy `kuking:kto-nie-dostal-listu`; przy
 * `LinkDoLogowania` ten token daje od razu SESJĘ, nie tylko formularz
 * hasła). Kto go ma, ten wchodzi na cudze konto. Dlatego:
 *
 *  1. surowy `payload` nie trafia na ekran w ŻADNEJ gałęzi — także w tej,
 *     w której komenda nie umie wiersza odczytać. Ta gałąź jest
 *     najgroźniejsza, bo „nie wiem, co to jest, więc wypiszę, co mam" jest
 *     odruchem, który wynosi dokładnie ten sekret;
 *  2. kolumna `exception` też nie trafia na ekran ani do komunikatu
 *     wyjątku — ślad stosu w Laravelu potrafi nieść argumenty wywołań
 *     (`App\Logging\WebhookBleduHandler`, ostrzeżenie przy
 *     `App\Support\Poczta::przeszkoda()`);
 *  3. `unserialize()` dostaje listę dozwolonych klas (`WOLNO_ODTWORZYC`),
 *     na której klas powiadomień NIE MA. Obiekt `UstawienieNowegoHasla`
 *     w ogóle więc nie powstaje i nie ma z czego przeczytać właściwości.
 *     To jest druga bariera, niezależna od dyscypliny drukowania.
 *
 * Pilnuje tego `tests/Feature/MartweZadaniaTest.php` — z kontrolą dodatnią,
 * czyli asercją, że żeton NAPRAWDĘ leży w ładunku. Bez niej „nie ma żetonu
 * na ekranie" przechodziłoby także wtedy, gdyby żetonu nie było nigdzie
 * (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE MA JEJ W HARMONOGRAMIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo `failed_jobs` to jedyny ślad po awarii, a ślad kasowany automatycznie
 * w nocy nie jest śladem. Sprzątanie samo z siebie wygasiłoby też `degraded`
 * w `/health` — czyli alarm zgasłby, zanim ktokolwiek go zobaczył. Decyzja
 * o wyrzuceniu tych wierszy należy do człowieka i zapada PO tym, jak zobaczy,
 * kogo dotyczyły.
 */
class MartweZadania extends Command
{
    /**
     * Klasy, które wolno odtworzyć z ładunku. Wszystko spoza tej listy wraca
     * jako `__PHP_Incomplete_Class` — patrz punkt 3 w nagłówku klasy.
     *
     * `SendQueuedNotifications` musi tu być, bo to on niesie odbiorców.
     * `ModelIdentifier` i kolekcja Eloquenta — bo tak Laravel zapisuje modele
     * w kolejce (`SerializesModels`). Klas powiadomień na tej liście nie ma
     * i nie wolno ich dopisywać.
     *
     * @var list<class-string>
     */
    private const WOLNO_ODTWORZYC = [
        SendQueuedNotifications::class,
        ModelIdentifier::class,
        EloquentCollection::class,
    ];

    /**
     * Powiadomienia niosące w ładunku ŻYWY SEKRET — i jedyne, które ta
     * komenda w ogóle rozważa do skasowania.
     *
     * Klucz to klasa powiadomienia (tak Laravel wypełnia `displayName`
     * dla `SendQueuedNotifications`), wartość to nazwa dla człowieka.
     * Ważność każdego z nich czyta `waznoscMinut()` — z konfiguracji,
     * nie stąd.
     *
     * @var array<class-string, string>
     */
    private const ZETONY = [
        UstawienieNowegoHasla::class => 'żeton resetu hasła',
        UstawienieHaslaZamiastLinku::class => 'żeton resetu hasła',
        LinkDoLogowania::class => 'żeton logowania linkiem',
    ];

    protected $signature = 'kuking:martwe-zadania
                            {--na-sucho : Tylko pokaż, co by zniknęło — TRYB DOMYŚLNY, nie trzeba go wpisywać}
                            {--skasuj : Naprawdę usuń wiersze z martwym żetonem (bez tego komenda niczego nie kasuje)}
                            {--ile=50 : Ile wierszy wypisać na liście do skasowania}';

    protected $description = 'Pokazuje, co stoi w `failed_jobs` i kogo dotyczy, i pozwala świadomie odrzucić zadania z martwym żetonem';

    public function handle(): int
    {
        $ile = max(1, (int) $this->option('ile'));
        $kasuje = (bool) $this->option('skasuj') && ! (bool) $this->option('na-sucho');

        $this->newLine();
        $this->line('<options=bold>Nieudane zadania kolejki — co tu stoi i co wolno odrzucić</>');
        $this->line('Czytam tabelę `failed_jobs`. Żetonów nie wypisuję nigdzie i pod żadną opcją.');
        $this->newLine();

        $odczyty = DB::table('failed_jobs')
            ->orderBy('failed_at')
            ->orderBy('id')
            ->get(['uuid', 'payload', 'failed_at'])
            ->map(fn (object $wiersz): array => $this->odczytaj($wiersz))
            ->all();

        if ($odczyty === []) {
            $this->info('Tabela `failed_jobs` jest PUSTA — nie ma czego pokazywać ani kasować.');
            $this->line('To znaczy też, że sprawdzenie `kolejka` w `/health` jest zielone.');
            $this->line('NIE znaczy, że każdy list doszedł — o tym mówi `php artisan kuking:nieudane-listy`.');

            return self::SUCCESS;
        }

        $this->wypiszCoStoi($odczyty);

        $martwe = array_values(array_filter($odczyty, fn (array $o): bool => $o['zeton'] !== null && $o['martwy']));
        $zywe = array_values(array_filter($odczyty, fn (array $o): bool => $o['zeton'] !== null && ! $o['martwy']));

        $this->wypiszZywe($zywe);

        if ($martwe === []) {
            $this->newLine();
            $this->info('Nie ma ani jednego zadania z MARTWYM żetonem — nie ma czego odrzucać.');
            $this->line('Wiersze powyżej zostają nietknięte. Jeśli `/health` ma przestać mówić `degraded`,');
            $this->line('decyzja o nich należy do właściciela: `queue:retry <uuid>` albo `queue:forget <uuid>`.');

            return self::SUCCESS;
        }

        $this->wypiszDoOdrzucenia($martwe, $ile);

        if (! $kasuje) {
            $this->wypiszTrybNaSucho((bool) $this->option('skasuj'));

            return self::SUCCESS;
        }

        return $this->skasuj($martwe);
    }

    /**
     * Tabela „co tu stoi": jeden wiersz na klasę zadania, ludzie liczeni
     * osobno od wierszy.
     *
     * @param  list<array{uuid: string, kiedy: ?Carbon, klasa: string, nazwa: string, osoby: list<string>, rozpoznano: bool, zeton: ?int, martwy: bool}>  $odczyty
     */
    private function wypiszCoStoi(array $odczyty): void
    {
        /** @var array<string, array{nazwa: string, wierszy: int, osoby: array<string, true>, nierozpoznane: int, od: ?Carbon, do: ?Carbon, zeton: ?int}> $grupy */
        $grupy = [];

        foreach ($odczyty as $odczyt) {
            $klucz = $odczyt['klasa'];

            $grupy[$klucz] ??= [
                'nazwa' => $odczyt['nazwa'],
                'wierszy' => 0,
                'osoby' => [],
                'nierozpoznane' => 0,
                'od' => null,
                'do' => null,
                'zeton' => $odczyt['zeton'],
            ];

            $grupy[$klucz]['wierszy']++;

            foreach ($odczyt['osoby'] as $osoba) {
                $grupy[$klucz]['osoby'][$osoba] = true;
            }

            if (! $odczyt['rozpoznano']) {
                $grupy[$klucz]['nierozpoznane']++;
            }

            $kiedy = $odczyt['kiedy'];

            if ($kiedy instanceof Carbon) {
                $grupy[$klucz]['od'] = $grupy[$klucz]['od'] === null || $kiedy->lt($grupy[$klucz]['od'])
                    ? $kiedy
                    : $grupy[$klucz]['od'];
                $grupy[$klucz]['do'] = $grupy[$klucz]['do'] === null || $kiedy->gt($grupy[$klucz]['do'])
                    ? $kiedy
                    : $grupy[$klucz]['do'];
            }
        }

        /** @var array<string, true> $wszyscyLudzie */
        $wszyscyLudzie = [];

        foreach ($odczyty as $odczyt) {
            foreach ($odczyt['osoby'] as $osoba) {
                $wszyscyLudzie[$osoba] = true;
            }
        }

        $this->line('Wierszy w `failed_jobs`: <options=bold>'.count($odczyty).'</>'
            .', różnych osób: <options=bold>'.count($wszyscyLudzie).'</>');
        $this->line('Osoba, do której list nie doszedł, klikała zwykle więcej niż raz — dlatego te dwie');
        $this->line('liczby różnią się i dlatego liczymy ludzi, a nie wiersze.');
        $this->newLine();

        $this->table(
            ['Co padło', 'Wierszy', 'Osób', 'Najstarsze (UTC)', 'Najnowsze (UTC)', 'Żeton'],
            array_map(fn (string $klasa, array $grupa): array => [
                $grupa['nazwa'],
                (string) $grupa['wierszy'],
                $this->komorkaOsob($grupa['osoby'], $grupa['nierozpoznane']),
                $grupa['od']?->format('Y-m-d H:i') ?? '—',
                $grupa['do']?->format('Y-m-d H:i') ?? '—',
                $grupa['zeton'] === null
                    ? '—'
                    : (self::ZETONY[$klasa] ?? 'żeton').', ważny '.$grupa['zeton'].' min',
            ], array_keys($grupy), array_values($grupy)),
        );
    }

    /**
     * @param  array<string, true>  $osoby
     */
    private function komorkaOsob(array $osoby, int $nierozpoznane): string
    {
        if ($nierozpoznane === 0) {
            return (string) count($osoby);
        }

        return count($osoby).' (+'.$nierozpoznane.' '.$this->wierszy($nierozpoznane).' bez rozpoznanej osoby)';
    }

    /**
     * Zadania z żetonem, który JESZCZE ŻYJE — tych nie kasujemy i mówimy
     * wprost, że tu `queue:retry` ma sens. Bez tej sekcji komenda
     * podpowiadałaby kasowanie także wtedy, gdy list da się jeszcze uratować.
     *
     * @param  list<array{uuid: string, kiedy: ?Carbon, klasa: string, nazwa: string, osoby: list<string>, rozpoznano: bool, zeton: ?int, martwy: bool}>  $zywe
     */
    private function wypiszZywe(array $zywe): void
    {
        if ($zywe === []) {
            return;
        }

        $this->newLine();
        $this->info('Zadań z ŻYWYM jeszcze żetonem: '.count($zywe).'. Tych ta komenda nie rusza.');
        $this->line('Tutaj ponowienie ma sens — link dojdzie, zanim wygaśnie:');
        $this->line('  php artisan queue:retry '.$zywe[0]['uuid']);
    }

    /**
     * @param  list<array{uuid: string, kiedy: ?Carbon, klasa: string, nazwa: string, osoby: list<string>, rozpoznano: bool, zeton: ?int, martwy: bool}>  $martwe
     */
    private function wypiszDoOdrzucenia(array $martwe, int $ile): void
    {
        /** @var array<string, true> $ludzie */
        $ludzie = [];

        foreach ($martwe as $odczyt) {
            foreach ($odczyt['osoby'] as $osoba) {
                $ludzie[$osoba] = true;
            }
        }

        $this->newLine();
        $this->line('<options=bold>DO ODRZUCENIA — żeton już nie żyje</>');
        $this->line('Wierszy: <options=bold>'.count($martwe).'</>, osób: <options=bold>'.count($ludzie).'</>.');
        $this->newLine();

        $this->table(
            ['Kiedy (UTC)', 'Co padło', 'Wiek', 'Żeton był ważny', 'Zadanie'],
            array_map(fn (array $o): array => [
                $o['kiedy']?->format('Y-m-d H:i') ?? '—',
                $o['nazwa'],
                $this->wiek($o['kiedy']),
                (string) $o['zeton'].' min',
                $o['uuid'],
            ], array_slice($martwe, 0, $ile)),
        );

        if (count($martwe) > $ile) {
            $this->line('…i jeszcze '.(count($martwe) - $ile).' — pełna lista: `--ile='.count($martwe).'`.');
            $this->newLine();
        }

        $this->warn('NIE URUCHAMIAJ NA NICH `php artisan queue:retry`.');
        $this->line('Link w tych listach jest ważny tyle minut, ile stoi w kolumnie wyżej, i liczy się');
        $this->line('od WYSTAWIENIA, a nie od wysłania. Ponowienie po dniach wyśle człowiekowi, który');
        $this->line('o nic dziś nie prosił, wiadomość o zmianie hasła z linkiem, który już nie działa.');
        $this->line('Dobra odpowiedź jest jedna: poproś te osoby, żeby jeszcze raz kliknęły');
        $this->line('„Nie pamiętam hasła”. Wtedy powstanie świeży żeton i świeży, żywy link.');
        $this->line('Kto to był — powie `php artisan kuking:kto-nie-dostal-listu`.');
    }

    private function wypiszTrybNaSucho(bool $bylSkasuj): void
    {
        $this->newLine();

        if ($bylSkasuj) {
            $this->warn('Podano `--skasuj` RAZEM z `--na-sucho`, więc NIC nie zostało skasowane.');
            $this->line('Przy dwóch sprzecznych intencjach wybieramy tę, którą da się cofnąć.');
            $this->line('Jeśli naprawdę chcesz skasować — uruchom jeszcze raz, bez `--na-sucho`.');

            return;
        }

        $this->warn('TRYB NA SUCHO — nie skasowano ANI JEDNEGO wiersza. To jest tryb domyślny.');
        $this->line('Gdy przeczytasz powyższe i wiesz, kogo to dotyczyło, potwierdź świadomie:');
        $this->line('  php artisan kuking:martwe-zadania --skasuj');
    }

    /**
     * @param  list<array{uuid: string, kiedy: ?Carbon, klasa: string, nazwa: string, osoby: list<string>, rozpoznano: bool, zeton: ?int, martwy: bool}>  $martwe
     */
    private function skasuj(array $martwe): int
    {
        // Kasujemy DOKŁADNIE te wiersze, które wypisaliśmy wyżej — po uuid,
        // nie po warunku na dacie. Warunek policzony drugi raz mógłby objąć
        // wiersz, który wjechał do tabeli w międzyczasie i którego człowiek
        // na oczy nie widział.
        $uuidy = array_map(fn (array $o): string => $o['uuid'], $martwe);

        $skasowanych = DB::table('failed_jobs')->whereIn('uuid', $uuidy)->delete();
        $zostalo = DB::table('failed_jobs')->count();

        $this->newLine();
        $this->info('Skasowanych wierszy: '.$skasowanych.'.');
        $this->line('Same listy nie zniknęły z niczyjej historii — w `failed_jobs` leżał tylko trup');
        $this->line('zadania kolejki. Zapis zdarzenia zostaje w `docs/infra/`, a ślad po odmowach');
        $this->line('dostawcy w `mail_failures` (`php artisan kuking:nieudane-listy`).');

        $this->newLine();

        if ($zostalo === 0) {
            $this->info('Tabela `failed_jobs` jest teraz PUSTA — `/health` przestanie mówić `degraded`');
            $this->line('przy następnym odpytaniu (sprawdzenie `kolejka`, powód `zadania_nieudane`).');

            return self::SUCCESS;
        }

        $this->warn('W tabeli zostaje jeszcze '.$zostalo.' '.$this->wierszy($zostalo).', więc `/health` NADAL będzie mówił `degraded`.');
        $this->line('To nie jest usterka tej komendy: zostawia ona wszystko, czego ponowienie ma jeszcze');
        $this->line('sens, i wszystko, co nie niesie żetonu. Zobacz, co to jest:');
        $this->line('  php artisan kuking:martwe-zadania');

        return self::SUCCESS;
    }

    /**
     * Jeden wiersz `failed_jobs` → to, co da się o nim powiedzieć BEZ
     * cytowania ładunku.
     *
     * Nic tu nie rzuca wyjątku na zewnątrz: wiersz, którego nie da się
     * przeczytać, wraca z `rozpoznano = false` i bez żetonu, czyli nigdy nie
     * wchodzi na listę do skasowania. Cisza byłaby tu gorsza od wpisu
     * „nie wiem": skoro nie wiadomo, co to jest, to nie wiadomo też, czy nie
     * jest to właśnie list, na który ktoś czeka.
     *
     * @return array{uuid: string, kiedy: ?Carbon, klasa: string, nazwa: string, osoby: list<string>, rozpoznano: bool, zeton: ?int, martwy: bool}
     */
    private function odczytaj(object $wiersz): array
    {
        $kiedy = $this->kiedy($wiersz);
        $uuid = $wiersz->uuid ?? null;

        $podstawa = [
            'uuid' => is_string($uuid) && $uuid !== '' ? $uuid : '— (brak uuid)',
            'kiedy' => $kiedy,
            'klasa' => '?',
            'nazwa' => '? (nie umiem odczytać tego wiersza)',
            'osoby' => [],
            'rozpoznano' => false,
            'zeton' => null,
            'martwy' => false,
        ];

        $surowy = $wiersz->payload ?? null;

        if (! is_string($surowy) || $surowy === '') {
            return $podstawa;
        }

        $payload = json_decode($surowy, true);

        if (! is_array($payload) || ! is_string($payload['displayName'] ?? null)) {
            return $podstawa;
        }

        /** @var string $klasa */
        $klasa = $payload['displayName'];

        $zeton = array_key_exists($klasa, self::ZETONY) ? $this->waznoscMinut($klasa) : null;

        $odczyt = [
            'klasa' => $klasa,
            'nazwa' => class_basename($klasa),
            'zeton' => $zeton,
            // WIEK LICZYMY OD `failed_at`, A ŻETON ŻYJE OD WYSTAWIENIA, czyli
            // od chwili WCZEŚNIEJSZEJ — zadanie musiało najpierw wejść do
            // kolejki i wyczerpać próby. Ten rachunek myli się więc zawsze na
            // stronę ostrożną: żeton, który tu wychodzi na martwy, był martwy
            // już trochę wcześniej. Odwrotnie pomylić się nie może.
            'martwy' => $zeton !== null && $kiedy instanceof Carbon && $kiedy->lte(now()->subMinutes($zeton)),
        ] + $podstawa;

        $serializowane = $payload['data']['command'] ?? null;

        if (($payload['data']['commandName'] ?? null) !== SendQueuedNotifications::class || ! is_string($serializowane)) {
            // Zadanie spoza powiadomień: klasę znamy, adresata nie ma.
            // `rozpoznano` zostaje `true`, bo nie ma tu nikogo do zgubienia.
            return ['rozpoznano' => true] + $odczyt;
        }

        try {
            $osoby = $this->osoby($serializowane);
        } catch (Throwable) {
            // Komunikat wyjątku NIE trafia nigdzie — w śladzie stosu Laravela
            // potrafią siedzieć argumenty wywołań, a jednym z nich jest żeton.
            return $odczyt;
        }

        return ['osoby' => $osoby, 'rozpoznano' => true] + $odczyt;
    }

    /**
     * Odbiorcy powiadomienia — jako identyfikatory kont, nie jako ludzie
     * z imienia i adresu. Imię i adres pokazuje `kuking:kto-nie-dostal-listu`;
     * tej komendzie potrzebna jest tylko LICZBA różnych osób.
     *
     * Jedyne miejsce, w którym ta komenda woła `unserialize()` — i woła je
     * z listą dozwolonych klas, patrz `WOLNO_ODTWORZYC`.
     *
     * @return list<string>
     */
    private function osoby(string $serializowane): array
    {
        // Szyfrowane zadanie z żetonem (audyt A5-10) — najpierw odszyfrowanie.
        $polecenie = @unserialize(PolecenieZadania::zserializowane($serializowane), ['allowed_classes' => self::WOLNO_ODTWORZYC]);

        if (! $polecenie instanceof SendQueuedNotifications) {
            return [];
        }

        $osoby = [];

        foreach ($polecenie->notifiables as $odbiorca) {
            if (! $odbiorca instanceof Model) {
                continue;
            }

            $osoby[] = $odbiorca::class.'#'.$odbiorca->getKey();
        }

        return array_values(array_unique($osoby));
    }

    /**
     * Ile minut żyje żeton tej klasy powiadomienia — Z KONFIGURACJI.
     */
    private function waznoscMinut(string $klasa): int
    {
        if ($klasa === LinkDoLogowania::class) {
            return max(1, (int) config('kuking.login_link.waznosc_minut', 30));
        }

        return max(1, (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60));
    }

    private function kiedy(object $wiersz): ?Carbon
    {
        $kiedy = $wiersz->failed_at ?? null;

        if (! is_string($kiedy) && ! $kiedy instanceof \DateTimeInterface) {
            return null;
        }

        try {
            return Carbon::parse($kiedy);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Odmiana słowa „wiersz" przez liczbę. Bez tego komenda pisze „zostaje
     * jeszcze 3 wierszy", a tekst, który potyka się o polszczyznę, czyta się
     * jak tekst pisany niedbale — także w tych miejscach, gdzie niesie
     * ostrzeżenie.
     */
    private function wierszy(int $ile): string
    {
        $reszta = $ile % 10;
        $setka = $ile % 100;

        return match (true) {
            $ile === 1 => 'wiersz',
            $reszta >= 2 && $reszta <= 4 && ($setka < 12 || $setka > 14) => 'wiersze',
            default => 'wierszy',
        };
    }

    /**
     * Wiek wiersza słowami, zaokrąglony W DÓŁ i bez ułamków. „2 dni" przy
     * dwóch dniach i dziewiętnastu godzinach mówi właścicielowi dokładnie to,
     * o co pyta — czy to stoi tu od dawna — i czyta się raz.
     */
    private function wiek(?Carbon $kiedy): string
    {
        if (! $kiedy instanceof Carbon) {
            return '—';
        }

        $minuty = (int) abs($kiedy->diffInMinutes(now()));

        if ($minuty < 60) {
            return $minuty.' min';
        }

        $godziny = intdiv($minuty, 60);

        if ($godziny < 48) {
            return $godziny.' godz.';
        }

        return intdiv($godziny, 24).' dni';
    }
}
