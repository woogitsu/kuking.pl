<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Strona tematu — lista wpisów oznaczonych jednym tematem (issue #31).
 *
 * DLACZEGO TO ISTNIEJE
 * Temat bez własnej strony jest etykietą, a nie miejscem. Cała wartość
 * tematów w tym produkcie polega na tym, że osoba, która nikogo nie
 * obserwuje, ma DOKĄD pójść (SOUL.md 4.7: „tanie 80% wartości grupy
 * bez budowania grup").
 *
 * Lista jest CHRONOLOGICZNA, tak jak feed. Żadnego rankingu, żadnego
 * „najpopularniejsze w temacie" — to jest ta sama decyzja co przy feedzie
 * obserwowanych (AGENTS.md) i z tego samego powodu: ranking zamienia
 * dzielenie się jedzeniem w konkurs.
 */
class TopicController extends Controller
{
    public function show(Request $request, Topic $topic): View
    {
        // Temat wycofany przez redakcję nadal MA stronę: wpisy, które już go
        // mają, nie mogą stracić miejsca, do którego ktoś wysłał link.
        // Ze świeżego wyboru znika (Topic::doWyboru), ale nie z sieci.
        $widz = $request->user();

        $wpisy = Post::query()
            ->where('topic_id', $topic->getKey())
            ->published()
            // Ta sama macierz widoczności co wszędzie indziej: wpisy tylko
            // dla obserwujących i prywatne NIE MOGĄ wypłynąć przez temat.
            // Bez tego strona tematu byłaby obejściem ustawień prywatności.
            ->widoczneDla($widz)
            ->with(['author.profile.avatar', 'media', 'topic'])
            ->withCount(['comments' => fn ($query) => $query->widoczneDla($widz)])
            ->latest('published_at')
            ->latest('id')
            ->paginate((int) config('kuking.feed.page_size'))
            ->withQueryString();

        return view('pages.topics.show', [
            'topic' => $topic,
            'posts' => $wpisy,
        ]);
    }
}
