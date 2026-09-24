<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Appeal;
use App\Models\ModerationAction;

/**
 * CZY ZDJĘCIE ZGŁOSZONEJ TREŚCI ZOSTAŁO COFNIĘTE PO ODWOŁANIU AUTORA (#1024).
 *
 * CO BYŁO ŹLE
 * Zgłaszający czytał na liście, na karcie sprawy i w powiadomieniu wyłącznie
 * skutek PIERWSZEJ decyzji — tej z `report_id` jego zgłoszenia. Gdy autor
 * wygrał odwołanie i `RestoreContent` przywrócił wpis, trwały zapis sprawy
 * dalej twierdził „Zgłoszona treść nie jest już dostępna w serwisie", choć
 * wpis był znowu publiczny.
 *
 * SKĄD WIEMY, ŻE TO TA SPRAWA — BEZ ZGADYWANIA PO `reason_code`
 * Powiązanie idzie kluczami obcymi, nie tekstem:
 * `reports.id` ← `moderation_actions.report_id` (decyzja pierwszej instancji)
 * ← `appeals.moderation_action_id` z `appellant = 'author'`
 * i `status = 'overturned'`. `UNIQUE (moderation_action_id, appellant)` daje
 * co najwyżej jedno takie odwołanie na decyzję, więc cofnięcie nie trafi do
 * cudzego zgłoszenia tej samej treści.
 *
 * DRUGI WARUNEK: TREŚĆ NAPRAWDĘ WRÓCIŁA
 * Cofnięta decyzja nie zawsze oznacza widoczną treść — `ResolveAppeal::cofnij()`
 * świadomie nic nie robi, gdy treści już nie ma w bazie. Dlatego ostatnia
 * decyzja o STANIE tej treści (`hide`/`remove`/`unhide`, po
 * `moderation_actions_target_idx`) musi być przywróceniem. Kolejne ukrycie
 * po cofnięciu (inna sprawa, inna decyzja) znowu robi odpowiedź „treści nie
 * ma" prawdziwą — i wtedy ją pokazujemy.
 *
 * RĘCZNE PRZYWRÓCENIE BEZ ODWOŁANIA NIE JEST TU ZMIANĄ DECYZJI
 * „Przywróć treść" z panelu (`RestoreContent` bez odwołania) zwykle znaczy
 * „autor poprawił treść", nie „pomyliliśmy się" — pierwsza decyzja była
 * wtedy słuszna i zgłaszający nie dostaje zdania, że ją zmieniliśmy.
 * Ta granica jest jawna i pilnuje jej test.
 *
 * CZEGO TA KLASA NIE ZDRADZA: kto się odwołał, co napisał i czy chodziło
 * o konto. Oddaje wyłącznie odwołanie, a zdania dla zgłaszającego liczy
 * `OdpowiedzDlaZglaszajacego::skutekPoZmianie()`.
 */
final class ZmianaDecyzjiPoOdwolaniu
{
    /**
     * @param  iterable<ModerationAction>  $decyzje  decyzje pierwszej instancji (z `report_id`)
     * @return array<string, Appeal> klucz: id decyzji; tylko te, których skutek się zmienił
     */
    public static function dla(iterable $decyzje): array
    {
        $zdejmujace = [];

        foreach ($decyzje as $decyzja) {
            if (in_array($decyzja->action, [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE], true)
                && $decyzja->target_id !== null) {
                $zdejmujace[(string) $decyzja->getKey()] = $decyzja;
            }
        }

        if ($zdejmujace === []) {
            return [];
        }

        $cofniete = Appeal::query()
            ->whereIn('moderation_action_id', array_keys($zdejmujace))
            ->where('appellant', Appeal::APPELLANT_AUTHOR)
            ->where('status', Appeal::STATUS_OVERTURNED)
            ->get()
            ->keyBy(static fn (Appeal $a): string => (string) $a->moderation_action_id);

        if ($cofniete->isEmpty()) {
            return [];
        }

        // Ostatni stan każdej treści — jedno zapytanie na całą stronę listy.
        $cele = $cofniete->keys()->map(static fn (string $id): ModerationAction => $zdejmujace[$id]);

        $ostatnie = ModerationAction::query()
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE, ModerationAction::ACTION_UNHIDE])
            ->where(function ($zapytanie) use ($cele): void {
                foreach ($cele as $cel) {
                    $zapytanie->orWhere(static fn ($q) => $q
                        ->where('target_type', $cel->target_type)
                        ->where('target_id', $cel->target_id));
                }
            })
            // Remis w tej samej sekundzie rozstrzyga `id` (UUIDv7) — ten sam
            // porządek co `RestoreContent::statusSprzedUkrycia()`.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique(static fn (ModerationAction $a): string => $a->target_type.':'.$a->target_id)
            ->keyBy(static fn (ModerationAction $a): string => $a->target_type.':'.$a->target_id);

        $wynik = [];

        foreach ($cofniete as $idDecyzji => $odwolanie) {
            $decyzja = $zdejmujace[$idDecyzji];
            $stan = $ostatnie->get($decyzja->target_type.':'.$decyzja->target_id);

            if ($stan?->action === ModerationAction::ACTION_UNHIDE) {
                $wynik[$idDecyzji] = $odwolanie;
            }
        }

        return $wynik;
    }

    public static function czyZmieniona(ModerationAction $decyzja): ?Appeal
    {
        return self::dla([$decyzja])[(string) $decyzja->getKey()] ?? null;
    }
}
