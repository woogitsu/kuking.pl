<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\KomentujWykonanie;
use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Notifications\Actions\OtworzKomusWyszlo;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\Actions\UsunWykonanie;
use App\Domain\Recipes\Actions\ZapiszWykonanieZFormularza;
use App\Domain\Recipes\Actions\ZbierzZdjeciaWykonania;
use App\Exceptions\BladDlaCzlowieka;
use App\Exceptions\BladZdjecFormularza;
use App\Http\Requests\Cooked\KomentarzWykonaniaRequest;
use App\Http\Requests\Cooked\PodziekowanieRequest;
use App\Http\Requests\Cooked\ZapisWykonaniaRequest;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Support\Komunikat;
use App\Support\OdpowiedziWatku;
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
 *
 * Kontroler tylko spina: wejście (walidacja i Policy) siedzi w
 * `App\Http\Requests\Cooked\*`, przypadki użycia w akcjach `App\Domain\*`
 * (issue #970).
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
        private readonly ZapiszWykonanieZFormularza $zapiszWykonanie,
        private readonly ZbierzZdjeciaWykonania $zdjecia,
        private readonly PublishComment $publishComment,
        private readonly KomentujWykonanie $komentuj,
        private readonly OtworzKomusWyszlo $otworzKomusWyszlo,
        private readonly UsunWykonanie $usunWykonanie,
    ) {}

    public function create(Request $request, string $recipe): View
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('cook', $model);

        return view('pages.cooked.create', [
            'recipe' => $model->load(['author.profile', 'heroMedia']),
            'kluczWyslania' => $this->kluczDlaFormularza(),
            // Zdjęcia, które przetrwały błąd innego pola (issue #872). Lista
            // z `old()` to dane od klienta — przechodzi tę samą bramkę co
            // przy zapisie, zanim dotknie zapytania (issue #871).
            'zachowane' => $this->zdjecia->zachowane(old('media_ids', []), $request->user()),
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

    public function store(ZapisWykonaniaRequest $request, string $recipe): RedirectResponse
    {
        // Przepis i Policy `cook` sprawdził już `ZapisWykonaniaRequest::authorize()`.
        $model = $request->przepis();
        $user = $request->user();

        // Zakończone wysłanie rozpoznaj przed ponownym zapisaniem zdjęć.
        // Autoryzacja jest już sprawdzona, a współbieżne żądania nadal
        // rozstrzyga unikalny klucz w RecordCookedEvent (#873).
        $zapisane = $this->record->wykonanieZTegoWyslania($user, $request->kluczWyslania());

        if ($zapisane !== null) {
            return $this->odpowiedzNaPonowienie($zapisane);
        }

        // ZDJĘCIA NAJPIERW, RESZTA PÓŹNIEJ — TA SAMA ZASADA CO C1
        // W `PostController::store` (issue #872).
        //
        // Wcześniej jedna walidacja obejmowała zdjęcia i notatki, a pliki
        // trafiały na dysk dopiero po niej. „1h 30" w polu minut odsyłało
        // formularz z tekstem, ale BEZ zdjęcia: `withInput()` nie przenosi
        // plików. Zdjęcie jest opcjonalne, więc człowiek poprawiał czas,
        // klikał „Wyślij" i autor przepisu dostawał wykonanie bez zdjęcia.
        // AGENTS.md §5: poprawne dane nigdy nie znikają.
        //
        // Teraz poprawne zdjęcia zapisują się przed walidacją pozostałych
        // pól i wracają jako identyfikatory w ukrytych polach. Zdjęcia
        // porzucone po zamknięciu karty sprząta
        // `kuking:sprzataj-osierocone-zdjecia` po dobie karencji.
        $request->walidujZdjecia();

        try {
            $mediaIds = $this->zdjecia->handle($request->input('media_ids', []), $request->file('photos', []), $user);
        } catch (BladZdjecFormularza $e) {
            // Zachowane i już zapisane zdjęcia wracają w ukrytych polach —
            // gołe `withInput()` odsyłało stare `media_ids` bez nowych
            // zdjęć (#2241).
            return back()->withInput($request->wejscieBezPlikow($e->mediaIds))->withErrors(['photos' => $e->getMessage()]);
        }

        // „Usuń to zdjęcie" przy zachowanym zdjęciu — świadoma decyzja, nie
        // wysłanie wykonania. Nowo wybrane w tym samym kliknięciu pliki już
        // leżą na dysku i zostają na liście, żeby nie przepadły.
        if ($request->filled('usun_zdjecie')) {
            $usun = $request->input('usun_zdjecie');
            $mediaIds = array_values(array_filter($mediaIds, fn (string $id): bool => $id !== $usun));

            return redirect()->route('cooked.create', $model->slug)
                ->withInput($request->wejscieBezPlikow($mediaIds));
        }

        // Walidator, nie `$request->validate()`: ten drugi sam odsyła stare
        // dane i nadpisałby nimi `media_ids`, które właśnie chcemy przekazać
        // — z nowo zapisanymi zdjęciami włącznie.
        $walidator = $request->walidatorPol();

        if ($walidator->fails()) {
            return back()->withErrors($walidator)->withInput($request->wejscieBezPlikow($mediaIds));
        }

        try {
            $event = $this->zapiszWykonanie->handle(
                cook: $user,
                recipe: $model,
                dane: $walidator->validated(),
                mediaIds: $mediaIds,
                ip: $request->ip(),
                kluczWyslania: $request->kluczWyslania(),
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput($request->wejscieBezPlikow($mediaIds))->withErrors(['note' => $e->getMessage()]);
        }

        // DRUGIE KLIKNIĘCIE „WYŚLIJ" — wykonanie jest to samo, co przy
        // pierwszym. Komunikat potwierdza zapis i drogę do kolejnego
        // gotowania (D-005). Nie obiecuje powiadomienia: własne wykonanie
        // i autor, który nie może czytać, mają świadome wyjątki (AGENTS §1).
        if (! $event->wasRecentlyCreated) {
            return $this->odpowiedzNaPonowienie($event);
        }

        return redirect()->route('cooked.show', $event)->with(Komunikat::sukces('Wykonanie zapisane.',
        ));
    }

    private function odpowiedzNaPonowienie(CookedEvent $event): RedirectResponse
    {
        return redirect()->route('cooked.show', $event)->with(Komunikat::informacja('To wykonanie już zapisaliśmy. '
            .'Gotujesz ten przepis drugi raz? Otwórz „Ugotowałem” jeszcze raz — każde wykonanie zapisujemy osobno.',
        ));
    }

    public function show(Request $request, CookedEvent $cookedEvent): View
    {
        $this->authorize('view', $cookedEvent);

        $cookedEvent->load([
            'user.profile.avatar',
            'recipe.author.profile',
            'media',
        ]);

        // Komentarze osobnym, PAGINOWANYM zapytaniem (issue #938), jak pod
        // wpisem i przepisem — ten sam limit i ta sama nazwa strony
        // `komentarze`, bo `CelPowiadomienia::adresy()` liczy numer
        // strony jednym wzorem dla wszystkich trzech treści. `->load()`
        // wciągał całą historię rozmowy naraz. Blokady (issue #41) na
        // wątkach i odpowiedziach; odpowiedzi jednego wątku porcjami
        // (issue #939, `OdpowiedziWatku`).
        $komentarze = $cookedEvent->comments()
            ->widoczneDla($request->user())
            ->with([
                'author.profile.avatar',
                // Odpowiedzi też porcjami (issue #939) — `OdpowiedziWatku`.
                'replies' => fn ($query) => OdpowiedziWatku::pierwszaPorcja($query, $request->user()),
                'replies.author.profile.avatar',
                // `Comment::subject()` przy każdym komentarzu — jak `recipe`
                // w `RecipeController`.
                'cookedEvent.recipe',
                'replies.cookedEvent.recipe',
            ])
            ->paginate((int) config('kuking.comments.page_size'), ['*'], 'komentarze');
        OdpowiedziWatku::uzupelnij($komentarze, $request, ['author.profile.avatar', 'cookedEvent.recipe']);

        return view('pages.cooked.show', [
            'event' => $cookedEvent,
            'komentarze' => $komentarze,
            // Nagłówek „Komentarze (N)” mówi o CAŁEJ rozmowie razem
            // z odpowiedziami, jak karta wpisu i strona przepisu (D-281,
            // D-309). `total()` liczy same wątki, więc zostaje do paginacji.
            'komentarzyRazem' => Comment::policzRozmowe($cookedEvent->comments(), $request->user()),
        ]);
    }

    /**
     * Ekran „Komuś wyszło" (issue #17) — najcenniejszy moment w produkcie,
     * pokazany PEŁNOEKRANOWO, nie jako zwykły wiersz na liście powiadomień.
     * Pokazuje się raz na wykonanie; regułę „raz" i oznaczenie powiadomienia
     * jako przeczytanego niesie `OtworzKomusWyszlo`.
     */
    public function celebrate(Request $request, CookedEvent $cookedEvent): View|RedirectResponse
    {
        $this->authorize('celebrate', $cookedEvent);

        $pokaz = $this->otworzKomusWyszlo->handle(
            $cookedEvent,
            $request->user(),
            $request->session()->get(Notification::SESJA_PIERWSZE_OTWARCIE),
        );

        if (! $pokaz) {
            return redirect()->route('cooked.show', $cookedEvent);
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
    public function thank(PodziekowanieRequest $request, CookedEvent $cookedEvent): RedirectResponse
    {
        // Policy i walidacja: `PodziekowanieRequest` (403 przed błędami pól).
        // Jawne `authorize()` zostaje tu celowo: jest idempotentne, a bramkę
        // na trasie z wiązaniem modelu widać w kontrolerze
        // (`AutoryzacjaTrasZWiazaniemModeluTest`) — jak w `PostController::comment()`.
        $this->authorize('celebrate', $cookedEvent);

        $data = $request->validated();

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $cookedEvent,
                body: $data['body'],
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('cooked.show', $cookedEvent)->with(Komunikat::sukces($cookedEvent->user->displayName().' dostanie Twoje podziękowanie.',
        ));
    }

    public function comment(KomentarzWykonaniaRequest $request, CookedEvent $cookedEvent): RedirectResponse
    {
        // Policy i walidacja: `KomentarzWykonaniaRequest` (403 przed błędami
        // pól). Jawne `authorize()` zostaje tu celowo — patrz `thank()`.
        // `comment`, nie `view`: zbanowany kucharz chowa wykonanie, ale nie
        // zamyka komentowania (decyzja właściciela do D-261).
        $this->authorize('comment', $cookedEvent);

        $data = $request->validated();

        try {
            // `?? null`, bo `validated()` NIE zwraca klucza, którego w żądaniu
            // nie było — a `parent_id` jest `nullable`. Komentarz wysłany bez
            // tego pola kończył się błędem „Undefined array key", czyli 500
            // zamiast komentarza.
            $this->komentuj->handle($request->user(), $cookedEvent, $data['body'], $data['parent_id'] ?? null);
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with(Komunikat::sukces('Komentarz dodany.'));
    }

    public function destroy(Request $request, CookedEvent $cookedEvent): RedirectResponse
    {
        $this->authorize('delete', $cookedEvent);

        $wlascicielWykonania = $cookedEvent->user;
        $recipe = $this->usunWykonanie->handle($cookedEvent, $request->user());

        if ($recipe === null) {
            // Bezpieczny powrót na profil kucharza (zakładka „Ugotowane”).
            // Jeśli konto kucharza nie ma profilu, wracamy na profil bieżącego użytkownika.
            $username = $wlascicielWykonania->profile->username
                ?? $request->user()->profile?->username;

            if ($username !== null) {
                return redirect()
                    ->route('profile.show', ['username' => $username, 'zakladka' => 'ugotowane'])
                    ->with(Komunikat::sukces('Wykonanie usunięte.'));
            }

            return redirect()->route('home')->with(Komunikat::sukces('Wykonanie usunięte.'));
        }

        return redirect()->route('recipes.show', $recipe->slug)->with(Komunikat::sukces('Wykonanie usunięte.'));
    }
}
