<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\PamiecZadania;

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
 * Pamięć żyje w pamięci bieżącego żądania (`PamiecZadania`; adapter HTTP
 * trzyma ją w atrybutach `Request`), nie w kontenerze: każde
 * żądanie (także kolejne żądanie w jednym teście) liczy od nowa, więc po
 * kliknięciu „Obserwuj" następna strona nie pokaże nieaktualnego skrótu.
 */
final class SkrotyObserwowania
{
    /** Najwyżej tyle tagów wpisu dostaje skrót w menu (kryterium #1809). */
    public const NAJWYZEJ_TAGOW = 2;

    /** Publiczny: `ListyWidza::uniewaznij()` czyści też tę pamięć. */
    public const KLUCZ = 'kuking.skroty_obserwowania';

    private readonly ListyWidza $listy;

    public function __construct(private readonly PamiecZadania $pamiec)
    {
        $this->listy = ListyWidza::zPamiecia($pamiec);
    }

    public function osobaDoObserwowania(User $widz, User $autor): bool
    {
        if ($widz->getKey() === $autor->getKey() || ! $widz->isActive() || ! $autor->isActive()) {
            return false;
        }

        $zbiory = $this->zbiory($widz);

        return ! isset($zbiory['osoby'][$autor->getKey()]) && ! isset($zbiory['blokady'][$autor->getKey()]);
    }

    /** Czy widz obserwuje autora — z tej samej pamięci, bez zapytania na kartę. */
    public function obserwuje(User $widz, User $autor): bool
    {
        return isset($this->zbiory($widz)['osoby'][$autor->getKey()]);
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
        $pamiec = $this->pamiec->pobierz(self::KLUCZ);

        if (is_array($pamiec) && ($pamiec['widz'] ?? null) === $widz->getKey()) {
            return $pamiec['zbiory'];
        }

        // Listy z pamięci żądania wspólnej z feedem i tablicą dnia (W7).
        $zbiory = [
            'osoby' => array_fill_keys($this->listy->osoby($widz), true),
            'tagi' => array_fill_keys($this->listy->tagiSurowe($widz), true),
            'blokady' => array_fill_keys($this->listy->blokady($widz), true),
        ];

        $this->pamiec->zapisz(self::KLUCZ, ['widz' => $widz->getKey(), 'zbiory' => $zbiory]);

        return $zbiory;
    }
}
