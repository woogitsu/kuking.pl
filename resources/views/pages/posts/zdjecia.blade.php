@php
    $zdjecia = $post->media;
    $ile = $zdjecia->count();
@endphp
<x-layout title="Zdjęcia w tym wpisie" :noindex="true">
    <h1>Zdjęcia w tym wpisie</h1>

    @if($ile < 2)
        {{--
            JEDNO ZDJĘCIE — ŻADNEGO WYBORU I ŻADNYCH PRZYCISKÓW.

            Trzy przyciski przy jednym zdjęciu to decyzja bez znaczenia:
            karuzela z jednym slajdem, kolaż z jednym polem i „zwykle" wyglądają
            identycznie. „Przenieś w górę" przy jednym zdjęciu nie ma dokąd
            przenosić. Zamiast pokazywać martwe kontrolki, mówimy wprost, kiedy
            to się pojawi (issue #92).
        --}}
        <p>Ten wpis ma jedno zdjęcie, więc nie ma tu czego ustawiać.</p>
        <p>Wybór kolejności i wyglądu pojawia się przy dwóch zdjęciach i większej liczbie.</p>

        <div class="form-actions">
            <a class="btn btn-secondary" href="{{ $post->url() }}">Wróć do wpisu</a>
        </div>
    @else
        <p style="margin-bottom:var(--spacing-5);">
            Ten wpis ma {{ $ile }} {{ \App\Support\Odmiana::rzeczownik($ile, 'zdjęcie', 'zdjęcia', 'zdjęć') }}.
            Ustaw kolejność i wybierz, jak mają się wyświetlić.
            Zmiany zobaczą wszyscy, którzy patrzą na ten wpis.
        </p>

        <x-error-summary />

        <form class="card" method="POST" action="{{ route('posts.media.update', $post) }}">
            @csrf

            {{--
                DOMYŚLNY PRZYCISK FORMULARZA — ZAPIS, NIE PRZESUNIĘCIE ZDJĘCIA.

                Wciśnięcie Entera w formularzu wysyła go PIERWSZYM przyciskiem
                w kodzie strony. Bez tej linijki byłoby to „Przenieś w dół"
                przy pierwszym zdjęciu: osoba, która wybrała karuzelę
                klawiaturą i nacisnęła Enter, przestawiłaby sobie kolejność
                zamiast zapisać wybór.

                Przycisk jest schowany przed wzrokiem, ale NIE przed czytnikiem
                ekranu — bo naprawdę działa i robi dokładnie to, co mówi.
                Poza kolejnością Tab (`tabindex="-1"`), żeby nie dublować
                widocznego „Zapisz" na dole.
            --}}
            <button class="visually-hidden" type="submit" tabindex="-1">Zapisz ustawienia zdjęć</button>

            {{--
                KOLEJNOŚĆ: „PRZENIEŚ W GÓRĘ / W DÓŁ", BEZ PRZECIĄGANIA

                Wzorzec jest ten sam co w kreatorze przepisu (issue #13):
                przeciąganie myszą albo palcem wymaga precyzji i sprawnej ręki,
                a każdy taki gest jest dla części naszych użytkowników drogą
                donikąd (docs/UX_50_PLUS.md). Tutaj to zwykłe przyciski
                w formularzu — działają bez JavaScriptu, z klawiatury
                i z czytnikiem ekranu.

                Każdy przycisk niesie identyfikator swojego zdjęcia
                (`name="przenies_w_gore" value="…"`), więc serwer wie, KTÓRE
                zdjęcie ruszyć, bez żadnego stanu po stronie przeglądarki.
            --}}
            <ol class="lista-zdjec">
                @foreach($zdjecia as $index => $media)
                    <li class="lista-zdjec-wiersz">
                        <div class="lista-zdjec-podglad">
                            <x-photo :media="$media"
                                     variant="thumb"
                                     :zoom="false"
                                     sizes="200px"
                                     :alt="$media->alt_text ?: 'Zdjęcie '.($index + 1).' z '.$ile.' w tym wpisie'" />
                        </div>

                        <div class="lista-zdjec-tresc">
                            <p class="lista-zdjec-numer">Zdjęcie {{ $index + 1 }} z {{ $ile }}</p>

                            <div class="lista-zdjec-akcje">
                                <button class="btn btn-secondary" type="submit"
                                        name="przenies_w_gore" value="{{ $media->getKey() }}"
                                        @disabled($index === 0)>Przenieś w górę</button>
                                <button class="btn btn-secondary" type="submit"
                                        name="przenies_w_dol" value="{{ $media->getKey() }}"
                                        @disabled($index === $ile - 1)>Przenieś w dół</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>

            <x-wybor-wygladu :wartosc="$post->display_mode ?? \App\Models\Post::DISPLAY_NORMAL" />

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Zapisz</button>
                <a class="btn btn-quiet" href="{{ $post->url() }}">Wróć do wpisu</a>
            </div>
        </form>
    @endif
</x-layout>
