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
        <button class="btn btn-primary" type="submit" style="margin-top:var(--spacing-4);">Szukaj</button>
    </form>

    <nav class="tabs" style="margin-top:var(--spacing-6);" aria-label="Co przeszukujemy">
        <a class="tab" href="{{ route('search', ['q' => $phrase, 'sekcja' => 'przepisy']) }}" @if($section === 'przepisy') aria-current="page" @endif>Przepisy</a>
        <a class="tab" href="{{ route('search', ['q' => $phrase, 'sekcja' => 'ludzie']) }}" @if($section === 'ludzie') aria-current="page" @endif>Ludzie</a>
    </nav>

    @if($phrase === '')
        <p class="meta">Wpisz coś w pole powyżej i kliknij „Szukaj”.</p>
    @elseif($section === 'przepisy')
        @if($recipes->isEmpty())
            <x-empty-state title="Nic nie znaleźliśmy">
                Nie ma jeszcze przepisu, który by pasował do „{{ $phrase }}”.
                Może to Ty go dodasz?
            </x-empty-state>
            <p style="text-align:center;"><a class="btn btn-primary" href="{{ route('recipes.create') }}">Dodaj taki przepis</a></p>
        @else
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
                <p style="text-align:center;">
                    <a class="btn btn-quiet"
                       href="{{ route('search', ['q' => $phrase, 'sekcja' => 'przepisy', 'ile' => $nastepneIle]) }}">
                        Pokaż więcej przepisów
                    </a>
                </p>
            @endif
        @endif
    @else
        @if($people->isEmpty())
            <x-empty-state title="Nikogo nie znaleźliśmy">Nie ma tu osoby o nazwie „{{ $phrase }}”.</x-empty-state>
        @else
            <div class="stack-tight">
                @foreach($people as $person)
                    <div class="card" style="display:flex; gap:var(--spacing-3); align-items:center;">
                        <x-avatar :user="$person->user" :size="52" />
                        <div>
                            <a class="author-name" href="{{ route('profile.show', $person->username) }}">{{ $person->display_name }}</a>
                            <p class="meta" style="margin:0;">&#64;{{ $person->username }} @if($person->speciality) · {{ $person->speciality }} @endif</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-layout>
