<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use App\Notifications\PotwierdzenieAdresu;
use Illuminate\Support\Facades\RateLimiter;

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
 *  PIERWSZY LIST GAŚNIE OSTATNI, PONOWIENIE — WCZEŚNIEJ (D-246)
 * ────────────────────────────────────────────────────────────────────────
 *
 * `handle()` — list przy rejestracji. Bez niego nowe konto nie potwierdzi
 * adresu, a fala migracyjna z Garnek.pl to setki kont zakładanych w kilka
 * dni. Własnego sufitu dobowego nie ma — jedynym jego ograniczeniem jest
 * wspólna pula, w której stoi na samym końcu kolejki do wygaszenia (klasa
 * `wejscie`, próg 0).
 *
 * `ponow()` — przycisk „Wyślij wiadomość jeszcze raz". Do 23 września 2026
 * szedł przez `handle()`, czyli też w klasie `wejscie`, a jedynym jego
 * limitem było 6 na minutę. Jedno niepotwierdzone konto opróżniało więc
 * całą pulę 300 listów w około 50 minut i do końca doby aplikacja odmawiała
 * wszystkim linków logowania i potwierdzeń rejestracji (audyt 23.09,
 * znalezisko 2). Od D-246 ponowienie ma DWIE granice, bo broni przed dwoma
 * różnymi rzeczami:
 *
 *  1. sufit dobowy NA KONTO (`poczta.ponowienie_potwierdzenia_na_dobe`) —
 *     przed jednym kontem klikającym w kółko;
 *  2. własną klasę we wspólnej puli (`ponowienie`) — przed wieloma kontami
 *     naraz: gaśnie, zanim sięgnie po listy zostawione dla wejścia.
 *
 * CZEGO TA KLASA NIE SPRAWDZA: czy adres jest już potwierdzony i czy konto
 * jest otwarte. Robi to wołający (`EmailVerificationController::resend`
 * i listener frameworka), i tak ma zostać — ta klasa odpowiada wyłącznie za
 * to, żeby list policzył się w puli dokładnie raz.
 */
final class WyslijPotwierdzenieAdresu
{
    /**
     * Dwie doby, choć przydział jest na jedną: klucz niesie datę, więc nowy
     * dzień i tak zaczyna się od nowego klucza. Termin jest tylko po to, żeby
     * stary klucz sprzątnął się sam.
     */
    private const WAZNOSC_LICZNIKA_SEKUND = 2 * 86400;

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

    /**
     * „Wyślij wiadomość jeszcze raz" — ponowienie z DWIEMA granicami (D-246).
     *
     * KOLEJNOŚĆ: najpierw sufit konta, potem pula. Odwrotna zajmowałaby
     * miejsce we wspólnej puli także temu, komu sufit konta i tak odmówi.
     *
     * LICZNIK KONTA RUSZA ATOMOWO (`RateLimiter::increment` zwraca stan po
     * zwiększeniu), a nie parą „sprawdź, a potem dolicz" — ta sama lekcja co
     * D-076: dwa równoległe kliknięcia przy stanie 4 z 5 przeszłyby oba.
     *
     * LICZY SIĘ LIST, KTÓRY WYSZEDŁ. Gdy pula odmówi, miejsce w liczniku
     * konta wraca — inaczej człowiek, któremu nic nie wysłaliśmy, miałby
     * przydział zjedzony przez nasze własne odmowy.
     */
    public function ponow(User $user): WynikPonowieniaPotwierdzenia
    {
        $klucz = self::kluczLicznikaKonta($user);

        if (RateLimiter::increment($klucz, self::WAZNOSC_LICZNIKA_SEKUND) > self::sufitPonowienNaDobe()) {
            RateLimiter::decrement($klucz, self::WAZNOSC_LICZNIKA_SEKUND);

            return WynikPonowieniaPotwierdzenia::SufitKonta;
        }

        if (! DziennyBudzetListow::dlaPonowieniaPotwierdzenia()->sprobujZarezerwowac()) {
            RateLimiter::decrement($klucz, self::WAZNOSC_LICZNIKA_SEKUND);

            return WynikPonowieniaPotwierdzenia::BrakMiejscaWPuli;
        }

        // Miejsca w puli nie oddajemy — powód jak w `handle()`.
        $user->notify(new PotwierdzenieAdresu);

        return WynikPonowieniaPotwierdzenia::Wyslano;
    }

    /** Ile ponowień na dobę wolno jednemu kontu — także do liczby w komunikacie. */
    public static function sufitPonowienNaDobe(): int
    {
        return max(0, (int) config('kuking.poczta.ponowienie_potwierdzenia_na_dobe', 5));
    }

    /**
     * Klucz po IDENTYFIKATORZE konta i DACIE, bez adresu e-mail — w tabeli
     * `cache` nie ma leżeć cudzy adres (ta sama lekcja co
     * `App\Support\KluczeLimitow`). Data w kluczu, bo liczymy dobę
     * kalendarzową, tak jak cała pula (`DziennyBudzetListow`): o północy
     * zaczyna się nowy klucz.
     */
    private static function kluczLicznikaKonta(User $user): string
    {
        return 'poczta:ponowienie-potwierdzenia:'.$user->getKey().':'.now()->format('Y-m-d');
    }
}
