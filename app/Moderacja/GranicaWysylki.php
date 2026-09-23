<?php

declare(strict_types=1);

namespace App\Moderacja;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Policies\CookedEventPolicy;
use App\Policies\PostPolicy;
use App\Policies\RecipePolicy;
use Illuminate\Support\Facades\Gate;

/**
 * CO WOLNO WYSŁAĆ DO OPENAI I CO WOLNO POŁOŻYĆ PRZED MODERATOREM (D-240).
 *
 * DWIE GRANICE, BO SĄ DWA RÓŻNE PYTANIA
 *
 * `publiczna()` — czy treść może opuścić serwer. „Publiczna" znaczy: gość bez
 * konta zobaczyłby ją w serwisie TERAZ. Pytamy te same Policy, które decydują
 * o stronie dla gościa (`PostPolicy::view`, `CommentPolicy::view` — a ta dalej
 * o rodzica: wpis, przepis albo wykonanie). Granica obejmuje więc od razu
 * wszystko, co tamte wiedzą: prywatność i „dla obserwujących", ukrycie przez
 * moderację, usunięcie, konto autora zbanowane albo w karencji usunięcia,
 * zapowiedź przepisu z bramką w przepisie.
 *
 * `pozaAutorem()` — czy automat może postawić oznaczenie z LOKALNYCH sygnałów
 * (D-052), które nie opuszczają serwera. Tu „dla obserwujących" wolno, jak
 * dotąd: spam do obserwujących jest spamem. Prywatnej treści nie — i to
 * dotyczy także komentarza pod rodzicem, który przestał być widoczny, czego
 * `PrzeanalizujTresc` wcześniej nie sprawdzało wcale (#827).
 *
 * STAN CZYTAMY Z BAZY PRZY KAŻDYM PYTANIU. Zadanie z kolejki startuje
 * później niż publikacja, a między zdjęciami wpisu mija czas żądania do
 * dostawcy. Obiekt w pamięci pamięta stan sprzed tej zmiany.
 *
 * Czego ta klasa NIE obiecuje: atomowości z żądaniem HTTP. Zmiana
 * widoczności w tej samej milisekundzie, w której żądanie już wyszło, jest
 * poza zasięgiem jakiejkolwiek kontroli po naszej stronie. Obiecujemy, że
 * każde żądanie jest poprzedzone świeżym pytaniem.
 */
final class GranicaWysylki
{
    public function publiczna(Post|Comment $tresc): bool
    {
        $aktualna = $this->aktualna($tresc);

        return $aktualna !== null && Gate::forUser(null)->allows('view', $aktualna);
    }

    /** Zdjęcie wychodzi tylko jako część publicznego wpisu, do którego wciąż należy. */
    public function zdjecieWpisu(Post $wpis, Media $zdjecie): bool
    {
        if (! $this->publiczna($wpis)) {
            return false;
        }

        return $wpis->media()
            ->whereKey($zdjecie->getKey())
            ->where('media.status', Media::STATUS_READY)
            ->exists();
    }

    public function pozaAutorem(Post|Comment $tresc): bool
    {
        $aktualna = $this->aktualna($tresc);

        if ($aktualna instanceof Comment) {
            return $aktualna->status === Comment::STATUS_PUBLISHED
                && $aktualna->author?->jestDostepnyJakoAutor() === true
                && $this->rodzicPozaAutorem($aktualna->subject());
        }

        return $aktualna !== null && $this->rodzicPozaAutorem($aktualna);
    }

    /**
     * Świeży wiersz albo `null`, gdy treści już nie ma.
     *
     * `find` pomija wiersze skasowane (SoftDeletes). Ślad „Komentarz
     * usunięty." zostaje w wątku dla odpowiedzi, ale treść autora jest już
     * wycofana — Policy tego pola nie sprawdza, więc robimy to tutaj.
     */
    private function aktualna(Post|Comment $tresc): Post|Comment|null
    {
        $aktualna = $tresc::query()->find($tresc->getKey());

        if ($aktualna instanceof Comment && $aktualna->body_removed_at !== null) {
            return null;
        }

        return $aktualna;
    }

    /**
     * Czy rodzic jest widoczny dla kogokolwiek poza autorem.
     *
     * Policy dla gościa odmawia „dla obserwujących", więc pytamy ją o KOPIĘ
     * z widocznością publiczną — o wszystkie pozostałe warunki (status,
     * usunięcie, konto autora, bramka przepisu). Kopii nigdy nie zapisujemy.
     * Nie podszywamy się pod autora: on widzi także własną treść prywatną
     * i ukrytą, więc jego Policy przepuściłaby za dużo.
     */
    private function rodzicPozaAutorem(Post|Recipe|CookedEvent|null $rodzic): bool
    {
        if ($rodzic instanceof CookedEvent) {
            if (! $rodzic->recipe instanceof Recipe || ! $this->rodzicPozaAutorem($rodzic->recipe)) {
                return false;
            }

            $kopia = clone $rodzic;
            $przepis = clone $rodzic->recipe;
            $przepis->visibility = Post::VISIBILITY_PUBLIC;
            $kopia->setRelation('recipe', $przepis);

            return app(CookedEventPolicy::class)->view(null, $kopia);
        }

        if ($rodzic === null
            || ! in_array($rodzic->visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS], true)) {
            return false;
        }

        $kopia = clone $rodzic;
        $kopia->visibility = Post::VISIBILITY_PUBLIC;

        return $kopia instanceof Post
            ? app(PostPolicy::class)->view(null, $kopia)
            : app(RecipePolicy::class)->view(null, $kopia);
    }
}
