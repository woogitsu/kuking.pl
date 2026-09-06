<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Comments\Actions\PublishComment;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private readonly PublishComment $publishComment) {}

    public function index(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $wpisy = Post::query()
            ->published()
            // Wpisy prywatne nie czekają na odpowiedź gospodarza — nikt poza
            // autorem ich nie widzi, więc brak komentarza nie jest problemem.
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
            ->whereDoesntHave('allComments', fn ($q) => $q->where('status', 'published'))
            // Konto zbanowane albo zgłoszone do usunięcia nie czeka na powitanie.
            ->whereHas('author', fn ($q) => $q->whereNotIn('status', [
                User::STATUS_BANNED,
                User::STATUS_PENDING_DELETE,
            ]))
            ->with(['author.profile.avatar', 'media', 'topic:id,slug,name'])
            ->orderBy('published_at')
            ->limit(50)
            ->get();

        // PIERWSZY WPIS DANEJ OSOBY TO NAJWAŻNIEJSZY WIERSZ NA LIŚCIE.
        //
        // Liczone jednym zapytaniem dla wszystkich autorów naraz, nie
        // w pętli: ten ekran ma się otwierać od razu, inaczej gospodarz
        // przestanie na niego zaglądać.
        $pierwszeWpisy = $this->pierwszeWpisyAutorow($wpisy->pluck('author_id')->unique()->all());

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
            'medianaReakcji' => $this->medianaCzasuDoPierwszejReakcji(),
        ]);
    }

    public function odpowiedz(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $dane = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Napisz coś, zanim wyślesz odpowiedź.',
        ]);

        $this->publishComment->handle(
            author: $request->user(),
            subject: $post,
            body: $dane['body'],
        );

        return back()->with('status', 'Odpowiedź wysłana. Wpis znika z listy.');
    }

    /**
     * Identyfikator NAJSTARSZEGO opublikowanego wpisu każdego z podanych
     * autorów.
     *
     * @param  list<string>  $autorzy
     * @return array<string, string>
     */
    private function pierwszeWpisyAutorow(array $autorzy): array
    {
        if ($autorzy === []) {
            return [];
        }

        return Post::query()
            ->whereIn('author_id', $autorzy)
            ->published()
            ->selectRaw('distinct on (author_id) author_id, id')
            ->orderBy('author_id')
            ->orderBy('published_at')
            ->orderBy('id')
            ->pluck('id', 'author_id')
            ->all();
    }

    /**
     * Mediana czasu od publikacji do pierwszego komentarza, z ostatnich
     * trzydziestu dni.
     *
     * MEDIANA, NIE ŚREDNIA — i to nie jest szczegół statystyczny. Jeden wpis,
     * na który ktoś odpowiedział po dwóch tygodniach, przesuwa średnią tak,
     * że liczba przestaje cokolwiek znaczyć. Mediana mówi, ile czeka
     * TYPOWA osoba, a to jest pytanie, które nas interesuje.
     *
     * @return float|null godziny; null, gdy nie ma jeszcze z czego liczyć
     */
    private function medianaCzasuDoPierwszejReakcji(): ?float
    {
        $wiersz = DB::selectOne(<<<'SQL'
            SELECT percentile_cont(0.5) WITHIN GROUP (
                ORDER BY EXTRACT(EPOCH FROM (pierwszy_komentarz - published_at)) / 3600
            ) AS mediana
            FROM (
                SELECT p.published_at, MIN(c.created_at) AS pierwszy_komentarz
                FROM posts p
                JOIN comments c ON c.post_id = p.id
                WHERE p.status = 'published'
                  AND p.deleted_at IS NULL
                  AND p.published_at >= now() - interval '30 days'
                  AND c.status = 'published'
                GROUP BY p.id, p.published_at
            ) AS pierwsze
        SQL);

        return $wiersz?->mediana === null ? null : round((float) $wiersz->mediana, 1);
    }
}
