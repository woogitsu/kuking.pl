{{--
    „WYBIERZ PRZEPISY DO WYDRUKU” (#2463). Zwykły formularz GET: lista tytułów
    z polami wyboru prowadzi do podglądu wydruku z wybranymi przepisami.
    Działa bez JavaScriptu i z klawiatury. Wybór niczego nie zmienia w zeszycie.
    Dostęp i widoczność: `CollectionPrintSelectionController`.
--}}
<x-layout :title="'Wybierz przepisy do wydruku — „'.$collection->name.'”'" :noindex="true">
    <h1>Wybierz przepisy do wydruku</h1>
    <p class="mb-3">
        Zaznacz przepisy z zeszytu „{{ $collection->name }}”, które chcesz mieć na papierze.
        Okładka, spis treści i liczba przepisów będą dotyczyć tylko Twojego wyboru.
        Na jednym wydruku zmieści się najwyżej {{ $limit }} {{ \App\Support\Odmiana::rzeczownik($limit, 'przepis', 'przepisy', 'przepisów') }}.
    </p>

    @if($pozycje->isEmpty())
        <p class="notice">W tym zeszycie nie ma przepisów, które możesz wydrukować.</p>
        <p><a class="btn btn-secondary" href="{{ route('collections.show', $collection) }}">Wróć do zeszytu</a></p>
    @else
        <form class="panel-formularza" method="GET" action="{{ route('collections.print', $collection) }}" novalidate>
            <input type="hidden" name="tryb" value="wybrane">
            @foreach($opcje as $nazwa => $wartosc)
                <input type="hidden" name="{{ $nazwa }}" value="{{ $wartosc }}">
            @endforeach
            <fieldset class="border-0 p-0" id="f-przepisy">
                <legend class="font-bold mb-3">Przepisy w zeszycie ({{ $pozycje->count() }})</legend>
                <div class="stack">
                    @foreach($pozycje as $pozycja)
                        <label class="choice" for="f-przepis-{{ $pozycja->getKey() }}">
                            <input id="f-przepis-{{ $pozycja->getKey() }}" type="checkbox" name="przepisy[]" value="{{ $pozycja->getKey() }}" @checked(isset($zaznaczone[(string) $pozycja->getKey()]))>
                            <span class="choice-label">{{ $pozycja->title }}</span>
                        </label>
                    @endforeach
                </div>
                @if($obcieto)
                    <p class="meta mt-3">Pokazujemy pierwszych {{ $maks }} przepisów w kolejności wydruku. Resztę wydrukujesz osobno, przyciskiem „Drukuj przepis” na stronie przepisu.</p>
                @endif
            </fieldset>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Pokaż wybrane do druku</button>
                <a class="btn btn-quiet" href="{{ route('collections.print', ['collection' => $collection] + $opcje) }}">Wydrukuj cały zeszyt</a>
            </div>
        </form>
    @endif
</x-layout>
