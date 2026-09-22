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

    STRONA Z WIELOMA FORMULARZAMI TEGO SAMEGO KSZTAŁTU (issue #243)
    Gdy jedna strona stawia to samo pole wiele razy w pętli — po jednym
    formularzu na sprawę, jak w `/admin/sygnaly` — podaj `:wiersz`
    z identyfikatorem TEJ sprawy (id, UUID, cokolwiek unikalnego na stronie).
    Bez tego dwie usterki naraz: `id` się dubluje (przeglądarka wiąże
    `<label for="f-note">` z PIERWSZYM takim polem w dokumencie), a `old()`
    po nieudanej walidacji jednego formularza wypełnia TĄ SAMĄ treścią
    wszystkie pozostałe pola o tej nazwie na stronie — moderator widzi
    cudzą notatkę przy swojej sprawie. `:wiersz` naprawia oba naraz: dokłada
    identyfikator do `id` (patrz `App\Support\WierszFormularza`) i pokazuje
    `old()`/błąd walidacji WYŁĄCZNIE w polu tego wiersza, który naprawdę
    wrócił z błędem — wymaga ukrytego pola
    `<input type="hidden" name="_wiersz" value="...">` w każdym formularzu
    pętli, z tą samą wartością.
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
    'wiersz' => null,
    'errorBag' => 'default',
    /*
     * LIMIT ZNAKÓW WIDOCZNY PRZED WYSŁANIEM (issue #762).
     *
     * Serwer i tak odrzuca za długi tekst (`max:4000` w każdym kontrolerze
     * komentarzy) — problemem nie była walidacja, tylko to, że człowiek
     * dowiadywał się o limicie DOPIERO po nieudanym POST/PUT, po stracie
     * czasu na wysłanie i po tym, jak formularz i tak musiał odzyskać jego
     * tekst z sesji.
     *
     * Podpowiedź pod polem („Najwyżej N znaków.") działa BEZ JavaScriptu —
     * to jest cała naprawa dla kogoś bez JS, zgodnie z D-053: pole ma
     * działać także wtedy, gdy skrypt się nie doczyta. `resources/js/licznik-znakow.js`
     * dokłada NA TO tekst „na żywo" (ile zostało / o ile za dużo) — to jest
     * ulepszenie, nie warunek działania.
     *
     * ŚWIADOMIE TYLKO DLA `textarea`. Pola jednowierszowe w tym serwisie nie
     * mają limitu, przy którym człowiek realnie się zbliża do granicy —
     * dokładanie licznika tam byłoby szumem bez odbiorcy.
     */
    'licznikZnakow' => null,
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
    $idSufiks = $wiersz !== null ? '-'.str_replace(['[', ']', '.'], '-', (string) $wiersz) : '';
    $id = $id ?? 'f-'.str_replace(['[', ']', '.'], '-', $name).$idSufiks;

    // Czy WOLNO temu polu pokazać old()/błąd z sesji: zawsze, jeśli pole nie
    // jest w pętli (`wiersz` nie podane), i tylko dla wiersza, którego
    // formularz naprawdę wrócił z błędem, jeśli jest.
    $tenWiersz = $wiersz === null || \App\Support\WierszFormularza::jestAktywny($wiersz);
    // Ten sam przypadek co w `x-error-summary`: widok może dostać gotowy
    // `MessageBag` zamiast `ViewErrorBag`, a ten nie ma `getBag()`.
    $workiBledow = $errors instanceof \Illuminate\Support\ViewErrorBag ? $errors->getBag($errorBag) : $errors;
    $error = $tenWiersz ? $workiBledow->first($name) : null;
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
    $current = $type === 'password' ? null : ($wire === null ? ($tenWiersz ? old($name, $value) : $value) : $value);

    /*
     * OCHRONA TYPU PRZED WYPISANIEM (issue #745).
     *
     * `old($name, $value)` bierze wprost to, co przyszło w żądaniu HTTP —
     * a HTML pozwala przesłać `name[]=coś` tam, gdzie pole jest zwykłym
     * `<input type="text">`. Walidator (`string`) taki wpis odrzuca, ale
     * ODRZUCA GO PO tym, jak trafił do sesji przez `withInput()`: `old()`
     * po redirect nadal zwraca tablicę. `{{ $current }}` w Blade wywołuje
     * `e()`, a `htmlspecialchars()` na tablicy rzuca `TypeError` — więc
     * zamiast błędu przy POLU wywalał się render CAŁEJ reszty formularza
     * (500), a poprawnie wypełnione pola znikały razem z nim.
     *
     * To pole (`x-field`) reprezentuje jedną wartość skalarną, więc każdy
     * typ inny niż skalar/`null` jest tu z definicji niepoprawnym wejściem
     * — wypisujemy pustą wartość i zostawiamy istniejący błąd walidacji
     * ($error, policzony wyżej z $errors->first(), którego to nie dotyczy)
     * żeby było widać, co poprawić. Grupy tablicowe (składniki, tagi,
     * kroki) NIE wchodzą przez ten komponent — mają własne pętle nad
     * old() — więc to zawężenie ich nie dotyka.
     */
    if ($current !== null && ! is_scalar($current)) {
        $current = '';
    }

    $licznikZnakow = $type === 'textarea' ? $licznikZnakow : null;
    $describedBy = collect([
        $help ? $id.'-help' : null,
        $licznikZnakow ? $id.'-licznik' : null,
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
                  {{--
                      `data-licznik` NIE jest atrybutem `maxlength`, celowo.
                      `maxlength` obcina wklejony tekst na poziomie
                      przeglądarki — a issue #762 wprost tego zakazuje: człowiek
                      ma zobaczyć, o ile jest za długo, i sam zdecydować, co
                      skrócić, nie stracić bez ostrzeżenia końcówkę wklejonego
                      tekstu. Licznik jest więc wyłącznie INFORMACYJNY;
                      rozstrzyga serwer (`max:4000` w kontrolerze).
                  --}}
                  @if($licznikZnakow) data-licznik="{{ $licznikZnakow }}" data-licznik-cel="{{ $id }}-licznik" @endif
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

    @if($licznikZnakow)
        {{--
            DZIAŁA BEZ JAVASCRIPTU (AGENTS.md, D-053): to zdanie stoi tu
            zawsze, niezależnie od tego, czy skrypt się doczyta. Bez JS to
            jest CAŁA informacja o limicie — i wystarcza, żeby człowiek wiedział
            PRZED wysłaniem, ile miejsca ma na tekst; walidacja serwera
            (`max:4000`) rozstrzyga i tak.

            `resources/js/licznik-znakow.js` PODMIENIA tę treść na „na żywo”
            (ile zostało / o ile za dużo) — patrz ten plik po uzasadnienie,
            dlaczego podmiana, a nie osobny drugi element.
        --}}
        <p class="field-help" id="{{ $id }}-licznik">Najwyżej {{ $licznikZnakow }} znaków.</p>
    @endif

    @if($error)
        <span class="field-error" id="{{ $id }}-error">{{ $error }}</span>
    @endif
</div>
