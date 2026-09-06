<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Posts\Actions\PublishPost;
use App\Models\Post;
use App\Models\Topic;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Wpisy: zdjęcie + kilka słów.
 *
 * Cały formularz jest na JEDNEJ stronie i działa BEZ JavaScriptu.
 * To nie jest ustępstwo — to warunek, żeby publikacja udawała się na starym
 * telefonie, przy słabym łączu i przy powiększonej czcionce.
 */
class PostController extends Controller
{
    public function __construct(
        private readonly PublishPost $publishPost,
        private readonly StoreUploadedImage $storeImage,
        private readonly PublishComment $publishComment,
    ) {}

    public function create(): View
    {
        // Zamknięta lista tematów (issue #31). Wybór jest OPCJONALNY —
        // wymuszanie go dokładałoby decyzję w momencie, w którym chcemy,
        // żeby człowiek po prostu wrzucił zdjęcie.
        return view('pages.posts.create', ['topics' => Topic::doWyboru()->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'photos' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'photos.*' => ['file', 'image', 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'body' => ['nullable', 'string', 'max:4000'],
            'visibility' => ['required', 'in:public,followers,private'],
            // Temat opcjonalny, ale MUSI istnieć i być aktywny. Sprawdzenie
            // po stronie akcji domenowej jest drugą bramką — ta tutaj jest
            // po to, żeby człowiek dostał komunikat zamiast cichego pominięcia.
            'topic_id' => ['nullable', 'uuid'],
        ], [
            'photos.*.image' => 'Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP.',
            'photos.*.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'photos.max' => LimityZdjec::komunikatZaDuzoZdjec(),
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten wpis.',
        ]);

        $user = $request->user();
        $mediaIds = [];

        try {
            foreach ($request->file('photos', []) as $photo) {
                $mediaIds[] = $this->storeImage->handle($user, $photo)->getKey();
            }

            $post = $this->publishPost->handle(
                author: $user,
                body: $data['body'] ?? null,
                mediaIds: $mediaIds,
                visibility: $data['visibility'],
                topicId: $data['topic_id'] ?? null,
                ip: $request->ip(),
            );
        } catch (RuntimeException $e) {
            // Formularz zachowuje wpisany tekst — poprawne dane nigdy nie giną
            // (docs/UX_50_PLUS.md).
            return back()->withInput()->withErrors(['photos' => $e->getMessage()]);
        }

        $isFirstPost = $user->posts()->published()->count() === 1;

        return redirect()->route('posts.show', $post)->with(
            'status',
            $isFirstPost
                ? 'Gotowe. To Twój pierwszy wpis w Kuking — od teraz masz swoje archiwum.'
                : 'Opublikowane. Dziękujemy.',
        );
    }

    public function show(Request $request, Post $post): View
    {
        $this->authorize('view', $post);

        $post->load([
            'author.profile.avatar',
            'media',
            'recipe:id,title,slug',
            // Komentarze filtrowane przez blokady (issue #41). Bez tego
            // zablokowana osoba nadal była widoczna pod cudzymi treściami.
            'comments' => fn ($query) => $query->widoczneDla($request->user()),
            'comments.author.profile.avatar',
            'comments.replies' => fn ($query) => $query->widoczneDla($request->user()),
            'comments.replies.author.profile.avatar',
        ]);

        return view('pages.posts.show', ['post' => $post]);
    }

    public function comment(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('comment', $post);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'parent_id' => ['nullable', 'uuid'],
        ], [
            'body.required' => 'Napisz coś, zanim wyślesz komentarz.',
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ]);

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $post,
                body: $data['body'],
                parent: $data['parent_id'] === null
                    ? null
                    : $post->allComments()->whereKey($data['parent_id'])->first(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('status', 'Komentarz dodany.');
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('home')->with('status', 'Wpis usunięty.');
    }
}
