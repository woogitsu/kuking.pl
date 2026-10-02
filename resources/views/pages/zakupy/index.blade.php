{{--
    Lista zakupów (#27, etap 2, D-333). Prywatna, `noindex`.

    Każda akcja to zwykły formularz z przyciskiem ≥ 48 px — ekran działa
    bez skryptu, bez przeciągania i bez gestów. Pozycje niezałatwione
    i odhaczone stoją w DWÓCH osobnych listach: znaczenie nie opiera się
    na samym przekreśleniu ani na kolorze.

    Pozycja jest tekstem. Skopiowana z przepisu niesie napis, z którego;
    tytuł i link są tylko wtedy, gdy właściciel listy wciąż widzi przepis
    (`ListaZakupow::pozycje`) — inaczej „Przepis jest już niedostępny.”.
    Pozycja NIE znika razem z przepisem.

    „Cofnij usunięcie” (#2630): po „Usuń” albo „Wyczyść odhaczone” u góry stoi
    TRWAŁA (nie znikająca po kilku sekundach) informacja z przyciskiem, aż do
    `cofnijDo`; odświeżenie jej nie kasuje. Zwykły formularz POST, bez skryptu.
--}}
<x-layout title="Lista zakupów" :noindex="true">
    <h1>Lista zakupów</h1>
    <p class="mb-5">Tę listę widzisz tylko Ty. Składniki z przepisu dopisujesz przyciskiem „Dodaj składniki” na stronie przepisu albo w planerze — trafiają tu dokładnie tak, jak je napisał autor, bez sumowania.</p>

    <x-error-summary />

    @if($cofniecie !== null)
        <section class="card mb-5" id="cofnij" aria-labelledby="cofnij-naglowek">
            <h2 class="mt-0" id="cofnij-naglowek">Pomyłkowo usunięte? Możesz to cofnąć</h2>
            <p>
                @if($cofniecie->scope === \App\Models\ShoppingListUndo::SCOPE_SINGLE)
                    Z listy usunięto pozycję: <strong>{{ $cofniecie->items[0]['text'] }}</strong>.
                @else
                    Z listy usunięto {{ $cofniecie->items_count }} {{ \App\Support\Odmiana::rzeczownik($cofniecie->items_count, 'odhaczoną pozycję', 'odhaczone pozycje', 'odhaczonych pozycji') }}.
                @endif
                Przycisk poniżej działa do godziny {{ $cofnijDo }}, potem usunięte pozycje znikną na stałe.
            </p>
            <p class="meta">Wrócą tylko te usunięte pozycje, na swoje miejsca i z odhaczeniem, jakie miały. To, co dopisano w międzyczasie, zostanie bez zmian. Kolejne usunięcie zastąpi tę możliwość.</p>
            <form method="POST" action="{{ route('shopping.undo') }}">
                @csrf
                <button class="btn btn-primary" type="submit">Cofnij usunięcie</button>
            </form>
        </section>
    @endif

    <section class="card mb-5" id="dopisz" aria-labelledby="dopisz-naglowek">
        <h2 class="mt-0" id="dopisz-naglowek">Dopisz pozycję</h2>
        <form class="planer-dopisz" method="POST" action="{{ route('shopping.store') }}">
            @csrf
            <x-field name="text" label="Co trzeba kupić?" :bez-oznaczenia="true" autocomplete="off"
                     help="Na przykład „mleko” albo „2 cebule”. Najwyżej {{ $maksZnakow }} znaków." />
            <button class="btn btn-primary" type="submit">Dopisz do listy</button>
        </form>
    </section>

    @if($ile === 0)
        <p>Lista zakupów jest jeszcze pusta. Dopisz pierwszą pozycję powyżej albo otwórz przepis i wybierz „Dodaj składniki do listy zakupów”.</p>
        <p class="mt-4"><a class="btn btn-secondary" href="{{ route('planer.show') }}">Planer tygodnia</a></p>
    @else
        <p class="meta mb-5" role="status">Na liście: {{ $ile }} z {{ $maksPozycji }} możliwych pozycji.</p>

        <section class="mb-6" aria-labelledby="do-kupienia-naglowek">
            <h2 id="do-kupienia-naglowek">Do kupienia ({{ count($do_kupienia) }})</h2>
            @if($do_kupienia === [])
                <p class="meta">Wszystko odhaczone. Możesz usunąć kupione pozycje przyciskiem niżej.</p>
            @else
                <ul class="planer-pozycje">
                    @foreach($do_kupienia as $wiersz)
                        @include('pages.zakupy._pozycja', ['wiersz' => $wiersz, 'odhaczona' => false])
                    @endforeach
                </ul>
                {{-- Kartka papierowa tylko z tym, co jeszcze do kupienia (#2495). --}}
                <p class="mt-4"><a class="btn btn-secondary" href="{{ route('shopping.print') }}" data-rola="wydrukuj-do-kupienia">Wydrukuj do kupienia</a></p>
            @endif
        </section>

        <section class="mb-6" aria-labelledby="odhaczone-naglowek">
            <h2 id="odhaczone-naglowek">Odhaczone ({{ count($odhaczone) }})</h2>
            @if($odhaczone === [])
                <p class="meta">Nic jeszcze nie odhaczone. Odhaczona pozycja przechodzi tu i nie znika, dopóki jej nie usuniesz.</p>
            @else
                <ul class="planer-pozycje">
                    @foreach($odhaczone as $wiersz)
                        @include('pages.zakupy._pozycja', ['wiersz' => $wiersz, 'odhaczona' => true])
                    @endforeach
                </ul>

                {{-- Akcja masowa: osobno od zwykłych przycisków, z pytaniem —
                     `<details>` otwiera się bez skryptu. Po niej przez kilka
                     minut działa „Cofnij usunięcie” (#2630). --}}
                <details class="mt-5">
                    <summary class="btn btn-secondary">Wyczyść odhaczone</summary>
                    <form class="mt-3" method="POST" action="{{ route('shopping.clear') }}">
                        @csrf @method('DELETE')
                        <p class="mt-0">Usuniemy z listy {{ count($odhaczone) }} {{ \App\Support\Odmiana::rzeczownik(count($odhaczone), 'odhaczoną pozycję', 'odhaczone pozycje', 'odhaczonych pozycji') }}. Pozycji do kupienia nie ruszamy. Jeśli to pomyłka, przez {{ \App\Domain\Zakupy\ListaZakupow::minutCofniecia() }} minut możesz to cofnąć u góry listy.</p>
                        <button class="btn btn-primary" type="submit">Tak, usuń odhaczone</button>
                    </form>
                </details>
            @endif
        </section>

        <p><a class="btn btn-secondary" href="{{ route('planer.show') }}">Planer tygodnia</a></p>
    @endif
</x-layout>
