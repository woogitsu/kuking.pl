@props(['pozycje'])

{{--
    „Ostatnio odłożone" — prawa szyna ekranu „Zeszyt" (`/zeszyt`, issue #205).

    DLACZEGO AKURAT TO
    Główna kolumna wypisuje ZESZYTY, a człowiek przychodzi tu po jedną
    konkretną rzecz: „gdzie jest to, co zapisałam wczoraj". Bez tej listy
    trzeba najpierw sobie przypomnieć, do którego zeszytu to poszło.
    To jest jedyna rzecz na tym ekranie, której w głównej kolumnie nie ma —
    powtórzenie listy zeszytów byłoby wypełniaczem.

    Kolejność liczy `CollectionController::ostatnioZapisane()` po CZASIE
    ODŁOŻENIA, nie po dacie publikacji, i przez ten sam filtr widoczności,
    którym idzie strona zeszytu.

    PUSTO ZNACZY PUSTO. Osoba, która nic jeszcze nie odłożyła, nie dostaje
    tu żadnego bloku — w głównej kolumnie stoi wtedy pusty stan z jednym
    wyjściem („Poszukaj przepisów") i druga zachęta obok tylko by go osłabiła.
--}}

@if($pozycje->isNotEmpty())
    <x-szyna-blok tytul="Ostatnio odłożone" id="szyna-ostatnio-zapisane" ikona="save">
        <ul class="szyna-lista">
            @foreach($pozycje as $pozycja)
                <li class="szyna-pozycja">
                    <a class="szyna-pozycja-link" href="{{ $pozycja['href'] }}">
                        {{-- `alt=""`: nazwa i podpis obok niosą całą treść
                             odnośnika, więc opis zdjęcia byłby dla czytnika
                             ekranu drugim przeczytaniem tego samego. --}}
                        <span class="szyna-miniatura">
                            <x-photo :media="$pozycja['media']" variant="thumb" :zoom="false" sizes="72px" alt="" />
                        </span>
                        <span class="min-w-0">
                            <span class="szyna-nazwa">{{ $pozycja['nazwa'] }}</span>
                            <span class="meta szyna-podpis">{{ $pozycja['podpis'] }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </x-szyna-blok>
@endif
