{{--
    Ustawienia → „Ostatnio oglądane” (#2553, V2).

    Opcjonalna, prywatna lista przepisów, do których można wrócić. DOMYŚLNIE
    WYŁĄCZONA: ekran najpierw mówi, co byłoby zapamiętywane, i dopiero przycisk
    „Włącz listę” coś zmienia. Trzy zwykłe formularze POST — włącz, wyłącz
    (kasuje historię od razu) i wyczyść — działają bez JavaScriptu, przyciski
    mają 48 px (`.btn`). Tekst bez rodzaju gramatycznego wobec czytającego.
    Lista nie trafia do feedu, statystyk, rekomendacji ani powiadomień.
--}}
@php
    $przepisowDni = \App\Support\Odmiana::rzeczownik($limit, 'przepis', 'przepisy', 'przepisów');
    $dniSlownie = \App\Support\Odmiana::rzeczownik($dni, 'dzień', 'dni', 'dni');
@endphp
<x-layout title="Ostatnio oglądane" :noindex="true">
    <h1>Ostatnio oglądane</h1>

    <p>
        Prywatna lista, dzięki której łatwo wrócić do przepisu, który się obejrzało, ale nie zapisało w zeszycie.
        Widzisz ją tylko Ty.
    </p>

    @if(! $wlaczone)
        <section class="panel-formularza" aria-labelledby="ogladane-wlacz">
            <h2 id="ogladane-wlacz" class="mt-0">Lista jest wyłączona</h2>
            <p>Teraz nic nie jest zapamiętywane. Jeśli ją włączysz:</p>
            <ul>
                <li>zapamiętamy na Twoim koncie tylko to, <strong>który przepis</strong> otwarto i <strong>kiedy</strong> — bez treści, zdjęć, wyszukiwanych fraz i wyboru alergenów;</li>
                <li>zostanie najwyżej <strong>{{ $limit }} {{ $przepisowDni }}</strong> z ostatnich <strong>{{ $dni }} {{ $dniSlownie }}</strong>; starsze znikają same;</li>
                <li>zapis zaczyna się dopiero od włączenia — nie odtwarzamy wcześniejszych wizyt;</li>
                <li>nikt inny tego nie widzi, autorzy przepisów też nie; lista nie wpływa na to, co pokazujemy w serwisie, i nie zapisuje niczego w zeszycie ani w „Ugotowałem”;</li>
                <li>wyłączenie listy albo przycisk „Wyczyść” usuwa zapamiętane przepisy od razu.</li>
            </ul>
            <form method="POST" action="{{ route('settings.ogladane.wlacz') }}">
                @csrf
                <button class="btn btn-primary" type="submit">Włącz listę</button>
            </form>
        </section>
    @else
        <section aria-labelledby="ogladane-lista" class="mt-6">
            <h2 id="ogladane-lista" class="mt-0">Twoja lista</h2>
            @if($pozycje->isEmpty())
                <p class="meta meta-samodzielne" data-rola="ogladane-pusto">
                    Na razie nic tu nie ma. Przepisy, które otworzysz od tej chwili, pojawią się tutaj
                    (najwyżej {{ $limit }}, z ostatnich {{ $dni }} {{ $dniSlownie }}). Przepisy własne i niedostępne dla Ciebie nie są zapamiętywane.
                </p>
            @else
                <div class="stack-tight" data-rola="ogladane-lista">
                    @foreach($pozycje as $pozycja)
                        <div>
                            <x-recipe-card :recipe="$pozycja['recipe']" />
                            <p class="meta m-0">Ostatnio oglądano: {{ \App\Support\Czas::data($pozycja['viewed_at'], 'j F Y, H:i') }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="meta">Pokazujemy tylko przepisy, które nadal możesz otworzyć. Resztę pomijamy.</p>
            @endif

            <form class="mt-4" method="POST" action="{{ route('settings.ogladane.wyczysc') }}">
                @csrf
                <button class="btn btn-secondary" type="submit">Wyczyść listę</button>
            </form>
        </section>

        <section class="danger-zone mt-8" aria-labelledby="ogladane-wylacz">
            <h2 id="ogladane-wylacz">Wyłączenie listy</h2>
            <p>Po wyłączeniu przestajemy zapamiętywać oglądane przepisy, a to, co już zapamiętano, usuwamy od razu. Włączyć listę można znów w każdej chwili.</p>
            <form method="POST" action="{{ route('settings.ogladane.wylacz') }}">
                @csrf
                <button class="btn btn-secondary" type="submit">Wyłącz i usuń zapamiętane</button>
            </form>
        </section>
    @endif

    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="ogladane" />
    </x-slot:rail>
</x-layout>
