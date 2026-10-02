{{--
    Jedna pozycja listy zakupów. Odhaczenie to zwykły formularz. Usunięcie
    wymaga otwarcia pytania przez <details>; dopiero wewnętrzny przycisk
    wysyła DELETE. Ponowne naciśnięcie <summary> zamyka pytanie bez żądania.
--}}
@php
    /** @var \App\Models\ShoppingListItem $pozycja */
    $pozycja = $wiersz['pozycja'];
@endphp
<li class="planer-pozycja" id="pozycja-{{ $pozycja->getKey() }}">
    <span class="planer-pozycja-tresc">
        {{ $pozycja->text }}<br>
        <span class="meta">
            @switch($wiersz['stan'])
                @case(\App\Domain\Zakupy\ListaZakupow::STAN_PRZEPIS)
                    Z przepisu: <a href="{{ route('recipes.show', $wiersz['przepis']->slug) }}">{{ $wiersz['przepis']->title }}</a>
                    @break
                @case(\App\Domain\Zakupy\ListaZakupow::STAN_NIEDOSTEPNY)
                    Z przepisu. Przepis jest już niedostępny.
                    @break
                @case(\App\Domain\Zakupy\ListaZakupow::STAN_USUNIETY)
                    Z przepisu. Przepis został usunięty.
                    @break
                @default
                    Dopisane ręcznie
            @endswitch
        </span>
        @if($pozycja->scaled_servings !== null)
            {{-- Przeliczona kopia nie udaje dosłownej linii autora (#2489). --}}
            <br><span class="meta">Ilość przeliczona z przepisu na {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($pozycja->scaled_servings) }}.</span>
        @endif
    </span>
    <div class="planer-nawigacja">
        <form method="POST" action="{{ route('shopping.toggle', $pozycja) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="odhaczona" value="{{ $odhaczona ? 0 : 1 }}">
            <button class="btn btn-secondary" type="submit">{{ $odhaczona ? 'Cofnij odhaczenie' : 'Odhacz' }}<span class="visually-hidden">: {{ $pozycja->text }}</span></button>
        </form>
        <details class="confirm planer-usuwanie">
            <summary class="btn btn-secondary confirm-summary">
                <span class="planer-usuwanie-otworz">Usuń</span>
                <span class="planer-usuwanie-zamknij">Nie usuwaj</span>
                <span class="visually-hidden"> z listy: {{ $pozycja->text }}</span>
            </summary>
            <div class="confirm-body">
                <p class="confirm-question">Usunąć tę pozycję z listy zakupów?</p>
                <p class="confirm-question-nazwa"><strong>{{ $pozycja->text }}</strong></p>
                <form method="POST" action="{{ route('shopping.destroy', $pozycja) }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger" type="submit">Tak, usuń tę pozycję</button>
                </form>
            </div>
        </details>
    </div>
</li>
