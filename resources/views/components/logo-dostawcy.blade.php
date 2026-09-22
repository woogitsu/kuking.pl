{{--
    ZNAK MARKI DOSTAWCY WEJŚCIA — Google i Facebook.

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

    ZNAK FACEBOOKA DOSZEDŁ RAZEM ZE SWOJĄ DROGĄ WEJŚCIA (issue #259), a nie
    wcześniej — dokładnie tak, jak zapowiadał to komentarz w
    `x-wejscia-zewnetrzne`: zasób, którego nic nie renderuje, przy następnym
    czytaniu wygląda jak zapomniany kod. Jest to jedna ścieżka („f"
    w kole) w firmowym niebieskim #1877F2, bez gradientów i bez cieni —
    czyli to, co Meta rozdaje jako znak podstawowy.
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

@if($nazwa === 'facebook')
    <svg class="logo-dostawcy" width="{{ $rozmiar }}" height="{{ $rozmiar }}"
         viewBox="0 0 48 48" aria-hidden="true" focusable="false">
        {{-- Koło w firmowym niebieskim i białe „f" — dwie ścieżki, zero
             gradientów. Kolory są STAŁE, tak jak przy Google: to cudzy znak,
             nie nasza ozdoba, więc nie bierze koloru z motywu. --}}
        <path fill="#1877F2" d="M46 24C46 11.85 36.15 2 24 2S2 11.85 2 24c0 10.98 8.04 20.08 18.56 21.73V30.36h-5.59V24h5.59v-4.85c0-5.51 3.28-8.56 8.31-8.56 2.41 0 4.93.43 4.93.43v5.42h-2.78c-2.73 0-3.58 1.7-3.58 3.44V24h6.1l-.98 6.36h-5.12v15.37C37.96 44.08 46 34.98 46 24z"/>
        <path fill="#FFFFFF" d="M32.56 30.36 33.54 24h-6.1v-4.12c0-1.74.85-3.44 3.58-3.44h2.78v-5.42s-2.52-.43-4.93-.43c-5.03 0-8.31 3.05-8.31 8.56V24h-5.59v6.36h5.59v15.37c1.12.18 2.27.27 3.44.27s2.32-.09 3.44-.27V30.36h5.12z"/>
    </svg>
@endif
