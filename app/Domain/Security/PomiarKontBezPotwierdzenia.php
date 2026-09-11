<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Wynik pomiaru kont bez potwierdzonego adresu e-mail (issue #317).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TO NIE JEST JEDNA LICZBA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Issue #317 pyta wprost: „ile jest dziś kont bez `email_verified_at`?".
 * Sama ta liczba jest jednak do decyzji BEZUŻYTECZNA, a przy tym zawyżona
 * w sposób, którego nie widać z zewnątrz — i to jest cały powód, dla którego
 * ta klasa ma dwanaście pól zamiast jednego:
 *
 *  1. `EraseAccountData` (D-022) przy anonimizacji konta USTAWIA
 *     `email_verified_at` na `null` (razem z adresem, hasłem i nazwą). Każde
 *     konto po wykonanej karencji siedzi więc w naiwnym `whereNull(...)`
 *     i podnosi liczbę, którą właściciel miałby przeczytać jako „tylu ludzi
 *     stracimy". Nie straci ani jednego: na konto ze statusem `erased`
 *     `WyslijLinkDoLogowania::wolnoWyslac()` linku nie wysyła DZIŚ, przed
 *     jakąkolwiek naprawą.
 *  2. Konta obsługi serwisu (`moderator`, `admin`) linku nie dostają
 *     w ogóle — z tej samej metody, z tego samego powodu (D-056,
 *     rozstrzygnięcie 4).
 *  3. Konta zalążkowe (`is_seeded`, D-025) to dwanaście person z pliku,
 *     nie ludzie. Nikt się nimi nie loguje.
 *
 * Liczbą, która naprawdę odpowiada na pytanie „kogo to dotyczy", jest
 * więc `$dotknieci`: konto bez potwierdzonego adresu, którego status
 * przepuszcza pocztę, które nie należy do obsługi serwisu i nie pochodzi
 * z pliku zalążkowego. Pozostałe pola stoją obok po to, żeby było widać,
 * ile odjęto i dlaczego — a nie żeby wierzyć jednej liczbie na słowo.
 *
 * PO NAPRAWIE #317 TEN SAM ZBIÓR ZNACZY CO INNEGO. Pytanie brzmiało
 * „komu zamkniemy drzwi", bo rozważaną naprawą była odmowa wysyłki.
 * Wybrano inną: konto bez potwierdzenia dostaje z formularza logowania
 * list z ustawieniem hasła (`WyslijOdzyskanieKonta`), a ten po kliknięciu
 * adres potwierdza. Drzwi nie zamknięto nikomu — `$dotknieci` liczy dziś
 * tych, którzy wchodzą dłuższą drogą, dopóki raz jej nie przejdą.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  „ŻYWE" KONTRA „PUSTA REJESTRACJA"
 * ────────────────────────────────────────────────────────────────────────
 *
 * `$dotknieciZywi` to te z `$dotknieci`, które mają w serwisie choć jeden
 * ślad pracy: wpis, przepis, komentarz albo „Ugotowałem". Ta różnica jest
 * sednem decyzji z issue #317, bo koszt zamknięcia drogi jest inny dla
 * człowieka, który ma tu trzy lata gotowania, i inny dla wiersza, który
 * powstał raz i nigdy nic nie zrobił.
 *
 * LICZYMY TEŻ SZKICE (`status = draft`) i wpisy prywatne — bo szkic
 * nieopublikowanego przepisu rodzinnego jest dokładnie tą rzeczą, którą
 * człowiek traci razem z drogą wejścia. Nie liczymy treści skasowanej
 * miękko: `SoftDeletes` odcina ją globalnym zakresem, a treść, którą ktoś
 * sam usunął, nie jest argumentem za utrzymaniem otwartych drzwi.
 */
final readonly class PomiarKontBezPotwierdzenia
{
    public function __construct(
        /** Wszystkie wiersze w `users`, bez żadnego filtra — mianownik procentu. */
        public int $kontaWszystkie,
        /** Naiwne `whereNull('email_verified_at')` — liczba z pytania w issue #317. */
        public int $bezPotwierdzeniaWszystkie,
        /** Z tego: konta zamknięte (`banned`, `pending_delete`, `erased`) — linku nie dostają dziś. */
        public int $bezPotwierdzeniaZamkniete,
        /** Z tego: konta zalążkowe z pliku (`is_seeded`, D-025) — nikt się nimi nie loguje. */
        public int $bezPotwierdzeniaZalazkowe,
        /** Z tego: moderatorzy i administratorzy — linku nie dostają dziś (D-056). */
        public int $bezPotwierdzeniaObsluga,
        /** KOGO DOTYCZY NAPRAWA #317: konta, którym link zamienił się na list z ustawieniem hasła. */
        public int $dotknieci,
        /** Z `$dotknieci`: mają choć jeden wpis, przepis, komentarz albo „Ugotowałem". */
        public int $dotknieciZywi,
        public int $dotknieciZWpisem,
        public int $dotknieciZPrzepisem,
        public int $dotknieciZKomentarzem,
        public int $dotknieciZUgotowaniem,
        /** Z `$dotknieci`: byli tu kiedykolwiek zalogowani (`ostatnio_widziany_at`). */
        public int $dotknieciWidzianiKiedykolwiek,
    ) {}

    /**
     * Puste rejestracje: konto bez potwierdzonego adresu, które nigdy nic
     * nie zrobiło. Kandydat na „tym zamknięcie drzwi nie zabiera niczego".
     */
    public function dotknieciPuscy(): int
    {
        return $this->dotknieci - $this->dotknieciZywi;
    }

    /** Procent wszystkich kont, liczony z naiwnej liczby — tej z pytania w issue. */
    public function procentBezPotwierdzenia(): float
    {
        return $this->procent($this->bezPotwierdzeniaWszystkie);
    }

    /** Procent wszystkich kont, liczony z liczby realnie dotkniętych. */
    public function procentDotknietych(): float
    {
        return $this->procent($this->dotknieci);
    }

    /**
     * Zero kont to zero procent, nie dzielenie przez zero. Pusta baza jest
     * tu przypadkiem NORMALNYM (świeży `migrate:fresh`, środowisko preview),
     * a nie sytuacją do zgłoszenia wyjątkiem.
     */
    private function procent(int $licznik): float
    {
        if ($this->kontaWszystkie === 0) {
            return 0.0;
        }

        return round($licznik * 100 / $this->kontaWszystkie, 1);
    }
}
