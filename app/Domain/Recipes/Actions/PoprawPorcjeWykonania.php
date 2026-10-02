<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Recipes\Gotowanie\PorcjeWykonania;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;

/**
 * Poprawienie albo usunięcie prywatnej liczby faktycznych porcji przy
 * ISTNIEJĄCYM wykonaniu (issue #2540).
 *
 * Zmienia wyłącznie `cooked_events.faktyczne_porcje`. Nie tworzy nowego
 * wykonania, nie wysyła powiadomienia, nie rusza `cooked_at`, notatki,
 * zdjęć, `actual_minutes` ani przepisu — dlatego zapis idzie zapytaniem po
 * kluczu, a nie przez `save()` (bez zdarzeń modelu i bez dotykania innych
 * kolumn). Pusta wartość czyści pole.
 *
 * Prawo do zmiany sprawdza Policy (`CookedEventPolicy::poprawPorcje`) w
 * kontrolerze; tu pilnujemy tylko wartości.
 */
final class PoprawPorcjeWykonania
{
    /**
     * @return float|null zapisana liczba albo `null` po wyczyszczeniu
     *
     * @throws BladDlaCzlowieka
     */
    public function handle(CookedEvent $event, ?string $wpisane): ?float
    {
        $liczba = PorcjeWykonania::sprawdz($wpisane);

        CookedEvent::query()->whereKey($event->getKey())->update(['faktyczne_porcje' => $liczba]);

        return $liczba;
    }
}
