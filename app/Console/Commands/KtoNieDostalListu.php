<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kolejka\PolecenieZadania;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * `kuking:kto-nie-dostal-listu` — czyta `failed_jobs` i mówi, DO KOGO list
 * nie doszedł oraz kiedy. Nie kasuje niczego i nie wysyła niczego.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ISTNIEJE, SKORO SĄ JUŻ `queue:failed` I `kuking:nieudane-listy`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Te trzy komendy odpowiadają na trzy różne pytania i żadna nie zastępuje
 * pozostałych:
 *
 *  - `php artisan queue:failed` mówi, ŻE coś padło: uuid, klasa, kolejka,
 *    godzina. Nie rozpakowuje ładunku, więc o człowieku po drugiej stronie
 *    nie mówi ani słowa;
 *  - `kuking:nieudane-listy` czyta `mail_failures` — trwały ślad zakładany
 *    od 10 września 2026 (issue #234, D-062) przez `App\Poczta\ZapiszNieudanyList`.
 *    Wierszy STARSZYCH niż ten mechanizm w tej tabeli NIE MA i nigdy nie będzie;
 *  - ta komenda czyta `failed_jobs`, czyli jedyne miejsce, w którym został
 *    ślad po zdarzeniu z 9 września 2026 — cztery listy „Ustaw nowe hasło",
 *    które nie wyszły, bo Railway blokuje SMTP na planach Free, Trial i Hobby
 *    (`App\Poczta\TransportEmailLabs`, D-047). Pełny zapis zdarzenia:
 *    `docs/infra/ZDARZENIE_2026-09-09_NIEWYSLANE_HASLA.md`.
 *
 * Różnica jest praktyczna, nie kosmetyczna. `queue:failed` odpowiada na
 * pytanie „czy kolejka jest zdrowa". Właściciel po awarii poczty ma inne
 * pytanie: „KTO na ten list czekał i nie dostał go do dziś".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TA KOMENDA TYLKO CZYTA — I TO JEST JEJ GŁÓWNA WŁAŚCIWOŚĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ani jednego `INSERT`, `UPDATE`, `DELETE`, ani jednego wysłanego listu,
 * ani jednego ponowionego zadania. Nie ma przełącznika, który by to zmieniał,
 * i nie wolno go dokładać: decyzja o `queue:flush` albo `queue:forget` należy
 * do właściciela i zapada PO tym, jak zobaczy, kogo to dotyczyło. Komenda,
 * która przy okazji sprząta, zabiera tę decyzję razem z dowodem.
 *
 * Kończy się zawsze kodem 0, także gdy coś znajdzie. Kod niezerowy znaczyłby
 * „komenda się nie udała", a ona udaje się również wtedy — a zwłaszcza
 * wtedy — gdy ma coś do pokazania.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TA KOMENDA NIE POKAZUJE NIGDY: TOKENU RESETU HASŁA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Zmierzone, nie założone (sonda na prawdziwym ładunku z kolejki `database`):
 * w `failed_jobs.payload` klucz `data.command` to zserializowany
 * `Illuminate\Notifications\SendQueuedNotifications`, a w nim OBIEKT
 * powiadomienia z polem `token` **w jawnej postaci**:
 *
 *     s:12:"notification";O:39:"App\Notifications\UstawienieNowegoHasla":2:
 *         {s:5:"token";s:22:"…";s:2:"id";s:36:"…";}
 *
 * Kto ma ten token, ten ustawia komuś hasło. Dlatego:
 *
 *  1. **surowy `payload` nie trafia na ekran w ŻADNEJ gałęzi** — także
 *     w tej, w której komenda nie umie wiersza odczytać. To jest gałąź
 *     najgroźniejsza, bo „nie wiem, co to jest, więc wypiszę, co mam"
 *     jest odruchem, który dokładnie ten sekret by wyniósł;
 *  2. kolumna `exception` też nie trafia na ekran — niesie ślad stosu,
 *     a ten w Laravelu potrafi nieść argumenty wywołań;
 *  3. `unserialize()` dostaje **listę dozwolonych klas** (`WOLNO_ODTWORZYC`),
 *     na której klas powiadomień NIE MA. Obiekt `UstawienieNowegoHasla`
 *     więc w ogóle nie powstaje — wraca jako `__PHP_Incomplete_Class`,
 *     z której nie da się przeczytać właściwości. To jest druga bariera,
 *     niezależna od dyscypliny drukowania: nawet `$cmd->notification->token`
 *     dopisane tu kiedyś przez pomyłkę nie zwróci tokenu, tylko błąd.
 *     Przy okazji: żadna klasa z ładunku nie dostaje swojego `__wakeup()`.
 *
 * Pilnuje tego `tests/Feature/KtoNieDostalListuTest.php` — z kontrolą
 * dodatnią, czyli asercją, że token NAPRAWDĘ jest w ładunku. Bez niej
 * „nie ma tokenu na ekranie" przechodziłoby także wtedy, gdyby tokenu nie
 * było nigdzie (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ADRESY E-MAIL SĄ DOMYŚLNIE SKRACANE — UZASADNIENIE WYBORU
 * ────────────────────────────────────────────────────────────────────────
 *
 * Adres e-mail to dana osobowa, a ta komenda ma jedno naturalne miejsce
 * uruchomienia: konsola Railwaya. Zawartość tej konsoli ląduje na zrzutach
 * ekranu — w zgłoszeniu do pomocy technicznej, w wiadomości do znajomego
 * programisty, w opisie issue. Zrzut z pełną listą adresów jest wtedy
 * wyciekiem, którego nikt nie zauważy, bo wygląda jak zwykły log.
 *
 * Dlatego DOMYŚLNIE wypisujemy `m***@przyklad.pl`, a pełny adres wymaga
 * jawnego `--pelne-adresy`. Rozstrzygnięcie jest takie, a nie odwrotne, bo
 * skracanie nie zabiera właścicielowi niczego, czego naprawdę potrzebuje:
 * w tej samej tabeli stoi identyfikator konta i nazwa z profilu, czyli
 * wszystko, czego trzeba, żeby konto odnaleźć w panelu i tam odczytać adres.
 * Pełny adres jest potrzebny do JEDNEJ czynności — napisania do tej osoby —
 * i ta czynność zasługuje na świadome wpisanie przełącznika.
 *
 * CO ZOSTAJE WIDOCZNE I DLACZEGO: domena. `m***@gmail.com` nie pozwala
 * napisać do nikogo ani potwierdzić, że dana osoba ma u nas konto, a mówi
 * to, co przy awarii poczty bywa całą diagnozą — czy odmowy nie skupiają
 * się na jednym dostawcy. Reszta adresu, czyli część identyfikująca
 * człowieka, znika. Granica tego kompromisu jest jedna i warto ją znać:
 * przy adresie we własnej, rzadkiej domenie sama domena zawęża krąg osób.
 * Zrzut ekranu z tej komendy nadal nie jest więc dokumentem publicznym —
 * jest tylko o rząd wielkości mniej groźny niż zrzut z pełnymi adresami.
 *
 * ADRES POCHODZI Z KONTA, NIE Z ŁADUNKU — i to jest ważne przy czytaniu
 * wyniku. Zmierzone: `payload` nie zawiera adresu e-mail wcale, tylko
 * `ModelIdentifier` z identyfikatorem konta. Komenda dociąga więc adres
 * AKTUALNY. Jeśli ktoś zmienił adres po awarii, zobaczysz ten nowy; jeśli
 * konto zostało usunięte albo zanonimizowane (D-022), nie zobaczysz żadnego
 * i komenda powie to wprost, zamiast wypisać pustą komórkę.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE WYWALA SIĘ NA NIETYPOWYM WIERSZU
 * ────────────────────────────────────────────────────────────────────────
 *
 * `failed_jobs` to tabela, do której trafia KAŻDE zadanie, jakie kiedykolwiek
 * padło — także zadania z wersji aplikacji sprzed roku, po zmianie nazwy
 * klasy, po zmianie formatu ładunku przez sam framework. Wyjątek na trzecim
 * wierszu z siedmiu ukryłby cztery pozostałe, czyli dokładnie te, po które
 * ktoś tę komendę uruchomił.
 *
 * Każdy wiersz jest więc czytany osobno, w `try`, a niepowodzenie kończy się
 * WPISEM NA LIŚCIE „tego nie umiem odczytać" z uuid zadania i jednozdaniowym
 * powodem — nie ciszą i nie surowym ładunkiem. Wiersz nieczytelny jest też
 * jedynym, którego NIE UKRYWA filtr klas: skoro nie wiadomo, co to jest, to
 * nie wiadomo też, czy to nie jest właśnie list, którego szukasz.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO FILTR KLAS DOMYŚLNIE POKAZUJE SAME POWIADOMIENIA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo pytanie brzmi „do kogo nie doszedł list", a listy chodzą u nas
 * powiadomieniami (`SendQueuedNotifications`). Przetwarzanie zdjęcia ani
 * eksport danych nie mają adresata, którego dałoby się wypisać w tej samej
 * tabeli — wyszłyby jako wiersze z pięcioma kreskami i rozmyły odpowiedź.
 *
 * Filtr jest jednak GŁOŚNY: komenda ZAWSZE mówi, ile wierszy jest w tabeli
 * w ogóle i ile z nich schowała. Cichy filtr byłby tu gorszy od braku filtru,
 * bo pozwoliłby wyjść z konsoli z przekonaniem, że w `failed_jobs` stoją
 * cztery wiersze, podczas gdy stoi ich czterdzieści — a to właśnie liczba
 * WSZYSTKICH wierszy trzyma `/health` na `degraded`
 * (`HealthController::sprawdzKolejke()`). `--wszystkie` pokazuje resztę.
 */
class KtoNieDostalListu extends Command
{
    /**
     * Klasy, które wolno odtworzyć z ładunku. Wszystko spoza tej listy wraca
     * jako `__PHP_Incomplete_Class` — patrz punkt 3 w nagłówku klasy.
     *
     * `SendQueuedNotifications` musi tu być, bo to on niesie odbiorców.
     * `ModelIdentifier` i kolekcja Eloquenta — bo tak Laravel zapisuje modele
     * w kolejce (`SerializesModels`) i bez nich odbiorcy nie odtworzą się
     * wcale. Klas powiadomień na tej liście nie ma i nie wolno ich dopisywać.
     *
     * @var list<class-string>
     */
    private const WOLNO_ODTWORZYC = [
        SendQueuedNotifications::class,
        ModelIdentifier::class,
        EloquentCollection::class,
    ];

    /**
     * Tak Laravel oznacza w ładunku zadanie, które jest wysyłką powiadomienia.
     */
    private const ZADANIE_POWIADOMIENIA = SendQueuedNotifications::class;

    protected $signature = 'kuking:kto-nie-dostal-listu
                            {--wszystkie : Pokaż także zadania, które nie są powiadomieniami}
                            {--pelne-adresy : Wypisz pełne adresy e-mail zamiast skróconych (nie rób tego przy zrzucie ekranu)}
                            {--ile=50 : Ile najświeższych wierszy wypisać}';

    protected $description = 'Czyta `failed_jobs` i mówi, do kogo nie doszedł list i kiedy. Niczego nie kasuje i nie wysyła.';

    public function handle(): int
    {
        $ile = max(1, (int) $this->option('ile'));
        $wszystkieKlasy = (bool) $this->option('wszystkie');
        $pelneAdresy = (bool) $this->option('pelne-adresy');

        $this->newLine();
        $this->line('<options=bold>Listy, które nie doszły do ludzi</>');
        $this->line('Czytam tabelę `failed_jobs`. Ta komenda niczego nie kasuje, nie wysyła i nie ponawia.');
        $this->newLine();

        $wszystkichWTabeli = DB::table('failed_jobs')->count();

        if ($wszystkichWTabeli === 0) {
            $this->info('Tabela `failed_jobs` jest PUSTA — żadne zadanie nie czeka tu na wyjaśnienie.');
            $this->line('To NIE znaczy, że każdy list doszedł do skrzynki. Znaczy tylko tyle, że żadne');
            $this->line('zadanie nie zakończyło się porażką i nie zostało po nim wiersza. O listach,');
            $this->line('które dostawca przyjął i odłożył do „Spamu”, mówi panel EmailLabs, nie ta komenda.');
            $this->line('O listach odrzuconych przez dostawcę mówi `php artisan kuking:nieudane-listy`.');

            return self::SUCCESS;
        }

        $odczyty = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit($ile)
            ->get(['uuid', 'connection', 'queue', 'payload', 'failed_at'])
            ->map(fn (object $wiersz): array => $this->odczytaj($wiersz))
            ->all();

        $powiadomienia = array_values(array_filter($odczyty, fn (array $o): bool => $o['rodzaj'] === 'powiadomienie'));
        $inne = array_values(array_filter($odczyty, fn (array $o): bool => $o['rodzaj'] === 'inne'));
        $nieczytelne = array_values(array_filter($odczyty, fn (array $o): bool => $o['rodzaj'] === 'nieczytelny'));

        $this->line('Wierszy w `failed_jobs`: <options=bold>'.$wszystkichWTabeli.'</>'
            .($wszystkichWTabeli > $ile ? ' (czytam '.$ile.' najświeższych — więcej: `--ile=N`)' : ''));

        $doPokazania = $wszystkieKlasy
            ? array_merge($powiadomienia, $inne, $nieczytelne)
            : array_merge($powiadomienia, $nieczytelne);

        if (! $wszystkieKlasy && $inne !== []) {
            $this->line('Zadań, które nie są powiadomieniami: <options=bold>'.count($inne).'</>'
                .' — schowane. Pokaże je `--wszystkie`.');
        }

        $this->newLine();

        if ($doPokazania === []) {
            $this->warn('Wśród przeczytanych wierszy nie ma ani jednego powiadomienia.');
            $this->line('Wszystkie to inne zadania. Zobacz je poleceniem:');
            $this->line('  php artisan kuking:kto-nie-dostal-listu --wszystkie');

            return self::SUCCESS;
        }

        $this->table(
            ['Kiedy (UTC)', 'Co miało pójść', 'Do kogo', 'Adres', 'Konto', 'Zadanie'],
            array_map(fn (array $o): array => [
                $o['kiedy'],
                $o['klasa'],
                $o['nazwa'],
                $this->kolumnaAdresu($o, $pelneAdresy),
                $o['konta'],
                $o['uuid'],
            ], $doPokazania),
        );

        $this->wypiszNieczytelne($nieczytelne);
        $this->wypiszCoDalej($powiadomienia, $pelneAdresy);

        return self::SUCCESS;
    }

    /**
     * Jeden wiersz `failed_jobs` → to, co da się o nim powiedzieć.
     *
     * Nic tu nie rzuca wyjątku na zewnątrz: wiersz, którego nie da się
     * przeczytać, wraca jako `rodzaj = nieczytelny` z powodem po polsku.
     * Powód opisuje, CZEGO zabrakło — nigdy nie cytuje ładunku.
     *
     * @return array{rodzaj: string, uuid: string, kiedy: string, klasa: string, nazwa: string, adresy: list<string>, konta: string, powod: string}
     */
    private function odczytaj(object $wiersz): array
    {
        /** @var string $uuid */
        $uuid = $wiersz->uuid ?? '— (brak uuid)';

        $podstawa = [
            'rodzaj' => 'nieczytelny',
            'uuid' => (string) $uuid,
            'kiedy' => $this->kiedy($wiersz),
            'klasa' => '?',
            'nazwa' => '—',
            'adresy' => [],
            'konta' => '—',
            'powod' => '',
        ];

        $surowy = $wiersz->payload ?? null;

        if (! is_string($surowy) || $surowy === '') {
            return ['powod' => 'kolumna `payload` jest pusta'] + $podstawa;
        }

        $payload = json_decode($surowy, true);

        if (! is_array($payload)) {
            return ['powod' => 'ładunek nie jest poprawnym JSON-em'] + $podstawa;
        }

        $klasa = is_string($payload['displayName'] ?? null) ? class_basename($payload['displayName']) : '?';
        $polecenie = $payload['data']['commandName'] ?? null;

        if (! is_string($polecenie)) {
            return ['powod' => 'ładunek nie ma klucza `data.commandName`', 'klasa' => $klasa] + $podstawa;
        }

        if ($polecenie !== self::ZADANIE_POWIADOMIENIA) {
            return [
                'rodzaj' => 'inne',
                'klasa' => $klasa,
            ] + $podstawa;
        }

        $serializowane = $payload['data']['command'] ?? null;

        if (! is_string($serializowane)) {
            return ['powod' => 'ładunek nie ma klucza `data.command`', 'klasa' => $klasa] + $podstawa;
        }

        try {
            [$nazwy, $adresy, $konta] = $this->odbiorcy($serializowane);
        } catch (Throwable $e) {
            return [
                'powod' => 'nie umiem rozpakować odbiorców ('.class_basename($e).')',
                'klasa' => $klasa,
            ] + $podstawa;
        }

        return [
            'rodzaj' => 'powiadomienie',
            'klasa' => $klasa,
            'nazwa' => $nazwy === [] ? '— (konta już nie ma)' : implode(', ', $nazwy),
            'adresy' => $adresy,
            'konta' => $konta === [] ? '— (konta już nie ma)' : implode(', ', $konta),
        ] + $podstawa;
    }

    /**
     * Odbiorcy powiadomienia z zserializowanego `SendQueuedNotifications`.
     *
     * Jedyne miejsce, w którym ta komenda woła `unserialize()` — i woła je
     * z listą dozwolonych klas, patrz `WOLNO_ODTWORZYC`.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} nazwy, adresy, identyfikatory kont
     */
    private function odbiorcy(string $serializowane): array
    {
        // Szyfrowane zadanie z żetonem (audyt A5-10) — najpierw odszyfrowanie.
        $polecenie = @unserialize(PolecenieZadania::zserializowane($serializowane), ['allowed_classes' => self::WOLNO_ODTWORZYC]);

        if (! $polecenie instanceof SendQueuedNotifications) {
            throw new RuntimeException('ładunek nie jest wysyłką powiadomienia');
        }

        $nazwy = [];
        $adresy = [];
        $konta = [];

        foreach ($polecenie->notifiables as $odbiorca) {
            if (! $odbiorca instanceof Model) {
                // Powiadomienie „na adres", bez konta (`Notification::route()`).
                // Adresu z niego nie wyciągamy: obiekt jest poza listą klas,
                // które wolno odtworzyć, więc nie ma czego czytać.
                $nazwy[] = '— (odbiorca spoza kont)';

                continue;
            }

            $konta[] = (string) $odbiorca->getKey();

            $nazwa = $odbiorca->profile?->display_name ?? null;
            $nazwy[] = is_string($nazwa) && $nazwa !== '' ? $nazwa : '— (bez nazwy w profilu)';

            $adres = $odbiorca->getAttribute('email');
            if (is_string($adres) && $adres !== '') {
                $adresy[] = $adres;
            }
        }

        return [$nazwy, $adresy, $konta];
    }

    /**
     * Komórka „Adres" — inna dla każdego z trzech rodzajów wiersza.
     *
     * @param  array{rodzaj: string, adresy: list<string>}  $odczyt
     */
    private function kolumnaAdresu(array $odczyt, bool $pelne): string
    {
        return match ($odczyt['rodzaj']) {
            'powiadomienie' => $this->adresy($odczyt['adresy'], $pelne),
            'inne' => '— (to nie jest powiadomienie)',
            default => '—',
        };
    }

    /**
     * @param  list<string>  $adresy
     */
    private function adresy(array $adresy, bool $pelne): string
    {
        if ($adresy === []) {
            return '— (konto bez adresu albo już usunięte)';
        }

        return implode(', ', array_map(
            fn (string $adres): string => $pelne ? $adres : $this->skroc($adres),
            $adresy,
        ));
    }

    /**
     * `maria.kowalska@przyklad.pl` → `m***@przyklad.pl`.
     *
     * Znika część identyfikująca człowieka, zostaje dostawca. Uzasadnienie
     * tego cięcia (a nie ostrzejszego i nie łagodniejszego) — w nagłówku klasy.
     */
    private function skroc(string $adres): string
    {
        $malpa = mb_strrpos($adres, '@');

        if ($malpa === false || $malpa === 0) {
            // Nie wygląda na adres — nie zgadujemy, gdzie go przeciąć.
            return '***';
        }

        return mb_substr($adres, 0, 1).'***@'.mb_substr($adres, $malpa + 1);
    }

    private function kiedy(object $wiersz): string
    {
        $kiedy = $wiersz->failed_at ?? null;

        if (! is_string($kiedy) && ! $kiedy instanceof \DateTimeInterface) {
            return '—';
        }

        try {
            return Carbon::parse($kiedy)->format('Y-m-d H:i');
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * @param  list<array{rodzaj: string, uuid: string, kiedy: string, klasa: string, nazwa: string, adresy: list<string>, konta: string, powod: string}>  $nieczytelne
     */
    private function wypiszNieczytelne(array $nieczytelne): void
    {
        if ($nieczytelne === []) {
            return;
        }

        $this->newLine();
        $this->warn('Wierszy, których nie umiem odczytać: '.count($nieczytelne).'.');
        $this->line('Wypisuję je, zamiast pomijać — bo skoro nie wiem, co to jest, to nie wiem też,');
        $this->line('czy nie jest to właśnie list, którego szukasz. Surowego ładunku NIE pokazuję:');
        $this->line('w powiadomieniu o haśle siedzi w nim token, którym da się ustawić komuś hasło.');
        $this->newLine();

        $this->table(
            ['Kiedy (UTC)', 'Klasa', 'Zadanie', 'Czego zabrakło'],
            array_map(fn (array $o): array => [
                $o['kiedy'],
                $o['klasa'],
                $o['uuid'],
                $o['powod'],
            ], $nieczytelne),
        );
    }

    /**
     * @param  list<array{rodzaj: string, uuid: string, kiedy: string, klasa: string, nazwa: string, adresy: list<string>, konta: string, powod: string}>  $powiadomienia
     */
    private function wypiszCoDalej(array $powiadomienia, bool $pelneAdresy): void
    {
        if ($powiadomienia === []) {
            return;
        }

        $haslowe = array_values(array_filter(
            $powiadomienia,
            fn (array $o): bool => $o['klasa'] === class_basename(UstawienieNowegoHasla::class),
        ));

        $this->newLine();
        $this->line('<options=bold>CO Z TYM ZROBIĆ</>');

        if ($haslowe !== []) {
            $minut = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            $this->newLine();
            $this->warn('Listów „Ustaw nowe hasło” wśród powyższych: '.count($haslowe).'.');
            $this->line('NIE URUCHAMIAJ NA NICH `php artisan queue:retry`. Link w tym liście jest ważny');
            $this->line($minut.' min. od WYSTAWIENIA (`config/auth.php`), a nie od wysłania. Ponowienie po dniach');
            $this->line('wyśle człowiekowi list z linkiem, który jest już martwy — czyli gorzej niż cisza:');
            $this->line('osoba, która o nic dziś nie prosiła, dostanie wiadomość o zmianie hasła, kliknie');
            $this->line('i zobaczy błąd. Dobra odpowiedź jest jedna: poproś te osoby, żeby jeszcze raz');
            $this->line('kliknęły „Nie pamiętam hasła”. Wtedy powstanie świeży token i świeży, żywy link.');
        }

        $this->newLine();
        $this->line('Nic z tego, co wyżej, nie zostało skasowane ani zmienione — ta komenda tylko czyta.');
        $this->line('Dopóki te wiersze stoją w `failed_jobs`, `/health` będzie mówił `degraded`');
        $this->line('(`HealthController::sprawdzKolejke()` liczy WSZYSTKIE wiersze tej tabeli).');
        $this->line('Kasowanie (`queue:forget <uuid>`, `queue:flush`) to decyzja właściciela i ma zapaść');
        $this->line('PO tym, jak te osoby dostaną odpowiedź — nie przy okazji czytania tej listy.');

        if (! $pelneAdresy) {
            $this->newLine();
            $this->line('Adresy są skrócone, żeby zrzut ekranu z konsoli nie był wyciekiem.');
            $this->line('Pełne — gdy naprawdę piszesz do tych osób, i nie przy świadkach:');
            $this->line('  php artisan kuking:kto-nie-dostal-listu --pelne-adresy');
        }

        $this->newLine();
        $this->line('Zapis zdarzenia z 9 września 2026: `docs/infra/ZDARZENIE_2026-09-09_NIEWYSLANE_HASLA.md`.');
    }
}
