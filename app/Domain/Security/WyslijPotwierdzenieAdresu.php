<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use App\Notifications\PotwierdzenieAdresu;

/**
 * List potwierdzający adres e-mail — JEDNA droga dla obu miejsc, w których
 * ten list powstaje (rejestracja i przycisk „Wyślij wiadomość jeszcze raz").
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO OSOBNA KLASA, SKORO BYŁA JEDNA LINIJKA `$user->notify(...)`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo ten list jest od 20 września 2026 liczony we WSPÓLNEJ puli poczty
 * (`DziennyBudzetListow::wspolny()`), a liczenie musi objąć OBIE drogi —
 * inaczej licznik kłamie. Rejestracja wysyła ten list przez zdarzenie
 * `Registered` i framework woła `User::sendEmailVerificationNotification()`;
 * przycisk „Wyślij jeszcze raz" wołał tę samą metodę z kontrolera. Dopisanie
 * rezerwacji w jednym z tych miejsc zostawiłoby drugie niepoliczone.
 *
 * Kontroler potrzebuje jednak czegoś, czego `sendEmailVerificationNotification()`
 * dać nie może: ODPOWIEDZI, CZY LIST NAPRAWDĘ WYSZEDŁ. Metoda modelu jest
 * `void` (taki ma kontrakt we frameworku), a człowiek stojący przed ekranem
 * musi usłyszeć albo „wysłaliśmy jeszcze raz", albo „ten list dziś nie
 * wyjdzie, nie czekaj na niego". Stąd akcja zwracająca `bool`, z której
 * metoda modelu korzysta, ignorując wynik.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TEN LIST GAŚNIE OSTATNI (klasa `wejscie`, próg 0)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo bez niego nowe konto nie potwierdzi adresu, a fala migracyjna
 * z Garnek.pl to setki kont zakładanych w kilka dni. Własnego sufitu
 * dobowego ten list nie ma i mieć nie będzie — jedynym jego ograniczeniem
 * jest wspólna pula, w której stoi na samym końcu kolejki do wygaszenia.
 *
 * CZEGO TA KLASA NIE SPRAWDZA: czy adres jest już potwierdzony i czy konto
 * jest otwarte. Robi to wołający (`EmailVerificationController::resend`
 * i listener frameworka), i tak ma zostać — ta klasa odpowiada wyłącznie za
 * to, żeby list policzył się w puli dokładnie raz.
 */
final class WyslijPotwierdzenieAdresu
{
    /**
     * @return bool `true` = list poszedł. `false` = NIE poszedł, bo wspólna
     *              pula poczty jest na dziś wyczerpana (albo nie udało się
     *              zdobyć blokady licznika). Wołający ma wtedy powiedzieć
     *              człowiekowi, co zrobić — milczenie jest tu najgorsze,
     *              bo wygląda identycznie jak udana wysyłka.
     */
    public function handle(User $user): bool
    {
        if (! DziennyBudzetListow::dlaPotwierdzeniaAdresu()->sprobujZarezerwowac()) {
            return false;
        }

        // Miejsca nie oddajemy: tu nie ma drogi, którą list by nie wyszedł.
        // Odbiorcą jest konto, które właśnie stoi po drugiej stronie żądania,
        // a nie adres wpisany w formularz przez kogokolwiek z zewnątrz —
        // czyli nie ma tu odpowiednika `zwolnij()` z logowania linkiem.
        $user->notify(new PotwierdzenieAdresu);

        return true;
    }
}
