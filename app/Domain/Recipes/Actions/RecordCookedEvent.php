<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Ugotowałem" — zapis realnego wykonania przepisu.
 *
 * To jest najcenniejsze zdarzenie w całym Kuking i jednocześnie najmilszy
 * moment dla autora przepisu. Dlatego:
 *
 *  - powiadomienie autora jest OBOWIĄZKOWĄ częścią tej operacji, nie dodatkiem;
 *  - nie wymagamy zdjęcia ani żadnego pola — wystarczy sam fakt ugotowania;
 *  - ta sama osoba może zrobić to dowolnie wiele razy dla tego samego przepisu.
 *
 * JEDNO WYSŁANIE FORMULARZA TO JEDNO WYKONANIE I JEDNO POWIADOMIENIE (ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`, wariant A3).
 *
 * Zmierzone przed zmianą: dwa kliknięcia „Wyślij" dawały dwa wiersze i DWA
 * powiadomienia u autora przepisu. Wykonanie da się usunąć — powiadomienia
 * nie da się cofnąć, a licznik „ugotowali to 4 osoby" przestawał znaczyć
 * „cztery osoby".
 *
 * OSTATNI PUNKT LISTY WYŻEJ ZOSTAJE NIENARUSZONY (D-005, „obowiązuje,
 * nienaruszalne"). Klucz wysłania to tożsamość FORMULARZA, nie przepisu:
 * drugie prawdziwe gotowanie przychodzi z nowego formularza, więc z nowym
 * kluczem, i zapisuje się normalnie. Zakazane jest wyłącznie policzenie
 * jednego wysłania dwa razy. Okno czasowe „ta sama treść w ciągu N sekund"
 * NIE zostało wybrane właśnie dlatego, że przy pustym wykonaniu (a pola tu są
 * wszystkie opcjonalne) degenerowałoby się do `(user_id, recipe_id)`, czyli
 * do tego, czego D-005 zakazuje (ADR §3.3).
 */
final class RecordCookedEvent
{
    public function __construct(private readonly NotifyUser $notify) {}

    /**
     * @param  list<string>  $mediaIds
     * @param  string|null  $kluczWyslania  tożsamość TEGO wysłania formularza; `null` znaczy
     *                                      „nie wiemy, zapisuj normalnie" (ADR §4.3)
     */
    public function handle(
        User $cook,
        Recipe $recipe,
        ?string $note = null,
        array $mediaIds = [],
        ?bool $wouldMakeAgain = null,
        ?string $perceivedDifficulty = null,
        ?int $actualMinutes = null,
        ?string $changesNote = null,
        ?string $ip = null,
        ?string $kluczWyslania = null,
    ): CookedEvent {
        if (! $recipe->isPublished()) {
            throw new BladDlaCzlowieka('Tego przepisu nie ma jeszcze opublikowanego.');
        }

        if ($cook->hasBlockRelationWith($recipe->author)) {
            throw new BladDlaCzlowieka('Nie można dodać wykonania do tego przepisu.');
        }

        $ownedMedia = Media::query()
            ->where('owner_id', $cook->getKey())
            ->whereIn('id', $mediaIds)
            ->pluck('id')
            ->all();

        $zapisz = function (?string $klucz) use (
            $cook, $recipe, $note, $wouldMakeAgain, $perceivedDifficulty, $actualMinutes, $changesNote, $mediaIds, $ownedMedia
        ): CookedEvent {
            return DB::transaction(function () use (
                $cook, $recipe, $note, $wouldMakeAgain, $perceivedDifficulty, $actualMinutes, $changesNote, $mediaIds, $ownedMedia, $klucz
            ): CookedEvent {
                $event = CookedEvent::create([
                    'user_id' => $cook->getKey(),
                    'recipe_id' => $recipe->getKey(),
                    'klucz_wyslania' => $klucz,
                    'note' => $note,
                    'would_make_again' => $wouldMakeAgain,
                    'perceived_difficulty' => $perceivedDifficulty,
                    'actual_minutes' => $actualMinutes,
                    'changes_note' => $changesNote,
                    'cooked_at' => now(),
                ]);

                $position = 0;

                foreach ($mediaIds as $mediaId) {
                    if (! in_array($mediaId, $ownedMedia, true)) {
                        continue;
                    }

                    $event->media()->attach($mediaId, ['position' => $position]);
                    $position++;
                }

                return $event;
            });
        };

        try {
            $event = $zapisz($kluczWyslania);
        } catch (UniqueConstraintViolationException $e) {
            if ($kluczWyslania === null) {
                // Bez klucza nie ma jak odbić się o
                // `cooked_events_one_per_klucz_wyslania` — to inne
                // ograniczenie i nie wolno go tu wyciszyć.
                throw $e;
            }

            // Indeks `cooked_events_one_per_klucz_wyslania` odbił wiersz: to
            // wysłanie już raz zapisało wykonanie. Zwracamy TO wykonanie —
            // i, co ważniejsze, wychodzimy PRZED powiadomieniem autora.
            $istniejace = $this->wykonanieZTegoWyslania($cook, $kluczWyslania);

            if ($istniejace !== null) {
                return $istniejace;
            }

            // Klucz zajęty, a wykonania nie widać (np. zostało w tym czasie
            // usunięte). Nie odmawiamy — zapisujemy bez klucza, z ryzykiem
            // duplikatu (ADR §4.3).
            $event = $zapisz(null);
        }

        $this->notify->handle(
            recipient: $recipe->author,
            type: Notification::TYPE_COOKED,
            actor: $cook,
            data: [
                'recipe_id' => $recipe->getKey(),
                'recipe_title' => $recipe->title,
                'recipe_slug' => $recipe->slug,
                'cooked_event_id' => $event->getKey(),
                'has_photo' => $event->media()->exists(),
            ],
        );

        AuditLogEntry::record(
            action: 'cooked_event.created',
            actor: $cook,
            subject: $event,
            metadata: ['recipe_id' => $recipe->getKey()],
            ip: $ip,
        );

        return $event;
    }

    /**
     * Wykonanie zapisane z TEGO wysłania formularza — jeśli zostało zapisane.
     *
     * Zawężone do osoby, która gotowała, a nie zadane samemu kluczowi:
     * `klucz_wyslania` przychodzi z żądania, a UUID w żądaniu nie jest
     * autoryzacją (`AGENTS.md` §7).
     */
    private function wykonanieZTegoWyslania(User $cook, ?string $kluczWyslania): ?CookedEvent
    {
        if ($kluczWyslania === null) {
            return null;
        }

        return CookedEvent::query()
            ->where('user_id', $cook->getKey())
            ->where('klucz_wyslania', $kluczWyslania)
            ->first();
    }
}
