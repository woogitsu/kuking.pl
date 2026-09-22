<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Posts\Actions\ArrangePostMedia;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Zdjęcia w opublikowanym wpisie: kolejność i sposób wyświetlania (issue #92).
 *
 * DLACZEGO OSOBNY EKRAN, A NIE TYLKO POLE W FORMULARZU PUBLIKACJI
 * W formularzu publikacji zdjęć JESZCZE NIE MA — przeglądarka trzyma je
 * w `<input type="file">` i nie wysyła ich na serwer, dopóki człowiek nie
 * kliknie „Opublikuj". Serwer nie wie więc, ile ich będzie, więc bez
 * JavaScriptu nie ma jak pokazać wyboru „dopiero od drugiego zdjęcia" ani
 * przycisków „w górę / w dół" przy konkretnym zdjęciu.
 *
 * Ten ekran jest odpowiedzią na to ograniczenie i jest JEDYNĄ drogą, nie
 * zapasową: działa zwykłym POST-em, bez linijki skryptu, i tak samo na starym
 * telefonie przy słabym zasięgu (AGENTS.md §5).
 *
 * Przez chwilę wybór stał także w formularzu publikacji — ukryty, odsłaniany
 * skryptem po wybraniu drugiego pliku. Właściciel zdecydował inaczej: funkcja,
 * która u części ludzi po prostu nie istnieje, jest gorsza niż jeden ekran
 * więcej dla wszystkich. `PostController::store()` przekierowuje tu po
 * opublikowaniu wpisu z dwoma zdjęciami albo większą ich liczbą.
 *
 * Wejście przez Policy, nie przez UUID w adresie (AGENTS.md §7): identyfikator
 * wpisu widać w linku pod każdym zdjęciem, więc sam jego brak w cudzych rękach
 * niczego nie chroni.
 */
class PostMediaController extends Controller
{
    public function __construct(private readonly ArrangePostMedia $arrange) {}

    public function edit(Request $request, Post $post): View
    {
        $this->authorize('update', $post);

        $post->load('media');

        return view('pages.posts.zdjecia', ['post' => $post]);
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $dane = $request->validate([
            'display_mode' => ['nullable', 'in:'.implode(',', Post::dozwoloneTrybyWyswietlania())],
            'przenies_w_gore' => ['nullable', 'uuid'],
            'przenies_w_dol' => ['nullable', 'uuid'],
            // Znacznik „przyszedłem tu prosto z publikacji" (issue #92).
            // Nie zmienia niczego w danych — decyduje wyłącznie o tym, dokąd
            // odsyłamy po zapisaniu.
            'wroc_do_wpisu' => ['nullable', 'in:1'],
        ], [
            // Komunikat mówi, CO WYBRAĆ — nazwami z ekranu, nie nazwami
            // z bazy (issue #86).
            'display_mode.in' => 'Zaznacz, jak mają się wyświetlić zdjęcia: zwykle, karuzela czy kolaż.',
        ]);

        $post->load('media');

        /** @var list<string> $kolejnosc */
        $kolejnosc = $post->media->pluck('id')->all();

        $przesuniete = $this->przesun(
            $kolejnosc,
            $dane['przenies_w_gore'] ?? null,
            $dane['przenies_w_dol'] ?? null,
        );

        $this->arrange->handle(
            actor: $request->user(),
            post: $post,
            orderedMediaIds: $przesuniete['kolejnosc'],
            displayMode: $dane['display_mode'] ?? $post->display_mode ?? Post::DISPLAY_NORMAL,
        );

        $przestawiono = $przesuniete['komunikat'] !== null;
        $prostoZPublikacji = ($dane['wroc_do_wpisu'] ?? null) === '1';

        // PRZESTAWIENIE ZDJĘCIA NIE JEST KOŃCEM PRACY.
        //
        // „Przenieś w górę" i „Zapisz" wysyłają TEN SAM formularz, więc bez
        // tego rozróżnienia pierwsze kliknięcie w strzałkę wyrzucałoby
        // człowieka do wpisu w połowie ustawiania kolejności. Przy zmianie
        // kolejności zostajemy tu i zabieramy znacznik ze sobą, żeby ekran
        // dalej wyglądał jak ostatni krok publikacji.
        if ($przestawiono) {
            return redirect()
                ->route('posts.media.edit', $post)
                ->with('poPublikacji', $prostoZPublikacji)
                ->with('status', $przesuniete['komunikat']);
        }

        if ($prostoZPublikacji) {
            return redirect()
                ->to($post->url())
                ->with('status', 'Opublikowane. Tak zobaczą ten wpis inni.');
        }

        return redirect()
            ->route('posts.media.edit', $post)
            ->with('status', 'Zapisane. Tak zobaczą ten wpis inni.');
    }

    /**
     * Nowa kolejność po kliknięciu „Przenieś w górę" albo „Przenieś w dół".
     *
     * Zamiana z SĄSIADEM, po jednym kroku na kliknięcie. To jest wzorzec
     * z kreatora przepisu (issue #13) i wybrano go zamiast przeciągania
     * myszą, bo przeciąganie wymaga precyzji, sprawnej ręki i wzroku
     * jednocześnie — a każdą z tych trzech rzeczy część naszych użytkowników
     * ma ograniczoną (docs/UX_50_PLUS.md).
     *
     * @param  list<string>  $kolejnosc
     * @return array{kolejnosc: list<string>, komunikat: string|null}
     */
    private function przesun(array $kolejnosc, ?string $wGore, ?string $wDol): array
    {
        $mediaId = $wGore ?? $wDol;

        if ($mediaId === null) {
            return ['kolejnosc' => $kolejnosc, 'komunikat' => null];
        }

        $index = array_search($mediaId, $kolejnosc, true);

        if ($index === false) {
            return ['kolejnosc' => $kolejnosc, 'komunikat' => null];
        }

        $cel = $wGore !== null ? $index - 1 : $index + 1;

        // Kliknięcie w nieaktywny przycisk na krańcu listy nie jest błędem
        // i nie może dać komunikatu o błędzie — po prostu nie ma dokąd
        // przesunąć.
        if ($cel < 0 || $cel >= count($kolejnosc)) {
            return ['kolejnosc' => $kolejnosc, 'komunikat' => null];
        }

        [$kolejnosc[$index], $kolejnosc[$cel]] = [$kolejnosc[$cel], $kolejnosc[$index]];

        return [
            'kolejnosc' => $kolejnosc,
            'komunikat' => $wGore !== null
                ? 'Zdjęcie przesunięte w górę. Jest teraz '.($cel + 1).' w kolejności.'
                : 'Zdjęcie przesunięte w dół. Jest teraz '.($cel + 1).' w kolejności.',
        ];
    }
}
