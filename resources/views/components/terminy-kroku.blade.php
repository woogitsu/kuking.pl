{{--
    „Wyjaśnij to” w trybie gotowania (#2343, D-333).

    Zwykły `<details>`: działa bez JavaScriptu, bez hovera i bez gestu, nic nie
    zapisuje i nic nie wysyła — rozwinięcie nie jest stanem wartym zapamiętania.
    Hasła pochodzą ze statycznego słownika w repozytorium (`SlownikTerminow`),
    nie z modelu. Gdy w kroku nie ma żadnego znanego terminu, nie ma też
    przycisku — żadnego pustego „Wyjaśnij”, które niczego by nie wyjaśniło.
--}}
@props(['tekst'])
@php($terminy = \App\Domain\Recipes\Gotowanie\SlownikTerminow::wTekscie((string) $tekst))
@if($terminy !== [])
    <details class="cook-terminy" data-terminy-kroku>
        <summary>Wyjaśnij to ({{ count($terminy) }})</summary>
        <dl class="cook-terminy-lista">
            @foreach($terminy as $termin)
                <dt>{{ $termin['haslo'] }}</dt>
                <dd>{{ $termin['wyjasnienie'] }}</dd>
            @endforeach
        </dl>
        <p class="cook-terminy-uwaga">To objaśnienie słowa, nie ocena bezpieczeństwa przepisu. Przy przetworach korzystaj z przebadanych zaleceń dla konkretnego produktu. Nie ma tu tego, czego szukasz? <a href="{{ route('search') }}">Poszukaj w wyszukiwarce</a> albo <a href="{{ route('kontakt') }}">napisz do nas</a>.</p>
    </details>
@endif
