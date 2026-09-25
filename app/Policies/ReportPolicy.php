<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Moderation\CelZAdresuZgloszenia;
use App\Domain\Moderation\ModeratedContent;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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
    public const WLASNE_ZGLOSZENIE = 'To zgłoszenie pochodzi od Ciebie, więc rozstrzygnie je ktoś inny z moderacji. '
        .'Nikt nie decyduje we własnej sprawie.';

    public const SPRAWA_O_CIEBIE = 'To zgłoszenie dotyczy Twojego konta albo Twojej treści, więc rozstrzygnie je ktoś inny z moderacji. '
        .'Nikt nie decyduje we własnej sprawie.';

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
     * NIKT NIE JEST SĘDZIĄ WE WŁASNEJ SPRAWIE — ani jako ten, kto sprawę
     * wniósł, ani jako ten, kogo sprawa dotyczy.
     *
     *  - Zgłoszenie złożone przez moderatora (albo administratora)
     *    rozstrzyga ktoś inny z moderacji. Bez tej reguły jedna osoba
     *    z uprawnieniami mogła sama wnieść sprawę, sama ją rozstrzygnąć
     *    i zostawić w logu ślad wyglądający jak zwykła, niezależna decyzja
     *    — także „Bez działania", które zamyka sprawę i odpisuje
     *    zgłaszającemu, czyli samemu sobie.
     *  - Zgłoszenie WŁASNEJ treści albo własnego profilu też rozstrzyga ktoś
     *    inny. Reguła rangi (`UserPolicy::sanctionAccount()`) nie pozwala
     *    ukarać samego siebie, ale „Bez działania" oddalałoby skargę ręką
     *    tego, na kogo ją złożono.
     *
     * `reporter_id IS NULL` (zgłoszenie prawne bez konta) nie ma w serwisie
     * strony, która mogłaby się z moderatorem pokrywać — rozstrzyga je każdy
     * moderator, o ile sprawa nie dotyczy jego samego.
     *
     * Cel sprawdzamy razem z miękko usuniętymi: skarga na własny, już
     * skasowany wpis nadal jest własną sprawą.
     *
     * To jest osobna reguła od rangi celu (`UserPolicy::sanctionAccount()`):
     * tamta pilnuje, KOGO wolno ukarać, ta — KTO w ogóle zamyka sprawę.
     */
    public function decide(User $user, Report $report): Response
    {
        if (! $user->isModerator()) {
            return Response::deny('Rozstrzygać zgłoszenia może tylko moderacja.');
        }

        if ($report->reporter_id !== null && $report->reporter_id === $user->getKey()) {
            return Response::deny(self::WLASNE_ZGLOSZENIE);
        }

        $cel = ModeratedContent::znajdz($report->target_type, $report->target_id, zUsunietymi: true);
        $osoba = $cel === null ? null : ModeratedContent::osoba($cel);

        if ($osoba !== null && $osoba->getKey() === $user->getKey()) {
            return Response::deny(self::SPRAWA_O_CIEBIE);
        }

        // Zgłoszenie prawne sprawdzamy jeszcze raz z ADRESU (audyt B2-02).
        // `https://kuking.pl/@moderator` dawało kiedyś `unknown`, reguła nie
        // miała kogo porównać i sprawę zamykał ten, na kogo ją złożono;
        // `…/wpisy/{uuid}#komentarz-{uuid}` wskazywało wpis, więc autor
        // komentarza pod cudzym wpisem rozstrzygał o własnym komentarzu.
        // Formularz rozpoznaje to dziś przy przyjęciu, ale zgłoszenia sprzed
        // tej zmiany leżą w kolejce z celem z tamtej chwili.
        if (is_string($report->target_url) && $report->target_url !== '') {
            [$typ, $id] = CelZAdresuZgloszenia::rozpoznaj($report->target_url);
            $zAdresu = ModeratedContent::znajdz($typ, $id, zUsunietymi: true);

            if ($zAdresu !== null && ModeratedContent::osoba($zAdresu)?->getKey() === $user->getKey()) {
                return Response::deny(self::SPRAWA_O_CIEBIE);
            }
        }

        return Response::allow();
    }
}
