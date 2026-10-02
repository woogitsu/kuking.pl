{{--
    „Dodać składniki jeszcze raz?” (#27, etap 2, D-333).

    Ostrzeżenie przy ponownym dodaniu składników TEGO SAMEGO przepisu.
    Nic nie jest dopisane, dopóki człowiek nie kliknie „Dodaj jeszcze raz”.
    Zwykły ekran i dwa zwykłe przyciski — działa bez skryptu.
--}}
<x-layout title="Dodać składniki jeszcze raz?" :noindex="true">
    <h1>Te składniki już są na liście</h1>

    <p class="mb-5">
        Składniki przepisu „{{ $recipe->title }}” trafiły na Twoją listę zakupów{{ $maInneListy ? ' „'.$nazwaListy.'”' : '' }} już {{ $kiedy }}
        ({{ $ile }} {{ \App\Support\Odmiana::rzeczownik($ile, 'pozycja', 'pozycje', 'pozycji') }}, część mogła już zostać odhaczona albo usunięta).
        Jeśli dodasz je jeszcze raz, każda pozycja pojawi się drugi raz — łączenia ani sumowania nie robimy.
    </p>

    <form class="panel-formularza" method="POST" action="{{ route('shopping.recipe.store', $recipe->slug) }}">
        @csrf
        <input type="hidden" name="potwierdzam" value="1">
        @if($docelowa !== null)
            <input type="hidden" name="lista" value="{{ $docelowa->getKey() }}">
        @endif
        @if(request()->boolean('z_planera'))
            <input type="hidden" name="z_planera" value="1">
        @endif
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Dodaj jeszcze raz</button>
            <a class="btn btn-secondary" href="{{ $docelowa !== null ? route('shopping.index', ['lista' => $docelowa->getKey()]) : route('shopping.index') }}">Nie dodawaj, otwórz listę</a>
            <a class="btn btn-quiet" href="{{ request()->boolean('z_planera') ? route('planer.show') : route('recipes.show', $recipe->slug) }}">Wróć bez dodawania</a>
        </div>
    </form>
</x-layout>
