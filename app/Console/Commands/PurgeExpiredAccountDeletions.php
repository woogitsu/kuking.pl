<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Console\Command;

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
 */
class PurgeExpiredAccountDeletions extends Command
{
    protected $signature = 'kuking:usun-wygasle-konta
                            {--dry-run : Pokaż, którym kontom minęła karencja, i nic nie zmieniaj}';

    protected $description = 'Trwale usuwa/anonimizuje dane kont, którym minęła 30-dniowa karencja po zgłoszeniu usunięcia';

    public function __construct(private readonly EraseAccountData $usunDane)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $graceDays = (int) config('kuking.account.delete_grace_days');
        $termin = now()->subDays($graceDays);

        $doWykonania = User::query()
            ->where('status', User::STATUS_PENDING_DELETE)
            ->whereNull('data_erased_at')
            ->whereNotNull('delete_requested_at')
            ->where('delete_requested_at', '<=', $termin)
            ->orderBy('delete_requested_at')
            ->get();

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
        $doPonowienia = User::query()
            ->whereNotNull('data_erased_at')
            ->whereHas('media')
            ->orderBy('data_erased_at')
            ->get();

        if ($doWykonania->isEmpty() && $doPonowienia->isEmpty()) {
            $this->info('Nie ma kont, którym minęła karencja na usunięcie.');

            return self::SUCCESS;
        }

        $usuniete = 0;
        $dokonczone = 0;

        foreach ($doWykonania as $user) {
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

        foreach ($doPonowienia as $user) {
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

        $this->info($dryRun
            ? 'Kont z minioną karencją: '.$doWykonania->count()
                .', do ponowienia (nieskasowane zdjęcia): '.$doPonowienia->count().' (nic nie zmieniono).'
            : 'Usunięto dane kont: '.$usuniete.'. Dokończono kasowanie zdjęć: '.$dokonczone.'.');

        return self::SUCCESS;
    }
}
