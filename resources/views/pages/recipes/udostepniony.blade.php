{{--
    PRZEPIS UDOSTĘPNIONY TEJ OSOBIE — STRONA CZYTANIA (#2650, D-333).

    Sam odczyt: tytuł, autor, zdjęcie, historia przepisu, składniki,
    alergeny i kroki. Celowo BEZ komentarzy, „Ugotowałem", „Zrób swoją
    wersję", zapisu do zeszytu, historii wersji, trybu gotowania, karty QR,
    skanu kartki i JSON-LD — każda z tych dróg pyta `RecipePolicy::view()`,
    którego odbiorca nie ma, więc przycisk byłby martwy (AGENTS.md §5).

    `noindex`: to nie jest strona do znalezienia. Nagłówek `private, no-store`
    dokłada `PreventSharedSessionCache` (zalogowana osoba), więc po odebraniu
    dostępu przeglądarka nie pokaże tej strony z własnego dysku.
--}}
@php
    $czasMinut = $recipe->totalMinutes();
    $porcje = $recipe->servingsLabel();
    $autor = $recipe->author;
@endphp
<x-layout :title="$recipe->title" :noindex="true">
    <article class="kolumna-czytania stack" aria-labelledby="przepis-tytul">
        <p class="meta m-0">Przepis udostępniony Tobie</p>
        <h1 id="przepis-tytul" class="m-0">{{ $recipe->title }}</h1>
        <p class="m-0">Autor: <strong>{{ $autor?->displayName() }}</strong>
            @if($autor?->profile?->username)
                <span class="meta">{{ '@'.$autor->profile->username }}</span>
            @endif
        </p>

        <section class="notice" aria-labelledby="zakres-dostepu" data-zakres-udostepnienia>
            <h2 id="zakres-dostepu" class="mt-0">Kto widzi ten przepis</h2>
            <p>Autor pokazał go Tobie — od {{ \App\Support\Czas::data($udostepnienie->created_at) }}. Nie jest widoczny dla wszystkich i nie znajdziesz go w wyszukiwarce.</p>
            <p class="m-0">Możesz go tu czytać i wydrukować z przeglądarki. Nie da się go skomentować, oznaczyć „Ugotowałem”, zapisać w zeszycie ani zrobić z niego swojej wersji. Autor może w każdej chwili odebrać dostęp.</p>
        </section>

        @if($recipe->family_since_year)
            <p class="meta m-0">W rodzinie od {{ $recipe->family_since_year }}</p>
        @endif
        @if($porcje || $czasMinut)
            <p class="meta m-0">
                @if($porcje){{ $porcje }}@endif
                @if($porcje && $czasMinut) · @endif
                @if($czasMinut)Czas: {{ \App\Support\Czas::czasPrzepisu($czasMinut) }} @endif
            </p>
        @endif

        @if($recipe->heroMedia)
            <div class="przepis-hero-zdjecie">
                <x-photo :media="$recipe->heroMedia" variant="large" :priority="true" class="post-photo" tresc="przepis" />
            </div>
        @endif

        @if($recipe->source_note)
            <section class="recipe-story" aria-labelledby="skad-przepis">
                <h2 id="skad-przepis">Skąd ten przepis</h2>
                <p class="whitespace-pre-line m-0">{{ $recipe->source_note }}</p>
            </section>
        @endif

        <section aria-labelledby="skladniki">
            <h2 id="skladniki">Składniki</h2>
            @if($recipe->ingredients->isEmpty())
                <p class="meta">Autor nie dodał składników.</p>
            @else
                @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($recipe->ingredients) as $grupa)
                    @if($grupa['nazwa'] !== null)
                        <h3 class="naglowek-grupy">{{ $grupa['nazwa'] }}</h3>
                    @endif
                    <ul class="ingredient-list">
                        @foreach($grupa['skladniki'] as $skladnik)
                            <li>{{ $skladnik->ingredient_text }}@if($skladnik->note)<span class="meta"> — {{ $skladnik->note }}</span>@endif @if($skladnik->substitutes)<span class="skladnik-zamiennik">Zamiast tego: {{ $skladnik->substitutes }}</span>@endif</li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
            @if(config('kuking.alergeny.wlaczone'))
                <x-alergeny.blok :recipe="$recipe" />
            @endif
        </section>

        <section aria-labelledby="przygotowanie">
            <h2 id="przygotowanie">Przygotowanie</h2>
            @if($recipe->steps->isEmpty())
                <p class="meta">Autor nie opisał przygotowania.</p>
            @else
                <ol class="step-list">
                    @foreach($recipe->steps as $step)
                        <li>
                            <span class="step-number" aria-hidden="true">{{ $step->position + 1 }}</span>
                            <div>
                                <span class="visually-hidden">Krok {{ $step->position + 1 }}.</span>
                                <p class="m-0 whitespace-pre-line">{{ $step->instruction }}</p>
                                @if($step->timerLabel())
                                    <p class="m-0">Czas kroku: {{ $step->timerLabel() }}</p>
                                @endif
                                @if($step->media)
                                    <div class="mt-3 max-w-[20rem]">
                                        <x-photo :media="$step->media" variant="feed" class="post-photo"
                                                 :alt="$step->media->alt_text ?: 'Zdjęcie do kroku '.($step->position + 1)"
                                                 tresc="przepis" />
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <section class="panel-formularza mt-8" aria-labelledby="nie-chce-dostepu">
            <h2 id="nie-chce-dostepu" class="mt-0">Nie potrzebujesz już tego przepisu?</h2>
            <p>Przepis zniknie z Twojej listy „Przepisy udostępnione mi”. Autor może udostępnić go ponownie.</p>
            <x-confirm-button
                :action="route('recipes.shared.leave', $udostepnienie)"
                label="Zrezygnuj z dostępu"
                question="Zrezygnować z dostępu do tego przepisu? Nie otworzysz go już, dopóki autor nie udostępni go ponownie." />
        </section>

        <p><a class="btn btn-secondary" href="{{ route('recipes.shared.index') }}">Wszystkie przepisy udostępnione mi</a></p>
    </article>
</x-layout>
