<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use InvalidArgumentException;

/**
 * Ustawienie nowego hasła — JEDNA droga dla zmiany w ustawieniach i dla
 * resetu linkiem z listu (issue #1358).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO BYŁO ZŁAMANE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Obie drogi zapisywały `users.password` POZA `ZamekKonta`, a dopiero potem
 * wołały `CancelEmailChange`, które bierze blokadę konta. Między zapisem
 * hasła a anulowaniem było okno, w które mieściło się całe potwierdzenie
 * zamówionej zmiany adresu:
 *
 *   1. właściciel zapisuje nowe hasło (zatwierdzone, bez blokady konta);
 *   2. link napastnika bierze blokadę, widzi ważne żądanie, zmienia adres;
 *   3. `CancelEmailChange` nie ma już czego kasować;
 *   4. formularz hasła kończy się sukcesem — a adres konta jest napastnika.
 *
 * To dokładnie ten scenariusz, na który `ZamekKonta` zostało napisane:
 * właściciel reaguje na ostrzeżenie o niezamówionej zmianie adresu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO ROBI TA KLASA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Zapis hasła, unieważnienie sesji i linków, skasowanie oczekującej zmiany
 * adresu, skasowanie linku resetu i oba wpisy dziennika idą w JEDNEJ
 * transakcji pod blokadą konta. Potwierdzenie adresu ustawia się wtedy
 * w kolejce: albo jest całe przed, albo całe po — i po nowym haśle nie ma
 * już czego potwierdzać. Błąd w środku wycofuje wszystko naraz, więc nie
 * zostaje nowe hasło z nadal ważnym żądaniem zmiany adresu, a dziennik nie
 * opisuje czegoś, co się nie stało.
 *
 * Samo przeniesienie `CancelEmailChange` wyżej nie wystarczy: zapis hasła
 * i anulowanie byłyby dalej dwoma osobnymi zdarzeniami. `CancelEmailChange`
 * wołamy tu W ŚRODKU blokady — `ZamekKonta` wolno zagnieździć, a jego
 * wpis do dziennika wejdzie do tej samej transakcji.
 *
 * CZEGO TO NIE ZAŁATWIA: potwierdzenia, które zakończyło się CAŁE, zanim ta
 * operacja wzięła blokadę. Takie potwierdzenie jest wcześniejszym,
 * zamkniętym zdarzeniem; nowe hasło go nie cofa.
 */
final class UstawNoweHaslo
{
    public function __construct(private readonly CancelEmailChange $anuluj) {}

    /**
     * @param  string  $powod  `CancelEmailChange::POWOD_ZMIANA_HASLA` albo `POWOD_RESET_HASLA`
     * @param  string|null  $zachowajSesje  identyfikator bieżącej sesji, która ma przeżyć
     *                                      zmianę; `null` kasuje wszystkie (reset)
     * @return bool czy anulowaliśmy przy tym zamówioną zmianę adresu
     *
     * @throws BladDlaCzlowieka gdy konta już nie ma
     */
    public function handle(User $user, string $haslo, string $powod, ?string $ip = null, ?string $zachowajSesje = null): bool
    {
        $zdarzenie = match ($powod) {
            CancelEmailChange::POWOD_ZMIANA_HASLA => 'account.password_changed',
            CancelEmailChange::POWOD_RESET_HASLA => 'account.password_reset',
            default => throw new InvalidArgumentException('Nieznany powód ustawienia hasła: '.$powod),
        };

        return ZamekKonta::zablokuj($user, function (?User $swiezy) use ($user, $haslo, $powod, $ip, $zachowajSesje, $zdarzenie): bool {
            if ($swiezy === null) {
                throw new BladDlaCzlowieka('Tego konta już nie ma, więc nie ustawiliśmy nowego hasła.');
            }

            // Jedna nazwana droga do hasła — `password` jest poza `$fillable`.
            // Na modelu odczytanym POD BLOKADĄ, nie na tym z początku żądania.
            $swiezy->assignPassword($haslo)->save();

            // Stare sesje, ciasteczko „zapamiętaj mnie" i link logowania
            // przestają działać razem z hasłem (issue #12, #584, D-056).
            $swiezy->invalidateSessions($zachowajSesje);

            // Link „Nie pamiętam hasła" wysłany wcześniej też jest drogą na
            // konto — po zmianie hasła w ustawieniach dalej ustawiłby nowe.
            // Przy resecie broker skasowałby go sam, ale dopiero po tej
            // transakcji; tu znika razem z hasłem.
            Password::broker()->deleteToken($swiezy);

            // ZMIANA HASŁA UNIEWAŻNIA ZAMÓWIONĄ ZMIANĘ ADRESU (issue #195) —
            // pod tą samą blokadą, więc potwierdzenie nie wejdzie pomiędzy.
            $anulowana = $this->anuluj->handle($swiezy, $powod, $ip);

            AuditLogEntry::record($zdarzenie, $swiezy, $swiezy, ip: $ip);

            // Kontroler dalej pracuje na modelu z początku żądania — musi
            // zobaczyć nowy skrót hasła i nowy token „zapamiętaj mnie".
            $user->setRawAttributes($swiezy->getAttributes(), sync: true);

            return $anulowana;
        });
    }
}
