<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Posts\Actions\EditPost;
use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Tags\TagSuggester;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityTagow;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
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
    ) {}

    public function create(): View
    {
        return view('pages.posts.create', [
            'tagNames' => (array) old('tag_names', []),
            'sugestieTagow' => $this->sugestieDlaZapytania(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
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
        $request->validate([
            'photos' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'photos.*' => ['file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'media_ids' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'media_ids.*' => ['uuid'],
        ], [
            'photos.*.image' => 'Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP.',
            'photos.*.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'photos.max' => LimityZdjec::komunikatZaDuzoZdjec(),
        ]);

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
            $powrot = redirect(url()->previous().'#tagi')
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
            'visibility' => ['required', 'in:public,followers,private'],
        ], [
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
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
                visibility: $data['visibility'],
                tagNames: $tagNames,
                ip: $request->ip(),
                // Wygląd zdjęć ustawia się DOPIERO PO publikacji, na osobnym
                // ekranie — patrz komentarz przy przekierowaniu niżej. Wpis
                // powstaje więc zawsze jako „zwykle".
                displayMode: Post::DISPLAY_NORMAL,
            );
        } catch (BladDlaCzlowieka $e) {
            // Formularz zachowuje wpisany tekst — poprawne dane nigdy nie giną
            // (docs/UX_50_PLUS.md). Dwa różne powody mogą tu wylądować
            // (wpis całkiem pusty ALBO za dużo tagów po rozwiązaniu nazw
            // na aliasy) — komunikat trafia pod pole, którego naprawdę
            // dotyczy, żeby „Poprawne dane nigdy nie znikają" nie zgubiło
            // się w złym miejscu ekranu.
            $pole = $e->getMessage() === LimityTagow::komunikatZaDuzoTagow() ? 'tagi' : 'photos';

            return back()
                ->withInput($this->wejscieBezPlikowITagow($request, $mediaIds, $tagNames))
                ->withErrors([$pole => $e->getMessage()]);
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

            if (count($tagNames) >= LimityTagow::maksTagowNaWpis()) {
                return [$tagNames, LimityTagow::komunikatZaDuzoTagow()];
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
        $odzyskane = Media::query()
            ->whereIn('id', (array) $request->input('media_ids', []))
            ->where('owner_id', $user->getKey())
            ->whereDoesntHave('posts')
            ->pluck('id')
            ->all();

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

    public function show(Request $request, Post $post): View
    {
        $this->authorize('view', $post);

        $post->load([
            'author.profile.avatar',
            'media',
            'recipe:id,title,slug',
            // Komentarze NIE SĄ tu ładowane (patrz niżej): rosną z popularnością
            // treści bez górnej granicy, więc idą osobnym, paginowanym
            // zapytaniem. `->load()` wciągał je wszystkie naraz.
        ]);

        // Jak przy przepisie — te same dwa powody: blokady (issue #41)
        // i paginacja wątków.
        $komentarze = $post->comments()
            ->widoczneDla($request->user())
            ->with([
                'author.profile.avatar',
                'replies' => fn ($query) => $query->widoczneDla($request->user()),
                'replies.author.profile.avatar',
            ])
            ->paginate((int) config('kuking.comments.page_size'), ['*'], 'komentarze');

        return view('pages.posts.show', [
            'komentarze' => $komentarze,
            'komentarzyRazem' => $komentarze->total(),
            'post' => $post,
            // Zachęta do kolejnego zdjęcia brzmi inaczej przy pierwszym wpisie
            // (COLD_START.md). Liczymy TYLKO dla autora — dla kogokolwiek
            // innego to dodatkowe zapytanie bez żadnego zastosowania.
            'toPierwszyWpis' => $request->user()?->getKey() === $post->author_id
                && $post->author->posts()->published()->count() === 1,
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

        try {
            $this->publishComment->handle(
                author: $request->user(),
                subject: $post,
                body: $data['body'],
                // `?? null`, bo `validate()` NIE zwraca klucza, którego
                // w żądaniu nie było — a `parent_id` jest `nullable`.
                // Komentarz wysłany bez tego pola (czyli każdy spoza naszego
                // formularza, który zawsze wysyła puste) kończył się błędem
                // „Undefined array key", czyli 500 zamiast komentarza.
                //
                // `widoczneDla()` — audyt W7-06. Bez tego można było podać
                // UUID komentarza ukrytego przez blokadę i podpiąć się pod
                // cudzy wątek. Akcja domenowa sprawdza to drugi raz, bo
                // kontrolerów jest kilka.
                parent: ($data['parent_id'] ?? null) === null
                    ? null
                    : $post->allComments()
                        ->widoczneDla($request->user())
                        ->whereKey($data['parent_id'])
                        ->first(),
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

        return view('pages.posts.edit', [
            'post' => $post,
            // Lista robocza tagów: to, co ktoś zdążył zmienić w tym
            // formularzu (`old()`), a jeśli to pierwsze wejście na ekran —
            // tagi, które wpis ma już dziś.
            'tagNames' => (array) old('tag_names', $post->tags->pluck('name')->all()),
            'sugestieTagow' => $this->sugestieDlaZapytania(),
        ]);
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $tagNames = $this->tagiZFormularza($request);

        // Ten sam rozdział „akcja pośrednia" / „zapis" co w `store()` —
        // patrz komentarz tam.
        if ($this->toAkcjaTagow($request)) {
            [$tagNames, $bladTagow] = $this->zastosujAkcjeTagow($request, $tagNames);

            $powrot = redirect(url()->previous().'#tagi')
                ->withInput($request->except('tag_names') + ['tag_names' => $tagNames]);

            return $bladTagow === null ? $powrot : $powrot->withErrors(['tagi' => $bladTagow]);
        }

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'visibility' => ['required', 'in:public,followers,private'],
        ], [
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten wpis.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — trzy opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.',
        ]);

        try {
            $this->editPost->handle(
                post: $post,
                body: $data['body'] ?? null,
                visibility: $data['visibility'],
                tagNames: $tagNames,
            );
        } catch (BladDlaCzlowieka $e) {
            // Poprawnie wpisany tekst nie ginie po nieudanej walidacji
            // domenowej (AGENTS.md §5, docs/UX_50_PLUS.md). Ten sam rozdział
            // pola błędu co w `store()` — patrz komentarz tam.
            $pole = $e->getMessage() === LimityTagow::komunikatZaDuzoTagow() ? 'tagi' : 'body';

            return back()->withInput()->withErrors([$pole => $e->getMessage()]);
        }

        return redirect()->route('posts.show', $post)->with('status', 'Wpis zapisany.');
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('home')->with('status', 'Wpis usunięty.');
    }
}
