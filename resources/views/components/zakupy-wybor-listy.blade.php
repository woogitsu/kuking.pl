{{--
    „Na którą listę zakupów?” (#2528) — jawny wybór listy docelowej przy
    „Dodaj składniki” na stronie przepisu i w planerze, a także na ekranie
    „Wybierz składniki do zakupów” (#2462) i w podglądzie przeliczonych
    porcji (#2489).

    Pole pokazuje się WYŁĄCZNIE osobie, która założyła nazwaną listę — kto ma
    tylko listę domyślną („Na co dzień”), widzi ten sam jeden przycisk co
    dotąd. Domyślnie zaznaczona jest zawsze lista „Na co dzień”: nie zgadujemy
    listy według tego, gdzie człowiek dopisywał ostatnio. Zwykły <select>
    z widoczną etykietą, bez skryptu. Pole `lista` ląduje w tym samym
    formularzu POST co przycisk; pusta wartość = lista domyślna.

    `wiersz` — unikalny na stronie dopisek do `id` (planer ma wiele formularzy).
    `wybrana` — identyfikator listy, którą człowiek już wybrał (powrót po
    ostrzeżeniu, po błędzie albo po zmianie przepisu). Obcy lub nieznany
    identyfikator niczego nie zaznacza, więc zostaje lista domyślna.
    `blad` — pokaż przy polu błąd `lista` z sesji; tylko na ekranach z jednym
    takim polem, żeby planer nie powtarzał go w każdym wierszu.
--}}
@props(['wiersz' => 'przepis', 'wybrana' => null, 'blad' => false])
@php
    $listy = null;
    if (auth()->check()) {
        $listy = request()->attributes->get('zakupy.listy');
        if ($listy === null) {
            $listy = \App\Models\ShoppingList::query()
                ->where('user_id', auth()->id())
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'name']);
            request()->attributes->set('zakupy.listy', $listy);
        }
    }
    $id = 'f-lista-zakupow-'.$wiersz;
    $wybrana = is_string($wybrana) && $wybrana !== '' ? $wybrana : null;
    $bladListy = $blad ? $errors->first('lista') : '';
@endphp
@if($listy !== null && $listy->isNotEmpty())
    <div class="field mt-3">
        <label for="{{ $id }}">Na którą listę zakupów?</label>
        <select class="field-input" id="{{ $id }}" name="lista"
                @if($bladListy) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
            <option value="">{{ \App\Models\ShoppingList::NAZWA_DOMYSLNEJ }}</option>
            @foreach($listy as $lista)
                {{-- Atrybut doklejony bez spacji: niewybrana opcja wygląda dokładnie jak dotąd. --}}
                <option value="{{ $lista->id }}"{{ $wybrana !== null && $lista->id === $wybrana ? ' selected' : '' }}>{{ $lista->name }}</option>
            @endforeach
        </select>
        @if($bladListy)
            <span class="field-error" id="{{ $id }}-error">{{ $bladListy }}</span>
        @endif
    </div>
@endif
