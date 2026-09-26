{{--
    „Jak dobieramy wpisy" (#1811, D-305).

    ZDANIA NIE STOJĄ W TYM PLIKU. Są w `App\Domain\Feed\JakDobieramyWpisy`,
    a `JakDobieramyWpisyMowiPrawdeTest` wiąże każde z kodem i sprawdza, że
    w bloku `data-jak-dobieramy` nie ma akapitu bez klucza. Nowe zdanie
    dopisuje się tam, razem z dowodem w teście — nie tutaj.

    Pod opisem, poza tym blokiem, droga do własnych ustawień: dla zalogowanego
    trzy odnośniki (osoby, tagi, „Ukryte"), dla gościa jedno zdanie.
    Słowo „tag", nie „temat" (`JednoSlowoNaTagiTest`).
--}}
<x-layout title="Jak dobieramy wpisy"
    description="Jak Kuking układa wpisy na Starcie, w Świeżo z Kuking, na tablicy na dziś, w wyszukiwarce i w tygodniowym e-mailu — i jak to zmienić.">
    <article class="prose">
        <h1>Jak dobieramy wpisy</h1>

        <p class="text-lead">Tu opisujemy każdą listę wpisów w serwisie: skąd biorą się wpisy i w jakiej kolejności je widzisz.</p>

        <div data-jak-dobieramy>
            @foreach($sekcje as $klucz => $sekcja)
                <section aria-labelledby="dobor-{{ $klucz }}">
                    <h2 id="dobor-{{ $klucz }}">@foreach(explode('{kuking}', $sekcja['tytul']) as $i => $czesc)@if($i > 0)<x-kuking-word />@endif{{ $czesc }}@endforeach</h2>
                    @foreach($sekcja['zdania'] as $kluczZdania => $zdanie)
                        <p data-zdanie="{{ $kluczZdania }}">@foreach(explode('{kuking}', $zdanie) as $i => $czesc)@if($i > 0)<x-kuking-word />@endif{{ $czesc }}@endforeach</p>
                    @endforeach
                </section>
            @endforeach
        </div>

        <section aria-labelledby="dobor-zmien" data-dobor-ustawienia>
            <h2 id="dobor-zmien">Jak to zmienić</h2>
            @auth
                <ul>
                    <li><a href="{{ route('social.following', auth()->user()->profile->username) }}">Osoby, które obserwujesz</a></li>
                    <li><a href="{{ route('settings.tags') }}">Tagi, które obserwujesz</a></li>
                    <li><a href="{{ route('settings.hidden') }}">Ukryte</a> — wpisy i osoby, które ukrywasz tylko dla siebie</li>
                </ul>
                <x-linia-ukryc :osoby="$ukryteOsoby" :wpisy="$ukryteWpisy" />
            @else
                <p>Po zalogowaniu wybierzesz tu, kogo i jakie tagi obserwujesz, i zobaczysz listę tego, co ukrywasz.
                    <a href="{{ route('login') }}">Zaloguj się</a></p>
            @endauth
        </section>
    </article>
</x-layout>
