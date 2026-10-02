{{--
    Lista kart produktów jednej sekcji „Co mam w domu” (#1903).

    Karta: nazwa, ilość (jeśli jest), rodzaj i data terminu, linia stanu
    słowami (stan niesie tekst, nie sam kolor), przyciski „Ustaw termin” albo
    „Zmień termin” oraz „Usuń” — każdy z nazwą produktu w `aria-label`, żeby
    czytnik ekranu nie czytał dziesięć razy samego „Usuń”.

    Usunięcie wymaga osobnego potwierdzenia (#2467). Natywne details otwiera
    pytanie i pozwala je anulować bez skryptu i bez wysyłania żądania.
--}}
<ul class="lista-naga stack-tight">
    @foreach($lista as $produkt)
        <li class="card" data-produkt>
            <div class="flex flex-wrap items-center justify-between gap-3">
                {{-- `min-w-0`: bez niego element flex nie zwęża się poniżej najdłuższego wyrazu i przy 320 px
                     z czcionką przeglądarki 200% wypycha stronę w bok (332 px) — dwa zagnieżdżone dopełnienia
                     (sekcja i karta) zostawiają tam 140 px na tekst. --}}
                <div class="min-w-0">
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
                </div>
            </div>
            <details class="confirm group mt-3 w-full min-w-0" data-potwierdzenie-spizarni>
                <summary class="btn btn-danger confirm-summary">
                    <span class="group-open:hidden">Usuń<span class="visually-hidden"> z listy: {{ $produkt->name }}</span></span>
                    <span class="hidden group-open:inline">Anuluj<span class="visually-hidden">: {{ $produkt->name }}</span></span>
                    <x-ikona nazwa="chevron" class="group-open:rotate-90" />
                </summary>
                <div class="confirm-body">
                    <p class="confirm-question">Usunąć ten produkt z listy? Znikną też jego ilość i zapisany termin.</p>
                    <p class="confirm-question-nazwa"><strong>{{ $produkt->name }}</strong></p>
                    <form method="POST" action="{{ route('pantry.destroy', $produkt) }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-danger" type="submit"
                                aria-label="Tak, usuń z listy: {{ $produkt->name }}">Tak, usuń z listy</button>
                    </form>
                </div>
            </details>
        </li>
    @endforeach
</ul>
