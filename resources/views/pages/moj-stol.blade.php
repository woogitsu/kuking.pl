{{--
    „Mój stół" (issue #1749, D-304) — dobrowolna, prywatna półka przepisów.

    DOMYŚLNIE WYŁĄCZONA. Dopóki człowiek jej nie włączy, strona tylko mówi,
    czym jest półka i jak dobiera — i nie liczy żadnej propozycji.

    „DLACZEGO TO WIDZĘ" stoi na górze półki jednym zdaniem
    (`MojStol::DLACZEGO`), a każda pozycja ma swoje „Pokazujemy, bo…".
    Dobór wyłącznie z zamkniętej listy AGENTS.md §8 — bez rankingu po
    reakcjach i bez uczenia z zachowania.

    Wszystko to zwykłe formularze i odnośniki: działa bez JavaScriptu,
    przyciski `.btn` (48 px), tekst na każdej akcji.
--}}
<x-layout title="Mój stół" :noindex="true">
    <h1>Mój stół</h1>

    <x-error-summary />

    @if(! $wlaczony)
        <p>Mój stół to Twoja prywatna półka z przepisami do ugotowania. Widzisz ją tylko Ty.</p>

        <section class="card stack-tight" aria-labelledby="moj-stol-jak">
            <h2 id="moj-stol-jak">Jak wybieramy przepisy</h2>
            <p class="m-0">{{ $dlaczego }}</p>
            <p class="m-0">Nic tu się nie uczy z tego, co klikasz. Półka zmienia się tylko wtedy,
                gdy zmienisz obserwowane tagi albo coś ukryjesz.</p>
        </section>

        <form method="POST" action="{{ route('moj-stol.ustaw') }}" class="form-actions mt-8">
            @csrf
            @method('PUT')
            <input type="hidden" name="wlaczony" value="1">
            <button class="btn btn-primary" type="submit">Włącz Mój stół</button>
        </form>
        <p class="meta">Możesz go wyłączyć w każdej chwili, na tej samej stronie.</p>
    @else
        <section class="notice" aria-labelledby="moj-stol-dlaczego">
            <h2 id="moj-stol-dlaczego" class="m-0">Dlaczego to widzę</h2>
            <p class="m-0">{{ $dlaczego }}</p>
        </section>

        <section class="sekcja-strony mt-8" aria-labelledby="moj-stol-z-tagow">
            <h2 id="moj-stol-z-tagow">Z tagów, które obserwujesz</h2>
            @if(! $polka['obserwuje_tagi'])
                <p>Nie obserwujesz jeszcze żadnego tagu. Wybierz kilka, a pokażemy tu najnowsze przepisy z nich.</p>
                <a class="btn btn-secondary" href="{{ route('settings.tags') }}">Wybierz tagi</a>
            @elseif($polka['z_tagow'] === [])
                <p>W Twoich tagach nie ma teraz przepisów, które możemy tu pokazać.</p>
                <a class="btn btn-secondary" href="{{ route('settings.tags') }}">Zmień obserwowane tagi</a>
            @else
                <ul class="szyna-lista stack">
                    @foreach($polka['z_tagow'] as $pozycja)
                        <x-moj-stol-pozycja :post="$pozycja['post']" :powod="'obserwujesz tag: '.$pozycja['tag']->name.'.'" />
                    @endforeach
                </ul>
                <p class="mt-4"><a href="{{ route('settings.tags') }}">Zmień obserwowane tagi</a></p>
            @endif
        </section>

        @if($polka['od_gospodarza'] !== null)
            @php $temat = $polka['od_gospodarza']['tag']; @endphp
            <section class="sekcja-strony mt-8" aria-labelledby="moj-stol-gospodarz">
                <h2 id="moj-stol-gospodarz">Wybór gospodarza: tag {{ $temat->name }}</h2>
                <p>Tego tagu jeszcze nie obserwujesz. Poleca go gospodarz serwisu.</p>
                <ul class="szyna-lista stack">
                    @foreach($polka['od_gospodarza']['wpisy'] as $post)
                        <x-moj-stol-pozycja :post="$post" :powod="'gospodarz poleca tag: '.$temat->name.'.'" />
                    @endforeach
                </ul>
                <div class="form-actions mt-4">
                    <form method="POST" action="{{ route('tags.follow', $temat) }}">
                        @csrf
                        <button class="btn btn-secondary" type="submit">Obserwuj tag: {{ $temat->name }}</button>
                    </form>
                    <a class="btn btn-secondary" href="{{ route('tags.show', $temat) }}">Zobacz wpisy z tym tagiem</a>
                </div>
            </section>
        @endif

        @if($polka['na_dzis'] !== [])
            {{-- Trzecia sekcja (decyzja właściciela 26.09, PR #1875): wybór
                 gospodarza na dziś, w jego kolejności, z tymi samymi
                 filtrami co reszta półki. --}}
            <section class="sekcja-strony mt-8" aria-labelledby="moj-stol-na-dzis">
                <h2 id="moj-stol-na-dzis"><x-kuking-word forma="i" /> na dziś</h2>
                <p>Te przepisy gospodarz wybrał na dziś.</p>
                <ul class="szyna-lista stack">
                    @foreach($polka['na_dzis'] as $post)
                        <x-moj-stol-pozycja :post="$post" powod="gospodarz wybrał ten przepis na dziś." />
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="sekcja-strony mt-8" aria-labelledby="moj-stol-ustawienia">
            <h2 id="moj-stol-ustawienia">Twoje ustawienia półki</h2>
            <p>Nic tu się nie uczy z tego, co klikasz, więc nie ma czego resetować.
                To, co ukryjesz, cofniesz na liście <a href="{{ route('settings.hidden') }}">Ukryte</a>.</p>
            <form method="POST" action="{{ route('moj-stol.ustaw') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="wlaczony" value="0">
                <button class="btn btn-secondary" type="submit">Wyłącz Mój stół</button>
            </form>
        </section>
    @endif
</x-layout>
