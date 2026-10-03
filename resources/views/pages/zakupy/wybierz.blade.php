{{--
    „Wybierz składniki do zakupów” (#2462, V2). Prywatny, `noindex`.

    Opcjonalna droga obok „Dodaj składniki do listy zakupów” (wszystkie linie
    jednym przyciskiem na stronie przepisu zostaje bez zmian). Przy każdej
    linii składnika jest pole wyboru z widoczną etykietą — dokładnie ten tekst,
    który stoi w przepisie, w kolejności grup i składników ze strony przepisu.
    „Dodaj wybrane” kopiuje wskazane linie bez parsowania, skalowania i
    sumowania; takie same teksty w różnych liniach to osobne linie.

    Zwykły formularz bez skryptu. Ukryte pole `odcisk` to skrót listy linii z
    chwili otwarcia strony: jeśli przepis zmienił się w międzyczasie, akcja
    niczego nie dopisze i pokaże tę stronę od nowa z zachowanym zaznaczeniem.
    Wysłane identyfikatory nie są treścią — tekst czyta serwer z przepisu.
    Sama ta strona (GET) nic nie dodaje.

    Po ostrzeżeniu „te składniki już są na liście” (`$wczesniej`) ta sama
    strona wraca z tym samym zaznaczeniem i przyciskiem „Dodaj wybrane jeszcze
    raz”; ukryte `potwierdzam` idzie wtedy razem z formularzem.
--}}
<x-layout :title="'Wybierz składniki: '.$recipe->title" :noindex="true">
    <h1>Wybierz składniki do zakupów</h1>

    <p class="mb-5">
        Przepis: <strong>{{ $recipe->title }}</strong>.
        Zaznacz tylko te składniki, których potrzebujesz — dopiszemy je do Twojej listy zakupów dokładnie tak, jak stoją w przepisie, bez sumowania. Tę listę widzisz tylko Ty.
    </p>

    @php
        $celeBledow = ['skladniki' => 'f-skladniki', 'text' => 'f-skladniki', 'odcisk' => 'f-skladniki', 'lista' => 'f-lista-zakupow-wybor'];
        $zaznaczone = array_map('strval', array_filter((array) old('skladniki', []), 'is_string'));
        $bladWyboru = $errors->first('skladniki') ?: $errors->first('text') ?: $errors->first('odcisk');
        $ostrzezenie = $wczesniej !== null;
    @endphp
    <x-error-summary :field-ids="$celeBledow" />

    @if($ostrzezenie)
        <section class="card mb-5" role="alert" aria-labelledby="juz-jest-naglowek">
            <h2 class="mt-0" id="juz-jest-naglowek">Składniki tego przepisu już są na liście</h2>
            <p>
                Składniki przepisu „{{ $recipe->title }}” trafiły na Twoją listę zakupów{{ $maInneListy ? ' „'.$nazwaListy.'”' : '' }} już {{ \App\Support\Czas::data($wczesniej, 'j F') }}
                (część mogła już zostać odhaczona albo usunięta). Jeśli dodasz zaznaczone jeszcze raz, każda z nich pojawi się drugi raz — łączenia ani sumowania nie robimy.
                Nic nie zostało dodane. Sprawdź zaznaczenie poniżej i naciśnij „Dodaj wybrane jeszcze raz” albo wróć bez dodawania.
            </p>
        </section>
    @endif

    @if($ile === 0)
        <p>Ten przepis nie ma jeszcze składników, więc nie ma czego dodać do listy zakupów.</p>
        <p class="mt-4"><a class="btn btn-secondary" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a></p>
    @else
        <form class="panel-formularza" method="POST" action="{{ route('shopping.recipe.pick.store', $recipe) }}" novalidate>
            @csrf
            <input type="hidden" name="odcisk" value="{{ $odcisk }}">
            @if($zPlanera)
                <input type="hidden" name="z_planera" value="1">
            @endif
            @if($ostrzezenie)
                <input type="hidden" name="potwierdzam" value="1">
                {{-- Potwierdzenie dotyczy listy z ostrzeżenia (#2528); inna lista = nowe pytanie. --}}
                <input type="hidden" name="potwierdzona_lista" value="{{ $docelowa?->getKey() }}">
            @endif

            <fieldset class="border-0 p-0" id="f-skladniki"
                      @if($bladWyboru) tabindex="-1" aria-invalid="true" aria-describedby="f-skladniki-error" @endif>
                <legend class="font-bold mb-3">Które składniki dopisać do listy zakupów?</legend>
                @foreach($grupy as $grupa)
                    @if($grupa['nazwa'] !== null)
                        <h2 class="mt-5 mb-3">{{ $grupa['nazwa'] }}</h2>
                    @endif
                    <div class="choice-grid">
                        @foreach($grupa['skladniki'] as $linia)
                            <label class="choice">
                                <input type="checkbox" name="skladniki[]" value="{{ $linia['id'] }}" @checked(in_array($linia['id'], $zaznaczone, true))>
                                <span><span class="choice-label">{{ $linia['tekst'] }}</span></span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
                @if($bladWyboru)
                    <span class="field-error" id="f-skladniki-error">{{ $bladWyboru }}</span>
                @endif
            </fieldset>

            <x-zakupy-wybor-listy wiersz="wybor" :wybrana="$wybranaLista" :blad="true" />

            <p class="meta">Na liście zakupów mieści się najwyżej {{ $maksPozycji }} pozycji. Zaznaczone składniki dopiszemy wszystkie albo żaden.</p>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ $ostrzezenie ? 'Dodaj wybrane jeszcze raz' : 'Dodaj wybrane' }}</button>
                <a class="btn btn-quiet" href="{{ $zPlanera ? route('planer.show') : route('recipes.show', $recipe->slug) }}">Wróć bez dodawania</a>
            </div>
        </form>
    @endif
</x-layout>
