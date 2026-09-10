{{--
    Pole formularza.

    Etykieta jest ZAWSZE widoczna — placeholder nie jest etykietą.
    Błąd jest powiązany z polem przez aria-describedby, więc czytnik ekranu
    przeczyta go razem z etykietą.

    Pole działa w dwóch trybach:

    - zwykły formularz HTML (domyślny): wartość bierze się z old() i przetrwa
      nieudaną walidację bez JavaScriptu;
    - kreator Livewire (`wire="nazwa.wlasciwosci"`): wartość bierze się ze
      stanu komponentu, a `wire:model` ma ZAWSZE debounce — `wire:model.live`
      bez debounce psuje INP (docs/seo/SEO_TECHNICAL.md). W tym trybie old()
      jest świadomie pomijane, żeby dane z innego, wcześniejszego formularza
      nie nadpisały tego, co człowiek widzi w kreatorze.
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'required' => false,
    /*
     * `bezOznaczenia` — nie pokazuj „(wymagane)" ani „(nieobowiązkowe)".
     *
     * Zgłoszenie właściciela: „po co informacja «wymagane» przy napisz
     * komentarz?". Odpowiedź: po nic. Oznaczenie ma JEDEN sens —
     * odróżnić pola, które trzeba wypełnić, od tych, które można pominąć.
     * Przy formularzu z JEDNYM polem nie ma czego odróżniać, więc dopisek
     * nie niesie informacji, a zabiera uwagę przy etykiecie, która jest
     * jednocześnie wezwaniem do działania („Napisz komentarz").
     *
     * DLACZEGO NIE LICZYMY PÓL AUTOMATYCZNIE. Bo składnik nie wie, ile pól
     * ma formularz, w którym stoi — a gdyby wiedział, decyzja o pokazaniu
     * dopisku zależałaby od tego, czy ktoś obok dołożył pole. Wolimy jawny
     * wybór w miejscu wywołania: widać go przy formularzu i nie zmienia się
     * pod wpływem czegoś, czego autor tego formularza nie widzi.
     *
     * NIE UŻYWAĆ, ŻEBY „ODCHUDZIĆ" FORMULARZ Z KILKOMA POLAMI. Tam
     * oznaczenie jest potrzebne, a przy grupie 50+ szczególnie
     * „(nieobowiązkowe)" — bo bez niego człowiek wypełnia wszystko
     * i porzuca formularz w połowie.
     */
    'bezOznaczenia' => false,
    'autocomplete' => null,
    'placeholder' => null,
    'rows' => null,
    'inputmode' => null,
    'min' => null,
    'max' => null,
    'step' => null,
    'wire' => null,
    'wireModifier' => 'live.debounce.3000ms',
    'id' => null,
])
@php
    /*
     * IDENTYFIKATOR WYPROWADZONY Z NAZWY POLA — CHYBA ŻE PODANY WPROST.
     *
     * Wyprowadzanie z `name` jest wygodne i w większości formularzy
     * poprawne, ale ZAŁAMUJE SIĘ, gdy jedna strona ma dwa formularze z
     * polem o tej samej nazwie. Tak było na `/ustawienia/bezpieczenstwo`:
     * „Nowe hasło" i „Wpisz swoje hasło" (wylogowanie innych urządzeń) to
     * oba `name="password"`, więc oba dostawały `id="f-password"`.
     *
     * Skutek nie był kosmetyczny. Kliknięcie etykiety „Wpisz swoje hasło"
     * przenosiło fokus 740 px wyżej, do pola „Nowe hasło" w INNYM
     * formularzu — czyli człowiek wpisywał hasło nie tam, gdzie patrzył.
     * Zduplikowany `id` psuł też `aria-describedby`: czytnik ekranu czytał
     * przy drugim polu podpowiedź pierwszego.
     *
     * Dlatego `id` da się teraz podać jawnie. Domyślne zachowanie zostaje
     * bez zmian, żeby nie ruszać kilkudziesięciu poprawnych formularzy.
     */
    $id = $id ?? 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
    $binding = $wire === null ? null : 'wire:model.'.$wireModifier;

    /*
     * POLE HASŁA NIGDY NIE WRACA Z WARTOŚCIĄ (audyt W7-03).
     *
     * To jest druga warstwa, nie pierwsza. Pierwszą jest `OdzyskiwalneDane`:
     * hasło nie ma prawa trafić do flasha sesji, więc `old()` nie ma czego
     * zwrócić. Ale to jest umowa, o której nowe pośrednie warstwy mogą
     * zapomnieć — a każde zapomnienie kończy się hasłem w atrybucie `value`
     * w HTML-u, czyli w DOM-ie, w narzędziach deweloperskich i w zasięgu
     * każdego dodatku do przeglądarki.
     *
     * Utrata wpisanego hasła po nieudanej walidacji jest kosztem żadnym:
     * hasło wpisuje się z pamięci albo z menedżera, a nie pisze się go
     * przez kwadrans jak przepis.
     */
    $current = $type === 'password' ? null : ($wire === null ? old($name, $value) : $value);
    $describedBy = collect([
        $help ? $id.'-help' : null,
        $error ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp
<div class="field @if($error) has-error @endif">
    <label for="{{ $id }}">
        {{ $label }}
        @unless($bezOznaczenia)
            @if($required)
                <span class="meta">(wymagane)</span>
            @else
                <span class="meta">(nieobowiązkowe)</span>
            @endif
        @endunless
    </label>

    @if($help)
        <span class="field-help" id="{{ $id }}-help">{{ $help }}</span>
    @endif

    @if($type === 'textarea')
        <textarea class="field-input" id="{{ $id }}" name="{{ $name }}"
                  rows="{{ $rows ?? 5 }}"
                  @if($binding) {{ $binding }}="{{ $wire }}" @endif
                  @if($placeholder) placeholder="{{ $placeholder }}" @endif
                  @if($required) required @endif
                  @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
                  @if($error) aria-invalid="true" @endif
        >{{ $current }}</textarea>
    @else
        <input class="field-input" id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
               value="{{ $current }}"
               @if($binding) {{ $binding }}="{{ $wire }}" @endif
               @if($placeholder) placeholder="{{ $placeholder }}" @endif
               @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
               @if($inputmode) inputmode="{{ $inputmode }}" @endif
               @if($min !== null) min="{{ $min }}" @endif
               @if($max !== null) max="{{ $max }}" @endif
               @if($step !== null) step="{{ $step }}" @endif
               @if($required) required @endif
               @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
               @if($error) aria-invalid="true" @endif>
    @endif

    @if($error)
        <span class="field-error" id="{{ $id }}-error">{{ $error }}</span>
    @endif
</div>
