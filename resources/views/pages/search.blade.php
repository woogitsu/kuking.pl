<x-layout title="Szukaj" :noindex="true">
    {{--
        PRAWA SZYNA (UI kit v2, ekran 03 „Szukaj i odkrywaj").

        Nazwa nagłówka ZOSTAJE „Szukaj", nie „Szukaj i odkrywaj" z makiety —
        `docs/brand/BRAND_EXTENDED.md` §1.1 ma to rozstrzygnięte wprost dla
        pojęcia „Wyszukiwanie": nazwa obowiązująca to „Szukaj", a „Odkrywaj"/
        „Discover" są na liście „nigdy". To jest ten sam rodzaj rozjazdu kitu
        z COPY_STYLE co „Komu wyszło" na ekranie przepisu (STAN_WDROZENIA_KITU
        z 7 września) — i tak samo rozstrzygnięty na rzecz COPY_STYLE.

        Treść szyny: patrz komentarz w `SearchController::__construct()` —
        to jest tablica „kuKINGi na dziś", nie dosłowne „Smaki września"
        z liczbami przepisów i nie lista osób z liczbą obserwujących.
    --}}
    <x-slot:rail>
        <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" />
    </x-slot:rail>

    <h1>Szukaj</h1>

    {{--
        POLE WYSZUKIWANIA W WIĘKSZYM UKŁADZIE Z KITU (ekrany 03 i 07).

        Etykieta ZOSTAJE widoczna nad polem — `docs/UX_50_PLUS.md`: „etykieta
        pola jest zawsze widoczna; placeholder nie jest etykietą". Kit rysuje
        samą ikonę lupy i tekst wewnątrz pola bez osobnej etykiety nad nim;
        to jest dopuszczalne w statycznej makiecie, ale nie w produkcie, który
        ma czytnikom ekranu i osobom słabiej widzącym pokazać, czym jest to
        pole, ZANIM w nie klikną. Ikona więc DOCHODZI do istniejącego pola,
        etykieta i tekst pomocy zostają bez zmian.
    --}}
    <form class="panel-formularza" method="GET" action="{{ route('search') }}">
        @include('components.error-summary', ['errors' => $searchErrors])
        <div class="field @if($searchErrors->has('q')) has-error @endif">
            <label for="f-q">Czego szukasz?</label>
            <span class="field-help" id="f-q-help">
                Możesz wpisać nazwę dania, składnik albo imię osoby. Polskie znaki nie mają znaczenia —
                „zurek” znajdzie „żurek”.
            </span>
            <div class="wyszukiwarka-pole-wiersz">
                <x-ikona nazwa="search" :rozmiar="24" class="wyszukiwarka-ikona" />
                <input class="field-input wyszukiwarka-input" id="f-q" name="q" type="search" value="{{ $phrase }}"
                       aria-describedby="f-q-help{{ $searchErrors->has('q') ? ' f-q-error' : '' }}"
                       @if($searchErrors->has('q')) aria-invalid="true" @endif
                       placeholder="żurek, pierogi, Basia">
            </div>
            @if($searchErrors->has('q'))
                <span class="field-error" id="f-q-error">{{ $searchErrors->first('q') }}</span>
            @endif
        </div>
        <input type="hidden" name="sekcja" value="{{ $section }}">
        <button class="btn btn-primary mt-4" type="submit">Szukaj</button>
    </form>

    {{--
        ZAKRESY Z KITU (ekran 03_desktop_search_discover).

        To są zwykłe odnośniki, nie przyciski sterowane skryptem — zawężenie
        wyników musi działać bez JavaScriptu (AGENTS.md), a każdy zakres ma
        własny adres, który da się zapisać w zakładkach i wysłać komuś.

        `aria-current="page"` zamiast samego koloru: który zakres jest włączony,
        musi być słyszalne dla czytnika ekranu, a nie tylko widoczne.
    --}}
    <nav class="chipsy mt-6" aria-label="Co przeszukujemy">
        @foreach([
            'wszystko' => 'Wszystko',
            'przepisy' => 'Przepisy',
            'ludzie' => 'Ludzie',
            'szybkie' => 'Do 30 minut',
        ] as $klucz => $etykieta)
            <a class="chip"
               href="{{ route('search', ['q' => $phrase, 'sekcja' => $klucz]) }}"
               @if($section === $klucz) aria-current="page" @endif>{{ $etykieta }}</a>
        @endforeach
    </nav>

    @if($phrase === '')
        <p class="meta">Wpisz coś w pole powyżej i kliknij „Szukaj”.</p>
        <section class="marka-szukaj-tagi" aria-labelledby="polecane-tagi-title">
            <h2 id="polecane-tagi-title">Polecane tagi</h2>
            @if($promowaneTagi->isNotEmpty())
                <ul class="lista-naga marka-szukaj-siatka">
                    @foreach($promowaneTagi as $tag)
                        <li class="marka-szukaj-tag">
                            <h3>{{ $tag->name }}</h3>
                            @if(filled($tag->promotion?->note))
                                <p>{{ $tag->promotion?->note }}</p>
                            @endif
                            <a class="marka-szukaj-tag-link" href="{{ route('tags.show', $tag) }}" aria-label="Zobacz tag: {{ $tag->name }}">Zobacz tag</a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p>Nie ma jeszcze polecanych tagów.</p>
            @endif
            <p><a class="btn btn-secondary marka-szukaj-wszystkie" href="{{ route('tags.index') }}">Wszystkie tagi</a></p>
        </section>
        {{--
            Ekran wyszukiwania bez frazy nie może kończyć się na samej
            instrukcji — to ślepy zaułek (docs/product/SOUL.md 4.11: pusty
            stan to zaproszenie, nie ściana). Ten sam odnośnik używa już
            `kuking-board.blade.php` i `tags/show.blade.php` w tej samej roli.
        --}}
        <p class="meta">Nie wiesz, od czego zacząć? Zajrzyj do <a href="{{ route('discover') }}">Świeżo z <x-kuking-word /></a>.</p>
    @elseif($zaKrotka)
        {{--
            Osobny, uczciwy tekst — nie „Nic nie znaleźliśmy" (SearchController
            tłumaczy dlaczego: przy jednym znaku silnik w ogóle nie szukał).
        --}}
        <p class="meta">Fraza „{{ $phrase }}” jest za krótka, żeby zacząć szukać. Wpisz co najmniej dwa znaki.</p>
    @elseif($searchErrors->isEmpty())
        @php
            // Puste jest dopiero wtedy, gdy pusty jest KAŻDY przeszukiwany
            // zakres. Przy „Wszystko" samo zero przepisów nie znaczy jeszcze
            // „nic nie znaleźliśmy" — obok mogą być ludzie.
            $nicNieMa = (! $szukaPrzepisow || $recipes->isEmpty())
                && (! $szukaLudzi || $people->isEmpty());
        @endphp

        @if($nicNieMa && $odPrzepisu === 0 && $odOsoby === 0)
            {{--
                Tekst gałęzi „przepisy" jest dosłownym
                cytatem z docs/brand/COPY_STYLE.md §6 „Puste stany" — ten
                dokument wiąże każdy tekst widoczny dla użytkownika i ma tu
                gotowe brzmienie, nie tylko przykład.
            --}}
            <x-empty-state title="Nic nie znaleźliśmy">
                @if($section === 'szybkie')
                    Nie ma przepisu do „{{ $phrase }}”, który zmieściłby się w pół godziny.
                    Spróbuj zakresu „Przepisy” — może być trochę dłuższy.
                @elseif($section === 'ludzie')
                    Nie ma tu osoby o nazwie „{{ $phrase }}”.
                @elseif($section === 'wszystko')
                    {{-- „Wszystko" przeszukuje przepisy I ludzi (#944). Tekst
                         o samym przepisie zmieniałby znaczenie zapytania
                         komuś, kto wpisał imię — do czego zachęca pomoc pola. --}}
                    Nie znaleźliśmy ani przepisu, ani osoby pasującej do „{{ $phrase }}”.
                    Sprawdź, czy wszystko jest dobrze wpisane, albo wpisz krócej: samo imię albo jedną nazwę dania.
                @else
                    Nie ma jeszcze przepisu, który by pasował do „{{ $phrase }}”. Może to Ty go dodasz?
                @endif
            </x-empty-state>

            {{--
                Droga dalej, nie ślepy zaułek (SOUL.md 4.11, IMPLEMENTATION_GUIDE
                etap D). Kto szuka przepisu — może go dodać. Każdy, niezależnie
                od zakresu — może zamiast tego zobaczyć, co dzieje się w Kuking
                teraz, tym samym odnośnikiem co przy pustej frazie wyżej.
            --}}
            <p class="text-center">
                @if($section !== 'ludzie')
                    {{-- W „Wszystko" fraza mogła być imieniem, więc bez „taki". --}}
                    <a class="btn btn-primary" href="{{ route('recipes.create') }}">{{ $section === 'wszystko' ? 'Dodaj przepis' : 'Dodaj taki przepis' }}</a>
                @endif
                <a class="btn btn-quiet" href="{{ route('discover') }}">Zajrzyj do Świeżo z <x-kuking-word /></a>
            </p>
        @endif

        @if($odPrzepisu > 0)
            <p><a class="btn btn-quiet" href="{{ route('search', $poczatekPrzepisow) }}">Wróć do początku przepisów</a></p>
            @if($recipes->isEmpty())
                <p>W tym zakresie nie ma już przepisów. Wróć do początku wyników.</p>
            @endif
        @endif
        @if($odOsoby > 0)
            <p><a class="btn btn-quiet" href="{{ route('search', $poczatekOsob) }}">Wróć do początku osób</a></p>
            @if($people->isEmpty())
                <p>W tym zakresie nie ma już osób. Wróć do początku wyników.</p>
            @endif
        @endif

        @if($szukaPrzepisow && $recipes->isNotEmpty())
            @if($section === 'wszystko')
                <h2 class="mt-6">Przepisy</h2>
            @endif

            <p class="meta">
                @if($odPrzepisu > 0 && $recipes->count() === 1)
                    Pokazujemy przepis {{ $odPrzepisu + 1 }}.
                @elseif($odPrzepisu > 0)
                    Pokazujemy przepisy {{ $odPrzepisu + 1 }}–{{ $odPrzepisu + $recipes->count() }}.
                @elseif($jestWiecej ?? false)
                    Pokazujemy {{ $recipes->count() }} {{ \App\Support\Odmiana::rzeczownik($recipes->count(), 'przepis', 'przepisy', 'przepisów') }}. Jest ich więcej.
                @else
                    Znaleziono {{ $recipes->count() }} {{ \App\Support\Odmiana::rzeczownik($recipes->count(), 'przepis', 'przepisy', 'przepisów') }}.
                @endif
            </p>
            <div class="stack">
                @foreach($recipes as $recipe)
                    <x-recipe-card :recipe="$recipe" />
                @endforeach
            </div>

            @if($jestWiecej ?? false)
                {{-- Zwykły odnośnik, nie przycisk sterowany skryptem: dalsze
                     wyniki muszą być osiągalne bez JavaScriptu (AGENTS.md). --}}
                <p class="text-center">
                    <a class="btn btn-quiet"
                       href="{{ route('search', $nastepnePrzepisy) }}">
                        Pokaż więcej przepisów
                    </a>
                </p>
            @endif
        @endif

        @if($szukaLudzi && $people->isNotEmpty())
            @if($section === 'wszystko')
                <h2 class="mt-8">Ludzie</h2>
            @endif

            <p class="meta">
                @if($odOsoby > 0 && $people->count() === 1)
                    Pokazujemy osobę {{ $odOsoby + 1 }}.
                @elseif($odOsoby > 0)
                    Pokazujemy osoby {{ $odOsoby + 1 }}–{{ $odOsoby + $people->count() }}.
                @elseif($jestWiecejOsob ?? false)
                    Pokazujemy {{ $people->count() }} {{ \App\Support\Odmiana::rzeczownik($people->count(), 'osobę', 'osoby', 'osób') }}. Jest ich więcej.
                @else
                    Znaleziono {{ $people->count() }} {{ \App\Support\Odmiana::rzeczownik($people->count(), 'osobę', 'osoby', 'osób') }}.
                @endif
            </p>
            <div class="stack-tight">
                @foreach($people as $person)
                    <div class="card flex gap-3 items-center">
                        <x-avatar :user="$person->user" :size="52" />
                        <div>
                            <a class="author-name" href="{{ route('profile.show', $person->username) }}">{{ $person->display_name }}</a>
                            <p class="meta m-0">&#64;{{ $person->username }} @if($person->speciality) · {{ $person->speciality }} @endif</p>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($jestWiecejOsob ?? false)
                {{-- Ten sam zwykły odnośnik co przy przepisach: dalsze wyniki
                     muszą być osiągalne bez JavaScriptu (AGENTS.md). --}}
                <p class="text-center">
                    <a class="btn btn-quiet"
                       href="{{ route('search', $nastepneOsoby) }}">
                        Pokaż więcej osób
                    </a>
                </p>
            @endif
        @endif
    @endif
</x-layout>
