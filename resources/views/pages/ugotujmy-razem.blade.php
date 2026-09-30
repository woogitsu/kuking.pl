{{--
    „Ugotujmy razem” (F3) — jeden przepis tygodnia wybrany przez gospodarza
    i wykonania („Ugotowałem”) z tego tygodnia.

    Dobór (AGENTS.md §8): przepis to jawny WYBÓR GOSPODARZA, wykonania idą
    wyłącznie PO CZASIE, od najnowszego. Świadomie bez licznika wykonań, bez
    odliczania do końca tygodnia i bez nagród — „bez liczników presji”.

    To nie jest grupa (#22): nie ma dołączania ani listy uczestników. Udziałem
    jest zwykłe „Ugotowałem” pod tym przepisem w tym tygodniu.

    Zwykłe odnośniki i przyciski `.btn` (48 px) z tekstem — działa bez skryptu;
    „Pokaż więcej” bez skryptu jest odnośnikiem do następnej strony.
--}}
@php
    $tytulStrony = $biezacy ? 'Ugotujmy razem' : 'Ugotujmy razem — tydzień '.$tydzien->opis();
    $opisStrony = $pick !== null
        ? ($biezacy
            ? 'W tym tygodniu gotujemy razem: '.$pick->recipe->title.'. Zobacz przepis i zdjęcia od ludzi, którzy już go zrobili.'
            : 'Tydzień '.$tydzien->opis().': gotowaliśmy razem '.$pick->recipe->title.'. Zdjęcia od ludzi, którzy go zrobili.')
        : 'Co tydzień jeden przepis, który gotujemy razem, i zdjęcia od ludzi, którzy już go zrobili.';
@endphp
<x-layout :title="$tytulStrony" :description="$opisStrony">
    <h1>Ugotujmy razem</h1>

    <p class="text-lead">
        Co tydzień gospodarz wybiera jeden przepis. Gotuje, kto chce — bez zapisów.
        Po ugotowaniu dodaj zdjęcie i kilka słów, a pojawią się tutaj.
    </p>

    @unless($biezacy)
        <p class="notice" data-archiwum-tygodnia>
            To jest archiwum: tydzień {{ $tydzien->opis() }}.
            <a href="{{ route('ugotujmy-razem') }}">Zobacz ten tydzień</a>.
        </p>
    @endunless

    @if($pick !== null)
        <section class="sekcja-strony stack" aria-labelledby="przepis-tygodnia" data-przepis-tygodnia>
            <p class="meta m-0" data-wybor-gospodarza>Wybór gospodarza</p>
            <h2 id="przepis-tygodnia" class="m-0">
                @if($biezacy)
                    W tym tygodniu gotujemy razem
                @else
                    W tamtym tygodniu gotowaliśmy razem
                @endif
            </h2>

            <x-recipe-card :recipe="$pick->recipe" />

            <div class="form-actions">
                @if($biezacy)
                    <a class="btn btn-primary" href="{{ route('cooking.show', $pick->recipe->slug) }}">Ugotuję w tym tygodniu</a>
                @endif
                <a class="btn btn-secondary" href="{{ route('recipes.show', $pick->recipe->slug) }}">Zobacz cały przepis</a>
            </div>
            @if($biezacy)
                @can('cook', $pick->recipe)
                    <p class="m-0">Już ugotowane? <a href="{{ route('cooked.create', $pick->recipe->slug) }}">Dodaj zdjęcie i kilka słów</a>.</p>
                @endcan
            @endif
        </section>

        <section class="stack mt-8" aria-labelledby="wykonania-tygodnia" data-wykonania-tygodnia>
            <h2 id="wykonania-tygodnia" class="m-0">
                {{ $biezacy ? 'Wykonania z tego tygodnia' : 'Wykonania z tamtego tygodnia' }}
            </h2>

            @if($wykonania->isNotEmpty())
                <p class="meta m-0">Od najnowszego. Kolejność to tylko czas dodania.</p>
                <div class="stack" id="lista-wykonan-tygodnia">
                    @foreach($wykonania as $event)
                        <x-cooked-card :event="$event" />
                    @endforeach
                </div>
                <x-show-more :paginator="$wykonania" czego="wykonań" lista="lista-wykonan-tygodnia" />
            @elseif($biezacy)
                <x-empty-state title="Tu pojawią się zdjęcia z tego tygodnia">
                    <span>Kto ugotuje ten przepis i doda zdjęcie, pojawi się na tej stronie.</span>
                </x-empty-state>
            @else
                <x-empty-state title="Nie ma tu widocznych wykonań z tamtego tygodnia" :mark="false" />
            @endif
        </section>
    @else
        <x-empty-state title="W tym tygodniu nie ma jeszcze wspólnego przepisu" action="Zobacz, co ludzie gotują" :href="route('discover')">
            <span>Gospodarz wybiera jeden przepis na tydzień. Zajrzyj tu znowu za kilka dni.</span>
        </x-empty-state>
    @endif

    @if($archiwum->isNotEmpty())
        <section class="sekcja-strony mt-8" aria-labelledby="poprzednie-tygodnie" data-archiwum>
            <h2 id="poprzednie-tygodnie">Poprzednie tygodnie</h2>
            <ul class="stack lista-naga" id="lista-tygodni">
                @foreach($archiwum as $poprzedni)
                    <li data-klucz="tydzien-{{ $poprzedni->getKey() }}">
                        <a href="{{ route('ugotujmy-razem.tydzien', $poprzedni->tydzien()->iso()) }}">{{ $poprzedni->recipe->title }}</a>
                        <span class="meta">— tydzień {{ $poprzedni->tydzien()->opis() }}</span>
                    </li>
                @endforeach
            </ul>
            <x-show-more :paginator="$archiwum" czego="tygodni" lista="lista-tygodni" />
        </section>
    @endif
</x-layout>
