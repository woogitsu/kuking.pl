{{--
    „Na którą listę zakupów?” (#2528) — jawny wybór listy docelowej przy
    „Dodaj składniki” na stronie przepisu i w planerze.

    Pole pokazuje się WYŁĄCZNIE osobie, która założyła nazwaną listę — kto ma
    tylko listę domyślną („Na co dzień”), widzi ten sam jeden przycisk co
    dotąd. Domyślnie zaznaczona jest zawsze lista „Na co dzień”: nie zgadujemy
    listy według tego, gdzie człowiek dopisywał ostatnio. Zwykły <select>
    z widoczną etykietą, bez skryptu. Pole `lista` ląduje w tym samym
    formularzu POST co przycisk; pusta wartość = lista domyślna.

    `wiersz` — unikalny na stronie dopisek do `id` (planer ma wiele formularzy).
--}}
@props(['wiersz' => 'przepis'])
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
@endphp
@if($listy !== null && $listy->isNotEmpty())
    <div class="field mt-3">
        <label for="{{ $id }}">Na którą listę zakupów?</label>
        <select class="field-input" id="{{ $id }}" name="lista">
            <option value="">{{ \App\Models\ShoppingList::NAZWA_DOMYSLNEJ }}</option>
            @foreach($listy as $lista)
                <option value="{{ $lista->id }}">{{ $lista->name }}</option>
            @endforeach
        </select>
    </div>
@endif
