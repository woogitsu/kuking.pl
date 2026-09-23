<?php

declare(strict_types=1);

namespace App\Moderacja;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
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
 * BAN KONTA NIE ZDEJMUJE LOKALNEJ ANALIZY (D-241). Decyzja właściciela
 * z 22.09: treść niepubliczna nie wychodzi poza serwer, ale lokalne wzorce
 * spamu sprawdzają ją NADAL. Policy gościa odrzuca treść zbanowanego konta
 * i zapowiedź przepisu „dla obserwujących" — dla `publiczna()` słusznie, ale
 * pytana bez poprawek zdejmowała też lokalne oznaczenie. Dlatego tutaj pytamy
 * ją o kopię, w której konto zbanowane udaje aktywne, a przepis zapowiedzi —
 * publiczny. Karencja usunięcia i konto wymazane zostają wycięte: to decyzja
 * samego człowieka („konto zniknie od razu”), nie kara.
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
                && $this->autorDoLokalnejAnalizy($aktualna->author)
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
     * Czy rodzic jest widoczny dla kogokolwiek poza autorem — przy założeniu,
     * że ban konta nie gra roli (D-241).
     *
     * Policy dla gościa odmawia „dla obserwujących", więc pytamy ją o KOPIĘ
     * (`kopiaDlaLokalnej()`) — o wszystkie pozostałe warunki (status,
     * usunięcie, karencja usunięcia konta, bramka przepisu). Kopii nigdy nie
     * zapisujemy. Nie podszywamy się pod autora: on widzi także własną treść
     * prywatną i ukrytą, więc jego Policy przepuściłaby za dużo.
     */
    private function rodzicPozaAutorem(Post|Recipe|CookedEvent|null $rodzic): bool
    {
        if ($rodzic instanceof CookedEvent) {
            // Wykonanie nie ma własnej widoczności — idzie za przepisem
            // (`CookedEventPolicy::view()`, punkt 5), a ta Policy pyta
            // `RecipePolicy` o przepis, który tu podmieniamy na kopię.
            $przepis = $rodzic->recipe instanceof Recipe ? $this->kopiaDlaLokalnej($rodzic->recipe) : null;

            if ($przepis === null) {
                return false;
            }

            $kopia = clone $rodzic;
            $kopia->setRelation('recipe', $przepis);

            return app(CookedEventPolicy::class)->view(null, $kopia);
        }

        $kopia = $rodzic === null ? null : $this->kopiaDlaLokalnej($rodzic);

        if ($kopia === null) {
            return false;
        }

        return $kopia instanceof Post
            ? app(PostPolicy::class)->view(null, $kopia)
            : app(RecipePolicy::class)->view(null, $kopia);
    }

    /**
     * Kopia wpisu albo przepisu, o którą pytamy Policy gościa — albo `null`,
     * gdy treść jest prywatna (tej lokalna analiza nie ogląda, D-052 pkt 3).
     *
     * Trzy podmiany, każda tylko w pamięci:
     *  1. „dla obserwujących" → „publiczny" (gość nie obserwuje nikogo);
     *  2. konto autora zbanowane → aktywne (D-241: ban nie zdejmuje lokalnej
     *     analizy; karencja usunięcia i wymazanie zostają wycięte, bo tych
     *     stanów nie podmieniamy);
     *  3. przepis, na który wskazuje zapowiedź → ta sama kopia, rekurencyjnie
     *     (zapowiedź przepisu „dla obserwujących" bierze bramkę z przepisu).
     */
    private function kopiaDlaLokalnej(Post|Recipe $tresc): Post|Recipe|null
    {
        if (! in_array($tresc->visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS], true)) {
            return null;
        }

        $kopia = clone $tresc;
        $kopia->visibility = Post::VISIBILITY_PUBLIC;

        $autor = $tresc->author;

        if ($autor instanceof User && $autor->status === User::STATUS_BANNED) {
            $aktywny = clone $autor;
            $aktywny->status = User::STATUS_ACTIVE;
            $kopia->setRelation('author', $aktywny);
        }

        if ($kopia instanceof Post && $tresc instanceof Post && $tresc->recipe instanceof Recipe) {
            $przepis = $this->kopiaDlaLokalnej($tresc->recipe);

            if ($przepis !== null) {
                $kopia->setRelation('recipe', $przepis);
            }
        }

        return $kopia;
    }

    /**
     * Autor komentarza: dostępny jak zwykle ALBO zbanowany (D-241). Karencja
     * usunięcia i konto wymazane — nie.
     */
    private function autorDoLokalnejAnalizy(?User $autor): bool
    {
        return $autor instanceof User
            && ($autor->jestDostepnyJakoAutor() || $autor->status === User::STATUS_BANNED);
    }
}
