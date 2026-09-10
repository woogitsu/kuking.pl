<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Social\ZamekPary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Notification;
use App\Models\User;

/**
 * Obserwowanie kogoś.
 *
 * Blokada ma pierwszeństwo: jeśli którakolwiek strona zablokowała drugą,
 * obserwowanie jest niemożliwe. Test regresyjny na to jest obowiązkowy.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TA AKCJA WCHODZI POD BLOKADĘ WIERSZY (D-080)
 * ══════════════════════════════════════════════════════════════════════
 *
 * Do 10.09.2026 stało tu samo `exists()` na tabeli `blocks`, a zaraz po nim
 * `attach()` — bez transakcji i bez blokady. Między jednym a drugim
 * `BlockUser` mógł się w całości wykonać, zdjąć obserwowanie w obie strony
 * i skończyć, a ta akcja i tak dopinała wiersz `follows`, bo pracowała na
 * odpowiedzi sprzed zapisu. Po blokadzie zostawało obserwowanie: zablokowana
 * osoba dalej dostawała wpisy w swoim feedzie obserwowanych.
 *
 * `ZamekPary` szereguje obie operacje po tej samej parze osób — i robi to
 * w JEDNEJ, deterministycznej kolejności wierszy, bo inaczej „Basia blokuje
 * Marka" i „Marek obserwuje Basię" w tej samej sekundzie zakleszczyłyby się
 * nawzajem. Uzasadnienie kolejności jest w `ZamekPary`, w jednym miejscu.
 *
 * ── DWA SPRAWDZENIA TEJ SAMEJ RZECZY TO NIE POMYŁKA ──
 *
 * Sprawdzenia przed blokadą zostają i są POTRZEBNE, mimo że powtarzają się
 * pod blokadą. Odpowiadają na inne pytania:
 *
 *  - PRZED blokadą — żeby zwykła odmowa („nie można obserwować tej osoby",
 *    „nie można obserwować samego siebie") nie kosztowała transakcji ani
 *    blokady dwóch wierszy. To najczęstsza droga: ktoś klika „Obserwuj"
 *    pod profilem osoby, którą już zablokował.
 *  - POD blokadą — bo blokada tylko ustawia w kolejce, a nie mówi temu
 *    żądaniu, że świat zmienił się, gdy ono czekało. Gwarancję daje
 *    dopiero ten drugi odczyt (D-079 §4: `exists()` w PHP jest dobre na
 *    ładny komunikat, gwarancję daje constraint albo lock).
 *
 * Komunikaty w obu miejscach są IDENTYCZNE i to jest celowe: człowiek nie
 * ma prawa dowiedzieć się z treści zdania, czy trafił w wyścig.
 *
 * ── DLACZEGO POWIADOMIENIE POWSTAJE POD BLOKADĄ, A NIE PO NIEJ ──
 *
 * Bo „X zaczyna Cię obserwować" po tym, jak człowiek tego kogoś zablokował,
 * jest dokładnie tym kontaktem, przed którym blokada miała chronić. Wiersz
 * powiadomienia musi być tym samym atomem co wiersz `follows`: albo są oba,
 * albo nie ma żadnego. `NotifyUser` tylko zapisuje do bazy (nie wysyła
 * poczty ani nie kolejkuje zadania), więc wejście z nim do transakcji nic
 * nie kosztuje i niczego nie wysyła przed `COMMIT`-em.
 *
 * ── IDEMPOTENCJA WYCHODZI Z TEGO ZA DARMO ──
 *
 * Dwa równoległe kliknięcia „Obserwuj" tej samej osoby też ustawiają się
 * w kolejce na tej samej parze wierszy. Wcześniej oba widziały „nie
 * obserwuję" i oba robiły `attach`, więc drugie dostawało naruszenie klucza
 * głównego `follows` — czyli błąd serwera za powtórzone kliknięcie. Teraz
 * drugie żądanie czyta stan po pierwszym i zwraca `false`, a kontroler mówi
 * „Już obserwujesz tę osobę."
 */
final class FollowUser
{
    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(User $follower, User $target): bool
    {
        if ($follower->getKey() === $target->getKey()) {
            throw new BladDlaCzlowieka('Nie można obserwować samego siebie.');
        }

        // Tania odmowa bez transakcji — patrz komentarz nad klasą.
        if ($follower->hasBlockRelationWith($target)) {
            throw new BladDlaCzlowieka('Nie można obserwować tej osoby.');
        }

        if (! $target->isActive()) {
            throw new BladDlaCzlowieka('To konto jest niedostępne.');
        }

        return ZamekPary::zablokuj($follower, $target, function (?User $obserwujacy, ?User $obserwowany) use ($follower): bool {
            // Konto mogło zniknąć między odczytem a wejściem pod blokadę.
            // Dla człowieka to ta sama sytuacja co konto nieaktywne, więc
            // i to samo zdanie.
            if ($obserwujacy === null || $obserwowany === null || ! $obserwowany->isActive()) {
                throw new BladDlaCzlowieka('To konto jest niedostępne.');
            }

            if ($obserwujacy->hasBlockRelationWith($obserwowany)) {
                throw new BladDlaCzlowieka('Nie można obserwować tej osoby.');
            }

            if ($obserwujacy->isFollowing($obserwowany)) {
                return false;
            }

            $obserwujacy->following()->attach($obserwowany->getKey(), ['created_at' => now()]);

            // `$follower->profile`, nie `$obserwujacy->profile`: model
            // odczytany pod blokadą nie ma wczytanej relacji profilu, a to
            // ten sam wiersz konta.
            $this->notify->handle(
                recipient: $obserwowany,
                type: Notification::TYPE_FOLLOW,
                actor: $obserwujacy,
                data: ['username' => $follower->profile?->username],
            );

            return true;
        });
    }
}
