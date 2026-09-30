{{--
    Wersja TEKSTOWA sobotniego przypomnienia o produktach (#1903, D-333).
    Pełne adresy wypisane, bo w zwykłym tekście nie ma czego kliknąć.
--}}
Produkty do zużycia w najbliższych dniach

Na Twojej liście „Co mam w domu” te produkty mają termin, który minął albo upływa w ciągu {{ $dni }} {{ $dni === 1 ? 'dnia' : 'dni' }}. Termin to Twoja notatka z opakowania — sprawdź produkt przed użyciem.

@foreach($pozycje as $pozycja)
- {{ $pozycja['nazwa'] }}@if($pozycja['ilosc']) ({{ $pozycja['ilosc'] }})@endif

  {{ $pozycja['termin'] }}. {{ $pozycja['stan'] }}
@endforeach
@if($reszta > 0)

I jeszcze {{ $reszta }} {{ \App\Support\Odmiana::rzeczownik($reszta, 'produkt', 'produkty', 'produktów') }} na liście.
@endif

Zobacz, co ugotować: {{ $przepisy }}

Dostajesz ten list raz w tygodniu, w sobotę, bo na stronie „Co mam w domu” zaznaczono zgodę na sobotnie przypomnienie.
W liście są nazwy produktów z Twojej listy — jeśli skrzynkę czyta ktoś jeszcze, możesz się wypisać.
Nie chcę więcej takich listów (bez logowania): {{ $wypisz }}
Lista „Co mam w domu” i ustawienia: {{ $lista }}
