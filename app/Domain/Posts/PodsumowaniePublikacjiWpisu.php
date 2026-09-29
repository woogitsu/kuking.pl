<?php

declare(strict_types=1);

namespace App\Domain\Posts;

use App\Models\Post;
use App\Models\User;
use App\Support\Czas;

/**
 * Co powiedzieć autorowi tuż po opublikowaniu wpisu (nie pytania) i dokąd go
 * odesłać — wyjęte z `PostController::store()` bez zmiany zachowania
 * (issue #970). Kontroler dalej składa samo przekierowanie.
 *
 * DATA I JAWNY KROK „ZOBACZ SWÓJ WPIS" — issue #1881. Obietnica z
 * `docs/product/COLD_START.md` i `docs/product/SOUL.md`: „Gotowe. To Twój
 * pierwszy wpis w Kuking — {data}." + link „Zobacz swój wpis", spójnie
 * niezależnie od liczby zdjęć.
 *
 * Zdjęcie przetwarza się w kolejce, więc w chwili przekierowania prawie na
 * pewno nie jest jeszcze `ready` — autor dowiaduje się o tym z komunikatu,
 * a nie dopiero z pustej ramki (audyt A2).
 *
 * KROK POŚREDNI PRZY KILKU ZDJĘCIACH (decyzja właściciela): ekran układu
 * „Zdjęcia w tym wpisie" TYLKO od dwóch zdjęć. Przy jednym karuzela, kolaż
 * i „zwykle" dają ten sam widok, więc pytanie bez treści byłoby gorsze
 * niż brak pytania.
 */
final readonly class PodsumowaniePublikacjiWpisu
{
    private function __construct(
        public bool $pierwszyWpis,
        public bool $ekranUkladuZdjec,
        public string $komunikat,
    ) {}

    public static function dla(Post $post, User $autor, int $liczbaZdjec): self
    {
        $pierwszyWpis = $autor->posts()->published()->count() === 1;
        $dataPublikacji = $post->published_at !== null ? Czas::data($post->published_at) : null;
        $maZdjecie = $liczbaZdjec > 0;

        if ($liczbaZdjec >= 2) {
            return new self($pierwszyWpis, true, $pierwszyWpis
                ? 'Opublikowane. To Twój pierwszy wpis w Kuking — '.$dataPublikacji.'.'
                : 'Opublikowane.');
        }

        return new self($pierwszyWpis, false, match (true) {
            $pierwszyWpis && $maZdjecie => 'Gotowe. To Twój pierwszy wpis w Kuking — '.$dataPublikacji.'. Zdjęcie za chwilę będzie widoczne, nic nie musisz robić.',
            $pierwszyWpis => 'Gotowe. To Twój pierwszy wpis w Kuking — '.$dataPublikacji.'.',
            $maZdjecie => 'Opublikowane. Zdjęcie za chwilę będzie widoczne — nic nie zginęło.',
            default => 'Opublikowane. Dziękujemy.',
        });
    }

    /**
     * Przycisk „Zobacz swój wpis" obok komunikatu — tylko przy pierwszym wpisie.
     *
     * @return array{url: string, etykieta: string}|null
     */
    public function akcja(Post $post): ?array
    {
        return $this->pierwszyWpis
            ? ['url' => $post->url(), 'etykieta' => 'Zobacz swój wpis']
            : null;
    }
}
