<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\ImportPrzepisu;
use App\Support\Storage\PlikTymczasowyImportu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprzątanie PO CZASIE tego, czego nie domknęło ani zadanie, ani `failed()`
 * (D-298 „maszyna stanów”, #1973, #1977). `kuking:odzyskaj-importy`, co
 * kwadrans.
 *
 *  1. Rezerwacje budżetu otwarte dłużej niż `rezerwacja_minut` — proces
 *     zabity po rezerwacji. Niewysłana wraca do budżetu, wysłana idzie
 *     w wydatki całą kwotą (`BudzetAi::zamknijOtwarte()`).
 *  2. Zlecenia `oczekuje`/`w_toku` bez zmiany od `zlecenie_minut` — zadanie
 *     zgubione (wyczyszczone `jobs`, zlecenie sprzed outboxa). Dostają
 *     jawny błąd `blad_wewnetrzny`, więc ekran pokazuje „Spróbuj jeszcze
 *     raz” zamiast bezterminowego „trwa”. Zadanie, które mimo to kiedyś
 *     ruszy, widzi stan końcowy i kończy bez wywołania.
 *
 *  3. Pliki PDF czekające na worker (#28 etap 2, #2051): kasowane po
 *     stanie końcowym i po retencji; osierocone (bez wiersza) też.
 *
 * NIE WYSYŁAMY ZADANIA PONOWNIE sami: gdyby zgubione zadanie jednak żyło,
 * dwa zadania jednego zlecenia to dwa płatne żądania. Ponowienie należy do
 * człowieka i liczy się do jego limitu.
 */
final class OdzyskanieImportow
{
    public function __construct(
        private readonly RozliczenieOdczytu $rozliczenie,
        private readonly BudzetAi $budzet,
        private readonly PlikTymczasowyImportu $pliki,
    ) {}

    /** @return array{rezerwacje: int, zlecenia: int, pliki: int} */
    public function odzyskaj(): array
    {
        $granicaRezerwacji = now()->subMinutes(max(1, (int) config('kuking.import.odzyskiwanie.rezerwacja_minut')));
        $rezerwacje = 0;

        foreach ($this->budzet->zleceniaZPorzuconymiRezerwacjami($granicaRezerwacji) as $importId) {
            $this->rozliczenie->zamknijPorzucone($importId, $granicaRezerwacji);
            $rezerwacje++;
        }

        $granicaZlecen = now()->subMinutes(max(1, (int) config('kuking.import.odzyskiwanie.zlecenie_minut')));
        $zlecenia = 0;

        $porzucone = ImportPrzepisu::query()
            ->whereIn('status', [ImportPrzepisu::STATUS_OCZEKUJE, ImportPrzepisu::STATUS_W_TOKU])
            ->where('updated_at', '<', $granicaZlecen)
            ->pluck('id');

        foreach ($porzucone as $id) {
            $domkniete = DB::transaction(function () use ($id, $granicaZlecen): bool {
                // Świeżo pod blokadą: zadanie mogło ruszyć przed chwilą.
                $zlecenie = ImportPrzepisu::query()->whereKey($id)->lockForUpdate()->first();

                if ($zlecenie === null || $zlecenie->jestKoncowy() || $zlecenie->updated_at >= $granicaZlecen) {
                    return false;
                }

                $this->rozliczenie->zamknijPorzucone((string) $id);

                $zlecenie->forceFill([
                    'status' => ImportPrzepisu::STATUS_NIEUDANY,
                    'kod_bledu' => ImportPrzepisu::KOD_BLAD_WEWNETRZNY,
                    // Adres strony (import z adresu, #28) nie zostaje w wierszu
                    // dłużej, niż trwa zlecenie.
                    'source_url' => null,
                    'zakonczono_at' => now(),
                ])->save();
                DB::table('proby_importu')->where('import_id', $id)->update([
                    'status' => 'nieudany',
                    'updated_at' => now(),
                ]);

                return true;
            });

            if ($domkniete) {
                $zlecenia++;
            }
        }

        $pliki = $this->posprzatajPliki();

        if ($rezerwacje > 0 || $zlecenia > 0 || $pliki > 0) {
            Log::warning('Odczyt przepisu: domknięto porzucone rezerwacje lub zlecenia albo skasowano zaległe pliki.', [
                'rezerwacje' => $rezerwacje,
                'zlecenia' => $zlecenia,
                'pliki' => $pliki,
                'stage' => 'import_odzyskanie',
            ]);
        }

        return ['rezerwacje' => $rezerwacje, 'zlecenia' => $zlecenia, 'pliki' => $pliki];
    }

    /**
     * Retencja plików PDF czekających na worker (#28 etap 2, #2051). Trzy
     * przypadki, wszystkie po kasowaniu pliku z dysku dopiero potem zerujemy
     * ścieżkę w wierszu (dysk odmówił = ścieżka zostaje, następny przebieg ponowi):
     *
     *  1. zlecenie w stanie KOŃCOWYM, które nadal ma plik — zadanie
     *     skasowało wiersz stanu, ale nie plik (dysk chwilowo niedostępny)
     *     albo zlecenie zamknęło odzyskiwanie wyżej;
     *  2. zlecenie nadal przejściowe, ale starsze niż retencja — zgubione
     *     zadanie; nikt już po ten plik nie sięgnie;
     *  3. plik na dysku, którego nie wskazuje żaden wiersz i który jest
     *     starszy niż retencja — zapis przyjęty, zanim powstał wiersz
     *     (przerwana transakcja zlecenia), oraz pozostałość po usuniętym koncie.
     *
     * Retencja to co najmniej `zlecenie_minut` + godzina, żeby sprzątanie nigdy
     * nie zabrało pliku zleceniu, które odzyskiwanie jeszcze uznaje za trwające.
     */
    private function posprzatajPliki(): int
    {
        $retencjaSekund = max(
            (int) config('kuking.import.pdf.retencja_godzin') * 3600,
            ((int) config('kuking.import.odzyskiwanie.zlecenie_minut') + 60) * 60,
        );
        $granica = now()->subSeconds($retencjaSekund);
        $skasowano = 0;

        $doZwolnienia = ImportPrzepisu::query()
            ->whereNotNull('plik_tymczasowy')
            ->where(fn ($q) => $q
                ->whereIn('status', ImportPrzepisu::STATUSY_KONCOWE)
                ->orWhere('updated_at', '<', $granica))
            ->get(['id', 'plik_tymczasowy']);

        foreach ($doZwolnienia as $zlecenie) {
            if ($this->pliki->skasuj($zlecenie->plik_tymczasowy)) {
                ImportPrzepisu::query()->whereKey($zlecenie->getKey())->update(['plik_tymczasowy' => null]);
                $skasowano++;
            }
        }

        $potrzebne = ImportPrzepisu::query()->whereNotNull('plik_tymczasowy')->pluck('plik_tymczasowy')->all();

        return $skasowano + $this->pliki->skasujOsierocone($retencjaSekund, array_values($potrzebne));
    }
}
