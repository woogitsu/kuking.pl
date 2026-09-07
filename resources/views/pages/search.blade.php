<x-layout title="Szukaj" :noindex="true">
    <h1>Szukaj</h1>

    <form class="card" method="GET" action="{{ route('search') }}">
        <div class="field">
            <label for="f-q">Czego szukasz?</label>
            <span class="field-help" id="f-q-help">
                Możesz wpisać nazwę dania, składnik albo imię osoby. Polskie znaki nie mają znaczenia —
                „zurek” znajdzie „żurek”.
            </span>
            <input class="field-input" id="f-q" name="q" type="search" value="{{ $phrase }}"
                   aria-describedby="f-q-help" placeholder="żurek, pierogi, Basia">
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
        {{--
            Ekran wyszukiwania bez frazy nie może kończyć się na samej
            instrukcji — to ślepy zaułek (docs/product/SOUL.md 4.11: pusty
            stan to zaproszenie, nie ściana). Ten sam odnośnik używa już
            `kuking-board.blade.php` i `tags/show.blade.php` w tej samej roli.
        --}}
        <p class="meta">Nie wiesz, od czego zacząć? Zajrzyj do <a href="{{ route('discover') }}">Świeżo z Kuking</a>.</p>
    @elseif($zaKrotka)
        {{--
            Osobny, uczciwy tekst — nie „Nic nie znaleźliśmy" (SearchController
            tłumaczy dlaczego: przy jednym znaku silnik w ogóle nie szukał).
        --}}
        <p class="meta">Fraza „{{ $phrase }}” jest za krótka, żeby zacząć szukać. Wpisz co najmniej dwa znaki.</p>
    @else
        @php
            // Puste jest dopiero wtedy, gdy pusty jest KAŻDY przeszukiwany
            // zakres. Przy „Wszystko" samo zero przepisów nie znaczy jeszcze
            // „nic nie znaleźliśmy" — obok mogą być ludzie.
            $nicNieMa = (! $szukaPrzepisow || $recipes->isEmpty())
                && (! $szukaLudzi || $people->isEmpty());
        @endphp

        @if($nicNieMa)
            {{--
                Tekst domyślnej gałęzi (przepisy/wszystko) jest dosłownym
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
                    <a class="btn btn-primary" href="{{ route('recipes.create') }}">Dodaj taki przepis</a>
                @endif
                <a class="btn btn-quiet" href="{{ route('discover') }}">Zajrzyj do Świeżo z Kuking</a>
            </p>
        @endif

        @if($szukaPrzepisow && $recipes->isNotEmpty())
            @if($section === 'wszystko')
                <h2 class="mt-6">Przepisy</h2>
            @endif

            <p class="meta">
                @if($jestWiecej ?? false)
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
                       href="{{ route('search', ['q' => $phrase, 'sekcja' => $section, 'ile' => $nastepneIle]) }}">
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
                @if($jestWiecejOsob ?? false)
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
                       href="{{ route('search', ['q' => $phrase, 'sekcja' => $section, 'ile' => $nastepneIle]) }}">
                        Pokaż więcej osób
                    </a>
                </p>
            @endif
        @endif
    @endif
</x-layout>
