{{--
    Baner szkicu z odczytu zdjęcia kartki (V2, D-298, projekt §6.3).

    Na każdym kroku kreatora i na formularzu jednostronicowym. Liczba
    niepewnych fragmentów jest napisem, nie samym kolorem.
--}}
<div class="notice" role="note">
    <p class="mt-0"><strong>Ten tekst odczytał komputer.</strong>
        Porównaj każdą linijkę ze zdjęciem kartki i popraw, co trzeba.
        Nic się nie opublikuje, dopóki nie klikniesz „Opublikuj”. Szkic jest prywatny — kto ma go widzieć, wybierasz w pierwszym kroku.</p>
    @if(($niepewnych ?? 0) > 0)
        <p class="mb-0"><strong>Do sprawdzenia: {{ $niepewnych }} {{ $niepewnych === 1 ? 'fragment oznaczony' : 'fragmenty oznaczone' }} znakiem [?].</strong>
            Popraw je i usuń znaczniki [? ?] — bez tego przepisu nie da się opublikować.</p>
    @endif
</div>
