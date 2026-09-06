<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * "Ugotowałem".
 *
 * Formularz ma jedno pole obowiązkowe: żadne. Wystarczy kliknąć i wysłać.
 * Zdjęcie, uwaga, czas i "zrobię ponownie" są opcjonalne — bo próg wejścia
 * musi być niższy niż przy komentarzu, a nie wyższy.
 */
class CookedEventController extends Controller
{
    /**
     * Domyślna treść „Podziękuj" (issue #17).
     *
     * Issue mówi „jedno wyraźne działanie", nie „jeden formularz do
     * wypełnienia" — próg wejścia ma być niższy niż przy zwykłym
     * komentarzu, tak jak przy samym „Ugotowałem" (patrz komentarz klasy
     * wyżej). Dlatego pole jest WIDOCZNE i EDYTOWALNE, ale wypełnione z góry:
     * można kliknąć raz i wysłać, ale kto chce dopisać coś swojego, nie musi
     * kasować gotowego tekstu i zaczynać od zera.
     */
    private const DOMYSLNE_PODZIEKOWANIE = 'Dziękuję, że ugotowałeś/aś mój przepis! Cieszę się, że wyszło.';

    public function __construct(
        private readonly RecordCookedEvent $record,
        private readonly StoreUploadedImage $storeImage,
        private readonly PublishComment $publishComment,
    ) {}

    public function create(Request $request, string $recipe): View
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('cook', $model);

