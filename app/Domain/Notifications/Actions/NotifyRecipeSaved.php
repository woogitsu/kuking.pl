<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Powiadomienie autora o zapisaniu jego przepisu do cudzego zeszytu —
 * DECYZJA WŁAŚCICIELA z 20.09.2026, domyka issue #906 i idzie dalej.
 *
 * DWIE RZECZY NARAZ, BO SĄ TYM SAMYM PROBLEMEM:
 *
 *  1. Jedna osoba, kilka SWOICH zeszytów = JEDEN zapis. Woła się to stąd
 *     dopiero, gdy `SaveRecipeToCollection` ustali, że to pierwsze zapisanie
 *     TEJ osoby (w którymkolwiek z jej zeszytów) — patrz tam.
 *  2. Zapisy od RÓŻNYCH osób zlewają się w JEDNO powiadomienie. Pierwsza
 *     osoba dostaje swoje powiadomienie NATYCHMIAST — to jest moment, który
 *     ma cieszyć autora. Każda kolejna, RÓŻNA osoba nie tworzy nowego
 *     wiersza — dokłada się do tego samego, jeszcze NIEPRZECZYTANEGO
 *     powiadomienia. Odczytanie zamyka partię: następny zapis zaczyna nową.
 *
 * WZORZEC BLOKADY: `e89f28a5` (`NotifyReporterReceipt`) — warunkowy zapis
 * w transakcji zamiast liczenia na to, że dwa równoległe żądania grzecznie
 * poczekają w kolejce. Tutaj zamkiem jest `SELECT ... FOR UPDATE` na
 * otwartej (nieprzeczytanej) partii: dwa równoległe zapisy od dwóch różnych
 * osób mają dać DOKŁADNIE jedną zaktualizowaną partię, nie dwie.
 */
final class NotifyRecipeSaved
{
    public function handle(User $saver, Recipe $recipe): void
    {
        $recipient = $recipe->author;

        if ($recipient === null || $recipient->getKey() === $saver->getKey()) {
            return;
        }

        if (! $recipient->mozeCzytac()) {
            return;
        }

        if ($recipient->hasBlockRelationWith($saver)) {
            return;
        }

        DB::transaction(function () use ($saver, $recipe, $recipient): void {
            $otwarta = Notification::query()
                ->where('user_id', $recipient->getKey())
                ->where('type', Notification::TYPE_SAVED)
                ->where('data->recipe_id', $recipe->getKey())
                ->whereNull('read_at')
                ->lockForUpdate()
                ->first();

            if ($otwarta === null) {
                // PIERWSZA OSOBA — powiadamia NATYCHMIAST, osobnym wierszem.
                Notification::create([
                    'user_id' => $recipient->getKey(),
                    'actor_id' => $saver->getKey(),
                    'type' => Notification::TYPE_SAVED,
                    'data' => [
                        'recipe_id' => $recipe->getKey(),
                        'recipe_title' => $recipe->title,
                        'recipe_slug' => $recipe->slug,
                        'savers' => [$saver->getKey()],
                        'others_count' => 0,
                    ],
                ]);

                return;
            }

            $savers = $otwarta->data['savers'] ?? [$otwarta->actor_id];

            if (in_array($saver->getKey(), $savers, true)) {
                // Już jest w tej partii (np. zapisała, wyszła z jednego
                // zeszytu i wróciła w innym) — nic nowego do dołożenia.
                return;
            }

            $savers[] = $saver->getKey();

            $otwarta->forceFill([
                'actor_id' => $savers[0],
                'data' => array_merge($otwarta->data, [
                    'savers' => $savers,
                    'others_count' => count($savers) - 1,
                ]),
            ])->save();
        });
    }

    /**
     * Wycofanie zapisu (issue #906, pytanie właściciela): jeśli osoba
     * wyjmuje przepis ze WSZYSTKICH swoich zeszytów, ZANIM autor przeczytał
     * powiadomienie o tej partii, jej udział znika z treści — tak, jakby
     * nigdy nie zapisała. Jeśli to ona była "pierwszą" i została wymieniona
     * z nazwy, miejsce przejmuje kolejna osoba z tej samej partii. Jeśli
     * była jedyna — partia znika całkowicie, bo nie ma już czego zbierać.
     *
     * PO PRZECZYTANIU powiadomienie jest już historią i się go nie rusza —
     * "poprawne dane nigdy nie znikają" (AGENTS.md), a przeczytana wiadomość
     * jest dowodem tego, co autor faktycznie zobaczył.
     */
    public function cofnij(User $saver, Recipe $recipe): void
    {
        DB::transaction(function () use ($saver, $recipe): void {
            $otwarta = Notification::query()
                ->where('type', Notification::TYPE_SAVED)
                ->where('data->recipe_id', $recipe->getKey())
                ->whereNull('read_at')
                ->lockForUpdate()
                ->first();

            if ($otwarta === null) {
                return;
            }

            $savers = $otwarta->data['savers'] ?? [$otwarta->actor_id];

            if (! in_array($saver->getKey(), $savers, true)) {
                return;
            }

            $pozostali = array_values(array_filter(
                $savers,
                fn (?string $id) => $id !== $saver->getKey(),
            ));

            if ($pozostali === []) {
                $otwarta->delete();

                return;
            }

            $otwarta->forceFill([
                'actor_id' => $pozostali[0],
                'data' => array_merge($otwarta->data, [
                    'savers' => $pozostali,
                    'others_count' => count($pozostali) - 1,
                ]),
            ])->save();
        });
    }
}
