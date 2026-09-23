<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * Kto widzi kartę zgłoszenia na `/zgloszenia/{report}` (issue #10).
 *
 * UUID W ADRESIE NIE JEST AUTORYZACJĄ (`AGENTS.md` §7). Numery spraw trafiają
 * do korespondencji, a identyfikator zgłoszenia widać w adresie przeglądarki
 * — bez tej bramki wystarczyłoby podmienić go w pasku, żeby przeczytać cudze
 * pismo razem z tym, co ta osoba o kimś napisała.
 *
 * TYLKO ZGŁASZAJĄCY, ŚWIADOMIE BEZ FURTKI DLA MODERATORA. Moderator ma własny
 * ekran (`/admin/zgloszenia`), pokazujący WIĘCEJ niż ten: dane zgłaszającego,
 * notatki wewnętrzne i formularz decyzji. Druga droga do tej samej sprawy,
 * przez kartę pisaną dla zgłaszającego, niczego by mu nie dała, a rozszerzała
 * powierzchnię, na której trzeba pilnować, komu co pokazujemy.
 *
 * `reporter_id IS NULL` (zgłoszenie prawne bez konta, art. 16 ust. 2 lit. c)
 * nie należy do nikogo w tej tabeli i nie ma go jak pokazać w serwisie —
 * ta osoba dostaje odpowiedź pocztą (`DecyzjaWSprawieZgloszenia`). Warunek
 * `=== null` stoi tu jawnie, bo bez niego porównanie `null === $user->getKey()`
 * byłoby fałszywe „przez przypadek", a nie dlatego, że tak postanowiliśmy.
 */
class ReportPolicy
{
    public function view(User $user, Report $report): bool
    {
        if ($report->reporter_id === null) {
            return false;
        }

        return $report->reporter_id === $user->getKey();
    }

    /**
     * Kto może ROZSTRZYGNĄĆ zgłoszenie w panelu moderacji (#1408, D-244).
     *
     * NIKT NIE JEST SĘDZIĄ WE WŁASNEJ SPRAWIE. Zgłoszenie złożone przez
     * moderatora (albo administratora) rozstrzyga ktoś inny z moderacji.
     * Bez tej reguły jedna osoba z uprawnieniami mogła sama wnieść sprawę,
     * sama ją rozstrzygnąć i zostawić w logu ślad wyglądający jak zwykła,
     * niezależna decyzja — także „Bez działania", które zamyka sprawę
     * i odpisuje zgłaszającemu, czyli samemu sobie.
     *
     * `reporter_id IS NULL` (zgłoszenie prawne bez konta) nie ma w serwisie
     * strony, która mogłaby się z moderatorem pokrywać — rozstrzyga je każdy
     * moderator.
     *
     * To jest osobna reguła od rangi celu (`UserPolicy::sanctionAccount()`):
     * tamta pilnuje, KOGO wolno ukarać, ta — KTO w ogóle zamyka sprawę.
     */
    public function decide(User $user, Report $report): bool
    {
        if (! $user->isModerator()) {
            return false;
        }

        return $report->reporter_id === null
            || $report->reporter_id !== $user->getKey();
    }
}
