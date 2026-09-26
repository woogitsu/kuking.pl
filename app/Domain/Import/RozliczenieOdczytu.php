<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\ImportPrzepisu;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Budżet i zlecenie rozliczane RAZEM (D-298 „maszyna stanów”, #1973, #1974).
 *
 * `BudzetAi` pilnuje księgi rezerwacji i licznika dnia; ta klasa dopina do
 * tego wiersz zlecenia — w JEDNEJ transakcji. Albo budżet jest rozliczony
 * i zlecenie ma koszt oraz odpowiedź modelu, albo nie ma ani jednego, ani
 * drugiego, a rezerwacja stoi otwarta dla `zamknijPorzucone()`.
 *
 * Koszt zlecenia dolicza się W SQL (`COALESCE(koszt_mikrousd, 0) + ?`),
 * nie z obiektu w pamięci: rezerwację porzuconą mogło domknąć sprzątanie
 * po czasie, a obiekt zadania tego nie wie.
 */
final class RozliczenieOdczytu
{
    public function __construct(private readonly BudzetAi $budzet) {}

    /**
     * Rozlicza próbę i zapisuje odpowiedź modelu. `null` = żądanie wyszło,
     * ale odpowiedzi nie ma (429/5xx/timeout) — cała rezerwacja w wydatki.
     */
    public function rozlicz(ImportPrzepisu $zlecenie, Rezerwacja $rezerwacja, ?OdpowiedzModelu $odpowiedz): void
    {
        $cennik = Cennik::zKonfiguracji();
        $faktyczny = $odpowiedz !== null && $odpowiedz->maUsage() && $cennik !== null
            ? $cennik->koszt((int) $odpowiedz->tokenyWejscia, (int) $odpowiedz->tokenyWyjscia)
            : null;

        DB::transaction(function () use ($zlecenie, $rezerwacja, $odpowiedz, $faktyczny): void {
            $wydano = $this->budzet->rozlicz($rezerwacja, $faktyczny);

            DB::table('importy_przepisow')->where('id', $zlecenie->getKey())->update([
                'koszt_mikrousd' => DB::raw('COALESCE(koszt_mikrousd, 0) + '.(int) $wydano),
                'tokeny_wejscia' => $odpowiedz?->tokenyWejscia,
                'tokeny_wyjscia' => $odpowiedz?->tokenyWyjscia,
                // Zapisana odpowiedź = etap „odczytano” zamknięty. Ponowienie
                // zadania czyta ją stąd i NIE płaci drugi raz (#1980).
                'odpowiedz_modelu' => $odpowiedz === null ? null : json_encode($odpowiedz->surowa, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });

        $zlecenie->refresh();
    }

    /**
     * Domyka rezerwacje zlecenia, których nikt nie domknął, i dopisuje
     * wydatek do zlecenia (jeśli jeszcze istnieje). Bezpieczne do
     * powtórzenia i do wołania równolegle — patrz `BudzetAi::zamknijOtwarte()`.
     *
     * @return int ile wpisano w wydatki tym wywołaniem
     */
    public function zamknijPorzucone(string $importId, ?CarbonInterface $starszeNiz = null): int
    {
        return DB::transaction(function () use ($importId, $starszeNiz): int {
            $wydano = $this->budzet->zamknijOtwarte($importId, $starszeNiz);

            if ($wydano > 0) {
                DB::table('importy_przepisow')->where('id', $importId)->update([
                    'koszt_mikrousd' => DB::raw('COALESCE(koszt_mikrousd, 0) + '.$wydano),
                    'updated_at' => now(),
                ]);
            }

            return $wydano;
        });
    }
}
