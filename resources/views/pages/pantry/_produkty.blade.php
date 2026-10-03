{{--
    Lista kart produktów jednej sekcji „Co mam w domu” (#1903).

    Karta: nazwa, ilość (jeśli jest), rodzaj i data terminu, linia stanu
    słowami (stan niesie tekst, nie sam kolor), przyciski „Ustaw termin” albo
    „Zmień termin” oraz „Usuń” — każdy z nazwą produktu w `aria-label`, żeby
    czytnik ekranu nie czytał dziesięć razy samego „Usuń”.

    Usunięcie wymaga osobnego potwierdzenia (#2467). Natywne details otwiera
    pytanie i pozwala je anulować bez skryptu i bez wysyłania żądania.

    Lista składa się z OPAKOWAŃ (#2568), nie z produktów: produkt z dwoma
    opakowaniami stoi na liście dwa razy (w sekcji właściwej dla terminu
    każdego z nich), a karta mówi, które opakowanie pokazuje. Usunięcie takiego
    opakowania nazywa swój zakres: tylko to opakowanie albo cały produkt.
--}}
<ul class="lista-naga stack-tight">
    @foreach($lista as $op)
        @php
            $produkt = $op->produkt;
            $podpis = $op->maDwa ? ', '.mb_strtolower($op->etykieta()) : '';
            $adresEdycji = $op->maDwa
                ? route('pantry.edit', ['pantryItem' => $produkt, 'opakowanie' => $op->numer])
                : route('pantry.edit', $produkt);
        @endphp
        <li class="card" data-produkt>
            <div class="flex flex-wrap items-center justify-between gap-3">
                {{-- `min-w-0`: bez niego element flex nie zwęża się poniżej najdłuższego wyrazu i przy 320 px
                     z czcionką przeglądarki 200% wypycha stronę w bok (332 px) — dwa zagnieżdżone dopełnienia
                     (sekcja i karta) zostawiają tam 140 px na tekst. --}}
                <div class="min-w-0">
                    <span class="text-lg">{{ $op->name }}</span>
                    @if($op->quantity_note)
                        <span class="meta">({{ $op->quantity_note }})</span>
                    @endif
                    @if($op->maDwa)
                        <p class="meta mt-1 mb-0" data-etykieta-opakowania="{{ $op->numer }}"><strong>{{ $op->etykieta() }}</strong></p>
                    @endif
                    @if($op->expires_on)
                        <p class="meta mt-1 mb-0">
                            {{ \App\Domain\Pantry\PriorytetZuzycia::etykietaTerminu($op) }}
                            {{ \App\Domain\Pantry\PriorytetZuzycia::dataSlownie($op->expires_on) }}
                        </p>
                    @endif
                    <p class="mt-1 mb-0" data-stan>{{ \App\Domain\Pantry\PriorytetZuzycia::opisStanu($op, $dzis) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <a class="btn btn-secondary" href="{{ $adresEdycji }}"
                       aria-label="{{ $op->expires_on ? 'Zmień termin' : 'Ustaw termin' }}: {{ $produkt->name }}{{ $podpis }}">{{ $op->expires_on ? 'Zmień termin' : 'Ustaw termin' }}</a>
                    {{-- Poprawienie samej nazwy bez utraty ilości i terminu (#2448).
                         Nazwa należy do produktu, więc dotyczy obu opakowań (#2568). --}}
                    <a class="btn btn-secondary" href="{{ route('pantry.name.edit', $produkt) }}"
                       aria-label="Zmień nazwę: {{ $produkt->name }}">Zmień nazwę</a>
                </div>
            </div>
            <details class="confirm group mt-3 w-full min-w-0" data-potwierdzenie-spizarni>
                <summary class="btn btn-danger confirm-summary">
                    <span class="group-open:hidden">Usuń<span class="visually-hidden"> z listy: {{ $produkt->name }}{{ $podpis }}</span></span>
                    <span class="hidden group-open:inline">Anuluj<span class="visually-hidden">: {{ $produkt->name }}{{ $podpis }}</span></span>
                    <x-ikona nazwa="chevron" class="group-open:rotate-90" />
                </summary>
                <div class="confirm-body">
                    @if($op->maDwa)
                        {{-- Dwa opakowania: pytanie nazywa zakres. Usunięcie jednego nie rusza drugiego;
                             usunięcie całego produktu jest osobno i odsunięte (drugi formularz). --}}
                        <p class="confirm-question">Co usunąć z listy? Ten produkt ma dwa opakowania, każde z własnym terminem.</p>
                        <p class="confirm-question-nazwa"><strong>{{ $produkt->name }}</strong> — {{ mb_strtolower($op->etykieta()) }}</p>
                        <form method="POST" action="{{ route('pantry.destroyOpakowanie', $produkt) }}" data-usun-opakowanie>
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="opakowanie" value="{{ $op->numer === 'drugie' ? $op->opakowanieId : 'pierwsze' }}">
                            @if($op->numer === 'pierwsze')
                                <input type="hidden" name="odcisk" value="{{ $op->odcisk() }}">
                            @endif
                            <button class="btn btn-danger" type="submit"
                                    aria-label="Tak, usuń tylko {{ mb_strtolower($op->etykieta()) }}: {{ $produkt->name }}">Tak, usuń tylko to opakowanie</button>
                        </form>
                        <p class="confirm-question mt-8">Albo usuń cały produkt — znikną oba opakowania, ich ilości i terminy.</p>
                        <form method="POST" action="{{ route('pantry.destroy', $produkt) }}" data-usun-caly-produkt>
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="widziane_pierwsze" value="{{ $produkt->first_package_id ?? $produkt->getKey() }}">
                            <input type="hidden" name="widziane_drugie" value="{{ $produkt->secondPackage?->getKey() }}">
                            <button class="btn btn-danger" type="submit"
                                    aria-label="Usuń cały produkt, oba opakowania: {{ $produkt->name }}">Usuń cały produkt (oba opakowania)</button>
                        </form>
                    @else
                        <p class="confirm-question">Usunąć ten produkt z listy? Znikną też jego ilość i zapisany termin.</p>
                        <p class="confirm-question-nazwa"><strong>{{ $produkt->name }}</strong></p>
                        <form method="POST" action="{{ route('pantry.destroy', $produkt) }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="widziane_pierwsze" value="{{ $produkt->first_package_id ?? $produkt->getKey() }}">
                            <input type="hidden" name="widziane_drugie" value="brak">
                            <button class="btn btn-danger" type="submit"
                                    aria-label="Tak, usuń z listy: {{ $produkt->name }}">Tak, usuń z listy</button>
                        </form>
                    @endif
                </div>
            </details>
        </li>
    @endforeach
</ul>
