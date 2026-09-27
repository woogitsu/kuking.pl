<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Wylogowanie wyłącza powiadomienia poza serwisem na TYM urządzeniu (#1979, D-303).
 *
 * DLACZEGO. Subskrypcja Web Push należy do przeglądarki, nie do sesji
 * Kuking. Bez tego kroku Basia wylogowuje się na wspólnym komputerze, a jej
 * „Marek — ugotowane z Twojego przepisu…" dalej wyskakuje na tym ekranie,
 * także gdy przy klawiaturze siedzi już Zenek. Zwykłe „Wyloguj się" daje
 * rozsądne oczekiwanie, że nic prywatnego tu się już nie pokaże.
 *
 * CZEGO TO NIE DOTYKA. Wygaśnięcia sesji: telefon, na którym sesja po
 * prostu minęła, dalej dostaje powiadomienia — nikt nie kliknął „Wyloguj".
 * I innych urządzeń tej osoby: gasi się wyłącznie to, na którym człowiek
 * właśnie wychodzi.
 *
 * JAK ROZPOZNAJEMY URZĄDZENIE — DWIE DROGI, SERWER NIE POLEGA NA SKRYPCIE.
 *
 * 1. Sesja. Przy włączeniu powiadomień kontroler zapisuje w sesji
 *    identyfikator wiersza (`KLUCZ_SESJI`). Logowanie tę wartość przenosi
 *    (regeneracja identyfikatora sesji zachowuje dane), więc zwykłe
 *    „włączyłem, potem się wylogowałem" działa bez JavaScriptu.
 * 2. Adres subskrypcji z formularza wylogowania. Uzupełnia go
 *    `resources/js/powiadomienia-push.js` z `pushManager.getSubscription()`
 *    — to pokrywa przeglądarkę, która włączyła powiadomienia w sesji już
 *    wygasłej. Adres nie trafia do HTML-a ani do logów; idzie tylko w tym
 *    jednym żądaniu POST.
 *
 * AUTORYZACJA. Obie drogi działają WYŁĄCZNIE w obrębie
 * `$user->pushSubscriptions()`. Podany z zewnątrz adres albo identyfikator
 * cudzego wiersza (np. przeglądarka przepięta już na Zenka) nie skasuje
 * niczego, co nie należy do wychodzącej osoby.
 */
final class OdlaczUrzadzeniePush
{
    public const KLUCZ_SESJI = 'push_urzadzenie';

    /** @return int liczba skasowanych wierszy (0 albo 1, wyjątkowo 2) */
    public function handle(User $user, mixed $idZSesji, mixed $endpoint): int
    {
        $id = is_string($idZSesji) && Str::isUuid($idZSesji) ? $idZSesji : null;
        $adres = is_string($endpoint) && $endpoint !== '' && strlen($endpoint) <= 2048 ? $endpoint : null;

        if ($id === null && $adres === null) {
            return 0;
        }

        return $user->pushSubscriptions()
            ->where(function ($q) use ($id, $adres): void {
                if ($id !== null) {
                    $q->orWhere('id', $id);
                }
                if ($adres !== null) {
                    $q->orWhere('endpoint', $adres);
                }
            })
            ->delete();
    }
}