        return view('pages.cooked.create', [
            'recipe' => $model->load(['author.profile', 'heroMedia']),
        ]);
    }

    public function store(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('cook', $model);

        $data = $request->validate([
            // BYŁO "max:4" wpisane tu na sztywno, niezależnie od
            // `config('kuking.media.max_per_post')` — dokładnie ten rozjazd
            // (ta sama liczba w dwóch miejscach) pozwolił na wysyłkę do
            // 4 × 15 MB = 60 MB w jednym żądaniu, ponad dwa razy więcej,
            // niż mieści `post_max_size` z `docker/php.ini` (audyt A31).
            // Teraz obowiązuje TEN SAM budżet co w PostController.
            'photos' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'photos.*' => ['file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'note' => ['nullable', 'string', 'max:2000'],
            'changes_note' => ['nullable', 'string', 'max:1000'],
            'would_make_again' => ['nullable', 'boolean'],
            'perceived_difficulty' => ['nullable', 'in:easy,medium,hard'],
            'actual_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
        ], [
            'photos.*.image' => 'Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP.',
            // Wcześniej nie było tu komunikatu — przy przekroczeniu rozmiaru
            // albo liczby zdjęć człowiek widziałby domyślny, angielski
            // komunikat Laravela. To łamie "błędy po polsku" z AGENTS.md.
            'photos.*.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'photos.max' => LimityZdjec::komunikatZaDuzoZdjec(),
            'note.max' => 'Ta uwaga jest za długa. Zmieść się w 2000 znakach.',
            // `in` ma mówić, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — to pole renderuje się jako
            // trzy przyciski, więc zdanie wymienia dokładnie te trzy.
            'perceived_difficulty.in' => 'Wybierz, jak trudny był ten przepis: łatwy, średni albo trudny.',
        ]);

        $user = $request->user();

        // „Zrobisz to jeszcze raz?" ma TRZY stany, nie dwa (audyt A22).
        //
        // Wcześniej stało tu `$request->boolean(...) ?: null`. Formularz wysyła
        // value="0", więc świadome „Raczej nie powtórzę" wpadało w `?:`
        // i lądowało w bazie jako `null`, czyli „nie zaznaczono". Zapisywały
        // się wyłącznie pochwały, a gotowy render negatywnej odpowiedzi
        // w karcie wykonania był kodem nie do wywołania.
        //
        // „Ugotowałem" to najcenniejszy sygnał jakości przepisu (AGENTS.md §1).
        // Sygnał, w którym da się zapisać tylko „tak", nie jest sygnałem
        // jakości — jest licznikiem pochwał.
        $wouldMakeAgain = ($data['would_make_again'] ?? null) === null
            ? null
            : $request->boolean('would_make_again');

        try {
            $mediaIds = [];

            foreach ($request->file('photos', []) as $photo) {
                $mediaIds[] = $this->storeImage->handle($user, $photo)->getKey();
            }

            $event = $this->record->handle(
                cook: $user,
                recipe: $model,
                note: $data['note'] ?? null,
                mediaIds: $mediaIds,
                wouldMakeAgain: $wouldMakeAgain,
                perceivedDifficulty: $data['perceived_difficulty'] ?? null,
                actualMinutes: $data['actual_minutes'] ?? null,
                changesNote: $data['changes_note'] ?? null,
                ip: $request->ip(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['note' => $e->getMessage()]);
        }

        $authorName = $model->author->displayName();

        return redirect()->route('cooked.show', $event)->with('status',
            "Zapisane. {$authorName} dowie się, że ktoś ugotował z tego przepisu.",
        );
    }

    public function show(Request $request, CookedEvent $cookedEvent): View
    {
        $this->authorize('view', $cookedEvent);

        $cookedEvent->load([
            'user.profile.avatar',
            'recipe.author.profile',
            'media',
            // Komentarze filtrowane przez blokady (issue #41). Bez tego
            // zablokowana osoba nadal była widoczna pod cudzymi treściami.
            'comments' => fn ($query) => $query->widoczneDla($request->user()),
            'comments.author.profile.avatar',
            'comments.replies' => fn ($query) => $query->widoczneDla($request->user()),
            'comments.replies.author.profile.avatar',
        ]);

        return view('pages.cooked.show', ['event' => $cookedEvent]);
    }

    /**
     * Ekran „Komuś wyszło" (issue #17) — najcenniejszy moment w produkcie,
     * pokazany PEŁNOEKRANOWO, nie jako zwykły wiersz na liście powiadomień.
     *
     * Pokazuje się raz na wykonanie. Zamiast dodawać nową kolumnę tylko po
     * to, żeby zapamiętać „już pokazano" (schemat = migracja + test +
     * docs/DATABASE.md, czego nic tu nie uzasadnia), pożyczamy stan z
     * `notifications.read_at` — ta kolumna istnieje dokładnie po to, żeby
     * zaznaczyć „ta osoba to już widziała", a druga flaga obok byłaby
     * drugim źródłem prawdy dla tego samego faktu. Efekt uboczny jest
     * pożądany, nie przypadkowy: kryterium akceptacji wprost mówi, że
     * obejrzenie tego ekranu ma oznaczyć powiadomienie jako przeczytane.
     *
     * Powiadomienia może nie być (np. skasowane porządkami, albo ktoś
     * wszedł tu starym linkiem sprzed tej funkcji) — wtedy traktujemy to
     * jak pierwsze wejście i pokazujemy ekran, po prostu nie ma czego
     * oznaczyć jako przeczytane.
     */
    public function celebrate(Request $request, CookedEvent $cookedEvent): View|RedirectResponse
    {
        $this->authorize('celebrate', $cookedEvent);

        $notification = Notification::query()
            ->where('user_id', $request->user()->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->where('data->cooked_event_id', $cookedEvent->getKey())
            ->first();

        if ($notification !== null && $notification->isUnread() === false) {
            return redirect()->route('cooked.show', $cookedEvent);
        }

        // `update()` przechodzi przez `fill()`, a `read_at` CELOWO nie jest
        // w `$fillable` Notification (żadne pole zapisywane z requestu nie
        // powinno tam trafić przez masowe przypisanie) — `update()` po cichu
        // by je zgubił, zamiast rzucić błąd, i ten ekran nigdy by się nie
        // "zapamiętywał". `forceFill()` to ten sam wzorzec co przy zmianie
        // `status`/`role` w User (tam z tego samego powodu).
        $notification?->forceFill(['read_at' => now()])->save();

        $cookedEvent->load(['user.profile.avatar', 'recipe', 'media']);

        return view('pages.cooked.celebrate', [
            'event' => $cookedEvent,
            'domyslnePodziekowanie' => self::DOMYSLNE_PODZIEKOWANIE,
        ]);
    }

    /**
     * „Podziękuj" z ekranu „Komuś wyszło" — zwykły komentarz pod wykonaniem,
     * wysłany przez `PublishComment` (ta sama droga, ta sama blokada
     * sprawdzana w środku, to samo powiadomienie dla kucharza co przy
     * dowolnym innym komentarzu). Nic tu nie duplikuje logiki komentarzy —
     * różni się tylko tym, SKĄD przychodzi kliknięcie i jaki tekst czeka
     * gotowy w polu.
     */
    public function thank(Request $request, CookedEvent $cookedEvent): RedirectResponse
    {
        $this->authorize('celebrate', $cookedEvent);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'Napisz albo zostaw gotowe podziękowanie, zanim wyślesz.',
        ]);

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $cookedEvent,
                body: $data['body'],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('cooked.show', $cookedEvent)->with(
            'status',
            $cookedEvent->user->displayName().' dowie się, że podziękowałaś/eś za wykonanie.',
        );
    }

    public function comment(Request $request, CookedEvent $cookedEvent): RedirectResponse
    {
        $this->authorize('view', $cookedEvent);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'parent_id' => ['nullable', 'uuid'],
        ], [
            'body.required' => 'Napisz coś, zanim wyślesz komentarz.',
        ]);

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $cookedEvent,
                body: $data['body'],
                parent: $data['parent_id'] === null
                    ? null
                    : $cookedEvent->comments()->whereKey($data['parent_id'])->first(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('status', 'Komentarz dodany.');
    }

    public function destroy(Request $request, CookedEvent $cookedEvent): RedirectResponse
    {
        $this->authorize('delete', $cookedEvent);

        // Przepis mógł zostać usunięty (soft delete) po zapisaniu wykonania —
        // wtedy relacja zwraca null (audyt A23). Wcześniej ta linia rzucała
        // wyjątek PRZED skasowaniem, więc człowiek nie mógł usunąć własnego
        // wykonania i zostawał z trwale zepsutą zakładką „Ugotowane".
        $slug = $cookedEvent->recipe?->slug;
        $wlascicielWykonania = $cookedEvent->user;
        $cookedEvent->delete();

        if ($slug === null) {
            // Nie ma dokąd wrócić „do przepisu" — wracamy tam, skąd człowiek
            // to zobaczył, czyli do zakładki „Ugotowane" na jego profilu.
            return redirect()
                ->route('profile.show', ['username' => $wlascicielWykonania->profile->username, 'zakladka' => 'ugotowane'])
                ->with('status', 'Wykonanie usunięte.');
        }

        return redirect()->route('recipes.show', $slug)->with('status', 'Wykonanie usunięte.');
    }
}
