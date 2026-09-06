<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Posts\Actions\PublishPost;
use App\Models\Media;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use RuntimeException;

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
        private readonly StoreUploadedImage $storeImage,
        private readonly PublishComment $publishComment,
    ) {}

    public function create(): View
    {
        // Zamknięta lista tematów (issue #31). Wybór jest OPCJONALNY —
        // wymuszanie go dokładałoby decyzję w momencie, w którym chcemy,
        // żeby człowiek po prostu wrzucił zdjęcie.
        return view('pages.posts.create', ['topics' => Topic::doWyboru()->get()]);
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
            'photos.*' => ['file', 'image', 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'media_ids' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'media_ids.*' => ['uuid'],
        ], [
            'photos.*.image' => 'Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP.',
            'photos.*.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'photos.max' => LimityZdjec::komunikatZaDuzoZdjec(),
        ]);

        try {
            $mediaIds = $this->zebranZdjecia($request, $user);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['photos' => $e->getMessage()]);
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
            // Temat opcjonalny, ale MUSI istnieć i być aktywny. Sprawdzenie
            // po stronie akcji domenowej jest drugą bramką — ta tutaj jest
            // po to, żeby człowiek dostał komunikat zamiast cichego pominięcia.
            'topic_id' => ['nullable', 'uuid'],
        ], [
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten wpis.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — trzy opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.',
        ]);

        if ($walidator->fails()) {
            // Zdjecia SA JUZ WGRANE — wracaja do formularza jako ukryte pola,
            // zeby nie trzeba bylo przechodzic przez galerie telefonu drugi raz.
            return back()
                ->withInput($this->wejscieBezPlikow($request, $mediaIds))
                ->withErrors($walidator);
        }

        $data = $walidator->validated();

        try {
            $post = $this->publishPost->handle(
                author: $user,
                body: $data['body'] ?? null,
                mediaIds: $mediaIds,
                visibility: $data['visibility'],
                topicId: $data['topic_id'] ?? null,
                ip: $request->ip(),
                // Wygląd zdjęć ustawia się DOPIERO PO publikacji, na osobnym
                // ekranie — patrz komentarz przy przekierowaniu niżej. Wpis
                // powstaje więc zawsze jako „zwykle".
                displayMode: Post::DISPLAY_NORMAL,
            );
        } catch (RuntimeException $e) {
            // Formularz zachowuje wpisany tekst — poprawne dane nigdy nie giną
            // (docs/UX_50_PLUS.md).
            return back()
                ->withInput($this->wejscieBezPlikow($request, $mediaIds))
                ->withErrors(['photos' => $e->getMessage()]);
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
            throw new RuntimeException(LimityZdjec::komunikatZaDuzoZdjec());
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
            // Komentarze filtrowane przez blokady (issue #41). Bez tego
            // zablokowana osoba nadal była widoczna pod cudzymi treściami.
            'comments' => fn ($query) => $query->widoczneDla($request->user()),
            'comments.author.profile.avatar',
            'comments.replies' => fn ($query) => $query->widoczneDla($request->user()),
            'comments.replies.author.profile.avatar',
        ]);

        return view('pages.posts.show', [
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
                parent: $data['parent_id'] === null
                    ? null
                    : $post->allComments()->whereKey($data['parent_id'])->first(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('status', 'Komentarz dodany.');
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('home')->with('status', 'Wpis usunięty.');
    }
}
