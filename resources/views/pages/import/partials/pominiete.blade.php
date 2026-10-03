{{--
    Ostrzeżenie o niepełnym imporcie (#2521): co import pominął albo uciął,
    bo przepis przekracza granice formularza. Czytane z bazy przy KAŻDYM
    otwarciu szkicu (`przepisy_z_importu.pominiete`), nie z komunikatu, który
    ginie. Dotyczy zdjęcia, adresu strony i pliku PDF.

    @var ?\App\Domain\Import\PominieteWImporcie $niepelny
--}}
@if(($niepelny ?? null) !== null)
    <div class="notice" role="note" id="import-niepelny">
        <p class="mt-0"><strong>{{ $niepelny->niepelny() ? 'Ten import jest niepełny.' : 'W tym szkicu są pola do uzupełnienia.' }}</strong></p>
        @if($niepelny->zdaniePominietych() !== '')
            <p class="mt-0">{{ $niepelny->zdaniePominietych() }}</p>
        @endif
        @if($niepelny->zdanieObcietych() !== '')
            <p class="mt-0">{{ $niepelny->zdanieObcietych() }}</p>
        @endif
        @if($niepelny->zdanieOstrzezenParsera() !== '')
            <p class="mt-0">{{ $niepelny->zdanieOstrzezenParsera() }}</p>
        @endif
        <p class="mb-0">
            <strong>Co zrobić:</strong> porównaj szkic ze źródłem i uzupełnij wskazane pola.@if($niepelny->niepelny()) Brakujące pozycje dopisz ręcznie albo podziel przepis na dwa.@endif
            Brak takiego ostrzeżenia nie znaczy, że odczyt jest bezbłędny — zawsze porównaj go ze źródłem.
            Nic się nie opublikuje, dopóki nie klikniesz „Opublikuj”.
        </p>
    </div>
@endif
