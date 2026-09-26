<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\ImportPrzepisu;
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
 * NIE WYSYŁAMY ZADANIA PONOWNIE sami: gdyby zgubione zadanie jednak żyło,
 * dwa zadania jednego zlecenia to dwa płatne żądania. Ponowienie należy do
 * człowieka i liczy się do jego limitu.
 */
final class OdzyskanieImportow
{
    public function __construct(private readonly RozliczenieOdczytu $rozliczenie, private readonly BudzetAi $budzet) {}

    /** @return array{rezerwacje: int, zlecenia: int} */
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
                    'zakonczono_at' => now(),
                ])->save();

                return true;
            });

            if ($domkniete) {
                $zlecenia++;
            }
        }

        if ($rezerwacje > 0 || $zlecenia > 0) {
            Log::warning('Odczyt przepisu: domknięto porzucone rezerwacje lub zlecenia.', [
                'rezerwacje' => $rezerwacje,
                'zlecenia' => $zlecenia,
                'stage' => 'import_odzyskanie',
            ]);
        }

        return ['rezerwacje' => $rezerwacje, 'zlecenia' => $zlecenia];
    }
}
