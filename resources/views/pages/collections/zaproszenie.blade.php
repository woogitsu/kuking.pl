<x-layout title="Zaproszenie do zeszytu" :noindex="true">
    {{--
        ODPOWIEDŹ NA ZAPROSZENIE (#1743, D-302) — po nazwie konta albo linkiem.

        Przed decyzją człowiek ma wiedzieć, na co się zgadza: czyj to zeszyt,
        co w nim zobaczy i zrobi, i że może odejść. Dwa przyciski obok siebie,
        oba zwykłymi formularzami (bez JavaScriptu). „Nie, dziękuję" nie jest
        akcją destrukcyjną — niczego nie kasuje i nikogo nie powiadamia.
    --}}
    <div class="marka-zeszyt">
    <h1>Zaproszenie do wspólnego zeszytu</h1>

    @if(! $czeka)
        <p>To zaproszenie jest już nieaktualne — wygasło, zostało odwołane albo ktoś już z niego skorzystał. Poproś o nowe.</p>
        <a class="btn btn-primary" href="{{ route('collections.index') }}">Przejdź do zeszytów</a>
    @elseif($jestWlascicielem)
        <p>To jest Twój zeszyt „{{ $zeszyt->name }}” — nie musisz do niego dołączać. Ten link wyślij osobie, którą zapraszasz.</p>
        <a class="btn btn-primary" href="{{ route('collections.sharing', $zeszyt) }}">Wróć do ustawień wspólnego zeszytu</a>
    @elseif($maJuzDostep)
        <p>Masz już dostęp do zeszytu „{{ $zeszyt->name }}”.</p>
        <a class="btn btn-primary" href="{{ route('collections.show', $zeszyt) }}">Otwórz zeszyt</a>
    @else
        <p class="text-lg"><strong>{{ $wlasciciel?->displayName() ?? 'Właściciel' }}</strong> zaprasza Cię do zeszytu <strong>„{{ $zeszyt->name }}”</strong>.</p>

        <section class="panel-formularza mb-6" aria-labelledby="co-oznacza">
            <h2 id="co-oznacza" class="mt-0">Co to znaczy</h2>
            <ul>
                <li>możesz zapisywać w tym zeszycie przepisy i wpisy oraz je stąd wyjmować,</li>
                <li>widzisz notatki przy pozycjach i możesz dopisywać własne,</li>
                <li>przy każdej pozycji widać, kto ją dodał.</li>
            </ul>
            <p class="m-0">Zeszyt należy do tej osoby: tylko ona zmienia jego nazwę i to, kto go widzi. Możesz odejść w każdej chwili — to, co dodasz, zostanie wtedy w zeszycie.</p>
        </section>

        <div class="flex flex-wrap gap-3">
            <form method="POST" action="{{ $przyjmij }}">
                @csrf
                <button class="btn btn-primary" type="submit">Dołączam</button>
            </form>
            <form method="POST" action="{{ $odrzuc }}">
                @csrf
                <button class="btn btn-secondary" type="submit">Nie, dziękuję</button>
            </form>
        </div>
        <p class="meta mt-4">Zaproszenie jest ważne do {{ $zaproszenie->expires_at->locale('pl')->isoFormat('D MMMM YYYY') }}.</p>
    @endif
    </div>
</x-layout>
