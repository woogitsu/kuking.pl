{{-- Oznaczenie alergenów przez autora przepisu (#1902, D-333) — pola wspólne dla kreatora
     (`wire:model`) i formularza bez kreatora (zwykłe pola POST). Renderowane tylko przy
     włączonej fladze `kuking.alergeny.wlaczone`; to sprawdza wywołujący.

     Cztery rzeczy, które ten blok MUSI robić i których nie wolno mu zmienić:
       1. Nic nie jest zaznaczone samo. Podpowiedź ze słownika (etap 2) jest tekstem pod
          polem, nie zaznaczeniem — o zapisie decyduje kliknięcie autora.
       2. Zaznaczone alergeny bez pola „Składniki sprawdzone” nie zapisują się jako
          deklaracja; błąd jest przy polu i w podsumowaniu (klucz `alergeny`).
       3. Ekran nigdy nie komunikuje braku podpowiedzi — jej brak nic nie znaczy.
       4. Żadnych haseł obiecujących cokolwiek (lista zakazanych słów: `AlergenySlownictwoTest`).

     $wire           bool   — kreator Livewire (`wire:model`) albo zwykły formularz
     $wybrane        list<string>  zaznaczone kody
     $potwierdzone   bool   — pole „Składniki sprawdzone”
     $stan           string — zapisany stan przepisu: unchecked | declared | needs_review
     $podpowiedzi    array<string, list<string>>  kod => fragmenty składników, w których słownik
                     widzi ten alergen (etap 2); puste = nic nie mówimy
     $wPrzegladzie   bool   — pokazać przycisk „Składniki nadal się zgadzają” (tylko kreator) --}}
@props([
    'wire' => false,
    'wybrane' => [],
    'potwierdzone' => false,
    'stan' => 'unchecked',
    'podpowiedzi' => [],
    'przyciskPrzegladu' => false,
])
<fieldset class="border-0 p-0 mt-6 mb-6" id="f-alergeny"
          @error('alergeny') tabindex="-1" aria-invalid="true" aria-describedby="f-alergeny-error" @enderror>
    <legend class="font-bold mb-3">Alergeny <span class="meta">(nieobowiązkowe)</span></legend>

    <p class="meta mb-3">
        Zaznacz alergeny, które są w składnikach tego przepisu. Jeśli nie wiesz lub nie chcesz,
        zostaw puste — przy przepisie pojawi się „nie sprawdzono”.
    </p>

    @if($stan === \App\Models\Recipe::ALERGENY_DO_PRZEGLADU)
        <div class="notice" role="note">
            <p class="mt-0 mb-0">
                <strong>Zmieniono składniki po zaznaczeniu alergenów. Sprawdź listę jeszcze raz.</strong>
                Dopóki jej nie potwierdzisz, przy przepisie stoi „nie sprawdzono”.
                @unless($przyciskPrzegladu)
                    Zaznacz pole „Składniki sprawdzone” i zapisz przepis.
                @endunless
            </p>
            @if($przyciskPrzegladu)
                <button class="btn btn-secondary mt-3" type="button" wire:click="potwierdzAlergenyPonownie">Składniki nadal się zgadzają</button>
            @endif
        </div>
    @endif

    <div class="choice-grid">
        @foreach(\App\Domain\Recipes\Alergeny\Alergen::cases() as $alergen)
            <label class="choice">
                @if($wire)
                    <input type="checkbox" wire:model="alergeny" value="{{ $alergen->value }}">
                @else
                    <input type="checkbox" name="alergeny[]" value="{{ $alergen->value }}" @checked(in_array($alergen->value, $wybrane, true))>
                @endif
                <span>
                    <span class="choice-label">{{ $alergen->etykieta() }}</span>
                    @if(! in_array($alergen->value, $wybrane, true) && ($podpowiedzi[$alergen->value] ?? []) !== [])
                        @php $fragmenty = implode('”, „', array_slice($podpowiedzi[$alergen->value], 0, 3)); @endphp
                        <span class="choice-help">Podpowiedź: w „{{ $fragmenty }}” widzimy {{ $alergen->nazwa() }}. To tylko podpowiedź — zaznacz to pole, jeśli to prawda.</span>
                    @endif
                </span>
            </label>
        @endforeach
    </div>

    <div class="field mt-4 @error('alergeny') has-error @enderror">
        <label class="choice">
            @if($wire)
                <input type="checkbox" wire:model="alergenyPotwierdzone" id="f-alergeny-potwierdzone">
            @else
                <input type="checkbox" name="alergeny_potwierdzone" value="1" id="f-alergeny-potwierdzone" @checked($potwierdzone)>
            @endif
            <span>
                <span class="choice-label">Składniki sprawdzone — zaznaczone alergeny to wszystkie, o których wiem</span>
                <span class="choice-help">
                    Bez tego pola zaznaczenia nie zapiszą się jako oznaczenie. Jeśli żaden z alergenów
                    nie występuje, zaznacz tylko to pole — przy przepisie pojawi się wtedy „autor nie zaznaczył
                    żadnego z 14 alergenów”. Żeby cofnąć oznaczenie, odznacz wszystkie alergeny i to pole.
                </span>
            </span>
        </label>
    </div>

    <x-blad-grupy name="alergeny" />

    <p class="field-help">
        To Twoje zaznaczenie, nie badanie. Gotowe produkty (sosy, kiełbasy, przyprawy, proszek do pieczenia)
        mogą zawierać alergeny, których nie widać w nazwie — zajrzyj na etykiety.
    </p>

    @if(! $wire)
        {{-- Znacznik: ten formularz ma sekcję alergenów. Bez niego (inny ekran, flaga wyłączona)
             brak pól znaczy „bez zmian”, nie „cofnij oznaczenie”. --}}
        <input type="hidden" name="alergeny_formularz" value="1">
    @endif
</fieldset>
