<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

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
    private const DOMYSLNE_PODZIEKOWANIE = 'Dziękuję za ugotowanie mojego przepisu. Cieszę się, że wyszło.';

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
            'kluczWyslania' => $this->kluczDlaFormularza(),
        ]);
    }

    /**
     * Klucz wysłania dla świeżo renderowanego formularza „Ugotowałem".
     *
     * `old()` pierwsze: po nieudanej walidacji (za długa uwaga, za duże
     * zdjęcie) formularz wystawia się od nowa i klucz musi zostać ten sam —
     * inaczej ochrona znika po pierwszym błędzie.
     */
    private function kluczDlaFormularza(): ?string
    {
        // Wyłącznik awaryjny mechanizmu — `config/kuking.php`, sekcja
        // `formularze` (tam stoi całe uzasadnienie i skutek wyłączenia).
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $stary = old('klucz_wyslania');

        return is_string($stary) && Str::isUuid($stary) ? $stary : (string) Str::uuid7();
    }

    /**
     * Klucz wysłania z żądania. Wartość niebędąca UUID-em schodzi do `null`,
     * czyli do „zapisz normalnie" — zawodzimy otwarcie, nie zamknięcie
     * (ADR §4.3).
     */
    private function kluczZZadania(Request $request): ?string
    {
        // Wyłącznik awaryjny — TA SAMA bramka, co przy renderowaniu formularza.
        // Bez niej wyłącznik działa tylko w połowie: karta otwarta PRZED
        // przełączeniem nadal niesie klucz w DOM-ie i odsyła go, więc częściowy
        // indeks dalej obowiązuje — dokładnie w tej awarii, dla której ten
        // wyłącznik istnieje. `config/kuking.php` obiecuje, że po wyłączeniu
        // „kolumna dostaje NULL"; ta linijka jest tym, co tę obietnicę dowozi.
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $klucz = $request->input('klucz_wyslania');

        return is_string($klucz) && Str::isUuid($klucz) ? $klucz : null;
    }

    public function store(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('cook', $model);

        // PONOWIENIE JUŻ ZAPISANEGO WYSŁANIA — PRZED ZDJĘCIAMI (issue #873).
        //
        // Deduplikacja w `RecordCookedEvent` działała dopiero PO zapisaniu
        // plików z żądania, więc drugie kliknięcie ponownie wgrywało,
        // przetwarzało i osieracało te same zdjęcia. Stoi ZA autoryzacją:
        // odrzucone żądanie nie dowie się niczego o wykonaniu. Wyścig dwóch
        // jednoczesnych żądań rozstrzyga dalej indeks UNIQUE w akcji.
        $zapisane = $this->record->wykonanieZTegoWyslania($request->user(), $this->kluczZZadania($request));

        if ($zapisane !== null) {
            return $this->odpowiedzNaPonowienie($zapisane);
        }

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
            'changes_note.max' => 'To jest za długie. Zmieść się w 1000 znakach.',
            // `in` ma mówić, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — to pole renderuje się jako
            // trzy przyciski, więc zdanie wymienia dokładnie te trzy.
            'perceived_difficulty.in' => 'Wybierz, jak trudny był ten przepis: łatwy, średni albo trudny.',
            /*
             * TRZY KOMUNIKATY DOPISANE PRZY PRZEGLĄDZIE KOMUNIKATÓW.
             *
             * `would_make_again` bez własnego zdania dostawał szablon ogólny
             * reguły `boolean` z nazwą pola z `attributes` — a ta nazwa sama
             * zawierała cudzysłów drukarski, więc na ekran szło zdanie
             * z cudzysłowem w cudzysłowie: „Pole «odpowiedź «zrobię jeszcze
             * raz»» przyjmuje tylko wartość tak/nie." Zmierzone prawdziwym
             * żądaniem. Nie mówiło też, co zrobić — a na ekranie stoją dwa
             * przyciski z konkretnymi napisami i to je wymienia nowe zdanie.
             *
             * `actual_minutes` mówił „musi być nie mniejsze niż 0" i nazywał
             * pole „rzeczywisty czas gotowania", choć etykieta na ekranie
             * brzmi „Ile Ci to zajęło (w minutach)".
             */
            'would_make_again.boolean' => 'Zaznacz jedną z odpowiedzi: „Tak, zrobię ponownie” albo „Raczej nie powtórzę”.',
            'actual_minutes.integer' => 'Wpisz sam czas w minutach, samymi cyframi — na przykład 90.',
            'actual_minutes.min' => 'Czas nie może być ujemny. Wpisz liczbę minut, na przykład 90.',
            'actual_minutes.max' => 'Ten czas jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
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
        $wouldMakeAgain = ($data['would_make_again'] ?? null) === null || $data['would_make_again'] === ''
            ? null
            : $request->boolean('would_make_again');

        $perceivedDifficulty = empty($data['perceived_difficulty']) ? null : $data['perceived_difficulty'];

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
                perceivedDifficulty: $perceivedDifficulty,
                actualMinutes: $data['actual_minutes'] ?? null,
                changesNote: $data['changes_note'] ?? null,
                ip: $request->ip(),
                kluczWyslania: $this->kluczZZadania($request),
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['note' => $e->getMessage()]);
        }

        // DRUGIE KLIKNIĘCIE „WYŚLIJ" — wykonanie jest to samo, co przy
        // pierwszym. Komunikat potwierdza zapis i drogę do kolejnego
        // gotowania (D-005). Nie obiecuje powiadomienia: własne wykonanie
        // i autor, który nie może czytać, mają świadome wyjątki (AGENTS §1).
        if (! $event->wasRecentlyCreated) {
            return $this->odpowiedzNaPonowienie($event);
        }

        return redirect()->route('cooked.show', $event)->with('status',
            'Wykonanie zapisane.',
        );
    }

    private function odpowiedzNaPonowienie(CookedEvent $event): RedirectResponse
    {
        return redirect()->route('cooked.show', $event)->with(
            'status',
            'To wykonanie już zapisaliśmy. '
            .'Gotujesz ten przepis drugi raz? Otwórz „Ugotowałem” jeszcze raz — każde wykonanie zapisujemy osobno.',
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
            // `Comment::subject()` przy każdym komentarzu — jak `recipe`
            // w `RecipeController`.
            'comments.cookedEvent.recipe',
            'comments.replies.cookedEvent.recipe',
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

        // ISSUE #770: „Zobacz" z listy ustawia `read_at` PRZED tym ekranem
        // (`NotificationController::open()`), więc samo `read_at` nie mówi,
        // czy ekran był już pokazany. Flash z tego jednego kliknięcia mówi:
        // „to pierwsze otwarcie". Po odświeżeniu flasha już nie ma, a `read_at`
        // stoi — i ekran pokazuje się raz, jak dotąd.
        $pierwszeOtwarcie = $notification !== null
            && $request->session()->get(Notification::SESJA_PIERWSZE_OTWARCIE) === (string) $notification->getKey();

        if ($notification !== null && $notification->isUnread() === false && ! $pierwszeOtwarcie) {
            return redirect()->route('cooked.show', $cookedEvent);
        }

        // `read_at` CELOWO nie jest w `$fillable` Notification, więc
        // `$model->update()` po cichu by je zgubił. Zapis idzie zapytaniem
        // z `whereNull('read_at')` w samym `UPDATE` (D-079, jak
        // w `NotificationController::open()`): przy pierwszym otwarciu z listy
        // znacznik już stoi i nie wolno go przesuwać w przód — od niego
        // liczy się retencja.
        if ($notification !== null) {
            Notification::query()
                ->whereKey($notification->getKey())
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

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
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('cooked.show', $cookedEvent)->with(
            'status',
            $cookedEvent->user->displayName().' dostanie Twoje podziękowanie.',
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
            // TEN SAM KOMUNIKAT CO POD WPISEM. Bez tej linii zostawał
            // domyślny tekst frameworka („Pole «treść» jest za długie — może
            // mieć najwyżej 4000 znaków"), czyli inny głos i inne słowo na
            // to samo pole na sąsiednim ekranie.
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ]);

        // `?? null`, bo `validate()` NIE zwraca klucza, którego w żądaniu nie
        // było — a `parent_id` jest `nullable`. Komentarz wysłany bez tego
        // pola (czyli każdy spoza naszego formularza, który zawsze wysyła
        // puste) kończył się błędem „Undefined array key", czyli 500 zamiast
        // komentarza.
        $parentId = $data['parent_id'] ?? null;

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $cookedEvent,
                body: $data['body'],
                // `widoczneDla()` — audyt W7-06. Bez tego można było podać
                // UUID komentarza ukrytego przez blokadę i podpiąć się pod
                // cudzy wątek. Akcja domenowa sprawdza to drugi raz, bo
                // kontrolerów jest kilka.
                parent: $parentId === null
                    ? null
                    : $cookedEvent->comments()
                        ->widoczneDla($request->user())
                        ->whereKey($parentId)
                        ->first(),
                // ISSUE #761: patrz komentarz przy tym samym parametrze
                // w PostController::comment().
                parentRequested: $parentId !== null,
            );
        } catch (BladDlaCzlowieka $e) {
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
        $recipe = $cookedEvent->recipe;
        $wlascicielWykonania = $cookedEvent->user;
        $cookedEvent->delete();

        // Przepis może nie istnieć (soft delete) ALBO aktor może nie mieć
        // już do niego uprawnień do odczytu (np. autor zmienił widoczność
        // na prywatną, moderacja ukryła przepis, relacja blokady — issue #766).
        // Bez sprawdzenia uprawnień powrót do recipes.show kończył się 403 Forbidden.
        if ($recipe === null || $request->user()->cannot('view', $recipe)) {
            // Bezpieczny powrót na profil kucharza (zakładka „Ugotowane”).
            // Jeśli konto kucharza nie ma profilu, wracamy na profil bieżącego użytkownika.
            $username = $wlascicielWykonania->profile?->username
                ?? $request->user()->profile?->username;

            if ($username !== null) {
                return redirect()
                    ->route('profile.show', ['username' => $username, 'zakladka' => 'ugotowane'])
                    ->with('status', 'Wykonanie usunięte.');
            }

            return redirect()->route('home')->with('status', 'Wykonanie usunięte.');
        }

        return redirect()->route('recipes.show', $recipe->slug)->with('status', 'Wykonanie usunięte.');
    }
}
