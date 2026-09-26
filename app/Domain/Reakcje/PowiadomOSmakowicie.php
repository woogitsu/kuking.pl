<?php

declare(strict_types=1);

namespace App\Domain\Reakcje;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Zbiorcze powiadomienie „N osób napisało: Smakowicie wygląda" — raz dziennie
 * (issue #1813, D-280, komenda `kuking:powiadom-smakowicie` w harmonogramie).
 *
 * Raz dziennie, nie od razu: „Ugotowałem" powiadamia natychmiast i ma zostać
 * najcenniejszą wiadomością w serwisie (AGENTS.md §1). Jedno powiadomienie na
 * autora, niezależnie od liczby wpisów i reakcji; liczy RÓŻNE osoby.
 *
 * Granice (te same co w `NotifyUser` i przy zbiorczym zapisie do zeszytu):
 *  - reakcje osób, z którymi autor ma blokadę (w którąkolwiek stronę), i kont
 *    nieaktywnych się nie liczą;
 *  - autor, który nie może czytać, nie dostaje nic (`NotifyUser`);
 *  - reakcja na wpis, którego już nie ma albo nie jest opublikowany, się nie liczy.
 * Każda czekająca reakcja zostaje oznaczona `notified_at` — także odsiana —
 * żeby nie wracała jutro. Tylko zapis do bazy; poczty ta klasa nie wysyła.
 */
final class PowiadomOSmakowicie
{
    public function __construct(private readonly NotifyUser $notify) {}

    /** @return int ile powiadomień powstało */
    public function wyslij(): int
    {
        $powstalo = 0;

        $autorzy = PostReaction::query()
            ->whereNull('post_reactions.notified_at')
            ->join('posts', 'posts.id', '=', 'post_reactions.post_id')
            ->distinct()
            ->pluck('posts.author_id');

        foreach ($autorzy as $autorId) {
            $autor = User::query()->find($autorId);

            DB::transaction(function () use ($autorId, $autor, &$powstalo): void {
                $czekajace = PostReaction::query()
                    ->select('post_reactions.*')
                    ->join('posts', 'posts.id', '=', 'post_reactions.post_id')
                    ->where('posts.author_id', $autorId)
                    ->whereNull('post_reactions.notified_at')
                    ->lockForUpdate()
                    ->get();

                $liczone = $autor === null ? collect() : PostReaction::query()
                    ->select('post_reactions.user_id', 'post_reactions.post_id', 'post_reactions.created_at')
                    ->whereIn('post_reactions.id', $czekajace->modelKeys())
                    ->join('posts', 'posts.id', '=', 'post_reactions.post_id')
                    ->join('users', 'users.id', '=', 'post_reactions.user_id')
                    ->where('users.status', User::STATUS_ACTIVE)
                    ->where('posts.status', Post::STATUS_PUBLISHED)
                    // Gołe `join` omija `SoftDeletes` modelu — wpis usunięty
                    // przez autora ma `status = published` i `deleted_at`
                    // ustawione (przegląd #1781).
                    ->whereNull('posts.deleted_at')
                    ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('blocks')
                        ->where(fn ($w) => $w->where('blocks.blocker_id', $autorId)->whereColumn('blocks.blocked_id', 'post_reactions.user_id'))
                        ->orWhere(fn ($w) => $w->whereColumn('blocks.blocker_id', 'post_reactions.user_id')->where('blocks.blocked_id', $autorId)))
                    ->orderByDesc('post_reactions.created_at')
                    ->get();

                $osob = $liczone->pluck('user_id')->unique()->count();

                if ($autor !== null && $osob > 0) {
                    $powiadomienie = $this->notify->handle(
                        recipient: $autor,
                        type: Notification::TYPE_SMAKOWICIE,
                        data: [
                            'osob' => $osob,
                            'wpisow' => $liczone->pluck('post_id')->unique()->count(),
                            'post_id' => (string) $liczone->first()->post_id,
                        ],
                    );
                    $powstalo += $powiadomienie === null ? 0 : 1;
                }

                PostReaction::query()->whereIn('id', $czekajace->modelKeys())->update(['notified_at' => now()]);
            });
        }

        return $powstalo;
    }
}
