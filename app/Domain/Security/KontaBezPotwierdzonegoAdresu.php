<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Ile kont nie ma potwierdzonego adresu e-mail — i ilu ludzi naprawdę
 * dotknęłoby zamknięcie logowania linkiem dla takich kont (issue #317).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TO JEST POMIAR, NIE ZMIANA ZACHOWANIA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ta klasa NICZEGO nie blokuje, nie zapisuje i nie wysyła. Powstała po to,
 * żeby właściciel podjął decyzję z issue #317 na liczbie, a nie na
 * przeczuciu — bo odruchowa naprawa („wymagaj `email_verified_at` przed
 * wysłaniem linku") zamyka drogę wejścia ludziom, dla których jest ona
 * drogą PODSTAWOWĄ, nie awaryjną (D-056, `docs/research/AUDIENCE_50_PLUS.md`:
 * podstawowe umiejętności cyfrowe ma 12,3% osób w wieku 65-74).
 *
 * Dlatego reguła „kto się liczy" mieszka tutaj, w `app/Domain`, a nie
 * w komendzie — dokładnie z tego powodu, z którego mieszka tu
 * `CookEligibility`: liczba, którą właściciel przeczyta jako podstawę
 * decyzji, nie ma prawa zależeć od tego, którym wywołaniem ją policzono.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE JEDNO `whereNull('email_verified_at')`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo taka liczba jest ZAWYŻONA i to nie jest kwestia gustu — pełne
 * uzasadnienie trzech odjęć stoi w `PomiarKontBezPotwierdzenia`. Najkrócej:
 * anonimizacja konta (D-022) sama zeruje `email_verified_at`, a konta
 * zamknięte, konta obsługi serwisu i konta zalążkowe linku do logowania
 * NIE DOSTAJĄ JUŻ DZIŚ — ich zamknięcie drogi nie dotyczy, więc w liczbie
 * kosztu nie mają czego robić.
 *
 * Warunki „komu dziś wolno wysłać link" są tu przepisane z
 * `WyslijLinkDoLogowania::wolnoWyslac()` i to jest jedyne miejsce w tej
 * klasie, które trzeba poprawić, jeśli tamta metoda kiedyś się zmieni.
 * Pilnuje tego zgodności `test_pomiar_zgadza_sie_z_tym_komu_dzis_wolno_wyslac_link`.
 */
final class KontaBezPotwierdzonegoAdresu
{
    public function policz(): PomiarKontBezPotwierdzenia
    {
        return new PomiarKontBezPotwierdzenia(
            kontaWszystkie: User::query()->count(),
            bezPotwierdzeniaWszystkie: $this->bezPotwierdzenia()->count(),
            bezPotwierdzeniaZamkniete: $this->bezPotwierdzenia()
                ->whereIn('status', User::STATUSY_ZAMKNIETEGO_KONTA)
                ->count(),
            bezPotwierdzeniaZalazkowe: $this->osiagalniLinkiem()
                ->where('is_seeded', true)
                ->count(),
            bezPotwierdzeniaObsluga: $this->osiagalniLinkiem()
                ->where('is_seeded', false)
                ->whereIn('role', [User::ROLE_MODERATOR, User::ROLE_ADMIN])
                ->count(),
            dotknieci: $this->dotknieci()->count(),
            dotknieciZywi: $this->dotknieci()
                ->where(function (Builder $konto): void {
                    // „Choć jeden ślad pracy" — cztery rodzaje wpisu z issue
                    // #317 w jednym nawiasie, bo każdy z nich osobno
                    // wystarcza. Nawias jest tu konieczny: bez niego
                    // `orWhereHas` rozerwałby warunki statusu i roli z
                    // `dotknieci()` i policzyłby także moderatorów.
                    $konto->whereHas('posts')
                        ->orWhereHas('recipes')
                        ->orWhereHas('comments')
                        ->orWhereHas('cookedEvents');
                })
                ->count(),
            dotknieciZWpisem: $this->dotknieci()->whereHas('posts')->count(),
            dotknieciZPrzepisem: $this->dotknieci()->whereHas('recipes')->count(),
            dotknieciZKomentarzem: $this->dotknieci()->whereHas('comments')->count(),
            dotknieciZUgotowaniem: $this->dotknieci()->whereHas('cookedEvents')->count(),
            dotknieciWidzianiKiedykolwiek: $this->dotknieci()
                ->whereNotNull('ostatnio_widziany_at')
                ->count(),
        );
    }

    /**
     * Konta bez potwierdzonego adresu — naiwny warunek z pytania w issue
     * #317, bez żadnego odjęcia.
     *
     * Każde wywołanie buduje NOWE zapytanie. Współdzielony `Builder` byłby
     * tu pułapką: `->count()` nie czyści warunków, więc drugi odczyt
     * dostawałby warunki pierwszego i liczby wychodziłyby malejąco, bez
     * jednego błędu.
     *
     * @return Builder<User>
     */
    private function bezPotwierdzenia(): Builder
    {
        return User::query()->whereNull('email_verified_at');
    }

    /**
     * Konta bez potwierdzenia, DO KTÓRYCH link dziś w ogóle dochodzi —
     * czyli takie, których statusu nie odrzuca `wolnoWyslac()`.
     *
     * `suspended` TU ZOSTAJE i nie jest to przeoczenie: zawieszenie nie jest
     * w `STATUSY_ZAMKNIETEGO_KONTA` (patrz komentarz tej stałej — „karą jest
     * pisanie, nie wejście"), więc konto zawieszone link dostaje i naprawa
     * odebrałaby mu go tak samo jak koncie aktywnemu.
     *
     * @return Builder<User>
     */
    private function osiagalniLinkiem(): Builder
    {
        return $this->bezPotwierdzenia()
            ->whereNotIn('status', User::STATUSY_ZAMKNIETEGO_KONTA);
    }

    /**
     * REALNY KOSZT NAPRAWY: konta, które dziś wchodzą linkiem, a po
     * naprawie już nie wejdą.
     *
     * @return Builder<User>
     */
    private function dotknieci(): Builder
    {
        return $this->osiagalniLinkiem()
            ->where('is_seeded', false)
            ->whereNotIn('role', [User::ROLE_MODERATOR, User::ROLE_ADMIN]);
    }
}
