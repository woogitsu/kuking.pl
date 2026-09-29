{{--
    Jedna pozycja listy zakupów. Trzy formularze bez skryptu: odhacz /
    cofnij odhaczenie oraz usuń. Nazwa pozycji jest w ukrytym dopisku
    przycisku, żeby czytnik ekranu nie czytał dziesięć razy samego „Usuń”.
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
    </span>
    <span class="planer-nawigacja">
        <form method="POST" action="{{ route('shopping.toggle', $pozycja) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="odhaczona" value="{{ $odhaczona ? 0 : 1 }}">
            <button class="btn btn-secondary" type="submit">{{ $odhaczona ? 'Cofnij odhaczenie' : 'Odhacz' }}<span class="visually-hidden">: {{ $pozycja->text }}</span></button>
        </form>
        <form method="POST" action="{{ route('shopping.destroy', $pozycja) }}">
            @csrf @method('DELETE')
            <button class="btn btn-secondary" type="submit">Usuń<span class="visually-hidden">: {{ $pozycja->text }}</span></button>
        </form>
    </span>
</li>
