<?php

declare(strict_types=1);

namespace App\Domain\Questions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * CO JEST ODPOWIEDZIĄ NA PYTANIE — jedna definicja dla listy `/pytania`,
 * licznika „Czeka na odpowiedź (N)” i kolejki gospodarza (#372).
 *
 * Odpowiedź to komentarz:
 *   • najwyższego poziomu (odpowiedź na komentarz to rozmowa pod odpowiedzią),
 *   • z treścią (ślad usuniętej treści zachowuje rozmowę, ale nie odpowiada),
 *   • napisany przez INNĄ osobę niż pytająca. Dopisek autora pod własnym
 *     pytaniem („Dodam, że mam piekarnik gazowy”) nie jest odpowiedzią —
 *     do 25.09.2026 lista i licznik liczyły go jako odpowiedź, a kolejka
 *     gospodarza nie, więc pytanie znikało z „Czeka na odpowiedź”, choć
 *     nikt na nie nie odpowiedział.
 *
 * Widoczność (status, autor dostępny, blokady) dokłada wywołujący, bo zależy
 * od tego, KTO patrzy: widz listy albo odbiorca w kolejce gospodarza.
 */
final class OdpowiedzNaPytanie
{
    /**
     * @param  Builder<\App\Models\Comment>|QueryBuilder  $komentarze
     * @param  string  $tabela  nazwa albo alias tabeli komentarzy w zapytaniu
     * @param  string  $autorPytania  kolumna z autorem pytania, np. `posts.author_id`
     */
    public static function zawez(Builder|QueryBuilder $komentarze, string $tabela, string $autorPytania): void
    {
        $komentarze->whereNull($tabela.'.parent_id')
            ->whereNull($tabela.'.body_removed_at')
            ->whereColumn($tabela.'.author_id', '!=', $autorPytania);
    }
}
