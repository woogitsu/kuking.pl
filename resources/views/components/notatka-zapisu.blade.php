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
{{--
    WSPÓLNY ZESZYT (#1743, D-302): notatkę widzą i piszą wszyscy, którzy mają
    ważny dostęp do zeszytu — właściciel i współpracownicy. `dostep` liczy
    kontroler raz na stronę (Policy `removeItem`), żeby nie pytać bazy przy
    każdej pozycji; bez niego — dawna reguła „tylko właściciel". Obcy
    oglądający publiczny zeszyt dalej nie widzi niczego.
--}}
@props(['zeszyt', 'typ', 'pozycja', 'dostep' => null, 'wspolny' => false])
@php
    $jestWlascicielem = auth()->id() !== null && ($dostep ?? auth()->id() === $zeszyt->owner_id);
    $notatka = $pozycja->pivot?->note;
    $wiersz = 'notatka-'.$typ.'-'.$pozycja->getKey();
    $worek = \App\Domain\Collections\Actions\UpdateCollectionItemNote::WOREK_BLEDOW;
    $zBledem = \App\Support\WierszFormularza::jestAktywny($wiersz) && $errors->getBag($worek)->any();
@endphp
@if($jestWlascicielem)
    <div class="notatka-zapisu">
        @if($notatka !== null && $notatka !== '')
            <p class="notatka-zapisu-tresc">
                @if($wspolny)
                    <strong>Notatka</strong> (widzą ją osoby, które mają dostęp do tego zeszytu):
                @else
                    <strong>Twoja notatka</strong> (widzisz ją tylko Ty):
                @endif
                <span class="whitespace-pre-line">{{ $notatka }}</span>
            </p>
        @endif
        <details class="mt-2" @if($zBledem) open @endif>
            <summary class="btn btn-quiet inline-flex">{{ $notatka ? 'Zmień notatkę' : ($wspolny ? 'Dodaj notatkę' : 'Dodaj notatkę dla siebie') }}</summary>
            <form class="mt-2" method="POST" action="{{ route('collections.note', ['collection' => $zeszyt, 'typ' => $typ, 'pozycja' => $pozycja->getKey()]) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_wiersz" value="{{ $wiersz }}">
                <x-field name="note" :wiersz="$wiersz" :error-bag="$worek" :label="$wspolny ? 'Notatka' : 'Notatka dla siebie'" type="textarea" :rows="3"
                         :value="$notatka" :licznik-znakow="\App\Domain\Collections\Actions\UpdateCollectionItemNote::LIMIT_ZNAKOW"
                         :help="$wspolny
                            ? 'Widzą ją osoby, które mają dostęp do tego zeszytu. Nie zobaczy jej autor ani nikt inny, kto ogląda ten zeszyt. Żeby ją usunąć, wyczyść pole i zapisz.'
                            : 'Widzisz ją tylko Ty — nie zobaczy jej autor ani nikt, kto ogląda ten zeszyt. Żeby ją usunąć, wyczyść pole i zapisz.'" />
                <button class="btn btn-primary" type="submit">Zapisz notatkę</button>
            </form>
        </details>
    </div>
@endif
