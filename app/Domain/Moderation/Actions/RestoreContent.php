<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Moderation\ModeratedContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

        // JEDNA TRANSAKCJA, BLOKADA WIERSZA NA POCZĄTKU (przegląd G31, B1).
        //
        // Wcześniej kroki szły bez transakcji: INSERT `unhide`, potem zapis
        // treści. Awaria między nimi zostawiała decyzję „przywrócone” przy
        // treści, która nadal była schowana. Dwa równoległe przywrócenia
        // (dwie karty, „Przywróć” i „cofam” naraz) czytały ten sam stary
        // stan i dawały dwie decyzje `unhide` i dwa powiadomienia.
        //
        // Stan czytamy POD blokadą, z bazy, a nie z modelu wołającego —
        // drugi w kolejce widzi „już widoczna” i nie zapisuje nic.
        // `ResolveAppeal::cofnij()` łapie ten wyjątek; zagnieżdżone
        // `DB::transaction` cofa wtedy tylko swój punkt zapisu.
        return DB::transaction(function () use ($moderator, $target, $typ, $reasonCode, $note, $userMessage, $ip, $zPowiadomieniem): ModerationAction {
            $zapytanie = $target::query();

            if (method_exists($target, 'trashed')) {
                $zapytanie->withTrashed();
            }

            $cel = $zapytanie->whereKey($target->getKey())->lockForUpdate()->first();

            if ($cel === null) {
                throw new BladDlaCzlowieka('Tej treści już nie ma w bazie — nie da się jej przywrócić.');
            }

            return $this->przywrocPodBlokada($moderator, $cel, $typ, $reasonCode, $note, $userMessage, $ip, $zPowiadomieniem);
        });
    }

    private function przywrocPodBlokada(
        User $moderator,
        Model $target,
        string $typ,
        string $reasonCode,
        ?string $note,
        ?string $userMessage,
        ?string $ip,
        bool $zPowiadomieniem,
    ): ModerationAction {
        $bylaUkryta = ModeratedContent::jestUkryta($target);
        $bylaUsunieta = method_exists($target, 'trashed') && $target->trashed();

        if (! $bylaUkryta && ! $bylaUsunieta) {
            throw new BladDlaCzlowieka('Ta treść jest już widoczna — nie ma czego przywracać.');
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

        $target->forceFill(['status' => $docelowy])->save();

        $korzenJakoSlad = $this->przywrocKorzenJakoSlad($target);

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
                'parent_restored_as_placeholder' => $korzenJakoSlad,
            ],
            ip: $ip,
        );

        return $decyzja;
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
            // Remis w tej samej sekundzie: `created_at` to `timestamptz(0)`,
            // więc „zdjęte” i „cofnięte” w jednej sekundzie remisują. `id`
            // to UUIDv7 (`HasUuids`) — rośnie z czasem w milisekundach
            // (przegląd G31).
            ->orderByDesc('id')
            ->first();

        $status = $ostatnie?->previous_status;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Odpowiedź wraca widocznie — także wtedy, gdy autor usunął jej korzeń,
     * zanim moderacja zdjęła ukrycie (#1317).
     *
     * Wątek pokazuje odpowiedzi tylko wewnątrz żywego komentarza głównego.
     * Korzeń w koszu = odpowiedź „przywrócona”, ale nikt jej nie widzi.
     * Wracamy więc korzeń jako ślad „Komentarz usunięty.” — ten sam, który
     * zostawia `DeleteComment`, gdy pod korzeniem jest odpowiedź. Tekst,
     * który autor korzenia usunął, NIE wraca.
     *
     * Korzeń zdjęty przez moderację (ostatnia decyzja o nim to `remove`)
     * zostaje w koszu: o nim rozstrzyga osobna decyzja, a przywrócenie
     * odpowiedzi nie jest furtką do jej obejścia. Korzeń ukryty (status
     * `hidden`) też zostaje ukryty — odpowiedź pokaże się razem z nim.
     *
     * Zwraca `true`, gdy korzeń wrócił jako ślad.
     */
    private function przywrocKorzenJakoSlad(Model $target): bool
    {
        if (! $target instanceof Comment || $target->parent_id === null) {
            return false;
        }

        $korzen = Comment::withTrashed()->whereKey($target->parent_id)->lockForUpdate()->first();

        if ($korzen === null || ! $korzen->trashed()) {
            return false;
        }

        $ostatnia = ModerationAction::query()
            ->where('target_type', ModeratedContent::typ($korzen))
            ->where('target_id', $korzen->getKey())
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE, ModerationAction::ACTION_UNHIDE])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('action');

        if ($ostatnia === ModerationAction::ACTION_REMOVE) {
            return false;
        }

        $korzen->forceFill([
            $korzen->getDeletedAtColumn() => null,
            'body' => DeleteComment::DELETED_PLACEHOLDER,
            'body_removed_at' => $korzen->body_removed_at ?? now(),
        ])->save();

        return true;
    }
}
