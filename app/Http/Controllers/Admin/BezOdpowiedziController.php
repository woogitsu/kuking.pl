<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Moderation\UnansweredContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wpisy, na które nikt jeszcze nie odpowiedział (issue #6).
 *
 * NAJTWARDSZA LICZBA Z CAŁEGO RESEARCHU
 * 55% osób 55-64 i 62% osób 65+ w mediach społecznościowych to WYŁĄCZNIE
 * odbiorcy treści (docs/research/AUDIENCE_50_PLUS.md). Kto opublikuje, robi
 * to wbrew własnemu nawykowi.
 *
 * Wniosek jest brutalnie prosty: odpowiedź od człowieka w ciągu doby
 * na pierwszy wpis jest ważniejsza niż którakolwiek funkcja z MVP. Wpis bez
 * żadnej reakcji to koniec — ta osoba już nie wróci.
 *
 * Ten ekran nie jest panelem statystyk. Jest listą rzeczy do zrobienia dzisiaj,
 * najstarsze na górze, z odpowiedzią wprost z listy — bo playbook gospodarza
 * z COLD_START.md ma być wykonalny, a nie „do zapamiętania".
 *
 * CZEGO TU ŚWIADOMIE NIE MA
 * Żadnych automatycznych komentarzy, gotowych „reakcji jednym kliknięciem"
 * ani podpowiedzi generowanych przez AI. Fałszywa reakcja jest gorsza niż jej
 * brak — a przy dwudziestu jeden osobach w alfie jest natychmiast
 * rozpoznawalna. To narzędzie ma wspierać człowieka, nie go zastępować.
 */
class BezOdpowiedziController extends Controller
{
    /** Ile godzin bez odpowiedzi to jeszcze spokój, a ile już alarm. */
    private const UWAGA_OD_GODZIN = 6;

    private const ALARM_OD_GODZIN = 24;

    public function __construct(private readonly PublishComment $publishComment, private readonly UnansweredContent $queue) {}

    public function index(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $type = $request->query('typ', 'wpisy');
        abort_unless(in_array($type, ['wpisy', 'przepisy', 'ugotowane', 'pytania'], true), 404);
        abort_if($type === 'pytania' && ! config('kuking.questions.enabled'), 404);

        if ($type === 'pytania') {
            $items = $this->queue->questions($request->user())->with('author.profile')
                ->orderBy('published_at')->orderBy('id')->paginate(25)->withQueryString();

            return view('pages.admin.bez-odpowiedzi-pytania', ['items' => $items, 'type' => $type]);
        }

        if ($type !== 'wpisy') {
            $items = $type === 'przepisy'
                ? $this->queue->recipes($request->user())->with('author.profile')->orderBy('published_at')->orderBy('id')->simplePaginate(25)
                : $this->queue->cooked($request->user())->with(['user.profile', 'recipe'])->orderBy('created_at')->orderBy('id')->simplePaginate(25);

            return view('pages.admin.bez-odpowiedzi-inne', [
                'type' => $type,
                'items' => $items->withQueryString(),
            ]);
        }

        $wpisy = $this->queue->posts($request->user())
            ->with(['author.profile.avatar', 'media', 'tags:id,slug,name,status'])
            ->orderBy('published_at')
            ->limit(50)
            ->get();

        // PIERWSZY WPIS DANEJ OSOBY TO NAJWAŻNIEJSZY WIERSZ NA LIŚCIE.
        //
        // Liczone jednym zapytaniem dla wszystkich autorów naraz, nie
        // w pętli: ten ekran ma się otwierać od razu, inaczej gospodarz
        // przestanie na niego zaglądać.
        $pierwszeWpisy = $this->queue->firstPostIds($request->user(), $wpisy->pluck('author_id')->unique()->all());

        $wpisy = $wpisy->map(function (Post $wpis) use ($pierwszeWpisy): Post {
            $wpis->toPierwszyWpis = ($pierwszeWpisy[$wpis->author_id] ?? null) === $wpis->getKey();
            $wpis->godzinCzekania = (int) $wpis->published_at->diffInHours(now());
            $wpis->pilnosc = match (true) {
                $wpis->godzinCzekania >= self::ALARM_OD_GODZIN => 'alarm',
                $wpis->godzinCzekania >= self::UWAGA_OD_GODZIN => 'uwaga',
                default => 'spokojnie',
            };

            return $wpis;
        })->sortByDesc(fn (Post $wpis) => [$wpis->toPierwszyWpis ? 1 : 0, -$wpis->published_at->timestamp])
            ->values();

        return view('pages.admin.bez-odpowiedzi', [
            'wpisy' => $wpisy,
            'najstarszy' => $wpisy->max('godzinCzekania'),
            'progUwagi' => self::UWAGA_OD_GODZIN,
            'progAlarmu' => self::ALARM_OD_GODZIN,
            'medianaReakcji' => $this->queue->medianPostResponseHours($request->user()),
        ]);
    }

    public function odpowiedz(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        // Ponownie sprawdzamy dostęp po otwarciu listy; cudza odpowiedź
        // nie odbiera prawa do dopisania własnej.
        abort_unless($this->queue->eligiblePosts($request->user())->whereKey($post->getKey())->exists(), 404);

        $dane = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Napisz coś, zanim wyślesz odpowiedź.',
        ]);

        // Ta sama granica co zwykły komentarz (`PostController::comment`,
        // #1382). Zapytanie kolejki to filtr listy, nie prawo komentowania —
        // np. zapowiedź prywatnego przepisu jest w kolejce, a zwykłą ścieżką
        // nie da się jej skomentować. `LockCommentContext` sprawdza tę Policy
        // drugi raz pod zamkiem; tu jest wejście, więc odmowa pada przed
        // akcją domenową. Błąd pola zamiast 403 — napisana odpowiedź zostaje.
        if ($request->user()->cannot('comment', $post)) {
            return back()->withInput()->withErrors([
                'body' => 'Tego wpisu nie możesz skomentować — nie masz do niego dostępu jako czytelnik. Wybierz inny wpis z listy.',
            ]);
        }

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $post,
                body: $dane['body'],
            );
        } catch (BladDlaCzlowieka $error) {
            return back()->withInput()->withErrors(['body' => $error->getMessage()]);
        }

        return back()->with('status', 'Odpowiedź wysłana.');
    }
}
