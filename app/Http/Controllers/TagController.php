<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\ZapisyWpisu;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Strona tagu — lista wpisów oznaczonych jednym tagiem (D-021, zastępuje
 * `TopicController`).
 *
 * DLACZEGO TO ISTNIEJE
 * Ten sam powód co przy Temacie: tag bez własnej strony jest etykietą,
 * a nie miejscem. Osoba, która nikogo nie obserwuje, ma DOKĄD pójść
 * (SOUL.md 4.7 — cytowane też przy `TopicController`, bo to dokładnie ta
 * sama wartość produktowa, tylko na otwartej taksonomii zamiast zamkniętej).
 *
 * Lista jest CHRONOLOGICZNA, tak jak feed. Żadnego rankingu — ta sama
 * decyzja co przy feedzie obserwowanych i z tego samego powodu: ranking
 * zamienia dzielenie się jedzeniem w konkurs.
 */
class TagController extends Controller
{
    public function __construct(private readonly ZapisyWpisu $zapisy = new ZapisyWpisu) {}

    public function show(Request $request, Tag $tag): View|RedirectResponse
    {
        // Tag ukryty (moderacja) nie ma publicznej strony — w odróżnieniu
        // od scalenia (niżej), to nie jest „przenieś się gdzie indziej",
        // tylko „tej treści tu nie ma".
        if ($tag->status === Tag::STATUS_HIDDEN) {
            abort(404);
        }

        // Tag SCALONY zostaje w bazie ze swoim slugiem (SPEC §1.8: „nie
        // kasować źródłowego tagu twardo") — więc stara strona istnieje
        // nadal, ale ma przekierować na kanoniczną. Bez osobnej tabeli
        // przekierowań (`recipe_slug_redirects`) — R1 §1.8 tłumaczy,
        // dlaczego tagi jej nie potrzebują: `Tag::tagKanoniczny()` już
        // wie, dokąd prowadzić.
        if ($tag->isMerged()) {
            return redirect()->route('tags.show', $tag->tagKanoniczny());
        }

        $widz = $request->user();

        $wpisy = Post::query()
            ->whereHas('tags', fn ($q) => $q->whereKey($tag->getKey()))
            ->published()
            // Ta sama macierz widoczności co wszędzie indziej: wpisy tylko
            // dla obserwujących i prywatne NIE MOGĄ wypłynąć przez tag.
            ->widoczneDla($widz)
            // Strona tagu POLECA treść nieznajomym, tak jak „Świeżo z Kuking":
            // konto pod sankcją nie ma być z niej promowane (audyt A5).
            ->tylkoOdAktywnychAutorow()
            ->with(['author.profile.avatar', 'media', 'tags:id,slug,name'])
            ->withCount(['comments' => fn ($query) => $query->widoczneDla($widz)])
            // Liczba zapisów i stan „mam to w zeszycie" — TYM SAMYM
            // zapytaniem (issue #275, D-081). Reguły siedzą w `ZapisyWpisu`,
            // tutaj jest tylko miejsce, w którym dokładamy kolumnę do SELECT-a.
            ->tap(fn ($query) => $this->zapisy->dolicz($query, $widz))
            ->latest('published_at')
            ->latest('id')
            ->paginate((int) config('kuking.feed.page_size'))
            ->withQueryString();

        return view('pages.tags.show', [
            'tag' => $tag,
            'posts' => $wpisy,
            'obserwowany' => $widz !== null && $widz->isFollowingTag($tag),
        ]);
    }
}
