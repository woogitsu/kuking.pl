{{--
    „PRZEPISY UDOSTĘPNIONE MI" (#2650, D-333).

    Lista przepisów, które ktoś pokazał wyłącznie tej osobie. Kontroler
    przepuszcza każdy wiersz przez `RecipePolicy::readShared()`, więc nie ma
    tu pozycji prowadzącej w odmowę. Udostępnienie daje jedno powiadomienie
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
            @foreach($udostepnienia as $udostepnienie)
                <li class="card">
                    <p class="m-0 font-bold"><a href="{{ route('recipes.shared.show', $udostepnienie->recipe) }}">{{ $udostepnienie->recipe->title }}</a></p>
                    <p class="meta m-0">Od: {{ $udostepnienie->recipe->author?->displayName() }} · udostępniony {{ \App\Support\Czas::data($udostepnienie->created_at) }}</p>
                    <p class="mt-2 mb-0"><a class="btn btn-secondary" href="{{ route('recipes.shared.show', $udostepnienie->recipe) }}">Czytaj przepis</a></p>
                </li>
            @endforeach
        </ul>
    @endif

    <a class="btn btn-secondary mt-8" href="{{ route('collections.index') }}">Wróć do „Moje”</a>
</x-layout>
