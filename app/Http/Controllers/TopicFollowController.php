<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Topic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Obserwowanie tematów (issue #31, część druga).
 *
 * ZWYKŁE FORMULARZE, ŻADNEGO JAVASCRIPTU
 * „Obserwuj" i „Przestań obserwować" to `<form method="POST">` z tokenem
 * CSRF. Przycisk, który bez skryptu nie robi nic, jest dla części naszych
 * użytkowników przyciskiem, którego nie ma (AGENTS.md §5).
 *
 * BEZ POWIADOMIENIA DLA KOGOKOLWIEK
 * Obserwowanie osoby powiadamia tę osobę — bo to jest zdarzenie między
 * ludźmi. Temat nie jest człowiekiem i nie ma komu tego zgłosić; licznik
 * obserwujących temat jest informacją redakcyjną, nie społeczną.
 */
class TopicFollowController extends Controller
{
    public function follow(Request $request, Topic $topic): RedirectResponse
    {
        // Temat wycofany przez redakcję nadal MA stronę (wpisy, które go
        // mają, nie mogą stracić adresu), ale nie da się go zacząć
        // obserwować — inaczej budowalibyśmy komuś feed z listy, która
        // przestała istnieć.
        if (! $topic->is_active) {
            return back()->with('status', 'Tego tematu nie da się już obserwować.');
        }

        // `syncWithoutDetaching` zamiast `attach`: „Obserwuj" bywa klikane
        // dwa razy z niepewności, a klucz główny na parze zamieniłby drugie
        // kliknięcie w błąd bazy danych zamiast w nic.
        $request->user()->followedTopics()->syncWithoutDetaching([
            $topic->getKey() => ['created_at' => now()],
        ]);

        return back()->with('status', "Obserwujesz temat „{$topic->name}”.");
    }

    public function unfollow(Request $request, Topic $topic): RedirectResponse
    {
        $request->user()->followedTopics()->detach($topic->getKey());

        return back()->with('status', "Nie obserwujesz już tematu „{$topic->name}”.");
    }

    /**
     * „Twoje tematy" w ustawieniach — jeden ekran z całą listą.
     *
     * Wybór tematów zapada w onboardingu, w momencie, w którym człowiek
     * chętnie odpowiada na pytania o siebie. Potem nie ma go gdzie zmienić,
     * a zainteresowania się zmieniają — i to jest jedyny ekran, na którym
     * widać, co właściwie decyduje o zawartości feedu.
     */
    public function edit(Request $request): View
    {
        return view('pages.settings.topics', [
            'topics' => Topic::doWyboru()->get(),
            'followed' => $request->user()->followedTopics()->pluck('topics.id')->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dane = $request->validate([
            'topics' => ['nullable', 'array'],
            'topics.*' => ['string', 'exists:topics,id'],
        ]);

        // Tylko tematy DO WYBORU, i tylko one podlegają zdjęciu. Gdyby ktoś
        // obserwował temat później wycofany przez redakcję, zwykły `sync`
        // zdjąłby mu go po cichu przy pierwszym zapisie tego formularza —
        // bo wycofanego tematu nie ma na liście, więc nie ma go w żądaniu.
        $doWyboru = Topic::doWyboru()->pluck('id')->all();
        $wybrane = array_values(array_intersect($dane['topics'] ?? [], $doWyboru));

        $obserwowane = $request->user()->followedTopics()->pluck('topics.id')->all();

        // Świadomie NIE `sync()`: ten przepisałby `created_at` wszystkim
        // tematom przy każdym zapisie formularza, także tym obserwowanym
        // od miesięcy. „Od kiedy" jest jedyną informacją, jaką ta tabela
        // niesie poza samym faktem — nie wolno jej gubić przy zapisie,
        // który niczego w danym wierszu nie zmienia.
        $doDodania = array_diff($wybrane, $obserwowane);
        $doZdjecia = array_intersect(array_diff($obserwowane, $wybrane), $doWyboru);

        if ($doDodania !== []) {
            $request->user()->followedTopics()->attach(
                array_fill_keys($doDodania, ['created_at' => now()]),
            );
        }

        if ($doZdjecia !== []) {
            $request->user()->followedTopics()->detach(array_values($doZdjecia));
        }

        return redirect()
            ->route('settings.topics')
            ->with('status', 'Zapisaliśmy Twoje tematy.');
    }
}
