<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Support\Facades\DB;

/**
 * Zgoda „odczyt AI” — jedyne miejsce, które ją udziela, wycofuje i czyta (D-296).
 *
 * WZÓR Z D-240 DLA AWATARA, ZASTOSOWANY DO KARTKI Z ZESZYTU. D-240 mówi, że
 * treść niepubliczna może wyjść do OpenAI dopiero po „osobnej decyzji: celu
 * zgody, ekranu udzielania i wycofania, sprawdzenia przed każdą wysyłką”.
 * To są te trzy rzeczy: cel `odczyt_ai` w `dziennik_zgod`, ekran „Przepisz
 * z kartki” + sekcja w ustawieniach prywatności, i `udzielona()` wołane
 * w zadaniu tuż przed wysłaniem zdjęcia — ze świeżym odczytem z bazy.
 *
 * STANEM JEST DZIENNIK, NIE KOLUMNA. Inaczej niż przy digeście nie dokładamy
 * booleana na gorącą tabelę `users`: zgoda obowiązuje, gdy OSTATNI wpis tej
 * osoby dla tego celu to `udzielona`. Dzięki temu nie ma dwóch prawd
 * (flaga i dowód), które mogłyby się rozjechać — i nie ma asymetrii
 * z `PrzestawZgodeNaDigest`: zapis dowodu JEST zmianą stanu, więc albo się
 * udaje w całości, albo człowiek widzi błąd i klika jeszcze raz.
 *
 * ZDARZENIE POWSTAJE TYLKO PRZY REALNEJ ZMIANIE — ta sama reguła co przy
 * digeście. Podwójne kliknięcie „Zgadzam się” nie dopisuje drugiego wiersza.
 * Równoległe zmiany jednej osoby szereguje blokada wiersza `users`.
 */
final class PrzestawZgodeNaOdczytAi
{
    /**
     * @param  string  $zrodlo  `WpisZgody::ZRODLO_USTAWIENIA`, `ZRODLO_EKRAN_IMPORTU` albo `ZRODLO_USUNIECIE_KONTA`
     * @return bool czy stan zgody FAKTYCZNIE się zmienił
     */
    public function handle(User $osoba, bool $chce, string $zrodlo): bool
    {
        return DB::transaction(function () use ($osoba, $chce, $zrodlo): bool {
            User::query()->whereKey($osoba->getKey())->lockForUpdate()->firstOrFail();

            if ($this->udzielona($osoba) === $chce) {
                return false;
            }

            WpisZgody::create([
                'user_id' => $osoba->getKey(),
                'cel' => WpisZgody::CEL_ODCZYT_AI,
                'czynnosc' => $chce ? WpisZgody::UDZIELONA : WpisZgody::WYCOFANA,
                'zrodlo' => $zrodlo,
                'wystapilo_at' => now(),
                'wersja_polityki' => (string) config('kuking.zgody.wersja_polityki'),
            ]);

            return true;
        });
    }

    /** Świeży odczyt z bazy — ostatni wpis tej osoby dla celu `odczyt_ai`. */
    public function udzielona(User $osoba): bool
    {
        return WpisZgody::query()
            ->where('user_id', $osoba->getKey())
            ->where('cel', WpisZgody::CEL_ODCZYT_AI)
            ->orderByDesc('wystapilo_at')
            ->orderByDesc('id')
            ->value('czynnosc') === WpisZgody::UDZIELONA;
    }
}
