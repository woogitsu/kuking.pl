<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Community\HostUserResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
     * Odcina od zapytania wiersze kont, które nie liczą się do WAC/kohorty.
     *
     * `$kolumna` to KWALIFIKOWANA kolumna z identyfikatorem konta w zapytaniu
     * zewnętrznym, np. `posts.author_id` albo `users.id`.
     *
     * FILTR W SQL, NIE LISTA UUID W PHP (#1309). Dawne `excludedUserIds()`
     * pobierało do PHP identyfikatory WSZYSTKICH zamkniętych i zalążkowych
     * kont i wstawiało je jako parametry `NOT IN` — w UNION trzy razy. Koszt
     * rósł z całą historią zamkniętych kont, także gdy raport dotyczył małej
     * bieżącej kohorty. `NOT EXISTS` ma stałą liczbę parametrów (statusy
     * i krótka lista nazw), a PostgreSQL wykonuje go jako anti-join po kluczu
     * głównym `users` / `profiles.user_id`.
     *
     * `NOT EXISTS`, a nie `IN (konta liczone)`, bo zachowuje dokładnie dawną
     * semantykę „odetnij wykluczonych": wiersz bez pasującego konta nie znika.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $zapytanie
     */
    public function tylkoLiczeni(EloquentBuilder|QueryBuilder $zapytanie, string $kolumna): void
    {
        // Gospodarz po stabilnym identyfikatorze (#1089), nie po nazwie —
        // w tym samym anti-joinie, żeby wiersz bez konta nie znikał.
        $gospodarzId = (new HostUserResolver)->resolve()?->getKey();

        $zapytanie->whereNotExists(function (QueryBuilder $konto) use ($kolumna, $gospodarzId): void {
            $konto->selectRaw('1')
                ->from('users as wykluczone_konto')
                ->whereColumn('wykluczone_konto.id', $kolumna)
                ->where(function (QueryBuilder $powod) use ($gospodarzId): void {
                    // `STATUSY_ZAMKNIETEGO_KONTA`, czyli razem z `erased`
                    // (D-022): konto po wykonanej karencji już nie gotuje
                    // i nie ma zasilać liczby, która ma mierzyć żywą
                    // społeczność. Jego wykonania zostają widoczne
                    // w serwisie — to dwie różne rzeczy.
                    $powod->whereIn('wykluczone_konto.status', User::STATUSY_ZAMKNIETEGO_KONTA)
                        ->orWhere('wykluczone_konto.is_seeded', true);

                    if ($gospodarzId !== null) {
                        $powod->orWhere('wykluczone_konto.id', $gospodarzId);
                    }
                });
        });

        $nazwy = $this->wykluczoneNazwy();

        if ($nazwy === []) {
            return;
        }

        $zapytanie->whereNotExists(function (QueryBuilder $profil) use ($kolumna, $nazwy): void {
            $profil->selectRaw('1')
                ->from('profiles as wykluczony_profil')
                ->whereColumn('wykluczony_profil.user_id', $kolumna)
                ->whereRaw(
                    'lower(wykluczony_profil.username) IN ('.implode(',', array_fill(0, count($nazwy), '?')).')',
                    $nazwy,
                );
        });
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
