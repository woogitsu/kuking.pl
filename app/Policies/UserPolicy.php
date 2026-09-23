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

    /**
     * Zdjęcie treści Z URZĘDU — bez niczyjego zgłoszenia (G31, D-251).
     *
     * Wspólna reguła dla `removeExOfficio()` w politykach treści. Trzy
     * warunki, wszystkie naraz:
     *
     *  - czynny moderator albo administrator (`isModerator()` patrzy też na
     *    status konta, #1336);
     *  - POTWIERDZONE 2FA — ta sama reguła wejścia co panel
     *    (`moderator.2fa`). Stoi też tu, a nie tylko w middleware, bo przycisk
     *    przy treści rysuje się poza panelem: bez 2FA prowadziłby na ekran
     *    odmowy;
     *  - autor ma NIŻSZĄ rolę. Inaczej niż przy decyzji ze zgłoszenia, gdzie
     *    ocena treści od roli autora nie zależy (D-244 pkt 3). Tam sprawę
     *    wnosi ktoś drugi, a rozstrzygający nie jest jej stroną. Z urzędu
     *    jeden człowiek jest naraz tym, kto sprawę znalazł, i tym, kto ją
     *    rozstrzyga — więc wobec równych i wyższych rangą nie rozstrzyga
     *    sam. Wpis administratora (albo drugiego moderatora) łamiący zasady
     *    idzie zwykłym „Zgłoś” i trafia do kogoś innego (`ReportPolicy::decide()`).
     *    Ta sama reguła rangi wyklucza zdejmowanie własnej treści tą drogą —
     *    własną usuwa się zwykłym „Usuń”.
     */
    public function takeDownContentOf(User $actor, ?User $author): bool
    {
        return $actor->isModerator()
            && $actor->hasTwoFactorConfirmed()
            && $author !== null
            && self::ranga($actor) > self::ranga($author);
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
