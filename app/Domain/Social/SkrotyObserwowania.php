<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Models\Block;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * „Obserwuj tę osobę" i „Obserwuj tag: …" w menu trzech kropek karty wpisu
 * (issue #1809, decyzja właściciela z 25 września 2026: zamiast „więcej
 * takich treści" — skróty do jawnych poleceń widza, AGENTS.md §8).
 *
 * PO CO OSOBNA KLASA, SKORO JEST `UserPolicy::follow`
 * Karta stoi w feedzie po 15 razy na stronie. `@can('follow', $autor)` na
 * każdej karcie to zapytanie o blokady na każdej karcie, a obserwowanie —
 * drugie. Tu liczymy trzy zbiory RAZ na żądanie (obserwowane osoby,
 * obserwowane tagi, blokady w obie strony) i odpowiadamy z pamięci.
 *
 * Reguła jest TA SAMA co w `UserPolicy::follow` (nie ja, oba konta aktywne,
 * bez blokady) i jej zgodność pilnuje `SkrotyObserwowaniaWMenuTest` na
 * macierzy przypadków. Menu tylko POKAZUJE skrót — o tym, czy wolno,
 * rozstrzyga dalej Policy w `SocialController::follow()`.
 *
 * Pamięć żyje w atrybutach bieżącego `Request`, nie w kontenerze: każde
 * żądanie (także kolejne żądanie w jednym teście) liczy od nowa, więc po
 * kliknięciu „Obserwuj" następna strona nie pokaże nieaktualnego skrótu.
 */
final class SkrotyObserwowania
{
    /** Najwyżej tyle tagów wpisu dostaje skrót w menu (kryterium #1809). */
    public const NAJWYZEJ_TAGOW = 2;

    private const KLUCZ = 'kuking.skroty_obserwowania';

    public function __construct(private readonly Request $request) {}

    public function osobaDoObserwowania(User $widz, User $autor): bool
    {
        if ($widz->getKey() === $autor->getKey() || ! $widz->isActive() || ! $autor->isActive()) {
            return false;
        }

        $zbiory = $this->zbiory($widz);

        return ! isset($zbiory['osoby'][$autor->getKey()]) && ! isset($zbiory['blokady'][$autor->getKey()]);
    }

    /**
     * Aktywne, jeszcze nieobserwowane tagi wpisu — w kolejności wpisu,
     * najwyżej dwa. Tylko z relacji już doładowanej przez feed: brak relacji
     * znaczy „brak skrótów", nie zapytanie na kartę.
     *
     * @return list<Tag>
     */
    public function tagiDoObserwowania(User $widz, Post $post): array
    {
        if (! $widz->isActive() || ! $post->relationLoaded('tags')) {
            return [];
        }

        $obserwowane = $this->zbiory($widz)['tagi'];

        return $post->tags
            ->filter(fn (Tag $tag) => $tag->status === Tag::STATUS_ACTIVE && ! isset($obserwowane[$tag->getKey()]))
            ->take(self::NAJWYZEJ_TAGOW)
            ->values()
            ->all();
    }

    /** @return array{osoby: array<string, true>, tagi: array<string, true>, blokady: array<string, true>} */
    private function zbiory(User $widz): array
    {
        $pamiec = $this->request->attributes->get(self::KLUCZ);

        if (is_array($pamiec) && ($pamiec['widz'] ?? null) === $widz->getKey()) {
            return $pamiec['zbiory'];
        }

        $blokady = [
            ...Block::query()->where('blocker_id', $widz->getKey())->pluck('blocked_id')->all(),
            ...Block::query()->where('blocked_id', $widz->getKey())->pluck('blocker_id')->all(),
        ];

        $zbiory = [
            'osoby' => array_fill_keys($widz->following()->pluck('users.id')->all(), true),
            'tagi' => array_fill_keys($widz->followedTags()->pluck('tags.id')->all(), true),
            'blokady' => array_fill_keys($blokady, true),
        ];

        $this->request->attributes->set(self::KLUCZ, ['widz' => $widz->getKey(), 'zbiory' => $zbiory]);

        return $zbiory;
    }
}
