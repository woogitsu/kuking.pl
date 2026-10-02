{{--
    Ostrzeżenie o niepełnym imporcie (#2521): co import pominął albo uciął,
    bo przepis przekracza granice formularza. Czytane z bazy przy KAŻDYM
    otwarciu szkicu (`przepisy_z_importu.pominiete`), nie z komunikatu, który
    ginie. Dotyczy zdjęcia, adresu strony i pliku PDF.

    @var ?\App\Domain\Import\PominieteWImporcie $niepelny
--}}
@if(($niepelny ?? null) !== null)
    <div class="notice" role="note" id="import-niepelny">
        <p class="mt-0"><strong>Ten import jest niepełny.</strong></p>
        @if($niepelny->zdaniePominietych() !== '')
            <p class="mt-0">{{ $niepelny->zdaniePominietych() }}</p>
        @endif
        @if($niepelny->zdanieObcietych() !== '')
            <p class="mt-0">{{ $niepelny->zdanieObcietych() }}</p>
        @endif
        <p class="mb-0">
            <strong>Co zrobić:</strong> porównaj szkic ze źródłem, dopisz brakujące pozycje ręcznie albo podziel przepis na dwa.
            Brak takiego ostrzeżenia nie znaczy, że odczyt jest bezbłędny — zawsze porównaj go ze źródłem.
            Nic się nie opublikuje, dopóki nie klikniesz „Opublikuj”.
        </p>
    </div>
@endif
