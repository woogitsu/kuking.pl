@props(['pozycje', 'akcja' => null])

{{--
    Lista odnośników w bloku szyny: nazwa, pod nią jedno zdanie wyjaśnienia.

    `pozycje` to lista tablic `['href' => …, 'nazwa' => …, 'podpis' => …]`.
    `podpis` jest opcjonalny, `nazwa` nie — pozycja bez nazwy byłaby
    odnośnikiem bez treści.

    W wariancie domyślnym CAŁY WIERSZ JEST CELEM DOTKNIĘCIA: `.szyna-pozycja-link`
    ma `min-height: var(--control-height-min)` (48 px) i wcięcie dookoła.
    To jest ta sama klasa, którą od kitu v2 ma „Mój zeszyt" na Starcie —
    dlatego wariant domyślny nie wymaga dodatkowej reguły CSS.
    Opcjonalna `akcja` wybiera krótki link z rozszerzonym kliknięciem
    (D-211, marka-zeszyt.css), gdy nazwa z opisem mogą przekroczyć ekran.

    IKONY TU NIE MA CELOWO. W szynie stoi kilka takich list obok siebie
    i ikona przy każdej pozycji zamieniłaby je w ścianę znaczków, z których
    żaden nic nie znaczy (AGENTS.md §5: ikona nigdy nie jest jedynym opisem —
    a tutaj byłaby opisem zbędnym).
--}}

<ul class="szyna-lista">
    @foreach($pozycje as $pozycja)
        <li @class(['szyna-pozycja', 'szyna-pozycja-krotka' => $akcja !== null])>
            @if($akcja !== null)
                {{-- D-211: pełna nazwa i opis nie powiększają obszaru fokusu
                     ponad ekran. Jeden krótki link otwiera całą pozycję. --}}
                <span class="szyna-nazwa">{{ $pozycja['nazwa'] }}</span>
                @if(($pozycja['podpis'] ?? null) !== null)
                    <span class="meta szyna-podpis">{{ $pozycja['podpis'] }}</span>
                @endif
                <a class="szyna-otworz" href="{{ $pozycja['href'] }}"
                   aria-label="{{ $akcja }}: {{ $pozycja['nazwa'] }}">{{ $akcja }}</a>
            @else
            <a class="szyna-pozycja-link" href="{{ $pozycja['href'] }}">
                <span class="min-w-0">
                    <span class="szyna-nazwa">{{ $pozycja['nazwa'] }}</span>
                    @if(($pozycja['podpis'] ?? null) !== null)
                        <span class="meta szyna-podpis">{{ $pozycja['podpis'] }}</span>
                    @endif
                </span>
            </a>
            @endif
        </li>
    @endforeach
</ul>
