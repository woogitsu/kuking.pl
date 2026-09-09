{{--
    „PODZIEL SIĘ" — wysłanie przepisu albo wpisu znajomym.

    KOLEJNOŚĆ WARSTW JEST TU CAŁĄ TREŚCIĄ TEGO KOMPONENTU

    Warstwa podstawowa, renderowana przez serwer i działająca bez jednej
    linijki JavaScriptu, to JAWNA LISTA: WhatsApp, e-mail, Facebook oraz
    widoczny, zaznaczalny adres. `navigator.share` (arkusz systemu, w którym
    człowiek widzi swojego Messengera i SMS-y) jest z definicji JavaScriptem,
    więc nie może być wersją pierwszą — jest ULEPSZENIEM nakładanym na tę
    listę w `resources/js/app.js` (AGENTS.md §5).

    Skutek praktyczny: przy wyłączonym skrypcie nie znika nic poza przyciskiem
    „Skopiuj adres" (ten jest w HTML-u z atrybutem `hidden` i odsłania go
    dopiero skrypt — bo bez skryptu nie miałby czego kopiować). Adres i tak
    stoi na wierzchu w polu tekstowym, więc zaznaczenie go myszą albo
    dwoma kliknięciami działa zawsze. Nigdzie nie ma napisu „twoja
    przeglądarka nie obsługuje".

    `<details>` zamiast okna sterowanego skryptem — ten sam wzorzec co
    `x-confirm-button` i menu „···" nad wpisem. Rozwija się natywnie,
    bez skryptu, bez najeżdżania myszą i bez gestu (AGENTS.md §5).

    PRZY CZYM SIĘ POKAZUJE
    Wyłącznie przy treści, którą zobaczy KTOŚ BEZ KONTA — o to pyta
    `App\Domain\Sharing\Udostepnianie::wolnoWyslac()` przez Policy.
    Wpis „tylko dla obserwujących" i „tylko dla mnie" przycisku nie dostaje
    nawet u własnego autora: wysłany adres pokazałby odbiorcy 403, a autor
    byłby przekonany, że coś wysłał. Autor dostaje w zamian jedno zdanie,
    co zrobić, żeby dało się wysłać.
--}}
@props(['tresc'])

@php
    $udostepnianie = app(\App\Domain\Sharing\Udostepnianie::class);
@endphp

@if($udostepnianie->obslugiwana($tresc))
    @if($udostepnianie->wolnoWyslac($tresc))
        @php
            $adres = $udostepnianie->adres($tresc);
            $tytul = $udostepnianie->tytul($tresc);
            $opis = $udostepnianie->opis($tresc);
            $drogi = $udostepnianie->drogi($tresc);
            $rzecz = $tresc instanceof \App\Models\Recipe ? 'przepis' : 'wpis';
            // Identyfikator z klucza treści, nie stały — na jednej stronie
            // może kiedyś stanąć więcej niż jeden taki blok, a zduplikowany
            // `id` przenosi fokus w złe miejsce (IdentyfikatoryNaStronieSaUnikalneTest).
            $id = 'podziel-'.$tresc->getKey();
        @endphp

        <details class="podziel-sie"
                 data-podziel-sie
                 data-podziel-tytul="{{ $tytul }}"
                 data-podziel-tekst="{{ $opis }}"
                 data-podziel-adres="{{ $adres }}">
            <summary class="btn btn-secondary btn-duzy podziel-sie-przycisk">
                <x-ikona nazwa="share" :rozmiar="24" />
                Podziel się
            </summary>

            <div class="podziel-sie-tresc">
                <p class="podziel-sie-wstep">
                    Wyślij ten {{ $rzecz }} komuś bliskiemu. Osoba, która dostanie adres,
                    otworzy go w przeglądarce — konto na Kuking nie jest jej do tego potrzebne.
                </p>

                <ul class="podziel-sie-lista">
                    @foreach($drogi as $droga)
                        <li>
                            {{-- `target="_blank"` tylko tam, gdzie otwiera się
                                 cudza strona: inaczej człowiek traci przepis,
                                 który właśnie chciał wysłać, i musi go szukać
                                 od nowa. `mailto:` zostaje w tej samej karcie,
                                 bo nowa karta zostałaby po nim pusta. --}}
                            <a class="btn btn-secondary podziel-sie-droga"
                               href="{{ $droga['adres'] }}"
                               @if($droga['zewnetrzny']) target="_blank" rel="noopener" @endif>
                                <span class="podziel-sie-droga-nazwa">{{ $droga['nazwa'] }}</span>
                                <span class="podziel-sie-droga-opis">{{ $droga['opis'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="field">
                    <label for="{{ $id }}-adres">Albo skopiuj adres i wyślij, jak lubisz</label>
                    {{-- POLE WIELOWIERSZOWE, NIE JEDNOWIERSZOWE.

                         Zmierzone przy 390 px: adres przepisu w zwykłym
                         `<input>` urywał się po „http://…/przepi…". Człowiek
                         bez JavaScriptu ma ten adres PRZECZYTAĆ i przepisać,
                         a nie tylko zaznaczyć w ciemno — więc musi być widać
                         go w całości.

                         `readonly`, nie `disabled`: pole ma dać się zaznaczyć
                         i skopiować także bez skryptu. `disabled` odbiera je
                         klawiaturze i wyszarza tekst. --}}
                    <textarea class="field-input podziel-sie-adres"
                              id="{{ $id }}-adres"
                              rows="2"
                              readonly
                              data-podziel-pole
                              aria-describedby="{{ $id }}-adres-help">{{ $adres }}</textarea>
                    <span class="field-help" id="{{ $id }}-adres-help">
                        Zaznacz adres i skopiuj go, a potem wklej w dowolnej wiadomości.
                    </span>
                </div>

                {{-- Przycisk kopiowania odsłania SKRYPT. W HTML-u stoi
                     z `hidden`, bo bez skryptu nie miałby czego zrobić,
                     a martwy przycisk jest gorszy niż jego brak. --}}
                <button type="button"
                        class="btn btn-secondary podziel-sie-kopiuj"
                        data-podziel-kopiuj
                        hidden>Skopiuj adres</button>

                {{-- Potwierdzenie kopiowania. Puste w HTML-u; wypełnia je
                     skrypt, a `role="status"` sprawia, że czytnik ekranu
                     przeczyta je bez zabierania fokusu. --}}
                <p class="podziel-sie-echo" role="status" aria-live="polite" data-podziel-echo></p>
            </div>
        </details>
    @elseif(auth()->check() && auth()->user()->can('update', $tresc))
        {{-- Tylko dla autora. Obcy nie ma się z czego dowiadywać, że coś
             takiego jak przycisk wysyłania w ogóle istnieje. --}}
        <p class="podziel-sie-niedostepne">{{ $udostepnianie->powodBrakuPrzycisku($tresc) }}</p>
    @endif
@endif
