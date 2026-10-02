<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\KomentujWykonanie;
use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Notifications\Actions\OtworzKomusWyszlo;
use App\Domain\Recipes\Actions\PoprawPorcjeWykonania;
use App\Domain\Recipes\Actions\PoprawWykonanie;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\Actions\UsunWykonanie;
use App\Domain\Recipes\Actions\ZapiszWykonanieZFormularza;
use App\Domain\Recipes\Actions\ZbierzZdjeciaWykonania;
use App\Domain\Recipes\Gotowanie\JakWyszlo;
use App\Domain\Recipes\Gotowanie\PolaKorekty;
use App\Domain\Recipes\Gotowanie\PorcjeWykonania;
use App\Domain\Recipes\Gotowanie\RoboczyDopisek;
use App\Domain\Recipes\Gotowanie\WersjaWykonania;
use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Domain\Recipes\KonfliktPoprawkiWykonania;
use App\Exceptions\BladDlaCzlowieka;
use App\Exceptions\BladZdjecFormularza;
use App\Http\Requests\Cooked\KomentarzWykonaniaRequest;
use App\Http\Requests\Cooked\PodziekowanieRequest;
use App\Http\Requests\Cooked\PoprawkaWykonaniaRequest;
use App\Http\Requests\Cooked\ZapisWykonaniaRequest;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Support\Komunikat;
use App\Support\OdpowiedziWatku;
use App\Support\StaryAdresPrzepisu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        private readonly PoprawPorcjeWykonania $poprawPorcje,
        private readonly PoprawWykonanie $popraw,
    ) {}

    public function create(Request $request, string $recipe): View|RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->first();
        if ($model === null) {
            return StaryAdresPrzepisu::przekieruj($request, $recipe, 'cooked.create', 'cook');
        }
        $this->authorize('cook', $model);

        return view('pages.cooked.create', [
            'dopisek' => $this->dopisekDoFormularza($request, $model),
            'recipe' => $model->load(['author.profile', 'heroMedia']),
            'kluczWyslania' => $this->kluczDlaFormularza(),
            'wersjaPrzepisu' => $this->wersjaDlaFormularza($model),
            // Zdjęcia, które przetrwały błąd innego pola (issue #872). Lista
            // z `old()` to dane od klienta — przechodzi tę samą bramkę co
            // przy zapisie, zanim dotknie zapytania (issue #871).
            'zachowane' => $this->zdjecia->zachowane(old('media_ids', []), $request->user()),
        ]);
    }

    /**
     * Prywatny roboczy dopisek z gotowania (#2587) jako PROPOZYCJA do pola
     * „Coś po swojemu?”. Nic nie wchodzi do pola samo:
     *
     * - `propozycja`: jest dopisek i formularz jest świeży — widok pokazuje
     *   podgląd i odnośnik „Wstaw do pola” (`?dopisek=wstaw`);
     * - `wstawiony`: osoba o to poprosiła — pole startuje od tekstu dopisku,
     *   który nadal można poprawić albo wyczyścić przed wysłaniem;
     * - `zajete`: formularz wrócił z błędem (są zachowane dane osoby) — nie
     *   ruszamy pola, a odnośnik zniknąłby razem z wpisanym tekstem, więc go nie ma.
     *
     * @return array{stan: string, tresc: string|null}
     */
    private function dopisekDoFormularza(Request $request, Recipe $recipe): array
    {
        $osoba = $request->user();
        $dopisek = $osoba === null ? null : app(RoboczyDopisek::class)->aktywny($osoba, $recipe);

        if ($dopisek === null) {
            return ['stan' => 'brak', 'tresc' => null];
        }

        if ($request->session()->hasOldInput()) {
            return ['stan' => 'zajete', 'tresc' => $dopisek->body];
        }

        return [
            'stan' => $request->query('dopisek') === 'wstaw' ? 'wstawiony' : 'propozycja',
            'tresc' => $dopisek->body,
        ];
    }

    /**
     * Wersja przepisu, którą człowiek ma przed oczami przy otwarciu
     * formularza (issue #2378). `old()` pierwsze: po błędzie walidacji
     * formularz ma pamiętać wersję z pierwszego otwarcia, nie z ponowienia.
     */
    private function wersjaDlaFormularza(Recipe $recipe): ?string
    {
        $stara = old('wersja_przepisu');

        if (is_string($stara) && Str::isUuid($stara)) {
            return $stara;
        }

        $id = $recipe->versions()->orderByDesc('version_number')->value('id');

        return $id === null ? null : (string) $id;
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
                wersjaPrzepisuId: $request->wersjaPrzepisu(),
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

        // Gotowanie z trybu gotowania w tej sesji jest domknięte — „Jak
        // wyszło?” już o nie nie zapyta, a raport liczy je jako ugotowane (F1).
        app(JakWyszlo::class)->poUgotowaniu($request->session(), $user, $model);

        // Prywatny roboczy dopisek (#2587) dotyczył tej próby, która właśnie
        // się skończyła — nie może przejść do następnego gotowania tego przepisu.
        // Treść, którą osoba zdecydowała się wysłać, jest już w wykonaniu.
        app(RoboczyDopisek::class)->usun($user, $model);

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

        // Wskazówka (#2352) interesuje tylko dwie osoby: kucharza (odpowiada)
        // i autora przepisu (prosi). Dla reszty nie pytamy bazy wcale.
        $widz = $request->user();
        $wskazowka = $widz !== null && ($widz->getKey() === $cookedEvent->user_id || $widz->getKey() === $cookedEvent->recipe?->author_id)
            ? RecipeHint::query()->where('cooked_event_id', $cookedEvent->getKey())->with(['author', 'recipe'])->first()
            : null;

        return view('pages.cooked.show', [
            'event' => $cookedEvent,
            'wskazowka' => $wskazowka,
            // Tylko dla kucharza (#2378): przypięta wersja albo `null`.
            // Obcy nie dostaje nawet informacji, że wskaźnik istnieje.
            'wersjaWykonania' => $cookedEvent->user_id === $request->user()?->getKey()
                ? WersjaWykonania::dla($cookedEvent, $request->user())
                : null,
            'maPrzypietaWersje' => $cookedEvent->user_id === $request->user()?->getKey()
                && $cookedEvent->recipe_version_id !== null,
            // Odnośnik do „Moich prób tego przepisu” (#2412): tylko kucharz
            // i tylko do przepisu, który może otworzyć (inaczej 403).
            'mojeProbyLink' => $cookedEvent->user_id === $request->user()?->getKey()
                && $cookedEvent->recipe !== null
                && Gate::forUser($request->user())->allows('view', $cookedEvent->recipe),
            'komentarze' => $komentarze,
            // Nagłówek „Komentarze (N)” mówi o CAŁEJ rozmowie razem
            // z odpowiedziami, jak karta wpisu i strona przepisu (D-281,
            // D-309). `total()` liczy same wątki, więc zostaje do paginacji.
            'komentarzyRazem' => Comment::policzRozmowe($cookedEvent->comments(), $request->user()),
        ]);
    }

    /**
     * „Wersja z tego gotowania" (issue #2378) — prywatny powrót kucharza do
     * wersji przepisu, z której gotował. Obcy dostaje 404, nie 403: nie
     * zdradzamy, że wykonanie ma przypiętą wersję. Brak dostępu do samej
     * wersji (ukryta, usunięta przez retencję, przepis prywatny) to zwykła
     * strona z komunikatem — bez tytułu i treści.
     */
    public function wersja(Request $request, CookedEvent $cookedEvent): View
    {
        // 404, nie 403: obcy nie dowiaduje się, że takie wykonanie istnieje.
        abort_unless(Gate::forUser($request->user())->allows('viewVersion', $cookedEvent), 404);

        $cookedEvent->loadMissing('recipe.author');
        $wersja = WersjaWykonania::dla($cookedEvent, $request->user());

        return view('pages.cooked.wersja', [
            'event' => $cookedEvent,
            'recipe' => $wersja === null ? null : $cookedEvent->recipe,
            'wersja' => $wersja,
            'migawka' => $wersja === null ? null : new MigawkaWersji($wersja->snapshot ?? []),
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

    /**
     * Prywatna liczba faktycznych porcji (#2540): ekran poprawy. Obcy dostaje
     * 403 z Policy — adres z UUID nie jest autoryzacją.
     */
    public function edytujPorcje(Request $request, CookedEvent $cookedEvent): View
    {
        $this->authorize('poprawPorcje', $cookedEvent);

        return view('pages.cooked.porcje', ['event' => $cookedEvent->loadMissing('recipe')]);
    }

    public function zapiszPorcje(Request $request, CookedEvent $cookedEvent): RedirectResponse
    {
        $this->authorize('poprawPorcje', $cookedEvent);

        $wpisane = $request->input('faktyczne_porcje');

        if ($wpisane !== null && ! is_string($wpisane)) {
            return back()->withInput()->withErrors(['faktyczne_porcje' => PorcjeWykonania::KOMUNIKAT_NIEZROZUMIALY]);
        }

        try {
            $zapisana = $this->poprawPorcje->handle($cookedEvent, $wpisane);
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['faktyczne_porcje' => $e->getMessage()]);
        }

        return redirect()->route('cooked.show', $cookedEvent)->with(Komunikat::sukces(
            $zapisana === null ? 'Usunęliśmy liczbę porcji z tego wykonania.' : 'Zapisaliśmy liczbę porcji: '.PorcjeWykonania::etykieta($zapisana).'.',
        ));
    }

    /**
     * Formularz korekty własnego wykonania (#2459) — uwaga, opis zmian, czas.
     * Tytuł przepisu dostaje tylko ten, kto go dziś może otworzyć; zablokowane
     * pola (wskazówka, otwarte zgłoszenie) pokazują zapisany tekst i powód.
     */
    public function edit(Request $request, CookedEvent $cookedEvent): View
    {
        $this->authorize('update', $cookedEvent);

        $cookedEvent->loadMissing('recipe.author');
        $przepis = $cookedEvent->recipe !== null && Gate::forUser($request->user())->allows('view', $cookedEvent->recipe)
            ? $cookedEvent->recipe
            : null;

        return view('pages.cooked.edit', [
            'event' => $cookedEvent,
            'przepis' => $przepis,
            'zablokowane' => PolaKorekty::zablokowane($cookedEvent),
            // Zawsze świeży odcisk z bazy (nie z `old()`): po konflikcie
            // następne wysłanie niesie aktualny, a tekst człowieka zostaje w polach.
            'wersja' => $cookedEvent->wersjaPolKorekty(),
        ]);
    }

    public function update(PoprawkaWykonaniaRequest $request, CookedEvent $cookedEvent): RedirectResponse
    {
        $this->authorize('update', $cookedEvent);

        try {
            $wynik = $this->popraw->handle(
                $request->user(),
                $cookedEvent,
                $request->safe()->only(PolaKorekty::POLA),
                $request->wersjaFormularza(),
                $request->ip(),
            );
        } catch (KonfliktPoprawkiWykonania $e) {
            return redirect()->route('cooked.edit', $cookedEvent)->withInput()->withErrors(['wersja' => $e->getMessage()]);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('cooked.show', $cookedEvent)->with(Komunikat::blad($e->getMessage()));
        }

        if ($wynik->pominiete !== []) {
            return redirect()->route('cooked.edit', $cookedEvent)
                ->withInput()
                ->withErrors(['wersja' => ($wynik->zmienione === [] ? '' : 'Resztę poprawki zapisaliśmy. ')
                    .'Nie zapisaliśmy pól, których teraz nie można zmienić. '.implode(' ', array_unique($wynik->pominiete))]);
        }

        return redirect()->route('cooked.show', $cookedEvent)->with(Komunikat::sukces(
            $wynik->zmienione === [] ? 'Bez zmian — wykonanie zostaje takie samo.' : 'Poprawka zapisana.',
        ));
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
