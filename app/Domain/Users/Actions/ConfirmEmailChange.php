<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Support\AdresEmail;

/**
 * Potwierdzenie nowego adresu e-mail — DOPIERO TU adres wchodzi w życie
 * (issue #195).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TO NIE JEST KILKA LINIJEK W KONTROLERZE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo trzy reguły, które tu mieszkają, muszą obowiązywać KAŻDĄ drogę do tej
 * zmiany, także tę, którą ktoś dopisze za pół roku:
 *
 *  1. żądanie musi być jeszcze ważne,
 *  2. adres nie może należeć do innego konta,
 *  3. potwierdzenie konsumuje żądanie — link działa dokładnie raz.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ADRES ZAJĘTY — ROZSTRZYGNIĘTE TUTAJ, A NIE W FORMULARZU
 * ────────────────────────────────────────────────────────────────────────
 *
 * Naturalny odruch to `Rule::unique('users', 'email')` w walidacji
 * formularza — tak robi rejestracja i tak było najprościej. Tutaj byłoby to
 * WYCIEK: zalogowany człowiek wpisuje dowolny adres i po odpowiedzi
 * formularza wie, czy ta osoba ma konto w Kuking. Serwis o gotowaniu, w
 * którym da się sprawdzić, czy sąsiadka albo była żona ma tu konto, to nie
 * jest drobiazg — a sprawdzić da się seriami, bo limit jest per konto,
 * a kont można założyć więcej.
 *
 * Dlatego formularz odpowiada ZAWSZE tak samo („wysłaliśmy list na ten
 * adres"), żądanie powstaje niezależnie od tego, czy adres jest wolny,
 * a o zajętości dowiaduje się dopiero ten, kto KLIKNIE W LINK — czyli
 * osoba czytająca pocztę pod tym adresem. Ta osoba i tak ma prawo wiedzieć,
 * że ma u nas konto: to jej skrzynka.
 *
 * Co z tego ma uczciwy człowiek, który się pomylił: na ekranie
 * `/ustawienia/e-mail` widzi swój oczekujący adres wypisany w całości, więc
 * literówkę zobaczy tam, a nie w komunikacie błędu. A jeśli pomyłkowo
 * wpisał adres własnego drugiego konta — kliknie link i przeczyta wprost,
 * co jest nie tak.
 *
 * Rejestracja ZOSTAJE jak była (`RegisterController` mówi „na ten adres jest
 * już założone konto"). To nie jest niekonsekwencja do posprzątania przy
 * okazji: tam ta odpowiedź jest jedyną drogą, żeby powiedzieć człowiekowi
 * „masz już konto, zaloguj się", a jej zmiana to osobna decyzja o osobnym
 * ekranie. Tutaj mamy wybór i wybieramy nieprzeciekającą stronę.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  WYŚCIG
 * ────────────────────────────────────────────────────────────────────────
 *
 * Sprawdzenie „czy adres wolny" i zapis idą w JEDNEJ transakcji, a wiersz
 * `users` jest wzięty pod `lockForUpdate()`. Ostatnim słowem jest i tak
 * baza: `users_email_lower_unique` nie pozwoli zapisać duplikatu, nawet
 * gdyby dwa potwierdzenia trafiły w tę samą milisekundę (AGENTS.md §6 —
 * walidacja w PHP jest dodatkiem, nie zamiennikiem).
 */
