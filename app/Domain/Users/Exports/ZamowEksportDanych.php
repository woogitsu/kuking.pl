<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Jobs\GenerateUserExport;
use App\Models\AuditLogEntry;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Zamówienie paczki z danymi (RODO art. 15 i 20) — przypadek użycia
 * wyjęty z `DataSettingsController::requestExport()` bez zmiany zachowania
 * (issue #970). Kontroler tylko wybiera zdanie dla człowieka na podstawie
 * `WynikZamowieniaEksportu`; rekord, transakcja razem z zadaniem w kolejce,
 * konflikt dwukliku, ponowienie porzuconego eksportu i wpis w dzienniku
 * mieszkają tutaj.
 */
final class ZamowEksportDanych
{
    public function handle(User $user, ?string $ip = null): WynikZamowieniaEksportu
    {
        $aktywny = $this->aktywnyEksport($user);

        if ($aktywny !== null) {
            return $this->przejmijTrwajacy($aktywny);
        }

        try {
            // `DB::transaction()` WOKÓŁ JEDNEGO `INSERT` — nie z ostrożności
            // na zapas, tylko dlatego, że na PostgreSQL samo try/catch nie
            // wystarcza. Gdy to żądanie biegnie wewnątrz SZERSZEJ transakcji
            // (a w testach `RefreshDatabase` opakowuje w nią cały test),
            // nieudany `INSERT` zatruwa CAŁĄ otaczającą transakcję: każde
            // następne zapytanie tym samym połączeniem odbija się o „current
            // transaction is aborted" — także to sprawdzające niżej, czy
            // aktywny eksport naprawdę istnieje. Laravel w trakcie transakcji
            // otwiera SAVEPOINT, więc konflikt cofa TYLKO tę jedną wstawkę.
            // Ta sama pułapka i to samo lekarstwo co w
            // `App\Domain\Analytics\ZapiszSygnal` i
            // `App\Domain\Contact\Actions\PrzyjmijWiadomosc`.
            // ZLECENIE WCHODZI DO TEJ SAMEJ TRANSAKCJI CO REKORD (audyt A02, P1).
            //
            // Przedtem `dispatch()` stał ZA tą transakcją i to jest całe
            // znalezisko: pomiędzy commitem rekordu `queued` a wysłaniem
            // zadania było okno, w którym awaria zapisu do kolejki, `kill`,
            // wyczerpana pamięć albo restart przy wdrożeniu zostawiały
            // rekord, którego NIKT nie wykona — a `maAktywnyEksport()`
            // blokował wtedy każde następne zgłoszenie. Człowiek widział
            // bezterminowo „już przygotowujemy" i nie miał ani przycisku
            // ponowienia, ani awarii, którą ekran mógłby pokazać. To jest
            // odmowa wykonania prawa z RODO art. 15 i 20, wyglądająca jak
            // cierpliwość. Zmierzone:
            // `tests/Feature/EksportNieUtykaMiedzyCommitemAWyslaniemTest.php`.
            //
            // `afterCommit()` TEGO NIE ZAMYKA i audyt pisze to wprost —
            // przenosi wysyłkę na moment po zatwierdzeniu, czyli zostawia
            // dokładnie to samo okno, tylko w innym miejscu.
            //
            // Zamyka to natomiast rzecz, której nie trzeba tu dobudowywać:
            // kolejka jest BAZODANOWA i stoi na tym samym połączeniu co
            // aplikacja, więc wiersz w `jobs` i wiersz w `data_exports`
            // zatwierdzają się RAZEM. To jest transactional outbox z ustaleń
            // audytu, tylko bez nowej tabeli, bez Redisa i bez nowego
            // mechanizmu dostarczania — czyli w granicach `AGENTS.md` §3.
            DB::transaction(function () use ($user): void {
                $export = DataExport::create([
                    'user_id' => $user->getKey(),
                    'status' => DataExport::STATUS_QUEUED,
                ]);

                $this->zlecWykonanie($export);
            });
        } catch (UniqueConstraintViolationException $e) {
            // TU WCHODZI DRUGIE, RÓWNOLEGŁE ŻĄDANIE (audyt QUEUE-04/RACE-05,
            // D-078). `exists()` wyżej jest dobre na komunikat, ale nie jest
            // gwarancją: dwa żądania widzą „nie ma aktywnego eksportu"
            // jednocześnie i oba idą do `INSERT`. Gwarancję daje indeks
            // częściowy `data_exports_one_active_per_user` — i dopiero on
            // sprowadza tu jedno z tych żądań.
            //
            // Człowiek, który kliknął dwa razy, musi zobaczyć DOKŁADNIE TO
            // SAMO co ten, który kliknął raz. Dlatego ten sam komunikat co
            // wyżej, a nie 500: to nie jest awaria, tylko druga odpowiedź na
            // to samo pytanie, i odpowiedź na nie jest twierdząca („już
            // przygotowujemy"). Ekran błędu byłby tu karą za dwuklik, czyli
            // za rzecz, która w grupie 60+ jest normalna
            // (`docs/UX_50_PLUS.md`).
            $aktywny = $this->aktywnyEksport($user);

            if ($aktywny === null) {
                // Konflikt unikalności, ale NIE ten. `data_exports` ma poza
                // tym indeksem tylko klucz główny, więc tu nie powinno się
                // dać wejść — a jeśli się dało, to znaczy, że odbiło się coś
                // innego, i wyciszenie tego zamiotłoby usterkę pod dywan
                // razem z paczką, której człowiek nie dostanie.
                throw $e;
            }

            return $this->przejmijTrwajacy($aktywny);
        }

        // WPIS POMOCNICZY ZA TRANSAKCJĄ (D-249, klasa 2; #1429). Autorytatywny
        // ślad przyjęcia żądania to wiersz `data_exports` z `created_at`
        // i zadanie w `jobs` — oba już zatwierdzone. Awaria dziennika nie
        // cofnie paczki, więc 500 byłoby tu nieprawdą, a ponowienie trafiłoby
        // w „już przygotowujemy" i wpisu i tak nie uzupełniło. Brak idzie do
        // `report()` z nazwą zdarzenia; człowiek dostaje potwierdzenie.
        AuditLogEntry::recordBezWywracania('data.export_requested', $user, $user, ip: $ip);

        return WynikZamowieniaEksportu::Przyjety;
    }

    /**
     * Eksport, który to konto ma teraz w robocie — albo `null`.
     *
     * Jedno pytanie, dwa wywołania: raz PRZED wstawieniem (żeby dać spokojny
     * komunikat bez dobijania się do bazy o konflikt), raz PO konflikcie
     * (żeby rozpoznać, czy odbiło się o TEN indeks, czy o coś innego).
     * Gdyby ta lista stanów stała w dwóch miejscach osobno, rozjechałaby się
     * przy pierwszej zmianie słownika stanów — i to cicho, bo obie gałęzie
     * kończą się tym samym ekranem.
     *
     * Zwraca MODEL, nie `bool`: od audytu A02 odpowiedź na „już trwa" zależy
     * od tego, czy ten eksport naprawdę jeszcze się rusza.
     */
    private function aktywnyEksport(User $user): ?DataExport
    {
        return $user->dataExports()
            ->whereIn('status', [DataExport::STATUS_QUEUED, DataExport::STATUS_PROCESSING])
            ->first();
    }

    /**
     * DRUGA POŁOWA A02: rekord porzucony w kolejce daje się PONOWIĆ.
     *
     * Transactional outbox wyżej zamyka okno na przyszłość, ale nie
     * odblokowuje konta, w którym eksport utknął WCZEŚNIEJ — ani żadnej innej
     * drogi zgubienia zadania: wyczyszczona tabela `jobs`, worker, który nigdy
     * nie wstał, zmieniona nazwa kolejki. Wszystkie kończą się tak samo:
     * wiersz `queued` bez wykonawcy i ekran, który bezterminowo mówi „już
     * przygotowujemy".
     *
     * Ponawiamy TEN SAM wiersz, nie zakładamy drugiego. Indeks częściowy
     * `data_exports_one_active_per_user` (D-078) i tak nie dopuści dwóch
     * aktywnych, ale powód jest głębszy: dwa wiersze znaczyłyby dwie paczki
     * tych samych zdjęć i dwa listy z dobowego wiadra (D-076).
     *
     * GRANICA JEST WĄSKA I ŚWIADOMA — tylko `queued`. Eksport w stanie
     * `processing` worker już PODJĄŁ; ponowienie dałoby dwa procesy pakujące
     * setki megabajtów na jednym rekordzie. Rekordy zawieszone w `processing`
     * domyka `GenerateUserExport::failed()`, który łapie także przekroczenie
     * 15-minutowego limitu czasu, i to jest właściwe dla nich miejsce.
     */
    private function przejmijTrwajacy(DataExport $aktywny): WynikZamowieniaEksportu
    {
        if (! $this->porzucony($aktywny)) {
            return WynikZamowieniaEksportu::JuzTrwa;
        }

        // POD BLOKADĄ WIERSZA I Z REWALIDACJĄ POD NIĄ (D-079 §2). Dwuklik
        // i dwa równoległe żądania dają tu dwa wejścia w to samo miejsce;
        // bez blokady oba wysłałyby zadanie. Stan czytamy jeszcze raz, bo
        // między pytaniem wyżej a tą transakcją worker mógł już rekord podjąć.
        $ponowiony = DB::transaction(function () use ($aktywny): bool {
            $swiezy = DataExport::query()->whereKey($aktywny->getKey())->lockForUpdate()->first();

            if ($swiezy === null || ! $this->porzucony($swiezy)) {
                return false;
            }

            // Znacznik ruchu PRZED wysłaniem zadania: od tej chwili rekord
            // nie jest już porzucony, więc następny dwuklik nie zrobi
            // trzeciego zadania. Bez tego „ponów" byłby przyciskiem do
            // mnożenia zadań pakujących te same zdjęcia.
            $swiezy->touch();

            $this->zlecWykonanie($swiezy);

            return true;
        });

        return $ponowiony ? WynikZamowieniaEksportu::Ponowiony : WynikZamowieniaEksportu::JuzTrwa;
    }

    /** Czy ten eksport stoi w kolejce bez ruchu dłużej, niż wolno (`DataExport::MINUT_NA_PODJECIE`). */
    private function porzucony(DataExport $export): bool
    {
        return $export->status === DataExport::STATUS_QUEUED
            && $export->updated_at !== null
            && $export->updated_at->lt(now()->subMinutes(DataExport::MINUT_NA_PODJECIE));
    }

    /**
     * Wysłanie zadania — WYŁĄCZNIE wewnątrz transakcji, która tworzy albo
     * przejmuje rekord.
     *
     * Odmowa, a nie ciche obejście, gdy kolejka nie jest ani `sync`, ani
     * bazodanowa na TYM SAMYM połączeniu co aplikacja. Przy takiej kolejce
     * (Redis, SQS) wiersz zadania stałby się widoczny dla workera PRZED
     * commitem rekordu — czyli ta sama usterka co A02, tylko odwrócona:
     * worker sięgałby po `data_exports`, którego jeszcze nie ma.
     *
     * `AGENTS.md` §3 zabrania tu Redisa i SQS-a, więc to nie jest przypadek
     * do obsłużenia — to jest pułapka do zastawienia. Ten sam wzorzec co
     * `ZdjeciaDoPrzypiecia::zablokuj()`: niech pada głośno, przy pierwszym
     * uruchomieniu testów, a nie po cichu na produkcji.
     */
    private function zlecWykonanie(DataExport $export): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'GenerateUserExport wolno wysłać tylko wewnątrz transakcji rekordu eksportu (audyt A02) '
                .'— poza nią wraca okno pomiędzy commitem a wysyłką.',
            );
        }

        $nazwa = (string) config('queue.default');
        $sterownik = (string) config("queue.connections.{$nazwa}.driver");
        $polaczenieKolejki = config("queue.connections.{$nazwa}.connection");

        $tejSamejBazy = $sterownik === 'database'
            && ($polaczenieKolejki === null || $polaczenieKolejki === config('database.default'));

        if ($sterownik !== 'sync' && ! $tejSamejBazy) {
            throw new LogicException(
                'Kolejka "'.$nazwa.'" (sterownik "'.$sterownik.'") nie zapisuje zadań do tej samej bazy '
                .'co rekord eksportu, więc zadanie i rekord nie zatwierdzą się razem (audyt A02). '
                .'Kuking używa kolejki bazodanowej (AGENTS.md §3) — zmiana sterownika wymaga tu '
                .'osobnego mechanizmu dostarczenia, nie zdjęcia tego warunku.',
            );
        }

        GenerateUserExport::dispatch((string) $export->getKey());
    }
}
