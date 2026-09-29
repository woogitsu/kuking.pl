<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Social\Actions\FollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Profile;
use App\Models\User;

/**
 * Obserwowanie osób wybranych na kroku „kogo obserwować" — wyjęte
 * z `OnboardingController::saveFollows()` bez zmiany zachowania (#970).
 * Wejście (limit, kształt pól) pilnuje `ZapisObserwowanychRequest`.
 *
 * NAZWA UŻYTKOWNIKA W FORMULARZU TO NIE AUTORYZACJA (#793).
 *
 * Ten krok wskazuje osoby NAZWAMI, a nazwę da się zwolnić zmianą
 * w Ustawieniach i od razu ponownie zająć — `UsernameNotTaken` sprawdza
 * tylko aktualne zajęcie, nie historię. Ekran onboardingu potrafi stać
 * otwarty bardzo długo (to jest krok, który ludzie przerywają i wracają do
 * niego), a jedno żądanie zakłada relacje z wieloma osobami naraz, więc
 * pomyłka nie jest pojedyncza, tylko seryjna. Stąd ukryte pole z
 * identyfikatorem osoby widzianej w chwili renderowania (`$oczekiwani`),
 * sparowane z nazwą i OPCJONALNE (starsze wywołania go nie wysyłają).
 * `SocialController::assertToTaSamaOsoba()` nie nadaje się tu — broni
 * JEDNEJ osoby wskazanej adresem trasy.
 */
final class ObserwujWybraneOsoby
{
    public function __construct(private readonly FollowUser $followUser) {}

    /**
     * @param  list<string>  $nazwy  zaznaczone nazwy, już bez powtórzeń
     * @param  array<string, string>  $oczekiwani  małe litery nazwy => identyfikator widziany na ekranie
     */
    public function handle(User $user, array $nazwy, array $oczekiwani): WynikObserwowaniaWybranych
    {
        $completed = 0;
        $skipped = 0;
        // Nazwy, które między wyrenderowaniem a wysłaniem zmieniły
        // właściciela. Człowiek MUSI o nich usłyszeć: cicho pominięte
        // zaznaczenie wygląda dokładnie jak zaznaczenie, którego nie było.
        $zmieniloWlasciciela = [];

        foreach ($nazwy as $username) {
            // Bez rozróżniania wielkości liter, tak samo jak profil
            // i listy obserwujących — patrz `Profile::poNazwie()`.
            $target = Profile::poNazwie($username)?->user;

            if ($target === null) {
                $skipped++;

                continue;
            }

            $oczekiwanyId = $oczekiwani[mb_strtolower($username)] ?? null;

            if ($oczekiwanyId !== null && (string) $target->getKey() !== $oczekiwanyId) {
                $zmieniloWlasciciela[] = $username;

                continue;
            }

            try {
                $this->followUser->handle($user, $target);
                // Już istniejąca relacja także spełnia wybór człowieka.
                $completed++;
            } catch (BladDlaCzlowieka) {
                // Pojedyncza nieudana próba (np. konto w międzyczasie
                // zablokowane) nie może przerwać całego onboardingu.
                //
                // Znacznik, a nie `RuntimeException`: ten drugi połykał tu
                // również `QueryException` (dziedziczy po nim przez
                // `PDOException`), więc awaria bazy udawała „konto
                // niedostępne" i onboarding kończył się bez ani jednego
                // obserwowania, nie mówiąc o tym nikomu.
                $skipped++;
            }
        }

        return new WynikObserwowaniaWybranych($completed, $skipped, $zmieniloWlasciciela);
    }
}
