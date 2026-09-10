@props(['tytul', 'id', 'ikona' => null, 'wiecej' => null, 'wiecejEtykieta' => 'Zobacz wszystko'])

{{--
    Jeden blok prawej szyny — wspólny kształt dla wszystkich ekranów (issue #205).

    DLACZEGO KOMPONENT, A NIE SKOPIOWANY `<section class="card">`
    Bloki szyny powstały na „Starcie" (`szyna-startowa`) i na „Szukaj"
    (`kuking-board`). Kiedy szyna weszła na kolejne pięć ekranów, ten sam
    nagłówek z odnośnikiem „Zobacz wszystko" trzeba było napisać jeszcze
    pięć razy — a wtedy pierwsza zmiana wyglądu szyny rozjeżdża połowę
    ekranów, bo poprawia się je po kolei i o którymś się zapomina.

    ZERO NOWEGO CSS-U. Klasy `.szyna-blok`, `.szyna-naglowek`, `.szyna-tytul`
    i `.szyna-wiecej` istnieją w `app.css` od kitu v2 — ten komponent tylko
    układa z nich nagłówek, żeby każdy blok szyny wyglądał tak samo.

    `id` JEST OBOWIĄZKOWE, bo `aria-labelledby` musi wskazywać na dokładnie
    jeden nagłówek na stronie. Wyliczanie go z tytułu dawałoby duplikaty
    w chwili, w której dwa bloki na jednym ekranie nazwą się podobnie —
    a duplikat `id` to dla czytnika ekranu blok bez nazwy.
--}}

<section class="card szyna-blok" aria-labelledby="{{ $id }}">
    <div class="szyna-naglowek">
        <h2 id="{{ $id }}" class="szyna-tytul">
            @if($ikona)
                <x-ikona :nazwa="$ikona" :rozmiar="22" />
            @endif
            {{ $tytul }}
        </h2>

        @if($wiecej)
            <a class="szyna-wiecej" href="{{ $wiecej }}">{{ $wiecejEtykieta }}</a>
        @endif
    </div>

    {{ $slot }}
</section>
