<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * JEDNA KOLEJNOŚĆ BLOKAD DLA OPERACJI NA ZMIANIE ADRESU E-MAIL
 * (ustalenie AUTH-01 / RACE-01 z audytu drugiej warstwy, 10.09.2026).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ZŁAMANE
 * ══════════════════════════════════════════════════════════════════════
 *
 * Trzy dokumenty w tym repozytorium obiecywały jedną własność:
 * **ustawienie nowego hasła unieważnia oczekującą zmianę adresu**. Wołają to
 * `PasswordResetController::reset()` i `SecuritySettingsController::
 * updatePassword()` przez `CancelEmailChange`, a `PendingEmailChange`
 * wymienia to jako jedną z trzech dróg wygaszenia żądania.
 *
 * Ta własność NIE BYŁA liniaryzowalna. `EmailSettingsController::confirm()`
 * pobierał wiersz `pending_email_changes`, sprawdzał go, a potem oddawał
 * MODEL do `ConfirmEmailChange::handle()`, które wchodziło do transakcji,
 * blokowało `users` — i nigdy nie czytało tego wiersza ponownie.
 * `CancelEmailChange` z kolei kasowało wiersz BEZ ŻADNEJ blokady. Między
 * odczytem w kontrolerze a transakcją w akcji było więc okno:
 *
 *   1. żądanie A czyta ważne `PendingEmailChange`;
 *   2. żądanie B ustawia nowe hasło i kasuje ten wiersz;
 *   3. żądanie A wchodzi do transakcji, przypisuje NOWY ADRES i woła
 *      `$zmiana->delete()`, które kasuje zero wierszy — i nie zgłasza błędu.
 *
 * DLACZEGO TO NIE JEST DROBIAZG. Scenariusz, w którym ta obietnica ma
 * znaczenie, to dokładnie ten, w którym ktoś obcy miał chwilowy dostęp do
 * konta: zamówił zmianę adresu na swój, a właściciel odzyskuje konto
 * ustawiając nowe hasło. Właściciel wykonuje dokładnie tę czynność, którą
 * serwis mu każe — i mimo tego link napastnika może później przestawić
 * adres konta. Czyli mechanizm zaprojektowany na wypadek przejęcia konta
 * dawał się przejęciu obejść.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO ROBI TA KLASA
 * ══════════════════════════════════════════════════════════════════════
 *
 * Ustala JEDNĄ kolejność blokad — najpierw `users`, potem
 * `pending_email_changes` — i pilnuje, żeby wszystkie trzy operacje
 * (zamówienie, potwierdzenie, anulowanie) wchodziły przez to samo gardło.
 * Dwie równoległe operacje na tym samym koncie ustawiają się wtedy w kolejce
 * zamiast wyprzedzać się nawzajem.
 *
 * KOLEJNOŚĆ JEST WAŻNIEJSZA NIŻ SAM FAKT BLOKOWANIA. Gdyby jedna operacja
 * brała najpierw `pending_email_changes`, a druga najpierw `users`, dwie
 * równoległe zablokowałyby się wzajemnie (deadlock) i PostgreSQL zabiłby
 * jedną z nich. Dlatego blokada `users` stoi TUTAJ, w jednym miejscu, a nie
 * w trzech akcjach osobno — trzy kopie tej samej kolejności rozjadą się przy
 * pierwszej zmianie.
 *
 * SAMA BLOKADA NIE WYSTARCZY. Blokada serializuje, ale nie mówi żądaniu A,
 * że świat zmienił się, gdy ono czekało. Dlatego `ConfirmEmailChange` musi
 * po wejściu pod blokadę PRZECZYTAĆ wiersz ponownie i sprawdzić go jeszcze
 * raz. Ta klasa daje kolejność; rewalidacja należy do akcji, bo tylko ona
 * wie, co dla niej znaczy „nadal aktualne".
 *
 * DLACZEGO BLOKUJEMY WIERSZ KONTA, A NIE ŻĄDANIA ZMIANY. Bo wiersz żądania
 * może NIE ISTNIEĆ — a `SELECT ... FOR UPDATE` na nieistniejącym wierszu
 * nie blokuje niczego i nie powstrzyma drugiego `INSERT`. Konto istnieje
 * zawsze i jest wspólne dla wszystkich trzech operacji, więc jest jedyną
 * rzeczą, na której da się je ustawić w kolejce.
 */
final class ZamekKonta
{
    /**
     * Wykonuje `$co` w transakcji, pod blokadą wiersza konta.
     *
     * Do wywołania zwrotnego trafia ŚWIEŻY model konta odczytany pod
     * blokadą — nie ten podany na wejściu. To nie jest kosmetyka: model
     * z zewnątrz mógł zostać odczytany przed sekundą albo przed godziną,
     * a operacja ma działać na stanie z chwili, w której naprawdę trzyma
     * blokadę.
     *
     * `null` w wywołaniu zwrotnym znaczy „konta już nie ma" — akcja
     * decyduje, co wtedy powiedzieć człowiekowi, bo dla zamówienia zmiany
     * i dla jej potwierdzenia to są dwa różne zdania.
     *
     * @template T
     *
     * @param  Closure(?User): T  $co
     * @return T
     */
    public static function zablokuj(User $user, Closure $co): mixed
    {
        return DB::transaction(static function () use ($user, $co) {
            $swiezy = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            return $co($swiezy);
        });
    }
}
