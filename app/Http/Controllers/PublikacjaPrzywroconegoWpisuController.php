<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Posts\Actions\PublishRestoredDraft;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Autor publikuje ponownie wpis przywrócony przez moderację jako szkic (#2461).
 *
 * Dwa kroki: ekran potwierdzenia (kto widzi wpis — bez możliwości zmiany)
 * i zwykły POST. Oba przechodzą przez `PostPolicy::publishRestored`; stan
 * wpisu rozstrzyga akcja pod blokadą (`PublishRestoredDraft`).
 */
class PublikacjaPrzywroconegoWpisuController extends Controller
{
    public function __construct(private readonly PublishRestoredDraft $publikuj) {}

    public function potwierdz(Request $request, Post $post): View|RedirectResponse
    {
        $this->authorize('publishRestored', $post);

        if ($post->status === Post::STATUS_PUBLISHED) {
            return redirect($post->url())
                ->with(Komunikat::informacja('Ten wpis jest już opublikowany.'));
        }

        $powod = PublishRestoredDraft::powodOdmowy($post);
        if ($powod !== null) {
            return redirect()->route('collections.own-posts')->with(Komunikat::blad($powod));
        }

        $post->load(['media', 'recipe:id,title,slug,visibility']);

        return view('pages.posts.publikacja-przywroconego', ['post' => $post]);
    }

    public function publikuj(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('publishRestored', $post);

        try {
            $terazOpublikowany = $this->publikuj->handle($request->user(), $post, $request->ip());
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('collections.own-posts')->with(Komunikat::blad($e->getMessage()));
        }

        return redirect($post->url())->with($terazOpublikowany
            ? Komunikat::sukces('Wpis jest znów opublikowany, na swoim dawnym miejscu.')
            : Komunikat::informacja('Ten wpis jest już opublikowany.'));
    }
}