final class ConfirmEmailChange
{
    /**
     * @return string adres, który od tej chwili obowiązuje
     *
     * @throws BladDlaCzlowieka gdy żądanie wygasło albo adres jest zajęty
     */
    public function handle(User $user, PendingEmailChange $zmiana, ?string $ip = null): string
    {
        $nowyAdres = User::normalizeEmail($zmiana->new_email);
        $staryAdres = (string) $user->email;

        if (! $zmiana->jestWazne()) {
            // Wiersz kasujemy od razu — po co ma czekać na sprzątanie, skoro
            // właśnie ustaliliśmy, że jest martwy. Człowiek dostaje wtedy
            // czysty ekran „zamów jeszcze raz", bez wiszącej pozycji
            // „oczekuje na potwierdzenie", której nie da się już potwierdzić.
            $zmiana->delete();

            throw new BladDlaCzlowieka(
                'Ten link do potwierdzenia adresu już wygasł. Zamów zmianę adresu jeszcze raz — '
                .'wyślemy nowy list.',
            );
        }

        ZamekKonta::zablokuj($user, function (?User $swiezy) use ($user, $zmiana, $nowyAdres): void {
            if ($swiezy === null) {
                throw new BladDlaCzlowieka(
                    'Tego konta już nie ma, więc nie mamy czemu zmienić adresu.',
                );
            }

            // REWALIDACJA POD BLOKADĄ — bez niej ta blokada niczego nie
            // pilnuje (AUTH-01 / RACE-01, audyt drugiej warstwy).
            //
            // `$zmiana` przychodzi tu jako MODEL odczytany przez kontroler
            // PRZED wejściem do tej sekcji. W okienku między tamtym odczytem
            // a tą transakcją równoległe żądanie mogło ustawić nowe hasło,
            // co przez `CancelEmailChange` kasuje ten wiersz. Kod czytał
            // wtedy dalej ze starego obiektu, przypisywał nowy adres
            // i wołał `delete()`, które kasowało ZERO wierszy — bez błędu.
            // Czyli obietnica „nowe hasło unieważnia oczekującą zmianę
            // adresu" nie obowiązywała, a jest to obietnica na wypadek
            // przejęcia konta (pełne uzasadnienie w `ZamekKonta`).
            //
            // Czytamy więc wiersz jeszcze raz, pod blokadą, i pytamy o to
            // samo co przed nią: czy istnieje, czy jest nasz, czy nie wygasł
            // i czy dotyczy TEGO adresu. Adres sprawdzamy, bo w tym samym
            // okienku mogło dojść nowsze zamówienie zmiany na inny adres —
            // wtedy ten link jest nieaktualny, choć jakiś wiersz istnieje.
            $aktualna = PendingEmailChange::query()
                ->whereKey($zmiana->getKey())
                ->where('user_id', $swiezy->getKey())
                ->lockForUpdate()
                ->first();

            $nadalTo = $aktualna !== null
                && $aktualna->jestWazne()
                && User::normalizeEmail($aktualna->new_email) === $nowyAdres;

            if (! $nadalTo) {
                throw new BladDlaCzlowieka(
                    'Ten odnośnik już nie działa — w międzyczasie zmiana adresu została anulowana, '
                    .'zastąpiona nowszą albo minął jej termin. To dzieje się też wtedy, gdy ktoś '
                    .'ustawił na tym koncie nowe hasło. Adres konta zostaje bez zmian; jeśli nadal '
                    .'chcesz go zmienić, zamów zmianę jeszcze raz.',
                );
            }

            $zajety = User::query()
                ->whereRaw('lower(email) = ?', [$nowyAdres])
                ->whereKeyNot($swiezy->getKey())
                ->exists();

            if ($zajety) {
                throw new BladDlaCzlowieka(
                    'Na ten adres jest już założone inne konto w Kuking, a jeden adres to jedno konto. '
                    .'Zaloguj się na tamto konto albo zamów zmianę na inny adres. '
                    .'Twoje obecne konto zostaje bez zmian.',
                );
            }

            // Adres jest potwierdzony JUŻ W TEJ CHWILI: kliknięcie w link
            // wysłany na tę skrzynkę jest dowodem dostępu do niej. Proszenie
            // o drugie potwierdzenie tego samego byłoby pytaniem o to samo
            // dwa razy — a przy okazji zostawiałoby konto bez potwierdzonego
            // adresu, czyli bez prawa do pobrania własnych danych.
            $swiezy->assignEmail($nowyAdres, potwierdzony: true)->save();

            // Żądanie skonsumowane: link działa dokładnie raz. Kasujemy
            // wiersz odczytany POD BLOKADĄ, nie model podany z zewnątrz.
            $aktualna->delete();

            // Model przekazany z zewnątrz musi zobaczyć nową wartość —
            // inaczej kontroler wypisze na ekranie stary adres.
            $user->setRawAttributes($swiezy->getAttributes(), sync: true);
        });

        AuditLogEntry::record(
            'account.email_changed',
            $user,
            $user,
            metadata: [
                'stary_adres_skrot' => AdresEmail::maska($staryAdres),
                'nowy_adres_skrot' => AdresEmail::maska($nowyAdres),
            ],
            ip: $ip,
        );

        return $nowyAdres;
    }
}
