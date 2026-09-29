<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Feed\DailyBoard;
use App\Domain\Search\SearchQuery;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;

/**
 * Dane ekranu „kogo obserwować" — i „znasz już kogoś tutaj?" — wyjęte
 * z `OnboardingController::people()` bez zmiany zachowania (#970).
 * Wejście z adresu i z sesji czyta `EkranLudziRequest`.
 *
 * Wyszukiwanie idzie DOKŁADNIE przez `SearchQuery::people()` — tę samą
 * klasę, której używa `SearchController` na `/szukaj`. Żadnej drugiej
 * wyszukiwarki, żadnych nowych reguł widoczności: kto jest zbanowany,
 * zawieszony, w trakcie usuwania konta albo zablokował/został zablokowany
 * przez tego widza, ten tam już dziś nie wychodzi.
 *
 * NIE ZAPISUJEMY SYGNAŁU ANALITYCZNEGO. W przeciwieństwie do `/szukaj`,
 * ten krok świadomie NIE woła `ZapiszSygnal` — nie ma decyzji produktowej,
 * że warto mierzyć to osobno (`docs/research/MIGRACJA_Z_GARNKA.md` §3.1).
 */
final class PrzygotujEkranLudzi
{
    /**
     * Ile trafień wyszukiwarki pokazujemy najwyżej na tym kroku.
     *
     * Celowo dużo mniej niż na `/szukaj` (tam 20). Ten krok ma pomóc
     * odnaleźć JEDNĄ konkretną, znaną osobę — nie przeglądać listę.
     * Przy popularnym imieniu wolimy powiedzieć „wpisz dokładniej", niż
     * dołożyć stronicowanie, które zamieniłoby to w katalog ludzi.
     */
    public const WYNIKI_WYSZUKIWANIA = 5;

    public function __construct(
        private readonly DailyBoard $board,
        private readonly SearchQuery $search,
    ) {}

    /**
     * @param  list<string>  $zaznaczone  nazwy zaznaczone wcześniej (już ważny wybór, inaczej pusta lista)
     * @param  array<string, string>  $oczekiwani  małe litery nazwy => identyfikator widziany na ekranie
     * @return array<string, mixed> zmienne widoku `pages.onboarding.people` (bez tokenu wyboru i flagi wygaśnięcia)
     */
    public function handle(User $viewer, string $phrase, array $zaznaczone, array $oczekiwani): array
    {
        $selected = $zaznaczone;
        /** @var MessageBag $searchErrors */
        $searchErrors = SearchQuery::phraseValidator($phrase, 'Imię lub nazwa użytkownika')->errors();

        // `jestPrzeszukiwalna()` MUSI się zgadzać z tym, co i tak robi
        // `SearchQuery::people()` (krócej niż 2 znaki ALBO pusta po
        // normalizacji, #1050, w ogóle nie odpytuje bazy), inaczej ekran
        // pokazałby „nic nie znaleźliśmy" tam, gdzie baza w ogóle nie
        // została zapytana.
        $zaKrotka = $phrase !== '' && ! SearchQuery::jestPrzeszukiwalna(SearchQuery::peoplePhrase($phrase));

        $wynikiWyszukiwania = null;

        if ($phrase !== '' && ! $zaKrotka && $searchErrors->isEmpty()) {
            $wynikiWyszukiwania = $this->search
                // Szukającego samego siebie nie ma sensu proponować mu
                // do zaobserwowania — `FollowUser` i tak by to odrzucił,
                // ale checkbox przy własnym koncie byłby mylący. Wykluczenie
                // idzie W ZAPYTANIU (`bezWidza`), nie przez `reject()` po
                // `LIMIT`: własny profil zajmował wtedy jedno z sześciu miejsc,
                // ekran gubił poprawną osobę i kłamał, że więcej nie ma (#945).
                ->people($phrase, $viewer, self::WYNIKI_WYSZUKIWANIA + 1, bezWidza: true);
        }

        $results = $wynikiWyszukiwania?->take(self::WYNIKI_WYSZUKIWANIA);
        // Wyniki wyszukiwania wykluczamy PRZED limitem SQL (#1299) — odsiane
        // po fakcie zjadałyby miejsca na liście polecanych.
        $people = $this->board->peopleToFollow($viewer, 8, $results?->pluck('user_id')->all() ?? []);
        $visibleNames = $people->pluck('profile.username')->merge($results?->pluck('username') ?? []);
        $selectedProfiles = Profile::query()->whereIn('username', $selected)->with('user')->get()
            ->filter(fn (Profile $profile) => $profile->user !== null && $viewer->can('follow', $profile->user));
        // Nazwa, która od wyrenderowania zmieniła właściciela, nie wraca
        // zaznaczona przy nowej osobie — tak samo jak przy zapisie
        // (`ObserwujWybraneOsoby`).
        /** @var array{0: Collection<int, Profile>, 1: Collection<int, Profile>} $podzial */
        $podzial = $selectedProfiles->partition(function (Profile $profile) use ($oczekiwani) {
            $oczekiwanyId = $oczekiwani[mb_strtolower($profile->username)] ?? null;

            return $oczekiwanyId === null || (string) $profile->user_id === $oczekiwanyId;
        });
        [$selectedProfiles, $zmienionePrzyWyborze] = $podzial;
        // whereIn nie gwarantuje kolejności. Zachowaj kolejność wyboru także
        // po kolejnych wyszukiwaniach, gdy identyfikatory w bazie są przemieszane.
        $pozycjeWyboru = array_flip($selected);
        $selectedProfiles = $selectedProfiles->sortBy(fn (Profile $profile) => $pozycjeWyboru[$profile->username] ?? PHP_INT_MAX)->values();
        $selected = $selectedProfiles->pluck('username')->all();

        return [
            'people' => $people,
            'selectedFollows' => $selected,
            'selectedProfiles' => $selectedProfiles->reject(fn ($profile) => $visibleNames->contains($profile->username)),
            'zmienionePrzyWyborze' => $zmienionePrzyWyborze->pluck('username')->values()->all(),
            'phrase' => $phrase,
            'searchErrors' => $searchErrors,
            'zaKrotka' => $zaKrotka,
            'wynikiWyszukiwania' => $results,
            'jestWiecejWynikow' => ($wynikiWyszukiwania?->count() ?? 0) > self::WYNIKI_WYSZUKIWANIA,
        ];
    }
}
