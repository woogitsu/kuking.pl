<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Wejście na profil.
     *
     * `jestDostepnyJakoAutor()`, a NIE `jestWidocznyJakoOsoba()` — i to jest
     * świadoma różnica (D-022).
     *
     * Konto `erased` (karencja wykonana, dane wymazane) tę bramkę PRZECHODZI.
     * Profil jest wtedy stroną z podpisem „Użytkownik usunięty" i listą
     * zanonimizowanych treści — a jest to adres, pod który prowadzi każdy
     * podpis pod tymi treściami. Gdyby dawał 403, każdy przepis, który
     * D-018 obiecało zostawić, miałby pod tytułem link do ściany.
     *
     * Konto `erased` nie jest za to NIGDZIE PODPOWIADANE: nie ma go
     * w wyszukiwarce osób (`SearchQuery::people()` pyta o `status = active`),
     * na listach obserwujących (`widocznyJakoOsoba()`), w mapie strony ani
     * w podpowiedziach. Różnica jest więc taka: pod ten adres można DOJŚĆ
     * z treści, ale nikt do niego nie zaprasza. `follow()` niżej i tak
     * odmawia — obserwować da się wyłącznie konto aktywne.
     */
    public function viewProfile(?User $viewer, User $target): bool
    {
        if (! $target->jestDostepnyJakoAutor()) {
            return $viewer !== null && $viewer->isModerator();
        }

        return ! ($viewer !== null && $viewer->hasBlockRelationWith($target));
    }

    public function follow(User $viewer, User $target): bool
    {
        return $viewer->getKey() !== $target->getKey()
            && $viewer->isActive()
            && $target->isActive()
            && ! $viewer->hasBlockRelationWith($target);
    }

    public function moderate(User $viewer): bool
    {
        return $viewer->isModerator();
    }

    public function unfollow(User $viewer, User $target): bool
    {
        // Można wycofać relację także z osobą, której konto przestało być aktywne.
        return $viewer->isActive() && $viewer->getKey() !== $target->getKey();
    }

    /**
     * Rozstrzyganie odwołań od decyzji moderacyjnych (A-4).
     *
     * `moderate()` powyżej wystarcza, żeby WEJŚĆ do panelu moderacji i
     * ZOBACZYĆ kolejkę odwołań — ale sama decyzja („podtrzymuję"/„cofam")
     * ma zapadać wyżej niż moderator, który tę pierwotną decyzję wydał.
     * Przy jednej roli ten sam człowiek był jednocześnie moderatorem
     * i JEDYNYM organem odwoławczym od własnych decyzji: 24-godzinna
     * karencja na PODTRZYMANIE własnej decyzji (`ResolveAppeal::sprawdzKarencje()`)
     * łagodziła to tylko częściowo — nie przeszkadzała ANI temu samemu
     * moderatorowi cofnąć własną decyzję od razu, ANI dowolnemu INNEMU
     * moderatorowi (nie tylko autorowi decyzji) rozstrzygnąć sprawę bez
     * żadnego opóźnienia.
     *
     * Admin JEST też moderatorem (`isModerator()`), więc nadal przechodzi
     * przez `moderate()` — ta bramka tylko zawęża, kto z panelu może
     * naciskać „Podtrzymuję"/„Cofam" pod konkretnym odwołaniem.
     */
    public function resolveAppeals(User $viewer): bool
    {
        return $viewer->isAdmin();
    }

    /**
     * Podgląd tego, co stoi w `failed_jobs` (ekran `/admin/kolejka`).
     *
     * ADMIN, NIE KAŻDY MODERATOR — i to nie jest ostrożność na zapas.
     * Ten ekran jest jedynym miejscem w serwisie, które mówi, co dokładnie
     * się psuje w kolejce: nazwa zadania plus nazwa klasy wyjątku. Z tego
     * składa się obraz infrastruktury — który dostawca poczty odmawia, kiedy
     * pada baza, o której godzinie chodzi worker. Moderator jest tu od treści
     * i od ludzi (`moderate()`), nie od serwera, a rola `moderator` bywa
     * nadawana komuś spoza kręgu osoby prowadzącej wdrożenie (D-039).
     *
     * To jest bramka NA ROLĘ i tylko na nią. Ekran nie ma żadnego
     * identyfikatora w adresie, bo nie ma czego wskazywać — a gdyby kiedyś
     * miał, sam UUID i tak nie byłby autoryzacją (AGENTS.md).
     */
    public function diagnozujKolejke(User $viewer): bool
    {
        return $viewer->isAdmin();
    }

    /**
     * Zawieszenie albo blokada KONTA decyzją moderacyjną (#1408, D-244).
     *
     * Karać wolno wyłącznie konto o NIŻSZEJ roli niż własna:
     *  - moderator zawiesza i blokuje zwykłe konta,
     *  - administrator także konta moderatorów,
     *  - konta administratora nie zawiesza ani nie blokuje nikt z panelu
     *    (równa ranga) — sprawa administratora idzie do właściciela serwisu,
     *    a rolę odbiera się komendą `kuking:nadaj-role`, która pilnuje
     *    ostatniego czynnego administratora (#1016).
     *
     * Wcześniej wystarczało `moderate()`: przejęte albo złośliwe konto
     * moderatora mogło zbanować administratora (ban unieważnia sesje), a przy
     * kilku kolejnych decyzjach — odciąć od panelu wszystkich administratorów
     * i tym samym całą drogę rozpatrywania odwołań.
     *
     * Reguła dotyczy KARY NA KONCIE. Ocena treści (ukrycie, usunięcie,
     * ostrzeżenie) nie zależy od roli autora — wpis administratora łamiący
     * zasady ukrywa się tak samo jak każdy inny.
     *
     * Własne konto jest równej rangi, więc tą samą regułą nikt nie zawiesza
     * sam siebie.
     */
    public function sanctionAccount(User $actor, User $target): bool
    {
        return $actor->isModerator()
            && self::ranga($actor) > self::ranga($target);
    }

    private static function ranga(User $user): int
    {
        return match ($user->role) {
            User::ROLE_ADMIN => 2,
            User::ROLE_MODERATOR => 1,
            default => 0,
        };
    }
}
