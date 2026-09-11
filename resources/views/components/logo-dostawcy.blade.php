{{--
    ZNAK MARKI DOSTAWCY WEJŚCIA — Google (i, gdy powstanie, Facebook).

    DLACZEGO OSOBNY KOMPONENT, A NIE `x-ikona`. To są dwie różne umowy
    i pomylenie ich zepsułoby oba. `x-ikona` rysuje KONTUREM i bierze kolor
    z otoczenia (`stroke: currentColor`), żeby jedna ikona obsłużyła motyw
    jasny, ciemny i stan „bieżąca pozycja". Znak marki jest odwrotnością:
    ma STAŁE kolory, których nie wolno zmieniać ani przemalowywać na motyw,
    bo to jest cudzy znak, nie nasza ozdoba.

    IKONA NIE JEST SAMA I TU TEŻ NIE BĘDZIE. `aria-hidden` i
    `focusable="false"` — dokładnie jak w `x-ikona`. Cały sens niesie napis
    obok („Wejdź kontem Google"), a znak jest tylko szybszym rozpoznaniem.

    DLACZEGO ZNAK W OGÓLE DOSZEDŁ, choć komentarz w `x-wejdz-google`
    argumentował przeciw. Tamten argument brzmiał: „na przycisku stoi zdanie,
    a nie kolorowe «G», którego osoba 65-letnia nie musi kojarzyć z niczym" —
    i był o znaku ZAMIAST napisu. Ten komponent daje znak OBOK napisu, więc
    tamten argument go nie dotyczy: kto nie kojarzy „G", czyta zdanie; kto
    kojarzy, znajduje przycisk szybciej. Prośba właściciela z 11.09 była
    dokładnie o to drugie.

    Kształty są oficjalne. Gdyby kiedyś trzeba je było podmienić, podmienia
    się je TUTAJ, w jednym miejscu — nie w widokach.
--}}
@props(['nazwa', 'rozmiar' => 24])

@if($nazwa === 'google')
    <svg class="logo-dostawcy" width="{{ $rozmiar }}" height="{{ $rozmiar }}"
         viewBox="0 0 48 48" aria-hidden="true" focusable="false">
        <path fill="#4285F4" d="M45.12 24.5c0-1.56-.14-3.06-.4-4.5H24v8.51h11.84c-.51 2.75-2.06 5.08-4.39 6.64l7.11 5.52C42.7 36.99 45.12 31.3 45.12 24.5z"/>
        <path fill="#34A853" d="M24 46c5.94 0 10.92-1.97 14.56-5.33l-7.11-5.52c-1.97 1.32-4.49 2.1-7.45 2.1-5.73 0-10.58-3.87-12.31-9.07l-7.35 5.7C7.96 41.07 15.4 46 24 46z"/>
        <path fill="#FBBC05" d="M11.69 28.18C11.25 26.86 11 25.45 11 24s.25-2.86.69-4.18l-7.35-5.7C2.85 17.09 2 20.45 2 24c0 3.55.85 6.91 2.34 9.88l7.35-5.7z"/>
        <path fill="#EA4335" d="M24 10.75c3.23 0 6.13 1.11 8.41 3.29l6.31-6.31C34.91 4.18 29.93 2 24 2 15.4 2 7.96 6.93 4.34 14.12l7.35 5.7c1.73-5.2 6.58-9.07 12.31-9.07z"/>
    </svg>
@endif
