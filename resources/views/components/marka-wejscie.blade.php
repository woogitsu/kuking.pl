@props(['opis'])

<div class="marka-wejscie">
    <div class="marka-wejscie-naglowek">{{ $naglowek }}</div>
    <div class="marka-wejscie-zaproszenie">
        <div class="marka-wejscie-znak" aria-hidden="true"><x-kuking-mark /></div>
        <p>{{ $opis }}</p>
        @isset($uzupelnienie)
            <div class="marka-wejscie-dalsze">{{ $uzupelnienie }}</div>
        @endisset
    </div>
    <div class="marka-wejscie-karta">
        {{ $slot }}
    </div>
</div>
