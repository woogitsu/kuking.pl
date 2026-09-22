@props(['opis'])

<div class="marka-wejscie">
    <div class="marka-wejscie-zaproszenie">
        <div class="marka-wejscie-znak" aria-hidden="true"><x-kuking-mark /></div>
        <p>{{ $opis }}</p>
    </div>
    <div class="marka-wejscie-karta">
        {{ $slot }}
    </div>
</div>
