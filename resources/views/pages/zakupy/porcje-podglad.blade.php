{{--
    „Dodaj składniki na wybraną liczbę porcji” — podgląd (V2, #2489).

    GET, bez zapisu. Pokazuje każdą linię autora obok tego, co trafi na listę,
    i wprost mówi, które linie zostają takie, jak napisał autor (wymagają
    samodzielnego sprawdzenia). Zatwierdzenie odsyła odcisk podglądu: gdy
    przepis zmieni się w międzyczasie, nic się nie dopisze. Ilości autora
    (bez przeliczenia) są dalej dostępne drugim przyciskiem.
--}}
<x-layout title="Składniki na wybraną liczbę porcji" :noindex="true">
    <h1>Składniki na {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wybor->wybrane) }}</h1>

    <p class="mb-5">
        Przepis „{{ $recipe->title }}” jest na {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wybor->zPrzepisu) }}.
        Poniżej widzisz, co trafi na Twoją listę zakupów. Jeszcze niczego nie dodaliśmy.
        Przeliczamy tylko ilości, które da się przeliczyć; reszta zostaje taka, jak napisał autor.
    </p>

    <ul class="planer-pozycje mb-5">
        @foreach($linie as $linia)
            <li class="planer-pozycja">
                <span class="planer-pozycja-tresc">
                    @if($linia['przeliczona'])
                        <strong>{{ $linia['tekst'] }}</strong><br>
                        <span class="meta">U autora: {{ $linia['oryginal'] }}</span>
                    @else
                        {{ $linia['tekst'] }}<br>
                        <span class="meta">Bez przeliczenia, jak u autora. {{ $linia['uwaga'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>

    <p class="meta mb-5" role="status">
        Przeliczone ilości: {{ $przeliczono }} z {{ count($linie) }}.
        @if($przeliczono < count($linie))
            Pozostałe linie sprawdź samodzielnie.
        @endif
        Nie zmieniamy czasu, temperatury ani wielkości naczynia.
    </p>

    <form class="panel-formularza" method="POST" action="{{ route('shopping.recipe.store', $recipe->slug) }}" novalidate>
        @csrf
        <input type="hidden" name="porcje" value="{{ $porcje }}">
        <input type="hidden" name="odcisk" value="{{ $odcisk }}">
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Dodaj składniki na {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wybor->wybrane) }}</button>
        </div>
    </form>

    <form method="POST" action="{{ route('shopping.recipe.store', $recipe->slug) }}" novalidate>
        @csrf
        <button class="btn btn-secondary" type="submit">Dodaj ilości autora ({{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wybor->zPrzepisu) }})</button>
        <a class="btn btn-quiet" href="{{ route('recipes.show', ['recipe' => $recipe->slug, 'porcje' => $porcje]) }}">Wróć do przepisu bez dodawania</a>
    </form>
</x-layout>
