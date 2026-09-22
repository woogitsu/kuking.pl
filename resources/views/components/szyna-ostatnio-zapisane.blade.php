@props(['pozycje'])

{{-- Historyczna nazwa komponentu pochodzi z prawej szyny (issue #205).
     Od D-211 ostatnie zapisy stoją w głównej treści zeszytu. Kontroler
     nadal wybiera je po czasie odłożenia i filtruje według widoczności.
     Brak danych nie tworzy pustego bloku ani przykładowych pozycji. --}}
@if($pozycje->isNotEmpty())
    <section class="marka-zeszyt-ostatnie" aria-labelledby="szyna-ostatnio-zapisane">
        <h2 id="szyna-ostatnio-zapisane">Ostatnio odłożone</h2>
        <ul class="marka-zeszyt-zapisy">
            @foreach($pozycje as $pozycja)
                <li class="card marka-zeszyt-zapis">
                    @if($pozycja['media'])
                    <div class="marka-zeszyt-miniatura">
                        <x-photo :media="$pozycja['media']" variant="feed" :zoom="false"
                                 sizes="(min-width: 768px) 220px, 100vw" alt="" />
                    </div>
                    @endif
                    <div class="min-w-0">
                        {{-- Fokus obejmuje krótką akcję; pseudo-element rozszerza
                             kliknięcie na zdjęcie i pozostały obszar karty. --}}
                        <h3 class="m-0">{{ $pozycja['nazwa'] }}</h3>
                        <p class="meta mb-0">{{ $pozycja['podpis'] }}</p>
                        <a class="marka-zeszyt-zapis-link" href="{{ $pozycja['href'] }}"
                           aria-label="Zobacz: {{ $pozycja['nazwa'] }}">Zobacz</a>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif
