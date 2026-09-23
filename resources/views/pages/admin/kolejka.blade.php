{{--
    Nieudane zadania kolejki — dlaczego `/health` mówi `degraded`.

    ────────────────────────────────────────────────────────────────────────
     CO TU JEST, A CZEGO NIE MA I NIE BĘDZIE
    ────────────────────────────────────────────────────────────────────────

    Jest: nazwa klasy zadania, nazwa klasy wyjątku, nazwa kolejki, liczba
    i dwie daty.
    Nie ma: ładunku zadania, treści wyjątku, adresów e-mail, imion, żetonów.
    Uzasadnienie pełne w `App\Domain\Kolejka\NieudaneZadania` — w skrócie:
    w ładunku leży ŻYWY żeton logowania, a ślad stosu potrafi nieść argumenty
    wywołań. Kto pokaże jedno albo drugie w przeglądarce, ten wynosi sekret.

    Nie ma też żadnego przycisku „ponów" ani „skasuj". To nie jest oszczędność
    pracy: zbiorcze `queue:retry` na starym żetonie resetu hasła wysyła
    człowiekowi martwy link, a skasowany wiersz `failed_jobs` to skasowany
    jedyny ślad po awarii. Obie decyzje zostają w `kuking:martwe-zadania`,
    gdzie podejmuje je człowiek po zobaczeniu, kogo dotyczą.

    ────────────────────────────────────────────────────────────────────────
     DLACZEGO NA GÓRZE STOI CO INNEGO NIŻ NA LIŚCIE
    ────────────────────────────────────────────────────────────────────────

    Górna ramka to ZDARZENIE (`StanKolejki`, issue #599): co padło w oknie
    ostatnich godzin i czy worker w ogóle chodzi. Lista niżej to STAN: co
    zalega w tabeli od zawsze. `/health` patrzy wyłącznie na to drugie
    i dlatego świeci nieprzerwanie od 9 września 2026 — pierwsza ramka jest
    po to, żeby dało się odróżnić awarię dzisiejszą od zeszłotygodniowej.
--}}
<x-layout title="Kolejka zadań — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Kolejka zadań" />

    <h1>Kolejka zadań</h1>

    {{-- ZDARZENIE: czy coś psuje się TERAZ. --}}
    <section class="card" aria-labelledby="kolejka-teraz">
        <h2 class="mt-0 text-title-sm" id="kolejka-teraz">Czy kolejka pracuje teraz</h2>

        @if($stan['stan'] === \App\Domain\Kolejka\StanKolejki::NIEDOSTEPNA)
            <p>Nie udało się odczytać tabel kolejki. To zwykle ta sama awaria bazy,
                o której mówi <code>/health</code> w polu <code>database</code>.</p>
        @else
            <p>
                @if($stan['stan'] === \App\Domain\Kolejka\StanKolejki::SPOKOJNA)
                    Kolejka pracuje. W ostatnich {{ $stan['okno_godzin'] }} godzinach nie padło żadne zadanie.
                @elseif($stan['stan'] === \App\Domain\Kolejka\StanKolejki::ZALEGLOSC)
                    <strong>Najstarsze gotowe zadanie czeka {{ $stan['zaleglosc_sekundy'] }} sekund</strong>
                    (próg: {{ $stan['prog_zaleglosci_sekundy'] }} s). To wygląda na workera, który nie pracuje —
                    a worker, który nie chodzi, nie zgłasza żadnego błędu.
                @else
                    <strong>W ostatnich {{ $stan['okno_godzin'] }} godzinach padło
                        {{ $stan['nieudane_w_oknie'] }} zadań.</strong> To jest awaria świeża, nie zaległość.
                @endif
            </p>

            <p class="meta">
                Czeka: {{ $stan['oczekujace'] }} ·
                zawieszonych: {{ $stan['zawieszone'] }} ·
                nieudanych w tabeli razem: {{ $stan['nieudane_razem'] }}
            </p>
        @endif
    </section>

    {{-- STAN: co trzyma `/health` w `degraded`. --}}
    <h2 id="kolejka-co-lezy">Co leży w tabeli nieudanych zadań</h2>

    @if($nieudane['odczytane'] === false)
        <p class="card">Nie udało się odczytać tabeli nieudanych zadań.</p>
    @elseif($nieudane['razem'] === 0)
        <p class="card">Tabela jest pusta. Z tego powodu <code>/health</code> nie ma prawa mówić
            <code>degraded</code> — jeśli mimo to mówi, powód jest inny niż kolejka.</p>
    @else
        <p>
            Wierszy razem: <strong>{{ $nieudane['razem'] }}</strong>.
            Dopóki tu cokolwiek leży, <code>/health</code> mówi <code>degraded</code>
            z powodem <code>zadania_nieudane</code>.
        </p>

        <ul class="stack list-none p-0">
            @foreach($nieudane['grupy'] as $grupa)
                <li class="card">
                    <h3 class="mt-0 text-title-sm">
                        {{ $grupa['nazwa'] }} — {{ $grupa['ile'].' '.($grupa['ile'] === 1 ? 'zadanie' : ($grupa['ile'] < 5 ? 'zadania' : 'zadań')) }}
                    </h3>

                    <p>Przewrócił to: <strong>{{ $grupa['nazwa_wyjatku'] }}</strong></p>

                    <dl class="meta">
                        <dt>Pełna nazwa zadania</dt>
                        <dd><code>{{ $grupa['klasa'] }}</code></dd>

                        <dt>Pełna nazwa wyjątku</dt>
                        <dd><code>{{ $grupa['wyjatek'] }}</code></dd>

                        <dt>Kolejka</dt>
                        <dd><code>{{ $grupa['kolejka'] }}</code></dd>

                        <dt>Najstarsze</dt>
                        <dd>{{ $grupa['najstarsze']?->format('j.m.Y, H:i') ?? 'nie wiadomo' }}</dd>

                        <dt>Najnowsze</dt>
                        <dd>{{ $grupa['najnowsze']?->format('j.m.Y, H:i') ?? 'nie wiadomo' }}</dd>
                    </dl>
                </li>
            @endforeach
        </ul>

        @if($nieudane['poza_lista'] > 0)
            <p class="meta">Grup poza listą: {{ $nieudane['poza_lista'] }}. Ekran pokazuje
                {{ \App\Domain\Kolejka\NieudaneZadania::GRUP_NA_EKRAN }} najświeższych.</p>
        @endif
    @endif

    <section class="card" aria-labelledby="kolejka-co-dalej">
        <h2 class="mt-0 text-title-sm" id="kolejka-co-dalej">Co z tym zrobić</h2>

        <p>Ten ekran wyłącznie czyta. Żeby dowiedzieć się, <strong>kogo</strong> te zadania
            dotyczyły, i żeby cokolwiek z tabeli wyrzucić, potrzebna jest powłoka serwera:</p>

        <ul>
            <li><code>php artisan kuking:martwe-zadania</code> — co to jest i ilu ludzi dotyczy
                (niczego nie kasuje bez <code>--skasuj</code>).</li>
            <li><code>php artisan kuking:kto-nie-dostal-listu</code> — do kogo list nie doszedł.</li>
        </ul>

        <p><strong>Nie ponawiaj zbiorczo starych zadań.</strong> Żeton resetu hasła wygasa,
            więc ponowienie po dniach wysyła człowiekowi martwy link.</p>
    </section>
</x-layout>
