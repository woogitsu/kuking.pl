<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Egzekutor 30-dniowej karencji po zgłoszeniu usunięcia konta (audyt A8).
 *
 * PROBLEM, KTÓRY TO ZAMYKA
 * `docs/legal/COMPLIANCE.md` obiecuje trwałe usunięcie/anonimizację danych
 * po karencji, a `DataSettingsController` obiecuje to samo wprost człowiekowi
 * na ekranie. Bez tej komendy nic nigdy tej obietnicy nie egzekwowało — konto
 * zostawało `pending_delete` na zawsze, czyli albo dane leżą w nieskończoność
 * (problem prawny, nie kosmetyczny), albo ktoś musiałby to robić ręcznie
 * w psql, co przy dwuosobowym zespole (D-012) po prostu by się nie działo.
 *
 * Sama anonimizacja mieszka w `EraseAccountData` (Domain/Users) — ta komenda
 * tylko WYBIERA komu minęła karencja i RAPORTUJE wynik. Podział jest celowy:
 * logikę „co znaczy usunąć dane" da się przetestować bez harmonogramu,
 * a komendę — bez wchodzenia w szczegóły anonimizacji.
 *
 * IDEMPOTENCJA: `EraseAccountData::handle()` sam sprawdza pod blokadą, czy
 * konto nie zostało już obsłużone (albo cofnięte w międzyczasie) i w takim
 * razie nic nie robi — dwa uruchomienia obok siebie albo jedno uruchomione
 * dwa razy nie psują nic ani nie liczą się podwójnie w audycie.
 *
 * ODPORNOŚĆ NA PRZERWANIE: każde konto jest osobną transakcją (patrz
 * `EraseAccountData`). Przerwanie w połowie listy zostawia już obsłużone
 * konta w pełni anonimowe, a resztę — nietkniętą i do podjęcia przy
 * następnym uruchomieniu (harmonogram, `routes/console.php`, chodzi codziennie).
 *
 * `Schedule::call()`, nie `Schedule::command()` — `proc_open` jest wyłączony
 * w `docker/php.ini` (patrz uzasadnienie przy innych zadaniach w
 * `routes/console.php`).
 *
 * DRUGA KOLEJKA: KONTA Z NIESKASOWANYMI ZDJĘCIAMI (audyt/issue #17).
 * Anonimizacja i kasowanie zdjęć to DWA ODDZIELNE kroki w `EraseAccountData`
 * — pierwszy w transakcji, drugi poza nią, bo kasowanie pliku jest
 * nieodwracalne przy wycofaniu. Awaria storage w połowie drugiego kroku
 * (jeden wariant z kilku, jeden dysk z kilku) zostawia więc konto już
 * zanonimizowane, ale z zdjęciami, których nie dało się skasować. Bez osobnej
 * kolejki takie konto nigdy więcej nie trafiłoby do tej komendy — warunek na
 * pierwszą kolejkę wymaga `data_erased_at IS NULL`, a tu ta kolumna jest już
 * ustawiona. Druga kolejka (`$doPonowienia`) wybiera właśnie te konta po tym,
 * że wciąż mają wiersze w `media`, i każe `EraseAccountData::handle()`
 * dokończyć wyłącznie kasowanie plików.
 *
 * PARTIE I BUDŻET PRZEBIEGU (issue #1028). Każda z dwóch kolejek bierze
 * najwyżej `--limit` kont na jedno uruchomienie (domyślnie
 * `BUDZET_PRZEBIEGU`), najstarsze zgłoszenia najpierw, i wczytuje je
 * partiami po `$rozmiarPartii`. Wcześniej cały backlog szedł do pamięci
 * jednym `get()`, a przy dłuższym zatorze przebieg nie kończył się przed
 * następnym — po cichu, bo harmonogram woła komendę przez `Artisan::call()`
 * i jej wyjście nigdzie nie trafia. Dlatego to, co zostało na następną noc,
 * idzie OSTRZEŻENIEM do dziennika serwera, a nie tylko na ekran.
 */
class PurgeExpiredAccountDeletions extends Command
{
    /**
     * Ile kont z JEDNEJ kolejki obsługuje jedno uruchomienie. Wymazanie
     * konta to transakcja plus kasowanie plików w storage — kilkaset na noc
     * mieści się w oknie harmonogramu z dużym zapasem.
     */
    public const BUDZET_PRZEBIEGU = 500;

    /** Domyślny rozmiar partii wczytywanej naraz do pamięci. */
    public const ROZMIAR_PARTII = 50;

    protected $signature = 'kuking:usun-wygasle-konta
                            {--dry-run : Pokaż, którym kontom minęła karencja, i nic nie zmieniaj}
                            {--limit= : Najwięcej kont z jednej kolejki w tym uruchomieniu (domyślnie '.self::BUDZET_PRZEBIEGU.')}';

    protected $description = 'Trwale usuwa/anonimizuje dane kont, którym minęła 30-dniowa karencja po zgłoszeniu usunięcia';

    public function __construct(
        private readonly EraseAccountData $usunDane,
        private readonly int $rozmiarPartii = self::ROZMIAR_PARTII,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $budzet = $this->option('limit') !== null
            ? max(1, (int) $this->option('limit'))
            : self::BUDZET_PRZEBIEGU;
        $graceDays = (int) config('kuking.account.delete_grace_days');
        $termin = now()->subDays($graceDays);

        $doWykonania = fn (): Builder => User::query()
            ->where('status', User::STATUS_PENDING_DELETE)
            ->whereNull('data_erased_at')
            ->whereNotNull('delete_requested_at')
            ->where('delete_requested_at', '<=', $termin)
            ->orderBy('delete_requested_at')
            ->orderBy('id');

        // PONOWIENIE (audyt/issue #17): konta już zanonimizowane
        // (`data_erased_at` ustawione), którym mimo to zostały nieskasowane
        // zdjęcia — bo poprzednie uruchomienie padło w połowie kasowania
        // plików jednego z nich (`EraseAccountData::dokonczKasowanieZdjec()`
        // zostawia wtedy wiersz `media` celowo, jako ślad do dokończenia).
        //
        // BEZ TEGO te konta nie kwalifikowałyby się już NIGDY WIĘCEJ do
        // żadnego przebiegu tej komendy: warunek wyżej wymaga
        // `whereNull('data_erased_at')`, a tu ta kolumna jest już ustawiona.
        // Zdjęcie z pełnym, nietkniętym EXIF-em zostawałoby więc dostępne
        // bezterminowo, mimo potwierdzenia usunięcia danych.
        $doPonowienia = fn (): Builder => User::query()
            ->whereNotNull('data_erased_at')
            ->whereHas('media')
            ->orderBy('data_erased_at')
            ->orderBy('id');

        $ileDoWykonania = $doWykonania()->count();
        $ileDoPonowienia = $doPonowienia()->count();

        if ($ileDoWykonania === 0 && $ileDoPonowienia === 0) {
            $this->info('Nie ma kont, którym minęła karencja na usunięcie.');

            return self::SUCCESS;
        }

        $usuniete = 0;
        $dokonczone = 0;

        foreach ($this->partiami($doWykonania, $budzet) as $user) {
            if ($dryRun) {
                $this->line("[dry-run] {$user->getKey()} — zgłoszono ".$user->delete_requested_at->format('Y-m-d H:i'));

                continue;
            }

            $wykonano = $this->usunDane->handle($user);

            if (! $wykonano) {
                // Ktoś cofnął usunięcie albo inny proces już to obsłużył
                // między SELECT-em wyżej a tym wywołaniem — nie błąd, tylko
                // wyścig, którego `EraseAccountData` sam pilnuje.
                $this->line("Pominięto (obsłużone w międzyczasie): {$user->getKey()}");

                continue;
            }

            $usuniete++;

            // Wpis do audytu z aktorem `null` — decyzję podjął zegar, nie
            // moderator ani sam użytkownik, tak samo jak przy wygasłych
            // zawieszeniach (`kuking:zdejmij-wygasle-kary`).
            // Zakres w metadanych: „co dokładnie zrobiliśmy temu kontu" musi
            // dać się odczytać po fakcie, bez odtwarzania decyzji z pamięci
            // (D-022). `delete_scope` czytamy ze ŚWIEŻEGO wiersza — akcja
            // domenowa pracuje na własnym odczycie pod blokadą.
            AuditLogEntry::record('account.data_erased', null, $user, metadata: [
                'zakres' => $user->fresh()?->delete_scope,
            ]);

            $this->line("Usunięto dane konta: {$user->getKey()}");
        }

        foreach ($this->partiami($doPonowienia, $budzet) as $user) {
            if ($dryRun) {
                $this->line("[dry-run, ponowienie] {$user->getKey()} — zostały nieskasowane zdjęcia z poprzedniej próby");

                continue;
            }

            // `handle()` na koncie z `data_erased_at` już ustawionym trafia
            // w gałąź ponowienia w `EraseAccountData` — dokańcza WYŁĄCZNIE
            // kasowanie plików, nic więcej w koncie nie zmienia. Dane osobowe
            // zostały już wymazane i zaudytowane przy poprzednim, udanym
            // przebiegu — nie zapisujemy tu drugiego wpisu `account.data_erased`
            // dla tego samego zdarzenia prawnego, żeby audyt nie sugerował
            // dwóch osobnych decyzji tam, gdzie była jedna.
            if ($this->usunDane->handle($user)) {
                $dokonczone++;
                $this->line("Dokończono kasowanie zdjęć konta: {$user->getKey()}");
            } else {
                $this->line("Nadal nie udało się skasować wszystkich zdjęć konta: {$user->getKey()} (spróbuję ponownie)");
            }
        }

        if ($dryRun) {
            $this->info('Kont z minioną karencją: '.$ileDoWykonania
                .', do ponowienia (nieskasowane zdjęcia): '.$ileDoPonowienia.' (nic nie zmieniono).'
                .($ileDoWykonania > $budzet || $ileDoPonowienia > $budzet
                    ? ' Jedno uruchomienie obsłuży najwyżej '.$budzet.' kont z każdej kolejki.'
                    : ''));

            return self::SUCCESS;
        }

        $this->info('Usunięto dane kont: '.$usuniete.'. Dokończono kasowanie zdjęć: '.$dokonczone.'.');

        // Ile zostało na następny przebieg — liczone od nowa, bo wyścigi
        // i nieudane kasowanie plików zmieniają obraz w trakcie.
        $zostaloDoWykonania = $doWykonania()->count();
        $zostaloDoPonowienia = $doPonowienia()->count();

        if ($zostaloDoWykonania + $zostaloDoPonowienia > 0) {
            $this->warn('Zostaje na następny przebieg: kont do wymazania '.$zostaloDoWykonania
                .', kont z nieskasowanymi zdjęciami '.$zostaloDoPonowienia.'.');

            Log::warning('Wymazywanie kont po karencji: część kont czeka na następny przebieg', [
                'zostalo_do_wymazania' => $zostaloDoWykonania,
                'zostalo_do_ponowienia_zdjec' => $zostaloDoPonowienia,
                'budzet_przebiegu_na_kolejke' => $budzet,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Najwyżej `$budzet` kont z kolejki, najstarsze najpierw, wczytywane
     * partiami po `$rozmiarPartii`.
     *
     * Najpierw same identyfikatory (budżet ogranicza ich liczbę), potem
     * modele partia po partii. Nie `chunk()` po `OFFSET`: obsłużone konto
     * wypada z warunku kolejki, więc przesunięcie przeskakiwałoby
     * nieobsłużone. Nie `chunkById()`: kolejność po identyfikatorze zamiast po
     * dacie zgłoszenia kazałaby najstarszym zgłoszeniom czekać przy zatorze.
     *
     * @param  \Closure(): Builder  $kolejka
     * @return \Generator<int, User>
     */
    private function partiami(\Closure $kolejka, int $budzet): \Generator
    {
        $identyfikatory = $kolejka()->limit($budzet)->pluck('id')->all();

        foreach (array_chunk($identyfikatory, max(1, $this->rozmiarPartii)) as $partia) {
            $konta = User::query()->whereKey($partia)->get()->keyBy(static fn (User $u): string => (string) $u->getKey());

            foreach ($partia as $id) {
                // Konto mogło zniknąć między odczytem identyfikatorów a partią.
                if ($konta->has((string) $id)) {
                    yield $konta->get((string) $id);
                }
            }
        }
    }
}
