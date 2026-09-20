<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tags\Actions\UpdateTagFollows;
use App\Domain\Tags\TagFollowForm;
use App\Http\Requests\TagSelection;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Obserwowanie tagów i ustawienia własnego konta (D-021). */
class TagFollowController extends Controller
{
    public function follow(Request $request, Tag $tag, UpdateTagFollows $follows): RedirectResponse
    {
        try {
            $follows->follow($request->user(), [$tag->getKey()]);
        } catch (ValidationException) {
            return back()->with('status', 'Tego tagu nie da się już obserwować. Wybierz inny tag.');
        }

        return back()->with('status', "Obserwujesz tag „{$tag->name}”.");
    }

    public function unfollow(Request $request, Tag $tag, UpdateTagFollows $follows): RedirectResponse
    {
        $follows->unfollow($request->user(), $tag->getKey());

        return back()->with('status', "Nie obserwujesz już tagu „{$tag->name}”.");
    }

    public function edit(Request $request, TagFollowForm $forms): View
    {
        $user = $request->user();
        $followed = $user->followedTags()->get();
        $token = old('form_scope');
        $scope = $forms->decode($user, $token);
        if ($scope !== null) {
            // Błąd walidacji nie przesuwa punktu odniesienia na stan z innej karty.
            // Niedostępnego nowego wyboru nie rysujemy jako aktywnej opcji.
            $tags = Tag::query()->whereIn('id', $scope['shown'])
                ->where(fn ($query) => $query->where('status', Tag::STATUS_ACTIVE)
                    ->orWhereIn('id', $followed->pluck('id')))->get();
        } else {
            // Każde już obserwowane hasło musi dać się zdjąć, także niepromowane.
            $tags = $followed->concat(Tag::promowane()->get())->unique('id');
            $token = $forms->encode($user, $tags->pluck('id')->all(),
                $followed->mapWithKeys(fn (Tag $tag): array => [$tag->getKey() => (string) $tag->pivot->created_at])->all());
        }
        $selected = session()->hasOldInput() ? (array) old('tags', []) : $followed->pluck('id')->all();

        return view('pages.settings.tags', [
            'tags' => $tags->sortBy('name')->values(),
            'wybrane' => $selected,
            'formScope' => $token,
        ]);
    }

    public function update(Request $request, TagFollowForm $forms, TagSelection $selection, UpdateTagFollows $follows): RedirectResponse
    {
        $scope = $forms->decode($request->user(), $request->input('form_scope'));
        if ($scope === null) {
            throw ValidationException::withMessages(['tags' => 'Sprawdź zaznaczenia w odświeżonym formularzu i zapisz ponownie.']);
        }
        // Limit wynika z rzeczywiście pokazanej listy, nie ogranicza liczby
        // obserwowań konta. Dopuszcza również pusty wybór.
        $selected = $selection->validate($request, count($scope['shown']));
        $follows->save($request->user(), $selected, $scope);

        return redirect()->route('settings.tags')->with('status', 'Zapisaliśmy Twoje tagi.');
    }
}
