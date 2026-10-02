{{--
    POLE „ZAPAMIĘTAJ MNIE NA TYM URZĄDZENIU” (#2708, pyt. 15, decyzja właściciela z 2.10.2026).

    DOMYŚLNIE ZAZNACZONE: zaznaczone = zostajesz zalogowany/a po zamknięciu
    przeglądarki (ciasteczko ważne 400 dni), odznaczone = zwykła sesja.
    Ukryte `0` przed polem wyboru odróżnia „odznaczone” od „formularza bez tego
    pola” (patrz `App\Support\ZapamietajMnie`). Etykieta jest klikalna, pole
    ma 48 px obszaru, całość działa bez JavaScriptu.
--}}
@props(['id' => 'f-zapamietaj', 'form' => null])
@php($zaznaczone = old(\App\Support\ZapamietajMnie::POLE, '1') !== '0')

<div class="field">
    <input type="hidden" name="{{ \App\Support\ZapamietajMnie::POLE }}" value="0" @if($form) form="{{ $form }}" @endif>
    <label class="choice" for="{{ $id }}">
        <input id="{{ $id }}" type="checkbox" name="{{ \App\Support\ZapamietajMnie::POLE }}" value="1" aria-describedby="{{ $id }}-pomoc" @if($form) form="{{ $form }}" @endif @checked($zaznaczone)>
        <span class="choice-label">Zapamiętaj mnie na tym urządzeniu</span>
    </label>
    <span class="field-help" id="{{ $id }}-pomoc">Na cudzym lub wspólnym komputerze odznacz to pole.</span>
</div>
