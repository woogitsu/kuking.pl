<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Logging\BezpiecznyBlad;
use App\Mail\DataExportReady;
use App\Mail\DataExportReadyInGracePeriod;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * List „paczka z danymi gotowa" — osobno od budowania paczki (issue #820).
 *
 * Do 23 września 2026 list wysyłał sam `GenerateUserExport`, raz, na końcu
 * piętnastominutowego przebiegu. Awaria poczty była łapana i logowana — paczka
 * zostawała gotowa, ale człowiek nie dostawał nic i nikt tego nie ponawiał.
 * Ponowić całego `GenerateUserExport` się nie da (paczka już `ready`, drugi
 * przebieg kończy się od razu) i nie wolno (ponowne pobieranie setek
 * megabajtów zdjęć, żeby wysłać jeden list). Stąd osobne, lekkie zadanie
 * z własnymi próbami.
 *
 * NAJWYŻEJ JEDEN LIST: zadanie najpierw ZAJMUJE list warunkowym
 * `UPDATE … SET notified_at = teraz WHERE notified_at IS NULL`. Dwa przebiegi
 * naraz (ponowne doręczenie z kolejki po `retry_after`, duplikat zlecenia)
 * — `UPDATE` przepuści jeden. Gdy wysyłka padnie, zajęcie jest zwalniane
 * (tylko NASZE, po tym samym znaczniku czasu) i kolejka ponawia.
 *
 * Czego to NIE gwarantuje: gdy dostawca przyjmie list, a połączenie zerwie się
 * przed odpowiedzią, dostaniemy wyjątek, zwolnimy zajęcie i wyślemy drugi raz.
 * Gdy proces padnie między zajęciem a wysyłką — listu nie będzie wcale, bo
 * zajęcie zostaje. Świadomie wybrana strona: paczka czeka w ustawieniach
 * i ekran mówi to wprost, a zdublowany list z linkiem do danych to gorszy
 * błąd niż brakujący.
 *
 * KTÓRY LIST: konto w karencji (`pending_delete`) dostaje
 * `DataExportReadyInGracePeriod` — „cofnij usunięcie do dnia X” — bo się nie
 * zaloguje, a pobranie wymaga logowania. Konto wymazane i paczka
 * niepobieralna (wygasła, unieważniona przez `EraseAccountData`) — żadnego.
 * Stan konta z ŚWIEŻEGO odczytu w tym zadaniu, nie z chwili zlecenia.
 */
class NotifyUserExportReady implements ShouldQueue
{
    use Queueable;

    /** Poczta potrafi leżeć dłużej niż kilka minut — ostatnia próba po około 80 minutach. */
    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public string $dataExportId)
    {
        // Jeden list, nie paczka — nie stoi w `low` za cudzym eksportem.
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $export = DataExport::with('user.profile')->find($this->dataExportId);
        $user = $export?->user;

        if ($export === null
            || $user === null
            || $export->notified_at !== null
            || ! $export->isDownloadable()
            || $user->isErased()
            || $user->data_erased_at !== null
            || $user->email === null) {
            return;
        }

        $zajete = Carbon::now();

        if (! $this->zajmij($zajete)) {
            return;
        }

        $mail = $user->status === User::STATUS_PENDING_DELETE
            ? new DataExportReadyInGracePeriod($export)
            : new DataExportReady($export);

        try {
            Mail::to($user->email)->send($mail);
        } catch (Throwable $e) {
            DataExport::query()
                ->whereKey($this->dataExportId)
                ->where('notified_at', $zajete)
                ->update(['notified_at' => null]);

            // BEZ KOMUNIKATU: transport przy odrzuconym odbiorcy wkleja
            // w tekst JEGO ADRES („550 5.1.1 <basia@wp.pl>: …"), a odpowiedź
            // dostawcy może nieść token i CR/LF. Dziennik nie jest miejscem
            // na adresy (AGENTS.md §7) — idzie klasa, kod i odcisk (#973).
            Log::warning('Paczka z danymi gotowa, ale e-mail nie wyszedł', [
                'data_export_id' => $this->dataExportId,
                'proba' => $this->attempts(),
                'error' => BezpiecznyBlad::kontekst($e),
            ]);

            // Nowy wyjątek BEZ `previous`: kolejka serializuje wyjątek do
            // `failed_jobs`, a oryginał niesie adres odbiorcy.
            throw new RuntimeException('Nie udało się wysłać listu o gotowej paczce z danymi.');
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Wyczerpano próby wysłania listu o gotowej paczce z danymi', [
            'data_export_id' => $this->dataExportId,
        ]);
    }

    /**
     * Zajęcie listu — jeden `UPDATE`, więc z dwóch przebiegów przejdzie jeden.
     * Warunki gotowości powtórzone w bazie: między odczytem wyżej a tym
     * zapisem `EraseAccountData` mogło przestawić termin w przeszłość.
     */
    private function zajmij(Carbon $kiedy): bool
    {
        return DataExport::query()
            ->whereKey($this->dataExportId)
            ->whereNull('notified_at')
            ->where('status', DataExport::STATUS_READY)
            ->where('expires_at', '>', $kiedy)
            ->update(['notified_at' => $kiedy]) === 1;
    }
}
