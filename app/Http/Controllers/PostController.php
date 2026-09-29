<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Media\ZachowaneZdjecia;
use App\Domain\Posts\Actions\EditPost;
use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Posts\Actions\ZbierzZdjeciaFormularza;
use App\Domain\Posts\KonfliktEdycjiWpisu;
use App\Domain\Posts\KontoNieMozePublikowac;
use App\Domain\Posts\PodsumowaniePublikacjiWpisu;
use App\Domain\Posts\StronaWpisu;
use App\Domain\Tags\TagSuggester;
use App\Exceptions\BladDlaCzlowieka;
use App\Exceptions\BladZdjecFormularza;
use App\Http\Requests\Posts\EdycjaWpisuRequest;
use App\Http\Requests\Posts\KomentarzRequest;
use App\Http\Requests\Posts\ZapisWpisuRequest;
use App\Models\Post;
use App\Models\Tag;
use App\Support\Komunikat;
use App\Support\LimityTagow;
use App\Support\OdpowiedziWatku;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
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
        private readonly ZbierzZdjeciaFormularza $zbierzZdjecia,
        private readonly PublishComment $publishComment,
        private readonly TagSuggester $tagSuggester,
        private readonly StronaWpisu $stronaWpisu,
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

    public function store(ZapisWpisuRequest $request): RedirectResponse
    {
        $question = $request->pytanie();
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

        if ($question && $request->filled('usun_zdjecie')) {
            $mediaIds = array_values(array_filter(
                ZachowaneZdjecia::identyfikatory($request->input('media_ids', []), $user->getKey()),
                fn (string $id): bool => $id !== $request->input('usun_zdjecie'),
            ));

            return redirect()->route('questions.create')
                ->withInput($request->wejscieBezPlikowITagow($mediaIds, $request->tagiZFormularza()));
        }

        // PONOWIENIE JUŻ OPUBLIKOWANEGO WYSŁANIA — PRZED ZDJĘCIAMI (issue #873).
        //
        // `PublishPost` rozpoznaje drugie kliknięcie dopiero po zapisaniu
        // plików, więc wcześniej ponowiony multipart wgrywał i przetwarzał
        // zdjęcia drugi raz, a potem je osieracał. Pytamy tylko przy
        // „Opublikuj" — przyciski tagów to praca nad formularzem, nie
        // wysłanie. Wyścig dwóch jednoczesnych żądań rozstrzyga dalej
        // indeks UNIQUE w akcji.
        if (! $request->toAkcjaTagow()) {
            $zapisany = $this->publishPost->wpisZTegoWyslania($user, $request->kluczWyslania());

            if ($zapisany !== null) {
                return $this->odpowiedzNaPonowienie($zapisany, $question);
            }
        }

        try {
            $mediaIds = $this->zbierzZdjecia->handle($user, $request->input('media_ids', []), $request->file('photos', []));
        } catch (BladZdjecFormularza $e) {
            return back()
                ->withInput($request->wejscieBezPlikow($e->mediaIds))
                ->withErrors(['photos' => $e->getMessage()]);
        }

        $tagNames = $request->tagiZFormularza();

        // TAGI: „Szukaj tagów" / „Dodaj" / „Usuń" — TRZY OSOBNE SUBMITY
        // w TYM SAMYM formularzu co „Opublikuj" (SPEC §1.6, R1 §6.2).
        //
        // Żaden z nich nie publikuje wpisu — rozpoznajemy to PRZED walidacją
        // treści/widoczności, bo na tym etapie mogą być jeszcze puste albo
        // niedokończone (ktoś dodaje tagi, zanim napisze tekst). Bez tego
        // rozróżnienia kliknięcie „Dodaj" próbowałoby opublikować wpis.
        if ($request->toAkcjaTagow()) {
            [$tagNames, $bladTagow] = $request->zastosujAkcjeTagow($tagNames, $question);

            // Fragment `#tagi` w adresie, żeby przeglądarka wróciła w miejsce,
            // gdzie ta osoba faktycznie pracuje, a nie na górę formularza
            // z tekstem i zdjęciami nad sekcją tagów (R1 §6.1).
            $powrot = redirect(url()->previous().($question ? '#f-tagi' : '#tagi'))
                ->withInput($request->wejscieBezPlikowITagow($mediaIds, $tagNames));

            return $bladTagow === null ? $powrot : $powrot->withErrors(['tagi' => $bladTagow]);
        }

        // Walidacja treści to faza 2 `ZapisWpisuRequest` — świadomie nie
        // rzuca wyjątku, bo kontrola nad starym wejściem (`media_ids`,
        // `tag_names`) musi zostać tutaj.
        $walidator = $request->walidatorTresci();

        if ($walidator->fails()) {
            // Zdjecia SA JUZ WGRANE, a TAGI JUZ WYBRANE — wracaja do formularza
            // jako ukryte pola, zeby poprawnie wpisane dane nigdy nie zniknely
            // (AGENTS.md §5), dokladnie tak jak zdjecia od audytu C1.
            return back()
                ->withInput($request->wejscieBezPlikowITagow($mediaIds, $tagNames))
                ->withErrors($walidator);
        }

        $data = $walidator->validated();

        try {
            $post = $this->publishPost->handle(
                author: $user,
                body: $data['body'] ?? null,
                mediaIds: $mediaIds,
                visibility: $request->widocznosc($data),
                tagNames: $tagNames,
                ip: $request->ip(),
                // Wygląd zdjęć ustawia się DOPIERO PO publikacji, na osobnym
                // ekranie — patrz komentarz przy przekierowaniu niżej. Wpis
                // powstaje więc zawsze jako „zwykle".
                displayMode: Post::DISPLAY_NORMAL,
                kluczWyslania: $request->kluczWyslania(),
                questionTitle: $question ? $data['title'] : null,
            );
        } catch (BladDlaCzlowieka $e) {
            // Formularz zachowuje wpisany tekst — poprawne dane nigdy nie giną
            // (docs/UX_50_PLUS.md). Odmowa po zmianie stanu konta dotyczy
            // całego wpisu; błędy zdjęć i tagów trafiają pod swoje pola.
            $pole = $e instanceof KontoNieMozePublikowac ? 'body'
                : (($e->getMessage() === LimityTagow::komunikatZaDuzoTagow()
                    || ($question && str_contains($e->getMessage(), '3 tagi'))) ? 'tagi' : 'photos');

            return back()
                ->withInput($request->wejscieBezPlikowITagow($mediaIds, $tagNames))
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
        if (! $post->wasRecentlyCreated) {
            return $this->odpowiedzNaPonowienie($post, $question);
        }
        if ($question) {
            return redirect()->route('questions.show', $post)->with(Komunikat::sukces('Pytanie opublikowane.'));
        }

        // Treść komunikatu (data, pierwszy wpis, zdjęcie w kolejce) i decyzja
        // o ekranie układu zdjęć (od dwóch zdjęć): `PodsumowaniePublikacjiWpisu`.
        $podsumowanie = PodsumowaniePublikacjiWpisu::dla($post, $user, count($mediaIds));

        $odpowiedz = $podsumowanie->ekranUkladuZdjec
            ? redirect()->route('posts.media.edit', $post)->with('poPublikacji', true)
            : redirect()->route('posts.show', $post);

        $odpowiedz->with(Komunikat::sukces($podsumowanie->komunikat));

        // Ten sam przycisk „Zobacz swój wpis” ma się pojawić wszędzie, gdzie
        // ląduje pierwsza publikacja (issue #1881), niezależnie od liczby zdjęć.
        if (($akcja = $podsumowanie->akcja($post)) !== null) {
            $odpowiedz->with('status_akcja', $akcja);
        }

        return $odpowiedz;
    }

    /**
     * Odpowiedź na ponowione, już opublikowane wysłanie (issue #873) — ta
     * sama dla wczesnego rozpoznania klucza i dla zderzenia na indeksie.
     */
    private function odpowiedzNaPonowienie(Post $post, bool $question): RedirectResponse
    {
        if ($question) {
            return redirect()->route('questions.show', $post)->with(Komunikat::informacja('To pytanie jest już opublikowane. Drugie kliknięcie nie dodało go ponownie.'));
        }

        return redirect()->route('posts.show', $post)->with(Komunikat::informacja('Ten wpis jest już opublikowany. Kliknięcie drugi raz nic nie zepsuło — wpis jest jeden. '
            .'Chcesz dodać osobny wpis? Otwórz „Dodaj zdjęcie” jeszcze raz — wtedy powstanie nowy.',
        ));
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

        // Ślad wglądu moderacji (#1018), relacje karty (#1037) i odcięcie
        // przepisu, którego widz nie może zobaczyć: `StronaWpisu`.
        $this->stronaWpisu->zapiszWgladModeracji($post, $request->user(), $request->ip());
        $this->stronaWpisu->zaladuj($post);

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

        $this->stronaWpisu->zdejmijNiedostepnyPrzepis($post, $request->user());

        $komentarze = $this->stronaWpisu->komentarze($post, $request->user());
        OdpowiedziWatku::uzupelnij($komentarze, $request, ['author.profile.avatar', 'post']);

        $strona = $this->stronaWpisu->dane($post, $request->user(), $komentarze);

        return view($strona['widok'], $strona['dane']);
    }

    public function comment(KomentarzRequest $request, Post $post): RedirectResponse
    {
        // Policy i walidacja: `KomentarzRequest` (w tej kolejności — 403 przed
        // błędami pól). Jawne `authorize()` zostaje tu celowo: jest
        // idempotentne, a bramkę na trasie z wiązaniem modelu widać w kontrolerze
        // (`AutoryzacjaTrasZWiazaniemModeluTest`).
        $this->authorize('comment', $post);

        $data = $request->validated();

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

        return back()->with(Komunikat::sukces('Komentarz dodany.'));
    }

    /**
     * Edycja wpisu: tekst, widoczność, tagi — nie zdjęcia (issue: menu „…"
     * pokazywało autorowi tylko „Otwórz wpis", mimo że `docs/FEATURES.md`
     * i `docs/ROADMAP.md` wymieniają edycję jako część MVP).
     *
     * Zdjęcia mają już swój ekran, patrz komentarz przy `EditPost`.
     */
    public function edit(Request $request, Post $post): View|RedirectResponse
    {
        // Issue #936: autor widzi własny ukryty wpis, ale nie może go
        // poprawić (PostPolicy::update). Zamiast gołego 403 mówimy, co zrobić.
        if ($this->autorWpisuPodDecyzja($request, $post)) {
            return redirect($post->url())->with(Komunikat::sukces(EditPost::KOMUNIKAT_POD_DECYZJA));
        }

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

    public function update(EdycjaWpisuRequest $request, Post $post): RedirectResponse|Response
    {
        // Issue #936: jak w `edit()`. Formularza edycji już nie ma, więc
        // wpisany tekst wraca na ekranie do skopiowania, a nie do pól.
        if ($this->autorWpisuPodDecyzja($request, $post)) {
            return $this->poprawkaPodDecyzja($request, $post);
        }

        $this->authorize('update', $post);
        $question = $post->kind === Post::KIND_QUESTION;
        abort_if($question && ! config('kuking.questions.enabled'), 404);

        // Zakres old input pochodzi z autoryzowanej trasy, nie z podrobionego
        // pola. Brak tag_names[] oznacza usunięcie całej ręcznej listy tylko
        // w tym konkretnym formularzu; cudzy formularz nie zeruje tagów.
        $request->oznaczFormularzWpisu($post);

        $tagNames = $request->tagiZFormularza();

        // Ten sam rozdział „akcja pośrednia" / „zapis" co w `store()` —
        // patrz komentarz tam.
        if ($request->toAkcjaTagow()) {
            [$tagNames, $bladTagow] = $request->zastosujAkcjeTagow($tagNames, $question);

            $powrot = redirect(url()->previous().($question ? '#f-tagi' : '#tagi'))
                ->withInput($request->except('tag_names') + ['tag_names' => $tagNames]);

            return $bladTagow === null ? $powrot : $powrot->withErrors(['tagi' => $bladTagow]);
        }

        $data = $request->trescWpisu($post);

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
            // Moderator ukrył wpis w trakcie zapisu (sprawdzone pod blokadą).
            if ($e->getMessage() === EditPost::KOMUNIKAT_POD_DECYZJA) {
                return $this->poprawkaPodDecyzja($request, $post);
            }
            // Poprawnie wpisany tekst nie ginie po nieudanej walidacji
            // domenowej (AGENTS.md §5, docs/UX_50_PLUS.md). Ten sam rozdział
            // pola błędu co w `store()` — patrz komentarz tam.
            $pole = in_array($e->getMessage(), [LimityTagow::komunikatZaDuzoTagow(), 'Do pytania dodaj najwyżej 3 tagi, także te wpisane w opisie.'], true) ? 'tagi' : 'body';

            return back()->withInput()->withErrors([$pole => $e->getMessage()]);
        }

        return redirect($post->url())->with(Komunikat::sukces($question ? 'Pytanie zapisane.' : 'Wpis zapisany.'));
    }

    private function poprawkaPodDecyzja(Request $request, Post $post): Response
    {
        return response()->view('pages.posts.edit-pod-decyzja', [
            'komunikat' => EditPost::KOMUNIKAT_POD_DECYZJA,
            'body' => is_string($request->input('body')) ? $request->input('body') : '',
            'returnUrl' => $post->url(),
        ], 403);
    }

    private function autorWpisuPodDecyzja(Request $request, Post $post): bool
    {
        return $request->user()?->getKey() === $post->author_id && $post->jestPodDecyzjaModeracji();
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('home')->with(Komunikat::sukces('Wpis usunięty.'));
    }
}
