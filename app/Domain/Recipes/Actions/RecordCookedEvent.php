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
 */
final class RecordCookedEvent
{
    public function __construct(private readonly NotifyUser $notify) {}

    /**
     * @param  list<string>  $mediaIds
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

        $event = DB::transaction(function () use (
            $cook, $recipe, $note, $wouldMakeAgain, $perceivedDifficulty, $actualMinutes, $changesNote, $mediaIds, $ownedMedia
        ): CookedEvent {
            $event = CookedEvent::create([
                'user_id' => $cook->getKey(),
                'recipe_id' => $recipe->getKey(),
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
}
