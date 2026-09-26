<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Community\HostUserResolver;
use App\Models\Profile;
use App\Models\User;

/**
 * Kto NIE liczy się do metryk North Star (issue #114).
 *
 * `docs/seo/ANALYTICS.md` §1.3-1.4 (referencja do brakującego
 * `docs/research/ANALITYKA.md`) znalazła lukę w zapytaniach WAC z §2.2
 * i w kohorcie retencji z §3.2: żadne z nich nie wykluczało kont
 * zbanowanych/`pending_delete`, konta gospodarza ani kont testowych.
 * Przy 20-50 kontach zamkniętej alfy gospodarz jako gwarantowany, cotygodniowy
 * wpis jest zauważalnym zniekształceniem liczby, którą zespół czyta jako
 * dowód sukcesu.
 *
 * JEDNO MIEJSCE DLA OBU ZAPYTAŃ
 * `WeeklyActiveCooks` (komenda `kuking:wac`) i `CookRetentionCohorts`
 * (kohorta D1/D7/D30 z §3.2) potrzebują TEJ SAMEJ reguły. Dwie kopie tego
 * samego filtra to dokładnie ta usterka, która wraca w tym repozytorium
 * najczęściej (`docs/HANDOVER.md` §2, patrz też `User::scopeDostepnyJakoAutor`
 * — audyt W5-08/W5-09 o tej samej klasie błędu).
 *
 * Świadomie NIE dotyka `User::scopeDostepnyJakoAutor()` — tamta reguła mówi
 * „czyją treść wolno pokazać komukolwiek" (widoczność), ta mówi „kogo wolno
 * policzyć do North Star" (analityka). Gospodarz jest w pełni widoczny i jego
 * treść ma się wyświetlać — po prostu nie ma zasilać liczby, która ma mierzyć
 * PRAWDZIWE zaangażowanie społeczności.
 *
 * KONTA ZALĄŻKOWE (`users.is_seeded`, D-025) SĄ TU Z TEGO SAMEGO POWODU
 * Dwanaście person z `tresc-zalazkowa.json` publikuje z definicji plikowej,
 * jednorazowo przy imporcie — ale ich treść zostaje na zawsze, więc
 * jakiekolwiek późniejsze „Ugotowałem"/wpis/przepis PRZYPISANY do takiego
 * konta (np. przez pomyłkę importu albo ręczną edycję w panelu) i tak
 * fałszowałby liczbę tak samo, jak zrobiłby to gospodarz. Wykluczenie idzie
 * po KOLUMNIE, nie po liście nazw w configu (jak konta testowe): `is_seeded`
 * jest już jedynym źródłem prawdy o tym, które konta pochodzą z pliku,
 * a druga lista obok niej rozjechałaby się przy pierwszej aktualizacji
 * treści zalążkowej.
 */
final class CookEligibility
{
    /**
     * Identyfikatory użytkowników wykluczonych z liczenia do WAC/kohorty.
     *
     * @return list<string>
     */
    public function excludedUserIds(): array
    {
        $zStatusu = User::query()
            // `STATUSY_ZAMKNIETEGO_KONTA`, czyli razem z `erased` (D-022):
            // konto po wykonanej karencji już nie gotuje i nie ma zasilać
            // liczby, która ma mierzyć żywą społeczność. Jego wykonania
            // zostają widoczne w serwisie — to dwie różne rzeczy.
            ->whereIn('status', User::STATUSY_ZAMKNIETEGO_KONTA)
            ->pluck('id')
            ->all();

        $zZalazka = User::query()
            ->where('is_seeded', true)
            ->pluck('id')
            ->all();

        $gospodarzId = (new HostUserResolver)->resolve()?->getKey();
        $zGospodarza = $gospodarzId === null ? [] : [$gospodarzId];

        $nazwy = $this->wykluczoneNazwy();

        if ($nazwy === []) {
            return array_values(array_unique([...$zStatusu, ...$zZalazka, ...$zGospodarza]));
        }

        $zNazwy = Profile::query()
            ->whereRaw(
                'lower(username) IN ('.implode(',', array_fill(0, count($nazwy), '?')).')',
                $nazwy,
            )
            ->pluck('user_id')
            ->all();

        return array_values(array_unique([...$zStatusu, ...$zZalazka, ...$zGospodarza, ...$zNazwy]));
    }

    /**
     * Konta testowe znormalizowane do porównania bez rozróżniania wielkości
     * liter — tak jak `Profile::poNazwie()`. Gospodarz jest wykluczany wyżej
     * po stabilnym identyfikatorze zwróconym przez `HostUserResolver`.
     *
     * @return list<string>
     */
    private function wykluczoneNazwy(): array
    {
        /** @var array<int, string> $testowe */
        $testowe = config('kuking.account.test_usernames', []);

        $wszystkie = $testowe;

        $znormalizowane = array_map(
            static fn (string $nazwa): string => mb_strtolower(trim($nazwa)),
            $wszystkie,
        );

        return array_values(array_unique(array_filter(
            $znormalizowane,
            static fn (string $nazwa): bool => $nazwa !== '',
        )));
    }
}
