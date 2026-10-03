{{--
    „PRZEPISY UDOSTĘPNIONE MI" (#2650, D-333).

    Lista przepisów, które ktoś pokazał wyłącznie tej osobie. Kontroler
    pokazuje dane przepisu tylko po `RecipePolicy::readShared()`. Gdy odczyt
    jest zamknięty, zostaje anonimowa pozycja z rezygnacją z własnego grantu.
    Udostępnienie daje jedno powiadomienie
    w serwisie (`recipe.shared`, bez listu i Web Push); ta lista (i odnośnik
    w „Moje") jest stałym miejscem, gdzie przepis na odbiorcę czeka.
--}}
<x-layout title="Przepisy udostępnione mi" :noindex="true">
    <h1>Przepisy udostępnione mi</h1>
    <p>Te przepisy autorzy pokazali tylko Tobie. Nie widzi ich nikt inny poza nimi, a autor może w każdej chwili odebrać dostęp.</p>

    @if($udostepnienia->isEmpty())
        <p class="notice">Na razie nikt nie udostępnił Ci przepisu. Gdy ktoś to zrobi, przepis pojawi się tutaj.</p>
    @else
        <ul class="stack list-none p-0" data-udostepnione-mi>
            @foreach($udostepnienia as $pozycja)
                <li class="card">
                    @if($pozycja['czytelny'])
                        <p class="m-0 font-bold"><a href="{{ route('recipes.shared.show', $pozycja['grant']->recipe) }}">{{ $pozycja['grant']->recipe->title }}</a></p>
                        <p class="meta m-0">Od: {{ $pozycja['grant']->recipe->author?->displayName() }} · udostępniony {{ \App\Support\Czas::data($pozycja['grant']->created_at) }}</p>
                        <p class="mt-2 mb-0"><a class="btn btn-secondary" href="{{ route('recipes.shared.show', $pozycja['grant']->recipe) }}">Czytaj przepis</a></p>
                    @else
                        <p class="m-0 font-bold">Udostępniony przepis jest teraz niedostępny</p>
                        <p class="meta m-0">Nie możesz go teraz przeczytać. Możesz zrezygnować z dostępu.</p>
                        <div class="mt-3">
                            <x-confirm-button
                                :action="route('recipes.shared.leave', $pozycja['grant'])"
                                label="Zrezygnuj z dostępu"
                                question="Zrezygnować z dostępu do tej pozycji? Nie otworzysz jej już, dopóki autor nie udostępni jej ponownie." />
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <a class="btn btn-secondary mt-8" href="{{ route('collections.index') }}">Wróć do „Moje”</a>
</x-layout>
