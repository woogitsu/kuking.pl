<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Obserwowanie tagów (D-021, zastępuje `TopicFollowController`).
 *
 * ZWYKŁE FORMULARZE, ŻADNEGO JAVASCRIPTU
 * „Obserwuj" i „Przestań obserwować" to `<form method="POST">` z tokenem
 * CSRF — ten sam wzorzec co przy Temacie (AGENTS.md §5).
 *
 * BEZ POWIADOMIENIA DLA KOGOKOLWIEK
 * Tag nie jest człowiekiem i nie ma komu tego zgłosić; licznik obserwujących
 * tag jest informacją redakcyjną, nie społeczną — ten sam powód co przy
 * Temacie.
 *
 * RÓŻNICA WZGLĘDEM `TopicFollowController`: WSZECHŚWIAT TAGÓW NIE JEST
 * ZAMKNIĘTY. Temat miał jedną, zamkniętą listę „do wyboru" — każdy formularz
 * (onboarding, ustawienia) czerpał z niej samej. Tagi nie mają takiej listy:
 * ktoś mógł zacząć obserwować tag ze strony `/tag/{slug}`, którego nie ma
 * na liście promowanej gospodarza. Dlatego ekran „Twoje tagi" pokazuje
 * SUMĘ tego, co obserwowane, i tego, co promowane — nie samą listę promowaną
 * (patrz `edit()`).
 */
class TagFollowController extends Controller
{
    public function follow(Request $request, Tag $tag): RedirectResponse
    {
        // Tag scalony albo ukryty nie ma stać się nowym obserwowaniem —
        // ten sam powód co wycofany Temat: nie budujemy komuś feedu z tagu,
        // który przestał być kanonicznym miejscem na tę treść.
        if ($tag->status !== Tag::STATUS_ACTIVE) {
            return back()->with('status', 'Tego tagu nie da się już obserwować.');
        }

        // `syncWithoutDetaching` zamiast `attach`: „Obserwuj" bywa klikane
        // dwa razy z niepewności, a klucz główny na parze zamieniłby drugie
        // kliknięcie w błąd bazy danych zamiast w nic.
        $request->user()->followedTags()->syncWithoutDetaching([
            $tag->getKey() => ['created_at' => now()],
        ]);

        return back()->with('status', "Obserwujesz tag „{$tag->name}”.");
    }

    public function unfollow(Request $request, Tag $tag): RedirectResponse
    {
        $request->user()->followedTags()->detach($tag->getKey());

        return back()->with('status', "Nie obserwujesz już tagu „{$tag->name}”.");
    }

    /**
     * „Twoje tagi" w ustawieniach — jeden ekran z całą listą.
     *
     * Pokazuje SUMĘ tagów już obserwowanych i tagów promowanych (D-021,
     * „tag promowany — lista gospodarza") — nie samą listę promowaną, bo
     * ktoś mógł zacząć obserwować tag spoza niej (patrz komentarz klasy).
     * Bez tej sumy odznaczenie takiego tagu byłoby niemożliwe z tego
     * ekranu: nie byłoby go na liście checkboxów.
     */
    public function edit(Request $request): View
    {
        $obserwowane = $request->user()->followedTags()->get();
        $promowane = Tag::promowane()->get();

        $doPokazania = $obserwowane->concat($promowane)
            ->unique(fn (Tag $tag): string => $tag->getKey())
            ->sortBy('name')
            ->values();

        return view('pages.settings.tags', [
            'tags' => $doPokazania,
            'followed' => $obserwowane->pluck('id')->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dane = $request->validate([
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'exists:tags,id'],
        ]);

        // Bez zawężania do „listy do wyboru" (w odróżnieniu od
        // `TopicFollowController::update()`) — wszechświat tagów nie jest
        // zamknięty, więc KAŻDY tag pokazany na tym ekranie (obserwowany
        // LUB promowany, patrz `edit()`) jest ważny do zaznaczenia/odznaczenia.
        $wybrane = $dane['tags'] ?? [];
        $obserwowane = $request->user()->followedTags()->pluck('tags.id')->all();

        // Świadomie NIE `sync()`: ten przepisałby `created_at` wszystkim
        // tagom przy każdym zapisie formularza, także tym obserwowanym
        // od miesięcy — ten sam powód co przy Temacie.
        $doDodania = array_diff($wybrane, $obserwowane);
        $doZdjecia = array_diff($obserwowane, $wybrane);

        if ($doDodania !== []) {
            $request->user()->followedTags()->attach(
                array_fill_keys($doDodania, ['created_at' => now()]),
            );
        }

        if ($doZdjecia !== []) {
            $request->user()->followedTags()->detach(array_values($doZdjecia));
        }

        return redirect()
            ->route('settings.tags')
            ->with('status', 'Zapisaliśmy Twoje tagi.');
    }
}
