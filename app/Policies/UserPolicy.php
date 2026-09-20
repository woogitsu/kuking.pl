<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Block;
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
            && ! $this->hasBlockRelation($viewer, $target);
    }

    private function hasBlockRelation(User $viewer, User $target): bool
    {
        // Zapis zawsze sprawdza bazę na nowo. Przy renderowaniu listy jedno
        // pobranie blokad wystarcza wszystkim kartom, wyłącznie w tym GET.
        $request = request();
        if (! $request->isMethod('GET')) {
            return $viewer->hasBlockRelationWith($target);
        }

        $key = self::class.'.blocks.'.$viewer->getKey();
        if (! $request->attributes->has($key)) {
            $ids = Block::query()
                ->where('blocker_id', $viewer->getKey())
                ->orWhere('blocked_id', $viewer->getKey())
                ->get(['blocker_id', 'blocked_id'])
                ->map(fn (Block $block) => $block->blocker_id === $viewer->getKey() ? $block->blocked_id : $block->blocker_id)
                ->flip();
            $request->attributes->set($key, $ids);
        }

        return $request->attributes->get($key)->has($target->getKey());
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
}
