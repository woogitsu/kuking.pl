<x-layout title="Start" :noindex="true">
    <h1>{{ $greeting }}</h1>

    {{--
        Zachęta do dodania wpisu (UI kit v2, ekrany 01 i 05).

        W mockupach to jest karta z awatarem, a nie samotny przycisk — dzięki
        temu główna akcja produktu wygląda jak miejsce, w którym coś się pisze,
        a nie jak jeden z wielu guzików na stronie.

        Świadomie NIE jest to pole tekstowe udające formularz: takie pole
        bez JavaScriptu nie robi po kliknięciu nic, a rejestracja, publikacja
        i komentarz mają działać bez skryptu (AGENTS.md §5). To jest zwykły
        odnośnik do strony dodawania — działa też z klawiatury i na czytniku
        ekranu.
    --}}
    <a class="card composer" href="{{ route('posts.create') }}">
        <x-avatar :user="auth()->user()" :size="48" />
        <span class="composer-copy">
            <span class="composer-title">Dodaj zdjęcie tego, co ugotowałeś</span>
            <span class="composer-help">Nie musi być ładne — ma być prawdziwe.</span>
        </span>
        <x-ikona nazwa="image" :rozmiar="28" />
    </a>

    {{-- Tablica stoi NAD feedem: dla osoby z pustym feedem to jest jedyna
         treść na tym ekranie, a dla pozostałych — powód, żeby kogoś nowego
         zaobserwować. --}}
    <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" />

    @if($wspomnienie ?? null)
        {{--
            „ROK TEMU GOTOWAŁAŚ…" — WŁASNE ARCHIWUM JAKO POWÓD POWROTU (issue #34).

            Dla osoby, która gotuje codziennie od czterdziestu lat, największą
            wartością nie jest nowy przepis — jest zobaczenie, co gotowała
            w tym dniu dwa lata temu (docs/product/SOUL.md).

            TON JEST CICHY I TO JEST CAŁA RÓŻNICA MIĘDZY TĄ FUNKCJĄ A OKRUCIEŃSTWEM.
            „Rok temu, 6 września" — stwierdzenie faktu. Nigdy „Pamiętasz ten
            wspaniały dzień?!", bo nie wiemy, czy był wspaniały, i nie mamy
            prawa tego zakładać. Wpis może być przepisem po kimś, kto właśnie
            umarł.

            Blok NIE MA pustego stanu i nigdy nie będzie miał: „nie masz jeszcze
            wspomnień" jest wyrzutem wobec kogoś, kto dopiero zaczyna.
        --}}
        <section class="wspomnienie" aria-labelledby="wspomnienie-podpis">
            <h2 id="wspomnienie-podpis" class="wspomnienie-podpis">{{ $podpisWspomnienia }}</h2>

            <x-post-card :post="$wspomnienie" />

            {{-- „Nie pokazuj mi tego więcej" stoi PRZY wspomnieniu, nie
                 w ustawieniach: w chwili, w której coś zabolało, nikt nie
                 szuka trzeciego menu. Zwykły formularz, działa bez JavaScriptu. --}}
            <form method="POST" action="{{ route('wspomnienia.ukryj', $wspomnienie) }}" class="wspomnienie-akcje">
                @csrf
                <button class="btn btn-quiet" type="submit">Nie pokazuj mi tego więcej</button>
            </form>
        </section>
    @endif

    @if(($zrodloFeedu ?? 'obserwowani') === 'tematy')
        {{-- Feed tematów (issue #31). Człowiek MUSI wiedzieć, skąd się wzięły
             te wpisy: feed, którego pochodzenia nie da się wytłumaczyć,
             wygląda jak algorytm, a tego tu nie ma i nie będzie. --}}
        <div class="notice">
            <strong>To wpisy z tematów, które obserwujesz.</strong>
            Kiedy zaczniesz obserwować ludzi, w tym miejscu pojawią się ich wpisy.
            <a href="{{ route('settings.topics') }}">Zmień swoje tematy</a>.
        </div>
    @endif

    @if($showingDiscover)
        {{--
            Feed obserwowanych jest pusty. Nie pokazujemy pustki — pokazujemy
            świeże treści i konkretne osoby do obserwowania. Bez tego nowy
            użytkownik widzi biały ekran i nie wraca (docs/product/COLD_START.md).
        --}}
        <div class="notice">
            <strong>Twoja strona główna jest jeszcze pusta.</strong>
            Poniżej pokazujemy to, co ostatnio ugotowali inni. Kiedy zaczniesz kogoś
            obserwować, w tym miejscu będą pojawiać się jego wpisy.
        </div>

        <h2>Świeżo z Kuking</h2>
    @endif

    @if($posts->count() === 0)
        <x-empty-state title="Jeszcze nic tu nie ma" action="Dodaj pierwsze zdjęcie" :href="route('posts.create')">
            Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.
        </x-empty-state>
    @else
        <div class="stack">
            @foreach($posts as $post)
                <x-post-card :post="$post" />
            @endforeach
        </div>

        <x-show-more :paginator="$posts" />
    @endif
</x-layout>
