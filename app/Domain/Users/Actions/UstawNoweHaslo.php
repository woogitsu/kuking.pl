<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\LinkResetuNieaktualny;
use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Notifications\PotwierdzenieZmianyHasla;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use InvalidArgumentException;
use Throwable;

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
 * RESET LINKIEM SPRAWDZA TOKEN JESZCZE RAZ, POD BLOKADĄ (issue #2055).
 * `PasswordBroker::reset()` sprawdza token PRZED wywołaniem zwrotnym,
 * a kasuje go dopiero PO nim. Dwa równoległe żądania z tym samym linkiem
 * przechodziły więc walidację brokera, ustawiały się w kolejce po tę
 * blokadę — i drugie, gdy już ją dostało, nadpisywało hasło pierwszego
 * tokenem, którego od chwili nie było. Sprawdzenie brokera zostaje jako
 * szybka odmowa; rozstrzyga to pod blokadą, w tej samej transakcji, w której
 * token znika. Tokeny tego konta kasuje się pod tą blokadą (niżej), więc
 * blokada wiersza konta wystarcza — wiersza tokenu nie trzeba blokować
 * osobno.
 *
 * CZEGO TO NIE ZAŁATWIA: potwierdzenia, które zakończyło się CAŁE, zanim ta
 * operacja wzięła blokadę. Takie potwierdzenie jest wcześniejszym,
 * zamkniętym zdarzeniem; nowe hasło go nie cofa.
 */
final class UstawNoweHaslo
{
    public function __construct(
        private readonly CancelEmailChange $anuluj,
        private readonly PotwierdzSesjePrzedZmianaBezpieczenstwa $potwierdzSesje,
    ) {}

    /**
     * @param  string  $powod  `CancelEmailChange::POWOD_ZMIANA_HASLA` albo `POWOD_RESET_HASLA`
     * @param  string|null  $zachowajSesje  identyfikator bieżącej sesji, która ma przeżyć
     *                                      zmianę; `null` kasuje wszystkie (reset)
     * @param  string|null  $tokenResetu  token z linku — wymagany przy resecie,
     *                                    sprawdzany ponownie pod blokadą (#2055)
     * @param  string|null  $obecneHaslo  tylko ustawienia: poświadczenie sprawdzane ponownie pod blokadą (#2851)
     * @param  int|null  $generacjaSesji  tylko ustawienia: generacja uwierzytelnionej sesji z początku żądania
     * @return bool czy anulowaliśmy przy tym zamówioną zmianę adresu
     *
     * @throws BladDlaCzlowieka gdy konta już nie ma
     * @throws LinkResetuNieaktualny gdy link resetu nie jest już ważny pod blokadą
     */
    public function handle(
        User $user,
        #[\SensitiveParameter] string $haslo,
        string $powod,
        ?string $ip = null,
        ?string $zachowajSesje = null,
        #[\SensitiveParameter] ?string $tokenResetu = null,
        #[\SensitiveParameter] ?string $obecneHaslo = null,
        ?int $generacjaSesji = null,
    ): bool {
        $zdarzenie = match ($powod) {
            CancelEmailChange::POWOD_ZMIANA_HASLA => 'account.password_changed',
            CancelEmailChange::POWOD_RESET_HASLA => 'account.password_reset',
            default => throw new InvalidArgumentException('Nieznany powód ustawienia hasła: '.$powod),
        };

        // Reset bez tokenu to błąd wywołującego, nie człowieka: bez tokenu
        // nie ma czego sprawdzić pod blokadą, a właśnie to zamyka #2055.
        if ($powod === CancelEmailChange::POWOD_RESET_HASLA && ($tokenResetu === null || $tokenResetu === '')) {
            throw new InvalidArgumentException('Reset hasła wymaga tokenu z linku.');
        }

        if ($powod === CancelEmailChange::POWOD_ZMIANA_HASLA && ($obecneHaslo === null || $generacjaSesji === null)) {
            throw new InvalidArgumentException('Zmiana hasła w ustawieniach wymaga obecnego hasła i generacji sesji.');
        }

        return ZamekKonta::zablokuj($user, function (?User $swiezy) use ($user, $haslo, $powod, $ip, $zachowajSesje, $zdarzenie, $tokenResetu, $obecneHaslo, $generacjaSesji): bool {
            if ($swiezy === null) {
                throw new BladDlaCzlowieka('Tego konta już nie ma, więc nie ustawiliśmy nowego hasła.');
            }

            // TEN SAM token, na świeżym modelu, pod blokadą (#2055): równoległe
            // żądanie z tym samym linkiem mogło go zużyć, gdy to czekało
            // w kolejce. `tokenExists()` sprawdza skrót i termin tak samo jak
            // walidacja brokera. Przed jakimkolwiek zapisem — odmowa niczego
            // nie zmienia.
            if ($tokenResetu !== null && ! Password::tokenExists($swiezy, $tokenResetu)) {
                throw new LinkResetuNieaktualny;
            }

            // Wstępne sprawdzenie formularza mogło wyprzedzić reset albo
            // zmianę hasła z drugiej sesji. Obie rzeczy muszą nadal pasować
            // do ŚWIEŻEGO konta pod tą samą blokadą, zanim cokolwiek zapiszemy.
            if ($powod === CancelEmailChange::POWOD_ZMIANA_HASLA) {
                $this->potwierdzSesje->sprawdz($swiezy, (string) $obecneHaslo, (int) $generacjaSesji);
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
            $broker = Password::broker();
            if (! $broker instanceof PasswordBroker) {
                throw new \LogicException('Skonfigurowany broker haseł nie udostępnia usuwania żetonów.');
            }
            $broker->deleteToken($swiezy);

            // ZMIANA HASŁA UNIEWAŻNIA ZAMÓWIONĄ ZMIANĘ ADRESU (issue #195) —
            // pod tą samą blokadą, więc potwierdzenie nie wejdzie pomiędzy.
            $anulowana = $this->anuluj->handle($swiezy, $powod, $ip);

            AuditLogEntry::record($zdarzenie, $swiezy, $swiezy, ip: $ip);

            // Kontroler dalej pracuje na modelu z początku żądania — musi
            // zobaczyć nowy skrót hasła i nowy token „zapamiętaj mnie".
            $user->setRawAttributes($swiezy->getAttributes(), sync: true);

            $this->zamowListPotwierdzajacy($swiezy, $powod);

            return $anulowana;
        });
    }

    /**
     * List „Hasło zostało zmienione" (#2565) — JEDNA droga dla obu ścieżek.
     *
     * Zamawiamy go z KONTA ODCZYTANEGO POD BLOKADĄ (adres nigdy z żądania),
     * ale ZLECAMY dopiero po zatwierdzeniu transakcji: rollback nie zostawia
     * listu o zmianie, której nie było. Błąd zlecenia (np. baza kolejki) idzie
     * do `report()` i nie cofa ani nie przewraca już zatwierdzonej zmiany.
     *
     * Zasady adresata:
     *  - konto wymazane — nic nie wysyłamy;
     *  - reset linkiem — kliknięcie w list z tej skrzynki jest dowodem, że
     *    adres jest właścicielski, choć znacznik weryfikacji zapisuje się
     *    dopiero po tej akcji (kontroler);
     *  - zmiana w ustawieniach — tylko na adres potwierdzony, żeby list
     *    bezpieczeństwa nie szedł na literówkę;
     *  - `banned` / `suspended` / `pending_delete` dostają list: to ochrona
     *    konta, nie funkcja serwisu, a droga odzyskania hasła jest dla nich
     *    otwarta tak samo.
     * Adres i imię utrwalamy teraz — worker może ruszyć po zmianie adresu.
     */
    private function zamowListPotwierdzajacy(User $swiezy, string $powod): void
    {
        if ($swiezy->isErased() || $swiezy->data_erased_at !== null) {
            return;
        }

        $adres = trim((string) $swiezy->email);
        $reset = $powod === CancelEmailChange::POWOD_RESET_HASLA;

        if ($adres === '' || (! $reset && ! $swiezy->hasVerifiedEmail())) {
            return;
        }

        $list = new PotwierdzenieZmianyHasla(
            now(),
            $reset ? PotwierdzenieZmianyHasla::RESET_LINKIEM : PotwierdzenieZmianyHasla::ZMIANA_W_USTAWIENIACH,
            $swiezy->profile?->display_name,
            $swiezy->profile?->form_of_address,
        );

        DB::afterCommit(static function () use ($adres, $list): void {
            try {
                Notification::route('mail', $adres)->notify($list);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
