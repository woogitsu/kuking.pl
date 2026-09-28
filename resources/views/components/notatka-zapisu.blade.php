{{--
    Prywatna notatka przy pozycji zeszytu (issue #978).

    Rysuje się WYŁĄCZNIE właścicielowi zeszytu — warunek stoi tutaj, a nie
    przy wywołaniu, żeby publiczny zeszyt nie ujawnił dopisku przez
    zapomniany `@if` w kolejnym widoku. Notatka jest przy parze
    zeszyt–treść, więc ta sama rzecz w dwóch zeszytach ma dwa dopiski.

    Bez JavaScriptu: `<details>` rozwija formularz, puste pole po zapisie
    usuwa notatkę. Po błędzie (za długi tekst) formularz TEJ pozycji wraca
    rozwinięty, z wpisanym tekstem — patrz `:wiersz` w `x-field`.
--}}
@props(['zeszyt', 'typ', 'pozycja'])
@php
    $jestWlascicielem = auth()->id() !== null && auth()->id() === $zeszyt->owner_id;
    $notatka = $pozycja->pivot?->note;
    $wiersz = 'notatka-'.$typ.'-'.$pozycja->getKey();
    $worek = \App\Domain\Collections\Actions\UpdateCollectionItemNote::WOREK_BLEDOW;
    $zBledem = \App\Support\WierszFormularza::jestAktywny($wiersz) && $errors->getBag($worek)->any();
@endphp
@if($jestWlascicielem)
    <div class="notatka-zapisu">
        @if($notatka !== null && $notatka !== '')
            <p class="notatka-zapisu-tresc">
                <strong>Twoja notatka</strong> (widzisz ją tylko Ty):
                <span class="whitespace-pre-line">{{ $notatka }}</span>
            </p>
        @endif
        <details class="mt-2" @if($zBledem) open @endif>
            <summary class="btn btn-quiet inline-flex">{{ $notatka ? 'Zmień notatkę' : 'Dodaj notatkę dla siebie' }}</summary>
            <form class="mt-2" method="POST" action="{{ route('collections.note', ['collection' => $zeszyt, 'typ' => $typ, 'pozycja' => $pozycja->getKey()]) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_wiersz" value="{{ $wiersz }}">
                <x-field name="note" :wiersz="$wiersz" :error-bag="$worek" label="Notatka dla siebie" type="textarea" :rows="3"
                         :value="$notatka" :licznik-znakow="\App\Domain\Collections\Actions\UpdateCollectionItemNote::LIMIT_ZNAKOW"
                         help="Widzisz ją tylko Ty — nie zobaczy jej autor ani nikt, kto ogląda ten zeszyt. Żeby ją usunąć, wyczyść pole i zapisz." />
                <button class="btn btn-primary" type="submit">Zapisz notatkę</button>
            </form>
        </details>
    </div>
@endif
