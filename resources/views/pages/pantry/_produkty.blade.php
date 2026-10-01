{{--
    Lista kart produktów jednej sekcji „Co mam w domu” (#1903).

    Karta: nazwa, ilość (jeśli jest), rodzaj i data terminu, linia stanu
    słowami (stan niesie tekst, nie sam kolor), przyciski „Ustaw termin” albo
    „Zmień termin” oraz „Usuń” — każdy z nazwą produktu w `aria-label`, żeby
    czytnik ekranu nie czytał dziesięć razy samego „Usuń”.

    Usunięcie nie ma potwierdzenia: to jedna linijka własnej listy, którą
    dopisuje się z powrotem jednym polem.
--}}
<ul class="lista-naga stack-tight">
    @foreach($lista as $produkt)
        <li class="card" data-produkt>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <span class="text-lg">{{ $produkt->name }}</span>
                    @if($produkt->quantity_note)
                        <span class="meta">({{ $produkt->quantity_note }})</span>
                    @endif
                    @if($produkt->expires_on)
                        <p class="meta mt-1 mb-0">
                            {{ \App\Domain\Pantry\PriorytetZuzycia::etykietaTerminu($produkt) }}
                            {{ \App\Domain\Pantry\PriorytetZuzycia::dataSlownie($produkt->expires_on) }}
                        </p>
                    @endif
                    <p class="mt-1 mb-0" data-stan>{{ \App\Domain\Pantry\PriorytetZuzycia::opisStanu($produkt, $dzis) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <a class="btn btn-secondary" href="{{ route('pantry.edit', $produkt) }}"
                       aria-label="{{ $produkt->expires_on ? 'Zmień termin' : 'Ustaw termin' }}: {{ $produkt->name }}">{{ $produkt->expires_on ? 'Zmień termin' : 'Ustaw termin' }}</a>
                    <form method="POST" action="{{ route('pantry.destroy', $produkt) }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-secondary" type="submit"
                                aria-label="Usuń z listy: {{ $produkt->name }}">Usuń</button>
                    </form>
                </div>
            </div>
        </li>
    @endforeach
</ul>
