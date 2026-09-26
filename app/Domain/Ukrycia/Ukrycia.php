<?php

declare(strict_types=1);

namespace App\Domain\Ukrycia;

use App\Models\Hide;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Odczyt prywatnych ukryć jednego widza (issue #1810, D-278).
 *
 * BEZ AGREGACJI. Każda metoda pyta o ukrycia JEDNEGO widza i odpowiada
 * wyłącznie jemu. Nic tu nie liczy, ile osób ukryło danego autora albo wpis
 * — takiej liczby nie ma w serwisie i mieć nie może (EROD 3/2025 pkt 95):
 * ukrycie nie wpływa na zasięg autora, nie trafia do moderacji ani analityki.
 */
final class Ukrycia
{
    private const KLUCZ = 'kuking.ukryte_wpisy_widza';

    public function __construct(private readonly Request $request) {}

    /**
     * Czy TEN widz ukrył sobie ten wpis — dla karty, która na profilu,
     * w wyszukiwarce i pod linkiem zwija się do „Ten wpis ukrywasz. Pokaż".
     * Zbiór liczony raz na żądanie (pamięć w atrybutach `Request`, jak
     * `SkrotyObserwowania`), więc karta nie dokłada zapytania.
     */
    public function wpisUkryty(?User $widz, Post $post): bool
    {
        if ($widz === null) {
            return false;
        }

        $pamiec = $this->request->attributes->get(self::KLUCZ);

        if (! is_array($pamiec) || ($pamiec['widz'] ?? null) !== $widz->getKey()) {
            $pamiec = [
                'widz' => $widz->getKey(),
                'wpisy' => array_fill_keys(
                    Hide::query()->aktywne()->where('user_id', $widz->getKey())->whereNotNull('post_id')->pluck('post_id')->all(),
                    true,
                ),
            ];
            $this->request->attributes->set(self::KLUCZ, $pamiec);
        }

        return isset($pamiec['wpisy'][$post->getKey()]);
    }

    /**
     * Które z tych wpisów widz może DZIŚ zobaczyć — dla listy „Ukryte"
     * i eksportu (przegląd #1781).
     *
     * Ukryty wpis mógł od tamtej pory zniknąć (usunięty przez autora — soft
     * delete, więc wiersz `hides` zostaje), zostać schowany przez moderację,
     * stać się prywatny albo „tylko dla obserwujących", a autor mógł widza
     * zablokować albo zamknąć konto. Lista nie może wtedy pokazywać treści
     * ani autora — to byłoby obejście tych bramek. Pytanie jest to samo co
     * w `PostPolicy::view`, zadane jednym zapytaniem dla całej listy:
     * opublikowany, niewyrzucony, widoczny dla widza (blokady w obie strony,
     * widoczność), od konta, które może pokazywać treść, z widocznym
     * przepisem.
     *
     * @param  list<string>  $postIds
     * @return array<string, true>
     */
    public function widoczneWpisy(User $widz, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $ids = Post::query()
            ->whereIn('posts.id', $postIds)
            ->published()
            ->widoczneDla($widz)
            ->whereHas('author', fn ($autor) => $autor->whereNotIn('status', User::STATUSY_UKRYWAJACE_TRESC))
            ->zWidocznymPrzepisem($widz)
            ->pluck('posts.id')
            ->all();

        return array_fill_keys(array_map('strval', $ids), true);
    }

    public function osobaUkryta(User $widz, User $osoba): bool
    {
        return Hide::query()->aktywne()
            ->where('user_id', $widz->getKey())
            ->where('hidden_user_id', $osoba->getKey())
            ->exists();
    }

    /**
     * Ile OSÓB ten widz ma teraz ukrytych — dla linii „Ukrywasz wpisy N osób.
     * Zmień" w „Świeżo z Kuking" i na stronie „Jak dobieramy wpisy" (#1811).
     * Liczone z ukryć TEGO widza, jak wszystko w tej klasie.
     */
    public function ileOsob(User $widz): int
    {
        return Hide::query()->aktywne()->where('user_id', $widz->getKey())->whereNotNull('hidden_user_id')->count();
    }

    /** Ile pojedynczych WPISÓW ten widz ma teraz ukrytych (#1811). */
    public function ileWpisow(User $widz): int
    {
        return Hide::query()->aktywne()->where('user_id', $widz->getKey())->whereNotNull('post_id')->count();
    }

    /** Ile rzeczy (wpisów i osób) widz ma teraz ukrytych. */
    public function ileAktywnych(User $widz): int
    {
        return Hide::query()->aktywne()->where('user_id', $widz->getKey())->count();
    }

    /**
     * Czy ukryte osoby to już co najmniej `prog_ostrzezenia` autorów aktywnych
     * w ostatnich `okno_aktywnosci_dni` dniach. Liczone z ukryć TEGO widza
     * i z publicznej aktywności autorów — nic o tym, kto ukrył kogo innego.
     */
    public function ostrzezenieOSkali(User $widz): bool
    {
        $okno = now()->subDays((int) config('kuking.ukrycia.okno_aktywnosci_dni'));

        $aktywniAutorzy = Post::query()
            ->publiclyVisible()
            ->where('posts.published_at', '>=', $okno)
            ->where('posts.author_id', '!=', $widz->getKey())
            ->distinct()
            ->count('posts.author_id');

        if ($aktywniAutorzy === 0) {
            return false;
        }

        $ukryciAktywni = Hide::query()->aktywne()
            ->where('user_id', $widz->getKey())
            ->whereNotNull('hidden_user_id')
            ->whereIn('hidden_user_id', Post::query()
                ->publiclyVisible()
                ->where('posts.published_at', '>=', $okno)
                ->select('posts.author_id'))
            ->count();

        return $ukryciAktywni / $aktywniAutorzy >= (float) config('kuking.ukrycia.prog_ostrzezenia');
    }
}
