<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\RegistrationInvite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use LogicException;

/**
 * Przyjęte zaproszenie do założenia konta — trzymane w sesji (D-085).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TO JEST JEDYNE MIEJSCE, KTÓRE ODPOWIADA NA PYTANIE
 *  „NA JAKI ADRES ZAKŁADAMY TO KONTO"
 * ────────────────────────────────────────────────────────────────────────
 *
 * I dlatego ta klasa istnieje osobno, zamiast siedzieć w kontrolerze.
 * Rejestracja z zaproszenia ma jedną własność, której nie wolno zgubić:
 * ADRES BIERZE SIĘ Z WIERSZA W BAZIE, nie z pola, które przyszło
 * z przeglądarki. Kto podmieni `email` w formularzu, nie zmieni niczego —
 * bo tamta wartość nie jest w ogóle czytana, gdy zaproszenie jest przyjęte.
 *
 * Powód nie jest formalny. Zmiana adresu unieważniałaby DOWÓD POSIADANIA
 * SKRZYNKI, na którym stoi cała ta droga: konto powstaje z `email_verified_at`
 * ustawionym i BEZ drugiej wiadomości weryfikacyjnej właśnie dlatego, że ktoś
 * kliknął link ze swojej skrzynki. Gdyby dało się przy tym wpisać inny adres,
 * mielibyśmy potwierdzony adres, którego nikt nigdy nie potwierdził — czyli
 * gotową drogę do konta na cudzej skrzynce.
 *
 * W sesji leży sam IDENTYFIKATOR wiersza. Nie adres (bo wtedy adres byłby
 * w dwóch miejscach i dałoby się je rozjechać) i nie token (bo token nie ma po
 * co przeżyć drogi z wiadomości do formularza; jego jedynym zadaniem jest
 * doprowadzić człowieka na ekran).
 *
 * WAŻNOŚĆ SPRAWDZAMY PRZY KAŻDYM ODCZYCIE. Sesja żyje długo, zaproszenie
 * najwyżej `login_link.zaproszenia.waznosc_godzin` — a między jednym
 * a drugim żądaniem zaproszenie mogło wygasnąć, zostać zużyte albo zniknąć
 * ze sprzątaniem. Ta klasa oddaje wtedy `null` i formularz zachowuje się jak
 * zwykła rejestracja: człowiek wpisuje adres i dostaje wiadomość
 * weryfikacyjną normalną drogą. Nie ma ślepej ściany.
 */
final class ZaproszenieWSesji
{
    /** Zaproszenie przyjęte — od teraz rejestracja idzie na TEN adres. */
    public function zapamietaj(RegistrationInvite $zaproszenie): void
    {
        Session::put(RegistrationInvite::KLUCZ_SESJI, $zaproszenie->getKey());
    }

    /**
     * Ważne zaproszenie z sesji — albo `null`.
     *
     * Wiersz nieważny (wygasły, zużyty, skasowany) czyści przy okazji klucz
     * w sesji: martwy identyfikator nie ma po co przeżywać kolejnego żądania
     * i wprowadzać w błąd widoku.
     */
    public function biezace(): ?RegistrationInvite
    {
        $id = Session::get(RegistrationInvite::KLUCZ_SESJI);

        if (! is_string($id) || $id === '') {
            return null;
        }

        $zaproszenie = RegistrationInvite::query()->whereKey($id)->first();

        if ($zaproszenie === null || ! $zaproszenie->jestWazne()) {
            $this->zapomnij();

            return null;
        }

        return $zaproszenie;
    }

    public function zapomnij(): void
    {
        Session::forget(RegistrationInvite::KLUCZ_SESJI);
    }

    /**
     * Zużycie zaproszenia — WOŁANE WEWNĄTRZ TRANSAKCJI ZAKŁADAJĄCEJ KONTO.
     *
     * `lockForUpdate()` nie jest tu ostrożnością na zapas. Bez niego dwa
     * równoległe żądania z tej samej sesji (podwójne kliknięcie „Załóż konto",
     * powtórzone wysłanie formularza) mogłyby OBA odczytać wiersz, OBA uznać
     * go za ważny i OBA pójść dalej. Drugie odbiłoby się dopiero o klucz
     * unikalny na `users.email` — czyli o błąd 500 zamiast o komunikat.
     * Kasujemy w tej samej transakcji, więc drugie żądanie zastaje albo
     * blokadę, albo pustkę.
     *
     * Oddaje adres z WIERSZA — to jest wartość, która trafia do konta.
     * Wołający nie ma po co jej znać skądkolwiek indziej.
     *
     * `null` znaczy „to zaproszenie już nie działa" i musi wycofać całą
     * transakcję: konto z potwierdzonym adresem bez ważnego dowodu
     * posiadania skrzynki nie ma prawa powstać.
     */
    public function zuzyj(RegistrationInvite $zaproszenie): ?string
    {
        if (! self::wTransakcji()) {
            throw new LogicException(
                'ZaproszenieWSesji::zuzyj() wołane poza transakcją. `lockForUpdate()` poza transakcją '
                .'nie blokuje niczego, a wygląda identycznie — zużycie zaproszenia musi iść w tej samej '
                .'transakcji, w której powstaje konto.',
            );
        }

        $wiersz = RegistrationInvite::query()
            ->whereKey($zaproszenie->getKey())
            ->lockForUpdate()
            ->first();

        if ($wiersz === null || ! $wiersz->jestWazne()) {
            $wiersz?->delete();

            return null;
        }

        $adres = $wiersz->email;

        $wiersz->delete();

        return $adres;
    }

    /**
     * Czy jesteśmy w transakcji — sprawdzenie dla `zuzyj()`.
     *
     * `lockForUpdate()` poza transakcją nie blokuje NICZEGO (PostgreSQL zwalnia
     * blokadę na końcu niejawnej transakcji jednego zapytania), a wygląda
     * identycznie w kodzie i w testach. To jest dokładnie ten rodzaj
     * zabezpieczenia, które przestaje działać po cichu — więc pytamy wprost.
     */
    public static function wTransakcji(): bool
    {
        return DB::transactionLevel() > 0;
    }
}
