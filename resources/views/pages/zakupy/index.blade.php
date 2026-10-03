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

    NAZWANE LISTY (#2528): kto ma tylko listę domyślną („Na co dzień”), widzi
    ekran jak dotąd plus jedno zwinięte „Nowa lista”. Kto założył kolejną, ma
    u góry przyciski-zakładki (zwykłe linki z `?lista=`), podpis „Wybrana
    lista” i formularz dopisywania z jawnym „Dopisujesz do listy: …”. Każdy
    formularz niesie listę w ukrytym polu `lista`; pusta wartość = domyślna.
--}}
@php
    $wieleList = count($zakladki) > 1;
    $idWybranej = $wybrana?->getKey();
    $blad_nazwy = $errors->has('nazwa');
    $blad_nowej_nazwy = $errors->has('nowa_nazwa');
    $blad_potwierdzenia = $errors->has('potwierdzam');
@endphp
<x-layout title="Lista zakupów" :noindex="true">
    <h1>Lista zakupów</h1>
    <p class="mb-5">Tę listę widzisz tylko Ty. Składniki z przepisu dopisujesz przyciskiem „Dodaj składniki” na stronie przepisu albo w planerze — trafiają tu dokładnie tak, jak je napisał autor, bez sumowania.</p>

    <x-error-summary :field-ids="['potwierdzam' => 'f-potwierdzam']" />

    @if($wieleList)
        <nav class="mb-5" aria-labelledby="listy-naglowek">
            <h2 class="mt-0" id="listy-naglowek">Twoje listy zakupów</h2>
            <ul class="planer-nawigacja" role="list">
                @foreach($zakladki as $zakladka)
                    @php($jestWybrana = ($zakladka['lista']?->getKey()) === $idWybranej)
                    <li>
                        <a class="btn {{ $jestWybrana ? 'btn-primary' : 'btn-secondary' }}"
                           href="{{ $zakladka['lista'] === null ? route('shopping.index') : route('shopping.index', ['lista' => $zakladka['lista']->getKey()]) }}"
                           @if($jestWybrana) aria-current="page" @endif>
                            {{ $zakladka['nazwa'] }} ({{ $zakladka['do_kupienia'] }} do kupienia)
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <h2 class="mt-0" id="wybrana-lista">Wybrana lista: {{ $nazwaWybranej }}</h2>
    @endif

    @if($cofniecie !== null)
        <section class="card mb-5" id="cofnij" aria-labelledby="cofnij-naglowek">
            <h2 class="mt-0" id="cofnij-naglowek">Pomyłkowo usunięte? Możesz to cofnąć</h2>
            <p>
                @if($cofniecie->scope === \App\Models\ShoppingListUndo::SCOPE_SINGLE)
                    Z listy usunięto pozycję: <strong>{{ $cofniecie->items[0]['text'] }}</strong>.
                @else
                    Z listy usunięto {{ $cofniecie->items_count }} {{ \App\Support\Odmiana::rzeczownik($cofniecie->items_count, 'odhaczoną pozycję', 'odhaczone pozycje', 'odhaczonych pozycji') }}.
                @endif
                @if($wieleList)
                    Była to lista „{{ $cofniecieLista }}”.
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
        @if($wieleList)
            <p class="mt-0">Dopisujesz do listy: <strong>{{ $nazwaWybranej }}</strong>.</p>
        @endif
        <form class="planer-dopisz" method="POST" action="{{ route('shopping.store') }}">
            @csrf
            @if($idWybranej !== null)
                <input type="hidden" name="lista" value="{{ $idWybranej }}">
            @endif
            <x-field name="text" label="Co trzeba kupić?" :bez-oznaczenia="true" autocomplete="off"
                     help="Na przykład „mleko” albo „2 cebule”. Najwyżej {{ $maksZnakow }} znaków." />
            <button class="btn btn-primary" type="submit">Dopisz do listy{{ $wieleList ? ' „'.$nazwaWybranej.'”' : '' }}</button>
        </form>
    </section>

    @if($ile === 0)
        @if($wybrana === null)
            <p>Lista zakupów jest jeszcze pusta. Dopisz pierwszą pozycję powyżej albo otwórz przepis i wybierz „Dodaj składniki do listy zakupów”.</p>
        @else
            <p>Lista „{{ $nazwaWybranej }}” jest jeszcze pusta. Dopisz pierwszą pozycję powyżej albo otwórz przepis, wybierz tę listę i dodaj składniki.</p>
        @endif
        <p class="mt-4"><a class="btn btn-secondary" href="{{ route('planer.show') }}">Planer tygodnia</a></p>
    @else
        @if($wieleList)
            <p class="meta mb-5" role="status">Na tej liście: {{ $ile }}. Na wszystkich listach razem: {{ $ileNaKoncie }} z {{ $maksPozycji }} możliwych pozycji.</p>
        @else
            <p class="meta mb-5" role="status">Na liście: {{ $ile }} z {{ $maksPozycji }} możliwych pozycji.</p>
        @endif

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
                <p class="mt-4"><a class="btn btn-secondary" href="{{ $idWybranej !== null ? route('shopping.print', ['lista' => $idWybranej]) : route('shopping.print') }}" data-rola="wydrukuj-do-kupienia">Wydrukuj do kupienia</a></p>
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
                     minut działa „Cofnij usunięcie” (#2630). Dotyczy tylko
                     wybranej listy (#2528). --}}
                <details class="mt-5">
                    <summary class="btn btn-secondary">Wyczyść odhaczone</summary>
                    <form class="mt-3" method="POST" action="{{ route('shopping.clear') }}">
                        @csrf @method('DELETE')
                        @if($idWybranej !== null)
                            <input type="hidden" name="lista" value="{{ $idWybranej }}">
                        @endif
                        <p class="mt-0">Usuniemy z listy{{ $wieleList ? ' „'.$nazwaWybranej.'”' : '' }} {{ count($odhaczone) }} {{ \App\Support\Odmiana::rzeczownik(count($odhaczone), 'odhaczoną pozycję', 'odhaczone pozycje', 'odhaczonych pozycji') }}. Pozycji do kupienia{{ $wieleList ? ' ani innych list' : '' }} nie ruszamy. Jeśli to pomyłka, przez {{ \App\Domain\Zakupy\ListaZakupow::minutCofniecia() }} minut możesz to cofnąć u góry listy.</p>
                        <button class="btn btn-primary" type="submit">Tak, usuń odhaczone</button>
                    </form>
                </details>

                {{-- Kupione do spiżarni (#2481): osobna, świadoma czynność; samo odhaczenie
                     niczego w „Co mam w domu” nie zmienia. --}}
                <p class="mt-5 mb-0"><a class="btn btn-secondary" href="{{ $idWybranej !== null ? route('shopping.pantry.form', ['lista' => $idWybranej]) : route('shopping.pantry.form') }}">Dodaj kupione do „Co mam w domu”</a></p>
            @endif
        </section>

        <p><a class="btn btn-secondary" href="{{ route('planer.show') }}">Planer tygodnia</a></p>
    @endif

    {{-- Zarządzanie wybraną, nazwaną listą: zmiana nazwy i usunięcie (z pytaniem
         o skutek). Lista domyślna nie ma ani jednego, ani drugiego. --}}
    @if($wybrana !== null)
        <section class="card mt-6" id="ta-lista" aria-labelledby="ta-lista-naglowek">
            <h2 class="mt-0" id="ta-lista-naglowek">Ta lista: {{ $wybrana->name }}</h2>

            <details class="mb-4" @if($blad_nowej_nazwy) open @endif>
                <summary class="btn btn-secondary">Zmień nazwę listy</summary>
                <form class="planer-dopisz mt-3" method="POST" action="{{ route('shopping.lists.rename', $wybrana) }}" novalidate>
                    @csrf @method('PATCH')
                    <x-field name="nowa_nazwa" label="Nowa nazwa listy" :value="$wybrana->name" :bez-oznaczenia="true" autocomplete="off"
                             help="Zmienia się tylko nazwa, pozycje zostają bez zmian. Najwyżej {{ $maksZnakowNazwy }} znaków." />
                    <button class="btn btn-primary" type="submit">Zapisz nazwę</button>
                </form>
            </details>

            <details class="confirm" @if($blad_potwierdzenia) open @endif>
                <summary class="btn btn-secondary confirm-summary" id="f-potwierdzam" @if($blad_potwierdzenia) aria-describedby="blad-potwierdzam" @endif>Usuń tę listę<span class="visually-hidden">: {{ $wybrana->name }}</span></summary>
                <div class="confirm-body">
                    @if($blad_potwierdzenia)
                        <p class="field-error" id="blad-potwierdzam">{{ $errors->first('potwierdzam') }}</p>
                    @endif
                    <p class="confirm-question">Usunąć listę „{{ $wybrana->name }}”?</p>
                    @if($ile > 0)
                        <p>Razem z listą zniknie {{ $ile }} {{ \App\Support\Odmiana::rzeczownik($ile, 'pozycja', 'pozycje', 'pozycji') }} (także odhaczone). Tego nie da się cofnąć. Inne listy zostaną bez zmian.</p>
                    @else
                        <p>Lista jest pusta, więc nic poza samą listą nie zniknie. Inne listy zostaną bez zmian.</p>
                    @endif
                    <form method="POST" action="{{ route('shopping.lists.destroy', $wybrana) }}">
                        @csrf @method('DELETE')
                        <input type="hidden" name="potwierdzam" value="1">
                        <input type="hidden" name="widziana_liczba" value="{{ $ile }}">
                        <button class="btn btn-danger" type="submit">Tak, usuń listę{{ $ile > 0 ? ' i jej pozycje' : '' }}</button>
                    </form>
                </div>
            </details>
        </section>
    @endif

    <section class="card mt-6" id="nowa-lista" aria-labelledby="nowa-lista-naglowek">
        <h2 class="mt-0" id="nowa-lista-naglowek">Osobna lista na okazję</h2>
        @if($mozeDodacListe || $blad_nazwy)
            <details @if($blad_nazwy) open @endif>
                <summary class="btn btn-secondary">Nowa lista</summary>
                @unless($mozeDodacListe)
                    <p>Masz już {{ $maksList }} list zakupów. Żeby założyć nową, usuń listę, której już nie potrzebujesz. Wpisaną nazwę możesz skopiować z pola poniżej.</p>
                @endunless
                <p class="mt-3">Na przykład „Święta” albo „Przyjęcie u Kasi”, żeby nie mieszać ich z zakupami na dziś. „Na co dzień” zostaje jak jest. Możesz mieć najwyżej {{ $maksList }} list razem z „Na co dzień”.</p>
                <form class="planer-dopisz" method="POST" action="{{ route('shopping.lists.store') }}" novalidate>
                    @csrf
                    <x-field name="nazwa" label="Nazwa nowej listy" :bez-oznaczenia="true" autocomplete="off"
                             help="Najwyżej {{ $maksZnakowNazwy }} znaków." />
                    <button class="btn btn-primary" type="submit" @disabled(! $mozeDodacListe)>Załóż listę</button>
                </form>
            </details>
        @else
            <p class="mt-0">Masz już {{ $maksList }} {{ \App\Support\Odmiana::rzeczownik($maksList, 'listę', 'listy', 'list') }} zakupów, a tyle jest najwyżej. Żeby założyć nową, usuń listę, której już nie potrzebujesz.</p>
        @endif
    </section>
</x-layout>
