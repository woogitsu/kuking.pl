<?php

declare(strict_types=1);

namespace App\Policies;

use App\Http\Support\BlokadyWZadaniu;
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
     * na listach obserwujących (`widocznyJakoOsoba()`) ani w podpowiedziach.
     * Wyjątek (decyzja właściciela z 30.09, D-333): profil z zachowanymi
     * publicznymi treściami jest w mapie strony i bez `noindex` — tą samą
     * bramką co tutaj. Różnica jest więc taka: pod ten adres można DOJŚĆ
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

    /**
     * „Mój rok w kuchni” (#2353): prywatne archiwum, ekran liczy się wyłącznie
     * z danych osoby zalogowanej. Bramka jest ta sama co przy własnych treściach
     * (`mozeCzytac()`): konto zawieszone dalej widzi swój dorobek
     * („poprawne dane nigdy nie znikają”), zamknięte — nie.
     */
    public function viewMyYear(User $viewer): bool
    {
        return $viewer->mozeCzytac();
    }

    public function follow(User $viewer, User $target): bool
    {
        return $viewer->getKey() !== $target->getKey()
            && $viewer->isActive()
            && $target->isActive()
            // Ta sama para co w `RecipePolicy::view()` (autor przepisu) — jedno
            // zapytanie o blokadę na stronę przepisu (audyt wydajności, P3 W8).
            && ! BlokadyWZadaniu::miedzy($viewer, $target);
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
     * Panel „Metryki doboru" (`/admin/metryki`, issue #1814, D-283) — same
     * agregaty, bez osób i wpisów. Admin, nie moderator: to materiał do
     * decyzji właściciela o regułach doboru (D-275), nie narzędzie moderacji.
     */
    public function przegladajMetryki(User $viewer): bool
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

    /**
     * „CSAM — natychmiast ukryj i zabezpiecz” (D-333, 1.10.2026).
     *
     * Czynny moderator albo administrator z POTWIERDZONYM 2FA — ta sama
     * bramka co panel i „Zdejmij z urzędu”, powtórzona tu, bo przycisk
     * rysuje się też poza panelem (przy wpisie, przepisie, komentarzu).
     *
     * ŚWIADOMIE BEZ REGUŁY RANGI AUTORA. `takeDownContentOf()` nie pozwala
     * moderatorowi zdejmować z urzędu treści równego i wyższego rangą, bo
     * tam moderator jest naraz tym, kto sprawę znalazł, i tym, kto ją
     * rozstrzyga. Tu ukrycie i zabezpieczenie są odwracalne tylko ludzką
     * decyzją poza panelem i nikogo nie karzą, a czekanie na „kogoś wyższego”
     * z materiałem widocznym w serwisie jest gorsze niż pomyłka. Karę dla
     * konta (blokadę) nadal rozstrzyga `sanctionAccount()` — zwykły moderator
     * nie zablokuje administratora ani drugiego moderatora.
     */
    public function secureCsam(User $actor): bool
    {
        return $actor->isModerator() && $actor->hasTwoFactorConfirmed();
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
