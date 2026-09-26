<?php

declare(strict_types=1);

namespace App\Domain\Reakcje;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * „Smakowicie wygląda" — zapis, cofnięcie i lista dla autora (issue #1813, D-280).
 *
 * Lżejsze niż „Ugotowałem" i świadomie ciche: autor nie dostaje powiadomienia
 * od razu, tylko zbiorczo raz dziennie (`PowiadomOSmakowicie`), żeby nie
 * zagłuszać „Ugotowałem", które powiadamia natychmiast (AGENTS.md §1).
 * Nie ma licznika: autor widzi, KTO napisał, nikt nie widzi, ILU.
 * Czy widz może zobaczyć wpis (bramki, blokady), sprawdza Policy w kontrolerze.
 */
final class Smakowicie
{
    public function dodaj(User $widz, Post $post): bool
    {
        if ($post->author_id === $widz->getKey()) {
            throw new BladDlaCzlowieka('Pod własnym wpisem tego nie piszesz.');
        }

        if ($widz->hasBlockRelationWith($post->author)) {
            throw new BladDlaCzlowieka('Nie możesz zareagować na ten wpis.');
        }

        $istnieje = PostReaction::query()->where('post_id', $post->getKey())->where('user_id', $widz->getKey())->exists();

        if ($istnieje) {
            return false;
        }

        // Dwa równoległe kliknięcia: drugie trafia w UNIQUE (post_id, user_id)
        // i kończy się bez błędu — stan jest ten sam.
        return PostReaction::query()->insertOrIgnore([
            'post_id' => $post->getKey(),
            'user_id' => $widz->getKey(),
            'created_at' => now(),
        ]) > 0;
    }

    public function cofnij(User $widz, Post $post): bool
    {
        return PostReaction::query()->where('post_id', $post->getKey())->where('user_id', $widz->getKey())->delete() > 0;
    }

    /**
     * Kto napisał „Smakowicie wygląda" — WYŁĄCZNIE dla autora wpisu. Bez osób,
     * z którymi autor ma blokadę (w którąkolwiek stronę), i bez kont, których
     * nie da się już pokazać jako autora.
     *
     * @return Collection<int, User>
     */
    public function ktoDla(User $autor, Post $post): Collection
    {
        if ($post->author_id !== $autor->getKey()) {
            return new Collection;
        }

        return $this->osobyWidoczneDlaAutora($autor)
            ->select('users.*')
            ->join('post_reactions', 'post_reactions.user_id', '=', 'users.id')
            ->where('post_reactions.post_id', $post->getKey())
            ->with('profile')
            // Po czasie reakcji — kolejność, w jakiej ludzie to napisali.
            ->orderBy('post_reactions.created_at')
            ->orderBy('users.id')
            ->get();
    }

    /**
     * Osoby, które autor może zobaczyć przy swoich reakcjach: konto, które da
     * się pokazać jako autora, i bez blokady w którąkolwiek stronę. Jedno
     * miejsce dla `ktoDla()` i eksportu (`reakcje_otrzymane`, przegląd #1781)
     * — paczka nie może nazwać kogoś, kogo strona wpisu nie pokazuje.
     *
     * @return Builder<User>
     */
    public function osobyWidoczneDlaAutora(User $autor): Builder
    {
        return User::query()
            ->dostepnyJakoAutor()
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('blocks')
                ->where(fn ($w) => $w->where('blocks.blocker_id', $autor->getKey())->whereColumn('blocks.blocked_id', 'users.id'))
                ->orWhere(fn ($w) => $w->whereColumn('blocks.blocker_id', 'users.id')->where('blocks.blocked_id', $autor->getKey())));
    }
}
