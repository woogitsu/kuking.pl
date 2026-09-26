{{--
    Wersja TEKSTOWA tygodniowego podsumowania (issue #11, zakres).

    Nie jest to „to samo bez znaczników". Ten wariant czyta:
      * klient pocztowy ustawiony na zwykły tekst (u osób 50+ zdarza się to
        częściej niż gdziekolwiek indziej — tak bywa skonfigurowana poczta
        na starszym komputerze albo w programie sprzed lat),
      * czytnik ekranu, gdy człowiek woli go od wersji HTML,
      * filtr antyspamowy, dla którego list bez wersji tekstowej jest sam
        w sobie sygnałem ostrzegawczym.

    Dlatego pełne adresy są WYPISANE, a nie schowane pod słowem: w zwykłym
    tekście nie ma czego kliknąć poza tym, co widać.

    Bez znaku „—" w środku zdań: część starszych klientów pocztowych
    zamienia go na krzaczek. W tej wersji wystarczy dwukropek i myślnik ASCII.
--}}
Dzień dobry, {{ $imie }},
@if($tresc->wykonania !== [])

KTOŚ UGOTOWAŁ Z TWOJEGO PRZEPISU
@foreach($tresc->wykonania as $wykonanie)

{{ $wykonanie->user?->displayName() }}: {{ $wykonanie->recipe?->title }}
@if(filled($wykonanie->note))
"{{ \Illuminate\Support\Str::limit($wykonanie->note, 180) }}"
@endif
Podziękuj: {{ route('cooked.show', ['cookedEvent' => $wykonanie->getKey()]) }}
@endforeach
@endif
@if($tresc->ileNowychObserwujacych > 0)

NOWE OSOBY PRZY TWOIM GOTOWANIU

@php
    $imiona = array_map(fn ($osoba) => $osoba->displayName(), $tresc->nowiObserwujacy);
    $pozostali = $tresc->ileNowychObserwujacych - count($imiona);
@endphp
{{ implode(', ', $imiona) }}@if($pozostali > 0) i jeszcze {{ $pozostali }} {{ \App\Support\Odmiana::rzeczownik($pozostali, 'osoba', 'osoby', 'osób') }}@endif - od tego tygodnia widzą, co gotujesz.
@endif
@if($tresc->wpisyObserwowanych !== [])

CO POKAZALI LUDZIE, KTÓRYCH OBSERWUJESZ
@foreach($tresc->wpisyObserwowanych as $wpis)

{{ $wpis->author?->displayName() }}@if(filled($wpis->body)): {{ \Illuminate\Support\Str::limit($wpis->body, 140) }}@elseif($wpis->recipe !== null): {{ $wpis->recipe->title }}@endif

{{ route('posts.show', ['post' => $wpis->getKey()]) }}
@endforeach
@endif
@if($tresc->pytanieGospodarza !== null)

{{ $tresc->pytanieGospodarza }}
@endif

Dobrego tygodnia,
{{ $gospodarz }}

--
Piszę raz w tygodniu i tylko wtedy, gdy jest o czym. Na tę wiadomość można po
prostu odpowiedzieć - czytam wszystkie odpowiedzi.

Nie chcesz tych wiadomości? Wyłącz je jednym kliknięciem. Bez pytań:
{{ $wypisz }}

Kuking.pl - pokaż, co dziś {{ \App\Support\Forma::dla($tresc->odbiorca, 'ugotowałaś', 'ugotowałeś', 'gotujesz') }}.
