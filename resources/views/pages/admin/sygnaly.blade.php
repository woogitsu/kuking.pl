<x-layout title="Sygnały automatu — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Sygnały automatu" />

    <h1>Sygnały automatu</h1>

    {{-- Błąd ukrytego identyfikatora grupy też musi być widoczny.
         Nie tworzymy odnośnika do ukrytego pola autor. --}}
    @if($errors->any())
        <div class="error-summary" role="alert" tabindex="-1">
            <p class="error-summary-title">Sprawdź formularz</p>
            <ul>
                @foreach($errors->all() as $blad)
                    <li>{{ $blad }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{--
        PIERWSZE ZDANIE MÓWI, CZEGO TA LISTA NIE ZNACZY.

        Kolejka podejrzeń bez tego zdania czyta się jak lista przewinień —
        a jest listą rzeczy do sprawdzenia, z których większość okaże się
        niczym. Przy fali nowych kont ta różnica decyduje o tym, jak człowiek
        po drugiej stronie ekranu traktuje sto pozycji dziennie.
    --}}
    <p class="text-lead">
        Treści, przy których automat podniósł rękę. <strong>Nikt ich nie zgłosił</strong>, nic się
        z nimi nie stało i ich autorzy o niczym nie wiedzą — są widoczne w serwisie tak samo jak
        wszystko inne. Automat niczego nie ukrywa i nie blokuje; to Ty decydujesz, czy jest tu coś do zrobienia.
    </p>

    <p class="meta">
        Czeka {{ $otwartych }} {{ $otwartych === 1 ? 'oznaczenie' : 'oznaczeń' }}.
        Zgłoszenia od ludzi są osobno: <a href="{{ route('admin.reports') }}">Zgłoszenia</a>.
    </p>

    @forelse($grupy as $grupa)
        @php($kluczGrupy = (string) ($grupa->autor_tresci_id ?? 'brak'))
        @php($wGrupie = $pozycje[$kluczGrupy] ?? collect())
        {{-- COUNT(*) wraca z PostgreSQL jako napis, a nie liczba — bez tego
             rzutowania `=== 1` nigdy nie byłoby prawdą i każda grupa mówiłaby
             „oznaczeń" nawet przy jednym. --}}
        @php($ile = (int) $grupa->getAttribute('ile'))
        @php($ostatnie = \Illuminate\Support\Carbon::parse((string) $grupa->getAttribute('ostatnie')))

        <article class="card mb-5">
            <h2 class="mt-0 text-title-sm">
                @if($grupa->autorTresci)
                    {{ $grupa->autorTresci->displayName() }}
                @else
                    Autor nieznany albo konto usunięte
                @endif
            </h2>

            <p class="meta">
                {{ $ile }} {{ $ile === 1 ? 'oznaczenie' : 'oznaczeń' }} ·
                ostatnie {{ \App\Support\Czas::data($ostatnie, 'j F Y, H:i') }}
            </p>

            <ul class="stack-tight">
                @foreach($wGrupie->take($pozycjiWGrupie) as $oznaczenie)
                    @php($podglad = $podglady[$oznaczenie->target_type.':'.$oznaczenie->target_id] ?? null)
                    <li>
                        <strong>{{ $oznaczenie->reasonLabel() }}</strong>
                        <span class="meta">· {{ $oznaczenie->targetLabel() }} ·
                            {{ \App\Support\Czas::data($oznaczenie->created_at, 'j F Y, H:i') }}</span>

                        @if($oznaczenie->details)
                            <p class="whitespace-pre-line">{{ $oznaczenie->details }}</p>
                        @endif

                        @if($podglad)
                            {{-- PODGLĄD, NIE ZASTĘPNIK PRZECZYTANIA.
                                 Miniatura i początek tekstu są po to, żeby dało się
                                 odsiać oczywiste przypadki wzrokiem — bez otwierania
                                 kilkudziesięciu kart. Odnośnik zostaje, bo przed
                                 prawdziwą decyzją moderator MUSI zobaczyć całość. --}}
                            <div class="sygnal-podglad">
                                @if($podglad['miniatura'] && $podglad['adres'])
                                    <a class="sygnal-podglad-zdjecie" href="{{ $podglad['adres'] }}"
                                       aria-label="{{ $podglad['odnosnik'] }}">
                                        <img src="{{ $podglad['miniatura'] }}" alt="" width="96" height="96" loading="lazy">
                                    </a>
                                @elseif($podglad['miniatura'])
                                    {{-- Konto bez publicznej strony (wymazane albo bez profilu):
                                         miniatura zostaje, odnośnika nie ma dokąd zrobić. --}}
                                    <span class="sygnal-podglad-zdjecie">
                                        <img src="{{ $podglad['miniatura'] }}" alt="" width="96" height="96" loading="lazy">
                                    </span>
                                @endif

                                <div class="sygnal-podglad-tekst">
                                    @if($podglad['tekst'])
                                        <p class="sygnal-podglad-cytat">{{ $podglad['tekst'] }}</p>
                                    @else
                                        <p class="meta">{{ $podglad['pusto'] }}</p>
                                    @endif

                                    @if($podglad['adres'])
                                        <p class="m-0">
                                            <a href="{{ $podglad['adres'] }}">{{ $podglad['odnosnik'] }}</a>
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @else
                            <p class="meta">Tej treści już nie ma w serwisie — autor ją usunął.</p>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{-- `$ile` z agregatu, nie `$wGrupie->count()`: kontroler pobiera
                 najwyżej `$pozycjiWGrupie` pozycji grupy (issue #1060). --}}
            @if($ile > $pozycjiWGrupie)
                <p class="meta">
                    …i jeszcze {{ $ile - $pozycjiWGrupie }} z tego samego konta.
                </p>
            @endif

            {{--
                DWIE DROGI, CELOWO NIERÓWNE.

                „To nic takiego" jest przyciskiem, bo to jest ruch, który
                moderator wykona w większości przypadków — i ma go wykonać
                JEDNYM kliknięciem dla całej grupy, a nie dziesięcioma.

                Prawdziwa decyzja (ukryj, usuń, zawieś) jest odnośnikiem do
                kolejki zgłoszeń, bo formularz decyzji z całym obowiązkiem
                z DSA art. 17 jest w serwisie JEDEN i ma taki zostać. Druga
                jego kopia tutaj rozjechałaby się z tamtą przy pierwszej
                zmianie w pouczeniu.
            --}}
            <form class="mt-4" method="POST" action="{{ route('admin.sygnaly.dismiss') }}">
                @csrf
                <input type="hidden" name="autor" value="{{ $kluczGrupy }}">
                {{-- Identyfikator TEGO wiersza (issue #243): bez niego `id` pola
                     notatki i `old()` po nieudanej walidacji dubluje się na całą
                     stronę — patrz `App\Support\WierszFormularza`. --}}
                <input type="hidden" name="{{ \App\Support\WierszFormularza::POLE }}" value="{{ $kluczGrupy }}">

                <x-field name="note" label="Notatka wewnętrzna" type="textarea" :rows="2" :wiersz="$kluczGrupy"
                         help="Zostaje w logu moderacji. Autor treści jej nie zobaczy — przy tej decyzji nie dostaje żadnego powiadomienia." />

                <button class="btn btn-primary mt-5" type="submit">
                    To nic takiego — zamknij {{ $ile === 1 ? 'to oznaczenie' : 'wszystkie '.$ile }}
                </button>
            </form>

            <p class="meta mt-4">
                Jeśli jednak jest tu co robić:
                <a href="{{ route('admin.reports', ['status' => 'open', 'zrodlo' => 'automat']) }}">rozpatrz oznaczenia pojedynczo</a>
                — tam jest pełny formularz decyzji z uzasadnieniem dla autora.
            </p>
        </article>
    @empty
        <x-empty-state title="Nic tu nie ma">
            Automat nie znalazł dziś niczego, co warto obejrzeć. To jest dobra wiadomość, a nie awaria.
        </x-empty-state>
    @endforelse

    <div class="mt-6">{{ $grupy->links() }}</div>
</x-layout>
