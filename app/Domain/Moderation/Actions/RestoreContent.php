<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Cofnięcie ukrycia albo usunięcia treści (issue #65).
 *
 * CO SIĘ DZIAŁO
 * `ModerationController` miał decyzję `hide`, a w komentarzu do metody
 * `applyAction()` stało wprost: „`hide` ukrywa treść (da się przywrócić)".
 * Endpointu przywracania nie było. Jedyną drogą powrotu był `UPDATE` w
 * produkcyjnej bazie — czyli operacja, której AGENTS.md §6 zabrania bez
 * zgody właściciela. „Da się przywrócić" było nieprawdą w komentarzu.
 *
 * Boli to najbardziej tam, gdzie ukrycie jest z założenia TYMCZASOWE:
 * podręcznik moderacji przy prawach autorskich każe „najpierw ukryć (dać
 * szansę poprawy)". Człowiek poprawia przepis własnymi słowami — i nie ma
 * kto zdjąć ukrycia. Kara przewidziana jako chwilowa robi się dożywotnia.
 *
 * DO JAKIEGO STANU WRACAMY
 * Do tego SPRZED ukrycia, nie na sztywno do `published`. Ukryty szkic po
 * przywróceniu ma być szkicem — inaczej przywracanie upubliczniałoby treści,
 * których autor nigdy nie opublikował. Stan sprzed decyzji zapisuje kolumna
 * `moderation_actions.previous_status` (migracja
 * `2026_09_06_100000_add_context_to_moderation_actions`); gdy go nie ma,
 * `ModeratedContent` oddaje stan bezpieczny, czyli szkic.
 *
 * PRZYWRÓCENIE TEŻ JEST DECYZJĄ
 * Zapisuje wiersz w `moderation_actions` (akcja `unhide`) i powiadamia autora
 * tym samym mechanizmem co każda inna decyzja. Log moderacji, w którym widać
 * karę, a nie widać jej zdjęcia, kłamie przy pierwszym audycie.
 */
final class RestoreContent
{
    public function __construct(private readonly NotifyModerationDecision $powiadom) {}

    /**
     * @param  Model  $target  treść do przywrócenia (może być miękko usunięta)
     * @param  bool  $zPowiadomieniem  `false` tylko wtedy, gdy autor dostanie
     *                                 wiadomość inną drogą. Tak jest przy cofnięciu decyzji po
     *                                 odwołaniu: `NotifyAppealOutcome` mówi to samo, tylko lepiej
     *                                 — a dwa powiadomienia o jednym zdarzeniu wyglądają jak
     *                                 usterka.
     *
     * @throws BladDlaCzlowieka gdy tej treści nie da się przywrócić
     */
    public function handle(
        User $moderator,
        Model $target,
        string $reasonCode,
        ?string $note = null,
        ?string $userMessage = null,
        ?string $ip = null,
        bool $zPowiadomieniem = true,
    ): ModerationAction {
        $typ = ModeratedContent::typ($target);

        if ($typ === null || ! ModeratedContent::daSieUkryc($target)) {
            throw new BladDlaCzlowieka('Tej treści nie da się przywrócić.');
        }

        $bylaUkryta = ModeratedContent::jestUkryta($target);
        $bylaUsunieta = method_exists($target, 'trashed') && $target->trashed();

        // Komentarz z odpowiedziami zdjęty przez moderację: wiersz żyje, ale
        // w `body` stoi napis „Komentarz usunięty.” (G31, `ZdejmijTresc`).
        $bylZastapiony = ! $bylaUsunieta && $target instanceof Comment && $target->body_removed_at !== null;

        if (! $bylaUkryta && ! $bylaUsunieta && ! $bylZastapiony) {
            throw new BladDlaCzlowieka('Ta treść jest już widoczna — nie ma czego przywracać.');
        }

        $tekst = $bylZastapiony ? $this->trescSprzedZdjecia((string) $target->getKey()) : null;

        if ($bylZastapiony && $tekst === null) {
            // Napis postawił autor komentarza albo autor treści pod nim
            // (`DeleteComment`) — tekst skasowali sami. Także wtedy, gdy
            // WCZEŚNIEJ zdjęła go moderacja, a po cofnięciu tamtej decyzji
            // autor sam go usunął: stara kopia nie jest zgodą na powrót.
            throw new BladDlaCzlowieka('Ten komentarz usunęła osoba, która go napisała, albo autor treści, '
                .'pod którą stał. Moderacja nie ma jego tekstu, więc nie da się go przywrócić.');
        }

        $poprzedni = $this->statusSprzedUkrycia($typ, (string) $target->getKey());
        $docelowy = ModeratedContent::statusPoPrzywroceniu($target, $poprzedni);

        // Stan, W KTÓRYM treść była w chwili przywracania — po to, żeby dało
        // się prześledzić także cofnięcie cofnięcia.
        $stanPrzed = (string) ($target->status ?? '');

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            // ŚWIADOMIE NULL, a nie identyfikator zgłoszenia, z którego
            // moderator kliknął „Przywróć treść". Indeks częściowy
            // `moderation_actions_one_per_report` dopuszcza JEDNĄ decyzję na
            // zgłoszenie (migracja 2026_09_06_190000) — przywrócenie jest
            // drugą i nie ma prawa zająć tego miejsca.
            //
            // Uzasadnienie z art. 17 czyta `report_id` po to, żeby powiedzieć
            // autorowi, czy sprawa zaczęła się od zgłoszenia. Puste pole
            // tutaj nic nie psuje: `UzasadnienieDecyzji::zdania()` nie tworzy
            // uzasadnienia dla `unhide` wcale — od zdjęcia ukrycia nikt się
            // nie odwołuje, więc zdanie o pochodzeniu sprawy nigdzie nie
            // trafia i nie ma jak stać się nieprawdą.
            'report_id' => null,
            'target_type' => $typ,
            'target_id' => $target->getKey(),
            'subject_user_id' => ModeratedContent::osoba($target)?->getKey(),
            'action' => ModerationAction::ACTION_UNHIDE,
            'previous_status' => $stanPrzed !== '' ? $stanPrzed : null,
            'reason_code' => $reasonCode,
            'note' => $note,
            'user_message' => $userMessage,
        ]);

        if ($bylaUsunieta) {
            $target->restore();
        }

        if ($tekst !== null) {
            $target->forceFill(['body' => $tekst, 'body_removed_at' => null]);

            // Tekst wrócił do komentarza — kopia przy decyzji nie ma już
            // celu (minimalizacja danych, RODO art. 5 ust. 1 lit. c). Jedyna
            // zmiana istniejącego wiersza rejestru, na jaką pozwala D-251:
            // zerowanie materiału sprawy, nigdy przepisanie decyzji.
            ModerationAction::query()
                ->where('target_type', 'comment')
                ->where('target_id', $target->getKey())
                ->whereNotNull('tresc_sprzed_zdjecia')
                ->update(['tresc_sprzed_zdjecia' => null]);
        }

        $target->forceFill(['status' => $docelowy])->save();

        $osoba = ModeratedContent::osoba($target);

        if ($osoba !== null && $zPowiadomieniem) {
            $this->powiadom->handle(
                osoba: $osoba,
                decyzja: ModerationAction::ACTION_UNHIDE,
                wiadomoscModeratora: $userMessage,
                decyzjaModeracyjna: $decyzja,
            );
        }

        AuditLogEntry::record(
            action: 'moderation.restored',
            actor: $moderator,
            subject: $decyzja,
            metadata: [
                'target_type' => $typ,
                'target_id' => (string) $target->getKey(),
                'restored_to' => $docelowy,
                // Widać, czy status wzięliśmy z logu, czy z bezpiecznego
                // domyślnego — bez tego nie da się później odróżnić „wrócił
                // jako szkic, bo był szkicem" od „wrócił jako szkic, bo nie
                // wiedzieliśmy".
                'previous_status_known' => $poprzedni !== null,
            ],
            ip: $ip,
        );

        return $decyzja;
    }

    /**
     * Tekst komentarza do przywrócenia — tylko wtedy, gdy NAJNOWSZA decyzja
     * `hide`/`remove`/`unhide` o nim to `remove`, która zastąpiła go napisem
     * i zachowała kopię (`ZdejmijTresc`, G31).
     *
     * Nie „ostatnia decyzja `remove` z kopią”: po `unhide` (np. „cofam” po
     * odwołaniu) autor mógł sam usunąć komentarz. Napis stoi wtedy znowu,
     * a stara kopia wyglądałaby jak materiał do przywrócenia — i moderator
     * przywróciłby tekst, który autor świadomie skasował.
     */
    private function trescSprzedZdjecia(string $id): ?string
    {
        $ostatnia = ModerationAction::query()
            ->where('target_type', 'comment')
            ->where('target_id', $id)
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE, ModerationAction::ACTION_UNHIDE])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($ostatnia?->action !== ModerationAction::ACTION_REMOVE) {
            return null;
        }

        return is_string($ostatnia->tresc_sprzed_zdjecia) ? $ostatnia->tresc_sprzed_zdjecia : null;
    }

    /**
     * Status treści sprzed OSTATNIEGO ukrycia albo usunięcia.
     *
     * Indeks `moderation_actions_target_idx (target_type, target_id,
     * created_at DESC)` istnieje od pierwszej migracji, więc to jedno
     * sięgnięcie do bazy, nie przegląd całego logu.
     */
    private function statusSprzedUkrycia(string $typ, string $id): ?string
    {
        $ostatnie = ModerationAction::query()
            ->where('target_type', $typ)
            ->where('target_id', $id)
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE])
            ->orderByDesc('created_at')
            ->first();

        $status = $ostatnie?->previous_status;

        return is_string($status) && $status !== '' ? $status : null;
    }
}
