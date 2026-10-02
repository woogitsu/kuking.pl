{{--
    Miejsce na dyktowanie przy polu przepisu (issue #2377, D-333: „Budujemy
    z ostrzeżeniem”).

    W HTML-u stoi WYŁĄCZNIE ten pusty znacznik. Przycisk „Dyktuj”, podgląd
    i zdanie o dostawcy rozpoznawania mowy dorysowuje `resources/js/dyktowanie.js`,
    i to tylko w przeglądarce, która ma `SpeechRecognition` albo
    `webkitSpeechRecognition` (D-053: bez skryptu nie ma martwego przycisku,
    a formularz działa jak dotąd). `wire:ignore` chroni dorysowaną zawartość
    przed przerysowaniem przez Livewire w kreatorze.

    Mikrofon jest odblokowany w nagłówku Permissions-Policy WYŁĄCZNIE na
    trasach kreatora przepisu (`ApplySecurityHeaders::trasaKreatoraPrzepisu`),
    więc ten komponent ma sens tylko na tych stronach.

    $cel        id pola, do którego „Wstaw do przepisu” dopisuje tekst
    $separator  „spacja” (domyślnie) albo „nowa-linia” — czym oddzielić
                dopisek od tego, co już stoi w polu
--}}
@props(['cel', 'separator' => 'spacja'])
<div data-dyktowanie data-cel="{{ $cel }}" data-separator="{{ $separator }}" wire:ignore></div>
