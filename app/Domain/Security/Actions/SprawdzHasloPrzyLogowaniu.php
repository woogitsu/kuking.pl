<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\KomunikatZamknietegoKonta;
use App\Domain\Security\LimitProbHasla;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Pierwszy składnik logowania: login + hasło → konto, które wolno wpuścić.
 *
 * WSPÓLNE DLA WWW I API (D-014, D-270). Do 25 września 2026 ta logika
 * siedziała w `LoginController::store()`; API potrzebowało dokładnie tego
 * samego — tych samych trzech koszyków limitu, tego samego komunikatu dla
 * konta, którego nie ma, i tej samej odmowy dla konta zamkniętego. Kopia
 * w drugim kontrolerze byłaby drugą wyrocznią hasła z osobnym życiorysem,
 * więc logika przeszła tutaj, a oba kontrolery ją wołają.
 *
 * Koszyki limitu są WSPÓLNE dla obu dróg (te same klucze w
 * `LimitProbHasla`): zgadujący nie podwoi sobie budżetu, przeskakując
 * między formularzem a aplikacją.
 *
 * Nie loguje i nie wydaje tokenu — to robi wołający, po sprawdzeniu 2FA.
 * Rzuca `ValidationException` pod polem `$pole`.
 */
final class SprawdzHasloPrzyLogowaniu
{
    public function __construct(private readonly LimitProbHasla $limit) {}

    public function handle(string $login, string $haslo, string $adres, string $pole = 'login'): User
    {
        // TRZY KOSZYKI, NIE JEDEN (W7-01, R3 §5). Liczby w
        // `config/kuking.php` → `login_limits`, klucze w `KluczeLimitow`.
        //
        // Wcześniej był jeden licznik po kluczu `login|ip`. Zmierzone: 20
        // nieudanych prób na TO SAMO konto z 20 różnych adresów nie
        // wywoływało żadnej blokady — bo licznik przywiązany do adresu
        // strukturalnie nie widzi ataku rozproszonego. To nie jest skutek
        // `trustProxies`: zmiana adresu jest dla napastnika tania także bez
        // podszywania się pod proxy (botnet, sieć mobilna, chmura).
        //
        // Koszyk KONTA zamyka tę lukę i jest jedyny, który nie zależy od
        // adresu wcale.
        // KOSZYKI ŻYJĄ TERAZ W `App\Domain\Security\LimitProbHasla`, bo ta
        // sama wyrocznia hasła stoi jeszcze w dwóch publicznych formularzach
        // (`/odwolanie`, `/cofnij-usuniecie-konta`), a licznik konta miało
        // tylko to jedno. Liczby, klucze i reguła „czyść parę i konto, nigdy
        // adres" są bez zmian — zmieniło się miejsce, w którym się je czyta,
        // i liczba drzwi, których pilnują.
        //
        // JEDEN KOMUNIKAT DLA WSZYSTKICH TRZECH KOSZYKÓW, świadomie.
        // Rozróżnienie („to Twoje konto jest zablokowane" kontra „to Twój
        // adres") powiedziałoby napastnikowi, który licznik trafił — czyli
        // czy konto o tym loginie w ogóle istnieje. Komunikat nazywa też
        // drogę wyjścia, bo koszyk konta z definicji pozwala OBCEMU
        // zablokować cudze konto (patrz `LimitProbHasla`).
        $this->limit->zatrzymajJesliZaDuzo($login, $adres, $pole);

        // Nazwa użytkownika i e-mail bez rozróżniania wielkości liter — „Basia"
        // i „basia" to ta sama osoba, a klawiatura telefonu podnosi pierwszą
        // literę bez pytania. Wcześniej przy parze „basia" / „Basia" `first()`
        // bez `ORDER BY` zwracał ten wiersz, który baza akurat podała pierwszy —
        // więc prawdziwa Basia mogła dostawać „nieprawidłowe hasło" przy
        // poprawnym haśle (audyt A25).
        //
        // Wyszukiwanie żyje w `User::findByLogin()`, bo pytają o to samo
        // DWA formularze dostępne PRZED zalogowaniem: odwołanie dla osób
        // zablokowanych (#10) i cofnięcie usunięcia konta (audyt A8). Obie te
        // osoby nie mogą wejść do serwisu, a muszą dać się rozpoznać.
        $user = User::findByLogin($login);

        // `Auth::validate()`, NIE `Auth::attempt()` — sprawdza hasło BEZ
        // logowania. Różnica jest tu istotna: konto z potwierdzonym 2FA
        // (niżej) nie może dostać zalogowanej sesji, dopóki nie poda też
        // kodu z aplikacji. `attempt()` logowałby od razu, na chwilę
        // otwierając serwis samym hasłem.
        if ($user === null || ! Auth::validate(['email' => $user->email, 'password' => $haslo])) {
            $this->limit->zapiszNieudanaProbe($login, $adres);

            throw ValidationException::withMessages([
                $pole => 'Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie. Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.',
            ]);
        }

        // UWAGA: warunkiem NIE jest `isActive()`.
        //
        // Zawieszenie jest z założenia karą „tylko do odczytu": konto żyje,
        // treści są widoczne, nie da się nic opublikować (EnsureAccountIsActive,
        // pasek w layoucie, issue #40). Odmowa logowania wywracała ten projekt
        // do góry nogami — `suspend()` kasuje sesje, więc osoba wylatywała
        // z serwisu i NIE MOGŁA WRÓCIĆ. Nie zobaczyłaby ani wiadomości od
        // moderacji, ani terminu końca kary, ani własnych przepisów. Kara
        // czasowa działała jak blokada na zawsze.
        //
        // Konto z minioną karą też przechodzi: `EnsureAccountIsActive`
        // przywraca je przy pierwszym żądaniu.
        // `STATUSY_ZAMKNIETEGO_KONTA` zamiast dwóch wypisanych statusów:
        // od D-022 jest trzeci (`erased`) i to jest właśnie ten status, przy
        // którym wpuszczenie kogokolwiek byłoby najgorsze — konto po
        // wymazaniu danych nie ma już właściciela, a jego hasło jest losowe.
        if (in_array($user->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
            // Bez `Auth::logout()` — `Auth::validate()` wyżej niczego nie
            // zalogowało, więc nie ma z czego wylogowywać.
            throw ValidationException::withMessages([
                $pole => KomunikatZamknietegoKonta::dla($user),
            ]);
        }

        // CZYŚCIMY PARĘ I KONTO, NIGDY ADRES.
        //
        // Gdyby poprawne logowanie czyściło koszyk ADRESU, napastnik
        // zalogowałby się na WŁASNE, jednorazowe konto z tego samego
        // adresu, żeby zresetować licznik adresowy — i wrócił do rozpylania
        // po cudzych kontach z czystym licznikiem. To jednozdaniowa reguła,
        // bardzo łatwa do pominięcia, więc pilnuje jej osobny test.
        $this->limit->wyczyscPoUdanej($login, $adres);

        return $user;
    }
}
