<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\ZapisyWpisu;
use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\ZachowaneZdjecia;
use App\Domain\Posts\Actions\EditPost;
use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Posts\KonfliktEdycjiWpisu;
use App\Domain\Posts\SasiedniWpisAutora;
use App\Domain\Tags\TagSuggester;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Policies\RecipePolicy;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityTagow;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

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
        private readonly EditPost $editPost,
        private readonly StoreUploadedImage $storeImage,
        private readonly PublishComment $publishComment,
        private readonly TagSuggester $tagSuggester,
        private readonly SasiedniWpisAutora $sasiedniWpis,
        private readonly ZapisyWpisu $zapisy = new ZapisyWpisu,
    ) {}

    public function create(Request $request): View
    {
        $question = $request->routeIs('questions.create');
        abort_if($question && ! config('kuking.questions.enabled'), 404);
        $tagNames = (array) old('tag_names', []);
        // Puste stare wejście też jest decyzją: po usunięciu ostatniego
        // tagu lub błędzie walidacji nie przywracamy wyboru z adresu.
        if (! $request->session()->hasOldInput()) {
            $slug = $request->query('tag');
            if (is_string($slug) && $slug !== '' && mb_strlen($slug) <= 200) {
                $tag = Tag::query()->where('slug', $slug)->first()?->tagKanoniczny();
                if ($tag?->isActive()) {
                    $tagNames = [$tag->name];
                }
            }
        }

        return view($question ? 'pages.questions.create' : 'pages.posts.create', [
            'tagNames' => $tagNames,
            'sugestieTagow' => $this->sugestieDlaZapytania(),
            'kluczWyslania' => $this->kluczDlaFormularza(),
        ]);
    }

    /**
     * Klucz wysłania dla świeżo renderowanego formularza.
     *
     * `old()` PIERWSZE, i to jest tu najważniejsza linijka: po nieudanej
     * walidacji formularz wystawiamy od nowa, a klucz MUSI zostać ten sam.
     * Gdyby powstawał nowy, ochrona znikałaby po pierwszym błędzie walidacji
     * — czyli dokładnie wtedy, gdy człowiek klika drugi raz. Klucza nie zużywa
     * żadne wysłanie, które wpisu nie utworzyło (błąd walidacji, „Dodaj tag").
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
     * Klucz wysłania z żądania.
     *
     * Wartość niebędąca UUID-em schodzi do `null`, czyli do „wyślij
     * normalnie" — a nie do błędu walidacji. To jest zawodzenie OTWARTE
     * (ADR §4.3): wpis utracony boli w tej grupie odbiorców bardziej niż
     * wpis zduplikowany, a formularz z popsutym ukrytym polem to nie jest
     * coś, co człowiek umie naprawić.
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

    public function store(Request $request): RedirectResponse
    {
        $question = $request->routeIs('questions.store');
        abort_if($question && ! config('kuking.questions.enabled'), 404);
        $user = $request->user();

        // ZDJECIA WGRYWAMY PRZED WALIDACJA RESZTY — I TO JEST CALY SENS C1.
        //
        // Wczesniej walidacja szla najpierw, a przy bledzie leciało
        // `back()->withInput()`. `withInput()` NIE PRZENOSI PLIKOW: przegladarka
        // nie pozwala wypelnic `<input type="file">` z serwera i dobrze robi,
        // bo inaczej strona mogla by podkrasc plik z dysku.
        //
        // Skutek byl taki: Basia wybierala trzy zdjecia, pisala dlugi tekst,
        // przekraczala 4000 znakow — i traciła WYBOR Z GALERII TELEFONU.
        // Tekst zostawal, zdjecia znikaly, bez slowa wyjasnienia. AGENTS.md
        // par. 5 mowi „poprawne dane nigdy nie znikaja", a zdjecie jest w tym
        // produkcie najwazniejsza wpisana dana.
        //
        // Teraz zdjecia trafiaja na dysk od razu, a przez blad walidacji
        // przechodza jako identyfikatory w ukrytych polach formularza.
        // Cena: zdjecia nieprzypiete do niczego, gdy ktos zamknie karte
        // zamiast poprawic blad — sprzata je `kuking:sprzataj-osierocone-zdjecia`
        // po dobie karencji.
        $bladRozmiaruZdjecia = 'Jedno ze zdjęć waży za dużo. Wybierz ponownie wszystkie nowe zdjęcia — każde do '
            .LimityZdjec::maksMegabajtowDoKomunikatu().' MB.';

        $request->validate([
            'photos' => ['nullable', 'array', 'max:'.($question ? 1 : LimityZdjec::maksZdjecNaWysylke())],
            'photos.*' => ['file', new ObslugiwaneZdjecie(komunikatZaDuzyPlik: $bladRozmiaruZdjecia), 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'media_ids' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'media_ids.*' => ['uuid'],
        ], [
            'photos.*.image' => 'Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP.',
            'photos.*.max' => $bladRozmiaruZdjecia,
            'photos.max' => $question ? 'Do pytania wybierz jedno zdjęcie.' : LimityZdjec::komunikatZaDuzoZdjec(),
        ]);

        if ($question && $request->filled('usun_zdjecie')) {
            $mediaIds = array_values(array_filter(
                ZachowaneZdjecia::identyfikatory($request->input('media_ids', []), $user->getKey()),
                fn (string $id): bool => $id !== $request->input('usun_zdjecie'),
            ));

            return redirect()->route('questions.create')
                ->withInput($this->wejscieBezPlikowITagow($request, $mediaIds, $this->tagiZFormularza($request)));
        }

        try {
            $mediaIds = $this->zebranZdjecia($request, $user);
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['photos' => $e->getMessage()]);
        }

        $tagNames = $this->tagiZFormularza($request);

        // TAGI: „Szukaj tagów" / „Dodaj" / „Usuń" — TRZY OSOBNE SUBMITY
        // w TYM SAMYM formularzu co „Opublikuj" (SPEC §1.6, R1 §6.2).
        //
        // Żaden z nich nie publikuje wpisu — rozpoznajemy to PRZED walidacją
        // treści/widoczności, bo na tym etapie mogą być jeszcze puste albo
        // niedokończone (ktoś dodaje tagi, zanim napisze tekst). Bez tego
        // rozróżnienia kliknięcie „Dodaj" próbowałoby opublikować wpis.
        if ($this->toAkcjaTagow($request)) {
            [$tagNames, $bladTagow] = $this->zastosujAkcjeTagow($request, $tagNames);

            // Fragment `#tagi` w adresie, żeby przeglądarka wróciła w miejsce,
            // gdzie ta osoba faktycznie pracuje, a nie na górę formularza
            // z tekstem i zdjęciami nad sekcją tagów (R1 §6.1).
            $powrot = redirect(url()->previous().($question ? '#f-tagi' : '#tagi'))
                ->withInput($this->wejscieBezPlikowITagow($request, $mediaIds, $tagNames));

            return $bladTagow === null ? $powrot : $powrot->withErrors(['tagi' => $bladTagow]);
        }

        // Walidacja przez `Validator::make`, a NIE `$request->validate()`.
        //
        // `$request->validate()` rzuca `ValidationException`, ktora sama
        // odsyla z powrotem i sama zapisuje stare dane — nadpisujac przy tym
        // `media_ids`, ktore wlasnie chcemy tam wlozyc. Kontrola nad tym,
        // co trafia do starego wejscia, musi zostac tutaj.
        $walidator = Validator::make($request->all(), [
            'body' => ['nullable', 'string', 'max:4000'],
            'visibility' => $question ? ['exclude'] : ['required', 'in:public,followers,private'],
            'title' => $question ? ['required', 'string', 'min:10', 'max:180'] : ['exclude'],
        ], [
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
            'title.required' => 'Napisz pytanie w tytule.',
            'title.min' => 'Rozwiń pytanie do co najmniej 10 znaków.',
            'title.max' => 'Skróć tytuł pytania do 180 znaków.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten wpis.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — trzy opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.',
        ]);

        if ($walidator->fails()) {
            // Zdjecia SA JUZ WGRANE, a TAGI JUZ WYBRANE — wracaja do formularza
            // jako ukryte pola, zeby poprawnie wpisane dane nigdy nie zniknely
            // (AGENTS.md §5), dokladnie tak jak zdjecia od audytu C1.
            return back()
                ->withInput($this->wejscieBezPlikowITagow($request, $mediaIds, $tagNames))
                ->withErrors($walidator);
        }

        $data = $walidator->validated();

        try {
            $post = $this->publishPost->handle(
                author: $user,
                body: $data['body'] ?? null,
                mediaIds: $mediaIds,
                visibility: $question ? Post::VISIBILITY_PUBLIC : $data['visibility'],
                tagNames: $tagNames,
                ip: $request->ip(),
                // Wygląd zdjęć ustawia się DOPIERO PO publikacji, na osobnym
                // ekranie — patrz komentarz przy przekierowaniu niżej. Wpis
                // powstaje więc zawsze jako „zwykle".
                displayMode: Post::DISPLAY_NORMAL,
                kluczWyslania: $this->kluczZZadania($request),
                questionTitle: $question ? $data['title'] : null,
            );
        } catch (BladDlaCzlowieka $e) {
            // Formularz zachowuje wpisany tekst — poprawne dane nigdy nie giną
            // (docs/UX_50_PLUS.md). Dwa różne powody mogą tu wylądować
            // (wpis całkiem pusty ALBO za dużo tagów po rozwiązaniu nazw
            // na aliasy) — komunikat trafia pod pole, którego naprawdę
            // dotyczy, żeby „Poprawne dane nigdy nie znikają" nie zgubiło
            // się w złym miejscu ekranu.
            $pole = $e->getMessage() === LimityTagow::komunikatZaDuzoTagow()
                || ($question && str_contains($e->getMessage(), '3 tagi')) ? 'tagi' : 'photos';

            return back()
                ->withInput($this->wejscieBezPlikowITagow($request, $mediaIds, $tagNames))
                ->withErrors([$pole => $e->getMessage()]);
        }

        // DRUGIE KLIKNIĘCIE „OPUBLIKUJ" — wpis jest ten sam, co przy pierwszym.
        //
        // `wasRecentlyCreated` jest `false`, gdy akcja domenowa oddała wpis
        // ODCZYTANY z bazy, czyli gdy to wysłanie już raz się zapisało.
        // Odsyłamy tam, gdzie odesłałoby pierwsze kliknięcie — bez błędu, bo
        // drugie kliknięcie nie jest pomyłką człowieka. Komunikat mówi wprost,
        // że nic się nie zepsuło, i pokazuje drogę do wpisu OSOBNEGO, gdyby
        // ktoś naprawdę chciał dodać drugi.
        if ($question) {
            return redirect()->route('questions.show', $post)->with('status',
                $post->wasRecentlyCreated ? 'Pytanie opublikowane.' : 'To pytanie jest już opublikowane. Drugie kliknięcie nie dodało go ponownie.');
        }
        if (! $post->wasRecentlyCreated) {
            return redirect()->route('posts.show', $post)->with(
                'status',
                'Ten wpis jest już opublikowany. Kliknięcie drugi raz nic nie zepsuło — wpis jest jeden. '
                .'Chcesz dodać osobny wpis? Otwórz „Dodaj zdjęcie” jeszcze raz — wtedy powstanie nowy.',
            );
        }

        $isFirstPost = $user->posts()->published()->count() === 1;

        // Zdjęcie przetwarza się w kolejce (StoreUploadedImage) — w chwili
        // tego przekierowania prawie na pewno jeszcze nie jest `ready`.
        // Autor MUSI się o tym dowiedzieć TERAZ, na najbardziej widocznym
        // komunikacie na stronie, a nie dopiero z placeholdera przy zdjęciu
        // niżej (audyt A2) — inaczej pusta ramka wygląda jak porażka
        // publikacji, nie jak „chwilę potrwa".
        $maZdjecie = $mediaIds !== [];

        // KROK POŚREDNI PRZY KILKU ZDJĘCIACH — DECYZJA WŁAŚCICIELA.
        //
        // Wybór „zwykle / karuzela / kolaż" stał wcześniej w formularzu
        // publikacji, ukryty, i odsłaniał go skrypt po wybraniu drugiego
        // pliku. Działało to wyłącznie u osób, którym skrypt się dociągnął:
        // zanim ktoś kliknie „Opublikuj", zdjęcia SĄ JESZCZE W PRZEGLĄDARCE,
        // więc serwer nie zna ich liczby i bez JavaScriptu nie ma jak pokazać
        // tego wyboru w odpowiednim momencie.
        //
        // Teraz pytamy po publikacji, na ekranie „Zdjęcia w tym wpisie" —
        // ta droga działa u wszystkich tak samo, bez linijki skryptu.
        //
        // TYLKO OD DWÓCH ZDJĘĆ. Przy jednym karuzela, kolaż i „zwykle" dają
        // dokładnie ten sam widok, a „przenieś w górę" nie ma dokąd
        // przenosić — pytanie bez treści jest gorsze niż brak pytania,
        // zwłaszcza na drodze do opublikowania zdjęcia.
        if (count($mediaIds) >= 2) {
            return redirect()->route('posts.media.edit', $post)
                ->with('poPublikacji', true)
                ->with('status', $isFirstPost
                    ? 'Opublikowane. To Twój pierwszy wpis w Kuking — od teraz masz swoje archiwum.'
                    : 'Opublikowane.');
        }

        return redirect()->route('posts.show', $post)->with(
            'status',
            match (true) {
                $isFirstPost && $maZdjecie => 'Gotowe. To Twój pierwszy wpis w Kuking — od teraz masz swoje archiwum. Zdjęcie za chwilę będzie widoczne, nic nie musisz robić.',
                $isFirstPost => 'Gotowe. To Twój pierwszy wpis w Kuking — od teraz masz swoje archiwum.',
                $maZdjecie => 'Opublikowane. Zdjęcie za chwilę będzie widoczne — nic nie zginęło.',
                default => 'Opublikowane. Dziękujemy.',
            },
        );
    }

    /**
     * Stare wejscie formularza: wszystko poza plikami, plus identyfikatory
     * zdjec, ktore juz sa na dysku.
     *
     * Pliki lecą do kosza świadomie — `withInput()` i tak ich nie przeniesie,
     * a `UploadedFile` w sesji to obiekt wskazujący na plik tymczasowy,
     * którego po żądaniu już nie ma.
     *
     * @param  list<string>  $mediaIds
     * @return array<string, mixed>
     */
    private function wejscieBezPlikow(Request $request, array $mediaIds): array
    {
        return $request->except('photos', 'media_ids') + ['media_ids' => $mediaIds];
    }

    /**
     * To samo co `wejscieBezPlikow()`, plus zachowana lista tagów — dwa
     * niezależne mechanizmy ratowania danych, bo dwa niezależne rodzaje
     * danych o innym kształcie (identyfikatory zdjęć kontra wolny tekst).
     *
     * @param  list<string>  $mediaIds
     * @param  list<string>  $tagNames
     * @return array<string, mixed>
     */
    private function wejscieBezPlikowITagow(Request $request, array $mediaIds, array $tagNames): array
    {
        // UWAGA NA `+`: operator sumy tablic zachowuje wartość z LEWEJ
        // strony przy zbieżnych kluczach. `wejscieBezPlikow()` zwraca
        // `tag_names` wprost z żądania (STARĄ listę, sprzed „Dodaj"/„Usuń"),
        // więc doklejenie `+ ['tag_names' => $tagNames]` po prawej NIC by
        // nie zmieniło — nowa lista przegrywałaby ze starą. `except()`
        // usuwa klucz PRZED złożeniem, więc kolizji już nie ma.
        return $request->except('photos', 'media_ids', 'tag_names')
            + ['media_ids' => $mediaIds, 'tag_names' => $tagNames];
    }

    /**
     * Tagi wpisane do tej pory — z ukrytych pól `tag_names[]`, w kolejności
     * dodania. To jest WOLNY TEKST od klienta, nie identyfikatory: prawdziwa
     * walidacja i tworzenie tagów dzieje się dopiero w
     * `App\Domain\Tags\Actions\ResolveTagsForPost`, wołanej przez akcję
     * domenową w chwili publikacji/zapisu — to pole tylko PRZENOSI stan
     * formularza między requestami.
     *
     * @return list<string>
     */
    private function tagiZFormularza(Request $request): array
    {
        return array_values(array_filter(
            (array) $request->input('tag_names', []),
            static fn ($nazwa): bool => is_string($nazwa) && trim($nazwa) !== '',
        ));
    }

    /**
     * Czy to żądanie to krok POŚREDNI („Szukaj tagów"/„Dodaj"/„Usuń"), a nie
     * próba publikacji/zapisu (R1 §6.2 — SPEC nie precyzuje tego rozróżnienia
     * wprost, ale formularz bez JS potrzebuje go, żeby kliknięcie „Dodaj"
     * nie próbowało jednocześnie opublikować niedokończonego wpisu).
     */
    private function toAkcjaTagow(Request $request): bool
    {
        return $request->has('szukaj_tagu') || $request->filled('dodaj_tag') || $request->filled('usun_tag');
    }

    /**
     * Wykonuje DOKŁADNIE JEDNĄ z trzech akcji tagowych na liście roboczej.
     *
     * „Szukaj tagów" sam w sobie NIE zmienia listy — tylko czyta
     * `tag_query` przy następnym renderze (patrz `sugestieDlaZapytania()`).
     * „Dodaj" i „Usuń" są tu, a nie w `ResolveTagsForPost`, bo dotyczą listy
     * ROBOCZEJ (wolny tekst w sesji formularza), nie prawdziwych wierszy
     * `Tag` — te powstają dopiero przy właściwej publikacji/zapisie.
     *
     * @param  list<string>  $tagNames
     * @return array{0: list<string>, 1: string|null} nowa lista i komunikat błędu (albo null)
     */
    private function zastosujAkcjeTagow(Request $request, array $tagNames): array
    {
        if ($request->filled('usun_tag')) {
            $doUsuniecia = Tag::znormalizujNazwe((string) $request->input('usun_tag'));

            $tagNames = array_values(array_filter(
                $tagNames,
                static fn (string $nazwa): bool => Tag::znormalizujNazwe($nazwa) !== $doUsuniecia,
            ));

            return [$tagNames, null];
        }

        if ($request->filled('dodaj_tag')) {
            $nowa = trim((string) $request->input('dodaj_tag'));
            $znormalizowana = Tag::znormalizujNazwe($nowa);

            if (! LimityTagow::dlugoscOk($znormalizowana) || ! LimityTagow::pasujeDoWzorca($znormalizowana)) {
                return [$tagNames, LimityTagow::komunikatNiepoprawnaNazwa()];
            }

            $jestJuzDodany = collect($tagNames)
                ->contains(fn (string $istniejacy): bool => Tag::znormalizujNazwe($istniejacy) === $znormalizowana);

            if ($jestJuzDodany) {
                return [$tagNames, LimityTagow::komunikatTagJuzDodany()];
            }

            $routePost = $request->route('post');
            $question = $request->routeIs('questions.store')
                || ($request->routeIs('posts.update') && $routePost instanceof Post && $routePost->kind === Post::KIND_QUESTION);
            if (count($tagNames) >= ($question ? 3 : LimityTagow::maksTagowNaWpis())) {
                return [$tagNames, $question ? 'Do pytania dodaj najwyżej 3 tagi.' : LimityTagow::komunikatZaDuzoTagow()];
            }

            $tagNames[] = $nowa;

            return [$tagNames, null];
        }

        // Zostaje tylko „Szukaj tagów" — lista niezmieniona.
        return [$tagNames, null];
    }

    /**
     * Podpowiedzi do pokazania pod polem „Znajdź tag" — z `tag_query`
     * wpisanego przez tę osobę (`old()`, żeby przetrwało kolejne kliknięcia
     * „Dodaj"/„Usuń" bez ponownego wpisywania frazy).
     */
    private function sugestieDlaZapytania(): Collection
    {
        $fraza = (string) old('tag_query', '');

        return $fraza === '' ? collect() : $this->tagSuggester->sugeruj($fraza);
    }

    /**
     * Zdjecia do tego wpisu: nowo wgrane plus te, ktore przetrwaly nieudana
     * walidacje w ukrytych polach formularza.
     *
     * BRAMKA WLASNOSCI JEST TU JEDYNA I MUSI BYC SZCZELNA.
     * `media_ids` przychodzi od klienta, wiec bez sprawdzenia mozna by
     * podpiac pod wlasny wpis CUDZE zdjecie — wystarczylby identyfikator
     * z adresu obrazka. Dlatego pytamy o wlasciciela ORAZ o to, czy zdjecie
     * nie jest juz gdzies przypiete. UUID w formularzu to nie autoryzacja,
     * dokladnie tak samo jak UUID w adresie (AGENTS.md par. 7).
     *
     * @return list<string>
     */
    private function zebranZdjecia(Request $request, User $user): array
    {
        // Kolejnosc z `media_ids[]`, nie z planu bazy (issue #934).
        $odzyskane = ZachowaneZdjecia::identyfikatory($request->input('media_ids', []), $user->getKey());

        $nowe = [];

        foreach ($request->file('photos', []) as $photo) {
            $nowe[] = $this->storeImage->handle($user, $photo)->getKey();
        }

        $wszystkie = array_values(array_unique([...$odzyskane, ...$nowe]));

        // Limit liczony na SUMIE, nie osobno na kazdej z dwoch drog. Inaczej
        // dalo by sie go obejsc, wysylajac polowe zdjec w plikach, a polowe
        // w ukrytych polach.
        if (count($wszystkie) > LimityZdjec::maksZdjecNaWysylke()) {
            throw new BladDlaCzlowieka(LimityZdjec::komunikatZaDuzoZdjec());
        }

        return $wszystkie;
    }

    public function show(Request $request, Post $post): View|RedirectResponse
    {
        if ($request->routeIs('questions.show')) {
            abort_unless(config('kuking.questions.enabled') && $post->kind === Post::KIND_QUESTION, 404);
        }
        $this->authorize('view', $post);

        // PYTANIE MA JEDEN ADRES: `/pytania/{id}` (#968). Pod `/wpisy/{id}`
        // też się renderowało, więc wyszukiwarka dostawała dwie
        // samokanoniczne kopie tej samej rozmowy. Przekierowanie stoi PO
        // `authorize()` — pytanie prywatne albo ukryte za flagą dostaje tu
        // to samo 403 co dotąd, zamiast zdradzać swój adres.
        //
        // `reflash()`, bo część akcji (edycja, komentarz) odsyła na
        // `posts.show` z komunikatem — bez tego człowiek traciłby „Zapisane"
        // albo błąd formularza na drugim skoku.
        if ($post->kind === Post::KIND_QUESTION && ! $request->routeIs('questions.show')) {
            $request->session()->reflash();
            $query = $request->getQueryString();

            return redirect()->to($post->url().($query ? '?'.$query : ''), 301);
        }

        $post->load([
            'author.profile.avatar',
            'media',
            'tags:id,slug,name,status',
            /*
             * KOLUMNY, KTÓRYCH WIDOK NAPRAWDĘ UŻYWA — a nie te trzy, które
             * wyglądają na wystarczające (issue #447).
             *
             * Było `recipe:id,title,slug`. Zawężenie do trzech kolumn gubiło
             * dwie, których widok potrzebuje, i żadna z nich nie zgłaszała się
             * błędem:
             *
             *   `hero_media_id` — bez niej relacja `heroMedia` nie ma po czym
             *   trafić w wiersz i zwraca `null`. Karta pyta
             *   `$post->recipe?->heroMedia` i po cichu nie rysuje zdjęcia.
             *   Wpis z przepisu NIE MA własnych zdjęć z założenia (#368), więc
             *   tracił jedyne, jakie miał: strona wpisu „Bigos z cukinii”
             *   miała na produkcji ZERO obrazków, przy zdjęciu widocznym na tej
             *   samej karcie w strumieniu.
             *
             *   `visibility` — bez niej karta bierze widoczność WPISU, a ta
             *   dla wpisu z przepisu jest zawsze `public` (bramką jest przepis,
             *   `Post::scopeZWidocznymPrzepisem()`). Strona pisała więc
             *   autorowi „· publicznie” także pod przepisem, który widzą
             *   wyłącznie jego obserwujący. Przed tym ostrzega komentarz przy
             *   `post-card.blade.php:60` — karta była zabezpieczona, ten
             *   kontroler nie.
             *
             * Reguła na przyszłość: zawężenie kolumn musi obejmować KLUCZE OBCE
             * relacji, które będą dociągane dalej. Brak klucza nie jest błędem
             * — jest cichym `null`.
             *
             * 2026-09-21: ZAWĘŻENIA TU JUŻ NIE MA — I TO NIE JEST NIEDBALSTWO.
             * Poniżej stoi teraz `RecipePolicy::view()`, decydująca, czy ten
             * ekran w ogóle wolno mu pokazać przepis. Polityka czyta `status`,
             * `published_at`, `author_id` i relację `author`, a lista kolumn
             * ich nie miała. Skutek był dokładnie taki, jak każe się
             * spodziewać akapitowi wyżej: `isPublished()` czytało `status`
             * równy `null`, więc polityka odmawiała WSZYSTKIM i pasek
             * „Z przepisu" zniknął także pod przepisem w pełni publicznym.
             * Złapały to kontrole dodatnie w
             * `Tests\Feature\Visibility\StronaWpisuBramkaPrzepisuTest`, nie
             * człowiek na produkcji — i tylko dlatego, że są.
             *
             * Ręcznie utrzymywana lista kolumn POD POLITYKĄ to maszynka do
             * cichych awarii: polityka wolno rośnie o kolejny warunek,
             * a lista o nim nie wie. Przepis to jeden wiersz na jeden ekran,
             * więc oszczędność była i tak niemierzalna.
             */
            'recipe',
            'recipe.heroMedia',
            // `recipe.author` — bo `RecipePolicy::view()` niżej pyta o stan
            // konta autora przepisu (`jestDostepnyJakoAutor()`) i o blokadę
            // między nim a widzem. Bez tego byłoby to lazy load, czyli
            // zapytanie schowane przed każdym, kto liczy zapytania tego
            // ekranu (`StronyTresciBezWachlarzaZapytanTest`).
            'recipe.author',
            // Komentarze NIE SĄ tu ładowane (patrz niżej): rosną z popularnością
            // treści bez górnej granicy, więc idą osobnym, paginowanym
            // zapytaniem. `->load()` wciągał je wszystkie naraz.
        ]);

        /*
         * WPIS, KTÓRY JEST SAMYM WSKAZANIEM PRZEPISU, NIE MA WŁASNEJ STRONY.
         *
         * Zgłoszenie właściciela z 12 września: „klikam na bigos z cukinii,
         * przekierowuje mnie na to okno gdzie jest info Ula bigos napisz
         * komentarz itp a nie ma przepisu ani zdjęcia. Muszę szukać i klikać
         * w bigos z cukinii żeby przejść do przepisu… To nie ma sensu”.
         *
         * Taki wpis zakłada `kuking:dopisz-wpisy-przepisow` (#368) po to, żeby
         * przepis w ogóle wszedł do strumienia. Własnej treści nie ma żadnej,
         * a wszystko, co ta strona potrafiła pokazać — zdjęcie i komentarze —
         * stoi na stronie przepisu, i to lepiej: ze składnikami i krokami.
         *
         * PRZEKIEROWANIE, A NIE 404 I NIE USUNIĘCIE TRASY: adres wpisu mógł już
         * ktoś komuś wysłać (karta ma przycisk „Podziel się”). Ma działać
         * dalej — tylko prowadzić tam, gdzie jest danie.
         *
         * WPIS Z KOMENTARZEM NIE JEST „SAMYM PRZEPISEM” (`jestSamymPrzepisem`)
         * i tu nie wchodzi: ma już coś własnego — rozmowę ludzi — więc
         * zostaje przy swojej stronie. Ta strona pokazuje mu teraz zdjęcie
         * przepisu i jego prawdziwą widoczność (poprawka w `load()` wyżej).
         */
        if ($post->jestSamymPrzepisem() && $post->recipe !== null) {
            return redirect()->route('recipes.show', $post->recipe->slug);
        }

        /*
         * WPIS ZOSTAJE, ODWOŁANIE DO PRZEPISU ZNIKA (czwarte miejsce z
         * przeglądu po #941).
         *
         * Tu trafia wpis, który ma coś WŁASNEGO: treść albo zdjęcia. Taki
         * wpis jest publiczny z własnych powodów i ma się otwierać — to jest
         * czyjeś „co dziś ugotowałem". Ale pasek „Z przepisu", zdjęcie główne
         * przepisu i przycisk „Ugotowałem" wypisywały tytuł i slug przepisu,
         * którego oglądający nie ma prawa zobaczyć; zmierzone dla gościa:
         * HTTP 200, tytuł w treści odnośnika, slug w `/przepisy/…` i
         * `alt="Zdjęcie do przepisu: …"`.
         *
         * Zdejmujemy więc RELACJĘ, a nie poszczególne pola w widoku. Karta
         * (`post-card.blade.php`) pyta o przepis w czterech miejscach —
         * zdjęcie zastępcze, pasek „Z przepisu", przycisk „Ugotowałem”
         * i odznaka widoczności — i piąte dopisze się kiedyś bez tej
         * poprawki. Jedno `setRelation()` zamyka wszystkie naraz, a odznaka
         * widoczności wraca wtedy do widoczności WPISU, czyli do jego
         * prawdziwej, własnej wartości.
         *
         * Zapowiedź przepisu tędy nie przechodzi — odcina ją wcześniej
         * `PostPolicy::view()`, bo po zdjęciu przepisu nie zostałoby z niej
         * nic poza nagłówkiem.
         */
        // `RecipePolicy` wprost, a nie `$request->user()->can()`: widzem bywa
        // GOŚĆ, a `?->can()` na `null` daje `null` — czyli warunek, który
        // odcinałby przepis także wtedy, gdy jest w pełni publiczny.
        // `RecipePolicy::view()` przyjmuje `?User` i to ona jest tu tabelą
        // prawdy, tą samą, co przy wejściu na sam przepis.
        if ($post->recipe !== null && ! app(RecipePolicy::class)->view($request->user(), $post->recipe)) {
            $post->setRelation('recipe', null);
        }

        // Liczba zapisów i stan „mam to w zeszycie" (issue #275, D-081).
        //
        // Tutaj JEDNYM ODDZIELNYM zapytaniem, a nie kolumną w SELECT-cie jak
        // w feedzie: ten ekran dostaje wpis z wiązania trasy, więc nie ma
        // zapytania, do którego dałoby się kolumnę dołożyć. Jeden wpis to
        // jeden ekran, więc to zapytanie jest STAŁE — nie jest to N+1.
        // Reguły są te same, bo `doliczDoWpisu()` woła to samo `dolicz()`,
        // co feed; gdyby ekran wpisu liczył po swojemu, ta sama liczba
        // znaczyłaby dwie różne rzeczy na dwóch ekranach.
        $this->zapisy->doliczDoWpisu($post, $request->user());

        // Jak przy przepisie — te same dwa powody: blokady (issue #41)
        // i paginacja wątków.
        $komentarze = $post->comments()
            ->widoczneDla($request->user())
            ->with([
                'author.profile.avatar',
                'replies' => fn ($query) => $query->widoczneDla($request->user()),
                'replies.author.profile.avatar',
                // Ten sam powód co `recipe`/`replies.recipe` w
                // `RecipeController`: `Comment::subject()` pytany przy każdym
                // komentarzu (`notifiableUserId()`, „Zdejmij z urzędu”).
                'post',
                'replies.post',
            ])
            ->paginate((int) config('kuking.comments.page_size'), ['*'], 'komentarze');

        if ($post->kind === Post::KIND_QUESTION) {
            $answerCount = $post->comments()->widoczneDla($request->user())->whereNull('comments.body_removed_at')->count();
            $post->setAttribute('comments_count', $answerCount);

            return view('pages.questions.show', [
                'komentarze' => $komentarze,
                'komentarzyRazem' => $answerCount,
                'post' => $post,
            ]);
        }

        return view('pages.posts.show', [
            'komentarze' => $komentarze,
            'komentarzyRazem' => $komentarze->total(),
            'post' => $post,
            // Zachęta do kolejnego zdjęcia brzmi inaczej przy pierwszym wpisie
            // (COLD_START.md). Liczymy TYLKO dla autora — dla kogokolwiek
            // innego to dodatkowe zapytanie bez żadnego zastosowania.
            'toPierwszyWpis' => $request->user()?->getKey() === $post->author_id
                && $post->author->posts()->published()->count() === 1,
            // „Kolejne zdjęcie" (issue: nawigacja jak w Garnku) — dwa proste
            // zapytania, oba po indeksie `posts_author_published_idx`.
            // Widoczność liczy `SasiedniWpisAutora`, nie ten kontroler.
            'poprzedniWpis' => $this->sasiedniWpis->poprzedni($post, $request->user()),
            'nastepnyWpis' => $this->sasiedniWpis->nastepny($post, $request->user()),
        ]);
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

        // `?? null`, bo `validate()` NIE zwraca klucza, którego w żądaniu nie
        // było — a `parent_id` jest `nullable`. Komentarz wysłany bez tego
        // pola (czyli każdy spoza naszego formularza, który zawsze wysyła
        // puste) kończył się błędem „Undefined array key", czyli 500 zamiast
        // komentarza.
        $parentId = $data['parent_id'] ?? null;

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $post,
                body: $data['body'],
                // `widoczneDla()` — audyt W7-06. Bez tego można było podać
                // UUID komentarza ukrytego przez blokadę i podpiąć się pod
                // cudzy wątek. Akcja domenowa sprawdza to drugi raz, bo
                // kontrolerów jest kilka.
                parent: $parentId === null
                    ? null
                    : $post->allComments()
                        ->widoczneDla($request->user())
                        ->whereKey($parentId)
                        ->first(),
                // ISSUE #761: `$parentId !== null` mówi akcji domenowej, że
                // formularz WSKAZAŁ konkretnego rodzica. Bez tego rozróżnienia
                // "rodzic nieznaleziony" (`null` powyżej) i "brak parent_id"
                // (też `null`) wyglądają identycznie, a odpowiedź pod
                // zniknięty/ukryty/obcy komentarz publikowała się po cichu
                // jako nowy komentarz główny.
                parentRequested: $parentId !== null,
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('status', 'Komentarz dodany.');
    }

    /**
     * Edycja wpisu: tekst, widoczność, tagi — nie zdjęcia (issue: menu „…"
     * pokazywało autorowi tylko „Otwórz wpis", mimo że `docs/FEATURES.md`
     * i `docs/ROADMAP.md` wymieniają edycję jako część MVP).
     *
     * Zdjęcia mają już swój ekran, patrz komentarz przy `EditPost`.
     */
    public function edit(Request $request, Post $post): View
    {
        $this->authorize('update', $post);
        abort_if($post->kind === Post::KIND_QUESTION && ! config('kuking.questions.enabled'), 404);

        return view('pages.posts.edit', [
            'post' => $post,
            // Lista robocza tagów: to, co ktoś zdążył zmienić w tym
            // formularzu (`old()`), a jeśli to pierwsze wejście na ekran —
            // tagi, które wpis ma już dziś.
            'tagNames' => old('_tag_form_post_id') === (string) $post->getKey()
                ? (array) old('tag_names', [])
                : $post->tags->filter(fn (Tag $tag): bool => $tag->pivot->dodany_recznie === true)->pluck('name')->all(),
            'sugestieTagow' => $this->sugestieDlaZapytania(),
            // Issue #981: wersja, na której ten formularz został otwarty.
            // Przy powrocie po błędzie albo akcji tagów zostaje ta z
            // formularza (`old()`), żeby zmiana z innej karty w międzyczasie
            // nadal była wykryta. Po samym konflikcie — bieżąca: człowiek
            // widział już obie wersje i świadomie zapisuje swoją.
            'wersjaEdycji' => session('konflikt_edycji') === true
                ? $this->editPost->wersja($post)
                : old('wersja_edycji', $this->editPost->wersja($post)),
        ]);
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);
        $question = $post->kind === Post::KIND_QUESTION;
        abort_if($question && ! config('kuking.questions.enabled'), 404);

        // Zakres old input pochodzi z autoryzowanej trasy, nie z podrobionego
        // pola. Brak tag_names[] oznacza usunięcie całej ręcznej listy tylko
        // w tym konkretnym formularzu; cudzy formularz nie zeruje tagów.
        $request->merge(['_tag_form_post_id' => (string) $post->getKey()]);

        $tagNames = $this->tagiZFormularza($request);

        // Ten sam rozdział „akcja pośrednia" / „zapis" co w `store()` —
        // patrz komentarz tam.
        if ($this->toAkcjaTagow($request)) {
            [$tagNames, $bladTagow] = $this->zastosujAkcjeTagow($request, $tagNames);

            $powrot = redirect(url()->previous().($question ? '#f-tagi' : '#tagi'))
                ->withInput($request->except('tag_names') + ['tag_names' => $tagNames]);

            return $bladTagow === null ? $powrot : $powrot->withErrors(['tagi' => $bladTagow]);
        }

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'visibility' => ['required', 'in:public,followers,private'],
            'title' => $question ? ['required', 'string', 'min:10', 'max:180'] : ['exclude'],
        ], [
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
            'title.required' => 'Napisz pytanie w tytule.',
            'title.min' => 'Rozwiń pytanie do co najmniej 10 znaków.',
            'title.max' => 'Skróć tytuł pytania do 180 znaków.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten wpis.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — trzy opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.',
        ]);

        try {
            $this->editPost->handle(
                actor: $request->user(),
                post: $post,
                body: $data['body'] ?? null,
                visibility: $data['visibility'],
                tagNames: $tagNames,
                questionTitle: $question ? $data['title'] : null,
                wersjaFormularza: $request->filled('wersja_edycji') ? (string) $request->input('wersja_edycji') : null,
            );
        } catch (KonfliktEdycjiWpisu $e) {
            // Nic nie zapisano; tekst z formularza wraca do pól (`withInput`),
            // a widok pokazuje obok wersję zapisaną w bazie. Osobny klucz
            // `wersja`, nie `body`: tekst jest poprawny, więc pole nie może
            // dostać `aria-invalid`; odnośnik w podsumowaniu prowadzi do
            // sekcji z zapisaną wersją (`#wersja-zapisana`).
            return back()->withInput()->withErrors(['wersja' => $e->getMessage()])->with('konflikt_edycji', true);
        } catch (BladDlaCzlowieka $e) {
            // Poprawnie wpisany tekst nie ginie po nieudanej walidacji
            // domenowej (AGENTS.md §5, docs/UX_50_PLUS.md). Ten sam rozdział
            // pola błędu co w `store()` — patrz komentarz tam.
            $pole = in_array($e->getMessage(), [LimityTagow::komunikatZaDuzoTagow(), 'Do pytania dodaj najwyżej 3 tagi, także te wpisane w opisie.'], true) ? 'tagi' : 'body';

            return back()->withInput()->withErrors([$pole => $e->getMessage()]);
        }

        return redirect($post->url())->with('status', $question ? 'Pytanie zapisane.' : 'Wpis zapisany.');
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('home')->with('status', 'Wpis usunięty.');
    }
}
