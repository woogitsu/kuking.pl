{{--
    OPIS DLA WYSZUKIWARKI I PODGLĄDU LINKU (issue #965). Publiczny zeszyt jest
    od tej zmiany dostępny bez konta, więc trafia do indeksu i do podglądów
    linków wysłanych rodzinie. Prywatny ma `noindex` i opisu nie potrzebuje —
    nie oddajemy go nawet w nagłówku strony.
--}}
@php
    // „Przenieś do innego zeszytu” (#2430): jedno pytanie o Policy na stronę.
    $mozePrzenosic = auth()->check() && \Illuminate\Support\Facades\Gate::allows('przenies', $collection);
    $opisStrony = $collection->isPublic()
        ? \Illuminate\Support\Str::limit(
            trim((string) $collection->description) !== ''
                ? trim((string) $collection->description)
                : 'Zeszyt „'.$collection->name.'” — przepisy i wpisy zebrane'.($collection->owner ? ' przez '.$collection->owner->displayName() : '').' w Kuking.',
            160,
        )
        : null;
@endphp
{{-- Kanał Atom (#2227) — tylko dla zeszytu, który kanał odda gościowi:
     `CollectionPolicy::view(null, …)`. Właściciel prywatnego zeszytu nie
     dostaje odnośnika, pod którym czytnik zobaczyłby 404. --}}
<x-layout :title="$collection->name" :noindex="! $collection->isPublic()" :description="$opisStrony"
    :kanalAtom="\Illuminate\Support\Facades\Gate::forUser(null)->allows('view', $collection) ? ['href' => route('kanaly.zeszyt', $collection), 'title' => 'Zeszyt „'.$collection->name.'” (Atom)'] : null">
    {{--
        PRAWA SZYNA (issue #205): pozostałe zeszyty tej samej osoby.

        To jest jedyna czynność, którą naprawdę robi się Z TEGO ekranu —
        przejście do drugiego zeszytu wymagało do tej pory cofnięcia się
        na „Moje". Kontroler oddaje tu wyłącznie zeszyty, które oglądający
        ma prawo otworzyć (`CollectionController::show()`); widok niczego
        nie filtruje sam.

        Osoba, która ma tylko jeden zeszyt, nie dostaje żadnego bloku —
        pusta szyna jest lepsza niż karta, która nic nie wnosi.
    --}}
    @if($inneZeszyty->isNotEmpty())
        <x-slot:rail>
            <x-szyna-blok
                :tytul="auth()->id() === $collection->owner_id ? 'Twoje inne zeszyty' : 'Inne zeszyty tej osoby'"
                id="szyna-inne-zeszyty"
                ikona="book"
                :wiecej="auth()->id() === $collection->owner_id ? route('collections.index') : null">
                <x-szyna-linki akcja="Otwórz zeszyt" :pozycje="$inneZeszyty->map(fn ($zeszyt) => [
                    'href' => route('collections.show', $zeszyt),
                    'nazwa' => $zeszyt->name,
                    'podpis' => $zeszyt->description,
                ])->all()" />
            </x-szyna-blok>
        </x-slot:rail>
    @endif

    <div class="marka-zeszyt">
    <h1>{{ $collection->name }}</h1>
    @if($saveContext !== [])
        <section class="panel-formularza mb-5">
            @if($saveContent)
                <h2>Dokończ zapis</h2>
                <p>{{ $saveContent instanceof \App\Models\Recipe ? $saveContent->title : (trim($saveContent->body ?? '') ?: 'Zdjęcie bez opisu') }}</p>
                <form method="POST" data-dokoncz-zapis action="{{ $saveContent instanceof \App\Models\Recipe ? route('collections.save', $saveContent->slug) : route('collections.save-post', $saveContent) }}">
                    @csrf
                    <input type="hidden" name="collection_id" value="{{ $collection->getKey() }}">
                    <input type="hidden" name="open_collection" value="1">
                    <button class="btn btn-primary" type="submit">Zapisuję w tym zeszycie</button>
                </form>
                <a class="btn btn-secondary mt-3" href="{{ $saveContent->url() }}">Wróć {{ $saveContent instanceof \App\Models\Recipe ? 'do przepisu' : 'do wpisu' }}</a>
            @else
                <p>Ta treść nie jest już dostępna. Zeszyt został utworzony, ale niczego w nim nie zapisaliśmy. Poszukaj innego przepisu lub wpisu.</p>
                <a class="btn btn-secondary" href="{{ route('search') }}">Szukaj</a>
            @endif
            <a class="btn btn-secondary mt-3" href="{{ route('collections.show', $collection) }}">Zostaw zeszyt bez tego zapisu</a>
        </section>
    @endif
    @if($collection->description)
        <p>{{ $collection->description }}</p>
    @endif
    <p class="meta mb-5">
        @if($collection->isPublic())
            Ten zeszyt widzą wszyscy.
        @elseif($jestWspolpracownikiem)
            Ten zeszyt widzą tylko jego właściciel i zaproszone osoby.
        @elseif($czlonkowie->isNotEmpty())
            Ten zeszyt widzisz Ty i osoby zaproszone do wspólnego zapisywania.
        @else
            Ten zeszyt widzisz tylko Ty.
        @endif
    </p>

    {{-- „Wydrukuj zeszyt" (#2351, F7): cały zeszyt jako książka do druku
         z przeglądarki. Tylko gdy jest co drukować — przepisy widoczne dla
         oglądającego (`$recipes->total()` liczy je tym samym zakresem).
         Zwykły odnośnik z tekstem, działa bez JavaScriptu. --}}
    @if($recipes->total() > 0)
        <p class="mb-5"><a class="btn btn-secondary" href="{{ route('collections.print', ['collection' => $collection, 'druk' => 1]) }}#jak-wydrukowac" rel="nofollow">Wydrukuj zeszyt</a></p>
    @endif

    {{-- „Podziel się" (#2000): przycisk dostaje wyłącznie publiczny zeszyt,
         który zobaczy ktoś bez konta; resztę rozstrzyga `Udostepnianie`
         przez Policy. Prywatny, domyślny i wspólny — bez przycisku. --}}
    <x-podziel-sie :tresc="$collection" />

    {{-- „Zgłoś” przy publicznym zeszycie (#2279, regulamin §7): nazwę i opis
         napisał właściciel i widzą je wszyscy. Właściciel siebie nie zgłasza;
         prywatny zeszyt obcy nie widzi wcale. Gość dostaje odnośnik
         z logowaniem (`x-zglos-dla-goscia`, #2221). --}}
    @if($collection->isPublic() && auth()->id() !== $collection->owner_id)
        <p class="mb-5">
            @auth
                <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'collection', 'id' => $collection->getKey()]) }}">Zgłoś ten zeszyt</a>
            @else
                <x-zglos-dla-goscia typ="collection" :id="$collection->getKey()" etykieta="Zgłoś ten zeszyt" />
            @endauth
        </p>
    @endif

    {{--
        WSPÓLNY ZESZYT (#1743, D-302). Kto ma dostęp — tylko osobom, które
        same go mają; obcy oglądający publiczny zeszyt nie dowiaduje się,
        że ktoś poza właścicielem w nim zapisuje.
    --}}
    @if($jestWspolpracownikiem)
        <section class="notice mb-5" data-wspolny-zeszyt>
            <p class="m-0">To jest zeszyt osoby <strong>{{ $collection->owner?->displayName() }}</strong>. Możesz w nim zapisywać i wyjmować przepisy oraz wpisy.</p>
            <div class="mt-3">
                <x-confirm-button
                    :action="route('collections.leave', $collection)"
                    label="Odejdź z tego zeszytu"
                    question="Odejść z tego zeszytu? Stracisz do niego dostęp. To, co w nim zapisano, zostanie u właściciela." />
            </div>
        </section>
    @elseif($czlonkowie->isNotEmpty())
        <p class="mb-5" data-wspolny-zeszyt>
            Wspólny zeszyt. Dostęp {{ $czlonkowie->count() === 1 ? 'ma' : 'mają' }} też:
            {{ $czlonkowie->map(fn ($czlonek) => $czlonek->displayName())->join(', ') }}.
        </p>
    @endif
    {{-- Błąd notatki (#978) ma własny worek, żeby nie mieszać się z błędem
         wyboru zeszytu, który layout pokazuje osobno. --}}
    <x-error-summary :error-bag="\App\Domain\Collections\Actions\UpdateCollectionItemNote::WOREK_BLEDOW" />

    @if($recipes->count() === 0 && ($posts ?? collect())->count() === 0 && ($niewidoczne ?? 0) === 0)
        <x-empty-state title="W tym zeszycie nic jeszcze nie ma" action="Poszukaj przepisów" :href="route('search', ['sekcja' => 'przepisy'])" />
    @else
        @if($recipes->count() > 0)
            <h2>Przepisy</h2>
            {{-- RĘCZNA KOLEJNOŚĆ (#2544): osobna, świadoma czynność właściciela
                 prywatnego zeszytu. Bez niej lista wygląda jak dotąd. Przyciski
                 to zwykłe formularze POST z podpisami — bez przeciągania i JS. --}}
            @if($mozeUkladac && $recipes->total() > 1)
                <div class="kolejnosc-zeszytu mb-5" data-kolejnosc-zeszytu>
                    @if($trybUkladania)
                        <div class="notice" role="status">
                            <p class="m-0"><strong>Układasz kolejność przepisów.</strong> Pod każdym przepisem są przyciski „Wyżej”, „Niżej”, „Na początek” i „Na koniec”. Kolejność zostaje po zamknięciu strony i obowiązuje na wydruku i w paczce danych. Nowo zapisany przepis staje na końcu.</p>
                        </div>
                        <div class="form-actions mt-3">
                            <a class="btn btn-primary" href="{{ route('collections.show', $collection) }}">Gotowe</a>
                            @if($jestUlozony)
                                <x-confirm-button
                                    method="POST"
                                    :action="route('collections.recipes.order-reset', $collection)"
                                    label="Wróć do kolejności zapisu"
                                    question="Wrócić do kolejności zapisu? Ułożona przez Ciebie kolejność zostanie zapomniana, a przepisy staną od najnowszego zapisu. Same przepisy, notatki i daty zapisów zostają."
                                    :fields="[\App\Http\Controllers\CollectionRecipeOrderController::POLE_ODCISKU => $odciskUkladu]" />
                            @endif
                        </div>
                    @else
                        <p class="m-0">
                            <a class="btn btn-secondary" href="{{ route('collections.show', ['collection' => $collection, 'uloz' => 1]) }}">Ułóż kolejność przepisów</a>
                        </p>
                        @if($jestUlozony)
                            <p class="meta mt-2">Przepisy są w kolejności, którą ułożono ręcznie.</p>
                        @endif
                    @endif
                </div>
            @endif
            <div class="marka-zeszyt-przepisy" id="lista-przepisow">
                @foreach($recipes as $recipe)
                    {{-- Opakowanie jest pozycją siatki: pod kartą właściciel
                         ma swoją notatkę (#978). --}}
                    <div class="marka-zeszyt-pozycja" id="przepis-{{ $recipe->getKey() }}">
                        <x-recipe-card :recipe="$recipe" uklad="kafel" />
                        @if($trybUkladania && $recipes->total() > 1)
                            @php
                                $miejsce = ($recipes->currentPage() - 1) * \App\Domain\Collections\KolejnoscPrzepisow::NA_STRONE + $loop->iteration;
                                $pierwszy = $miejsce === 1;
                                $ostatni = $miejsce === $recipes->total();
                            @endphp
                            <form class="kolejnosc-przepisu" method="POST" action="{{ route('collections.recipes.move', ['collection' => $collection, 'pozycja' => $recipe->getKey()]) }}" data-kolejnosc-przepisu>
                                @csrf
                                <input type="hidden" name="{{ \App\Http\Controllers\CollectionRecipeOrderController::POLE_ODCISKU }}" value="{{ $odciskUkladu }}">
                                <input type="hidden" name="strona" value="{{ $recipes->currentPage() }}">
                                <p class="kolejnosc-przepisu-miejsce m-0"><strong>Miejsce {{ $miejsce }} z {{ $recipes->total() }}</strong></p>
                                <div class="kolejnosc-przepisu-przyciski">
                                    @unless($pierwszy)
                                        <button class="btn btn-secondary" type="submit" name="kierunek" value="wyzej">Wyżej</button>
                                    @endunless
                                    @unless($ostatni)
                                        <button class="btn btn-secondary" type="submit" name="kierunek" value="nizej">Niżej</button>
                                    @endunless
                                    @unless($pierwszy)
                                        <button class="btn btn-quiet" type="submit" name="kierunek" value="poczatek">Na początek</button>
                                    @endunless
                                    @unless($ostatni)
                                        <button class="btn btn-quiet" type="submit" name="kierunek" value="koniec">Na koniec</button>
                                    @endunless
                                </div>
                            </form>
                        @endif
                        @if($wspolny)
                            <p class="meta mt-2" data-kto-dodal>Dodane przez: {{ $podpisyDodania[(string) $recipe->pivot->added_by_id] ?? 'osoba, która usunęła konto' }}</p>
                            <form method="POST" action="{{ route('collections.unsave', $recipe->slug) }}" class="mt-2">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="collection_id" value="{{ $collection->getKey() }}">
                                <button class="btn btn-secondary" type="submit" data-rola="wyjmij-z-tego-zeszytu">Usuń z tego zeszytu</button>
                            </form>
                        @endif
                        <x-notatka-zapisu :zeszyt="$collection" typ="przepis" :pozycja="$recipe" :dostep="$dostepDoNotatek" :wspolny="$wspolny" />
                        @if($mozePrzenosic)
                            <p class="mt-2"><a class="btn btn-secondary" href="{{ route('collections.move.form', ['collection' => $collection, 'typ' => 'przepis', 'pozycja' => $recipe->getKey()]) }}" data-rola="przenies-do-zeszytu">Przenieś do innego zeszytu</a></p>
                        @endif
                    </div>
                @endforeach
            </div>
            <x-show-more :paginator="$recipes" czego="przepisów" lista="lista-przepisow" />
        @endif

        @if(($posts ?? collect())->count() > 0)
            {{-- Wpisy odłożone „na potem" (UI kit v2, ekran 01). Osobna sekcja,
                 a nie wymieszane z przepisami: to są dwie różne rzeczy i dwa
                 różne powody, dla których się je zapisuje. --}}
            <h2 class="mt-8">Zapisane wpisy</h2>
            <div class="stack" id="lista-zapisanych-wpisow">
                @foreach($posts as $post)
                    {{-- `:zeszyt` daje karcie kontekst TEGO zeszytu, więc
                         zamiast odnośnika „Masz to w zeszycie" pokazuje
                         przycisk usuwający TYLKO stąd (issue #775, #776). --}}
                    <div class="marka-zeszyt-pozycja">
                        <x-post-card :post="$post" :zeszyt="$collection" />
                        @if($wspolny)
                            <p class="meta mt-2" data-kto-dodal>Dodane przez: {{ $podpisyDodania[(string) $post->pivot->added_by_id] ?? 'osoba, która usunęła konto' }}</p>
                        @endif
                        <x-notatka-zapisu :zeszyt="$collection" typ="wpis" :pozycja="$post" :dostep="$dostepDoNotatek" :wspolny="$wspolny" />
                        @if($mozePrzenosic)
                            <p class="mt-2"><a class="btn btn-secondary" href="{{ route('collections.move.form', ['collection' => $collection, 'typ' => 'wpis', 'pozycja' => $post->getKey()]) }}" data-rola="przenies-do-zeszytu">Przenieś do innego zeszytu</a></p>
                        @endif
                    </div>
                @endforeach
            </div>
            <x-show-more :paginator="$posts" czego="zapisanych wpisów" lista="lista-zapisanych-wpisow" />
        @endif

        @if(($niewidoczne ?? 0) > 0)
            {{--
                NIE MÓWIMY, CO TU BYŁO — MÓWIMY, ŻE COŚ BYŁO.

                Zapisana treść, którą autor pokazywał obserwującym, znika
                po tym, jak przestaniesz go obserwować. Ciche zniknięcie
                wygląda jak utrata danych („miałam to tu wczoraj"), a pokazanie
                treści łamie widoczność, którą autor sobie ustawił. Zostaje
                trzecia droga: powiedzieć ILE, nie mówiąc CZEGO.
            --}}
            <p class="notice mt-6" data-niedostepne-zapisy>
                {{ $niewidoczne }}
                {{ \App\Support\Odmiana::rzeczownik($niewidoczne, 'zapis nie jest dla Ciebie dostępny', 'zapisy nie są dla Ciebie dostępne', 'zapisów nie jest dla Ciebie dostępnych') }}.
                Te zapisy nadal są w tym zeszycie.
            </p>
            @error('zakres')
                <p class="notice mt-4" role="alert">{{ $message }}</p>
            @enderror
            {{-- Porządkowanie bez kasowania całego zeszytu (#773). Tylko
                 właściciel; formularz niesie odcisk zbioru z tej chwili, więc
                 serwer nie wyjmie innej grupy niż ta, którą tu policzono. --}}
            @if($odciskNiedostepnych ?? null)
                <div class="mt-4">
                    <x-confirm-button
                        :action="route('collections.unavailable.destroy', $collection)"
                        label="Wyjmij niedostępne zapisy"
                        :fields="['zakres' => $odciskNiedostepnych]"
                        :question="'Wyjąć z tego zeszytu '.$niewidoczne.' '.\App\Support\Odmiana::rzeczownik($niewidoczne, 'niedostępny zapis', 'niedostępne zapisy', 'niedostępnych zapisów').'? Nie wrócą same, nawet gdy autor znowu je udostępni. Widoczne zapisy i inne zeszyty zostaną bez zmian.'" />
                </div>
            @endif
        @endif
    @endif

    @guest
        {{--
            GOŚĆ NIE DOSTAJE ŻADNEJ AKCJI ZA LOGOWANIEM (issue #965).

            Zeszyt „Wszyscy" otwiera się bez konta, ale zapisywanie i własne
            zeszyty wymagają konta. Zamiast przycisku, który przerzuca
            na logowanie bez słowa wyjaśnienia, mówimy wprost, co trzeba zrobić.
        --}}
        <section class="panel-formularza mt-8" data-rola="zeszyt-gosc">
            <h2>Chcesz mieć własny zeszyt?</h2>
            <p>Zaloguj się albo załóż konto, a zapiszesz ulubione przepisy we własnym zeszycie.</p>
            <div class="form-actions">
                <a class="btn btn-primary" href="{{ route('login') }}">Zaloguj się</a>
                <a class="btn btn-secondary" href="{{ route('register') }}">Załóż konto</a>
            </div>
        </section>
    @endguest

    @if(auth()->id() === $collection->owner_id)
        {{--
            COFNIĘCIE PUBLICZNEGO UDOSTĘPNIENIA BEZ KASOWANIA ZESZYTU (#777).

            Do tej zmiany jedyną widoczną drogą do zamknięcia publicznego
            zeszytu było usunięcie go w całości — razem z nazwą, opisem
            i wszystkimi zapisami. „Edytuj zeszyt" prowadzi na formularz
            z tymi samymi trzema polami co przy zakładaniu, więc zmiana
            widoczności nie wymaga już utraty niczego innego.
        --}}
        <a class="btn btn-secondary mt-6" href="{{ route('collections.edit', $collection) }}">Edytuj zeszyt</a>
        {{-- Skrót na ekranie „Moje” (#2542): jeden własny zeszyt, zwykły
             formularz POST/DELETE, działa bez JavaScriptu. --}}
        @if(auth()->user()->ulubiony_zeszyt_id === $collection->getKey())
            <form method="POST" action="{{ route('collections.shortcut.destroy', $collection) }}" class="mt-4" data-rola="skrot-usun">
                @csrf
                @method('DELETE')
                <button class="btn btn-secondary" type="submit">Usuń skrót</button>
            </form>
        @else
            <form method="POST" action="{{ route('collections.shortcut.store', $collection) }}" class="mt-4" data-rola="skrot-ustaw">
                @csrf
                <button class="btn btn-secondary" type="submit">Ustaw jako skrót w »Moje«</button>
            </form>
        @endif
        @unless($collection->is_default)
            {{-- Wspólne zapisywanie z bliską osobą (#1743). --}}
            <a class="btn btn-secondary mt-6" href="{{ route('collections.sharing', $collection) }}">Zaproś do wspólnego zapisywania</a>
        @endunless
    @endif

    @if(auth()->id() === $collection->owner_id && ! $collection->is_default)
        <div class="danger-zone">
            <x-confirm-button
                :action="route('collections.destroy', $collection)"
                label="Usuń ten zeszyt"
                :question="'Usunąć zeszyt „'.$collection->name.'”? Same przepisy zostaną — znikną tylko z tego zeszytu.'.($czlonkowie->isNotEmpty() ? ' Zaproszone osoby stracą do niego dostęp.' : '')" />
        </div>
    @endif
    </div>
</x-layout>
