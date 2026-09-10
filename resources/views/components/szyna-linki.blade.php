@props(['pozycje'])

{{--
    Lista odnośników w bloku szyny: nazwa, pod nią jedno zdanie wyjaśnienia.

    `pozycje` to lista tablic `['href' => …, 'nazwa' => …, 'podpis' => …]`.
    `podpis` jest opcjonalny, `nazwa` nie — pozycja bez nazwy byłaby
    odnośnikiem bez treści.

    CAŁY WIERSZ JEST CELEM DOTKNIĘCIA, nie sam napis: `.szyna-pozycja-link`
    ma `min-height: var(--control-height-min)` (48 px) i wcięcie dookoła.
    To jest ta sama klasa, którą od kitu v2 ma „Twój zeszyt" na Starcie —
    dlatego ten komponent nie dokłada ani jednej reguły CSS.

    IKONY TU NIE MA CELOWO. W szynie stoi kilka takich list obok siebie
    i ikona przy każdej pozycji zamieniłaby je w ścianę znaczków, z których
    żaden nic nie znaczy (AGENTS.md §5: ikona nigdy nie jest jedynym opisem —
    a tutaj byłaby opisem zbędnym).
--}}

<ul class="szyna-lista">
    @foreach($pozycje as $pozycja)
        <li class="szyna-pozycja">
            <a class="szyna-pozycja-link" href="{{ $pozycja['href'] }}">
                <span class="min-w-0">
                    <span class="szyna-nazwa">{{ $pozycja['nazwa'] }}</span>
                    @if(($pozycja['podpis'] ?? null) !== null)
                        <span class="meta szyna-podpis">{{ $pozycja['podpis'] }}</span>
                    @endif
                </span>
            </a>
        </li>
    @endforeach
</ul>
