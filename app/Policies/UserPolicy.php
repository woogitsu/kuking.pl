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
}
