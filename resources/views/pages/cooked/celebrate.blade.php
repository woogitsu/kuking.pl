{{--
    „Komuś wyszło" (issue #17) — najcenniejszy ekran w produkcie.

    Zwykła strona, nie modal: żeby działała bez JavaScriptu, bez hover i bez
    gestu, tak jak wymaga AGENTS.md. Zdjęcie i nagłówek idą PIERWSZE — to jest
    dowód, że ktoś naprawdę stanął przy garnku, nie kolejny wiersz na liście.

    Zero rankingu, zero liczb ("to już N. wykonanie") — SOUL.md §6. Zero
    konfetti i animacji — to nie jest gra, to jest podziękowanie.
--}}
@php
    $kucharz = $event->user;
    $tytulPrzepisu = $event->recipe?->title;
    $zdjecia = $event->media;
    $maZdjecie = $zdjecia->isNotEmpty();
@endphp
<x-layout title="{{ $kucharz->displayName() }} — ugotowane z Twojego przepisu" :noindex="true">
    <article class="card stack text-center">
        <div>
            <p class="meta m-0 mb-2">Komuś wyszło</p>
            {{-- „ugotowane", nie „ugotowała/ugotował": ukośnika nie da się
                 przeczytać na głos, a `docs/brand/COPY_STYLE.md` §2 każe wtedy
                 zmienić konstrukcję zdania zamiast wybierać rodzaj. --}}
            <h1 class="text-title-lg m-0">
                {{ $kucharz->displayName() }} — ugotowane
                @if($tytulPrzepisu)
                    z Twojego przepisu „{{ $tytulPrzepisu }}”
                @else
                    z Twojego przepisu
                @endif
            </h1>
        </div>

        @if($maZdjecie)
            <div class="rounded-md overflow-hidden">
                <x-photo :media="$zdjecia->first()" variant="large" :priority="true" class="post-photo" />
            </div>
        @endif

        @if($event->note)
            {{--
                Bez zdjęcia notatka jest jedynym dowodem, że to się wydarzyło —
                dlatego dostaje większą czcionkę zamiast zwykłego akapitu
                (issue #17: „ta sama struktura, mocniej wyeksponowana uwaga").
            --}}
            <p class="tekst-jak-napisano m-0 @if(! $maZdjecie) notatka-wyrozniona @endif">
                „{{ $event->note }}”
            </p>
        @elseif(! $maZdjecie)
            {{--
                Ani zdjęcia, ani notatki — "Ugotowałem" nie wymaga żadnego pola
                (patrz RecordCookedEvent), więc to prawdziwy, częsty przypadek,
                nie błąd. Sam fakt ugotowania zostaje bohaterem ekranu zamiast
                pustego miejsca po treści, której nigdy nie było.
            --}}
            <p class="meta m-0">Bez zdjęcia i bez notatki — ale to i tak się liczy.</p>
        @endif

        <div class="max-w-[32rem] mx-auto text-left">
            <form method="POST" action="{{ route('cooked.thank', $event) }}">
                @csrf
                <x-field name="body" label="Podziękuj" type="textarea" :rows="3"
                         :value="$domyslnePodziekowanie"
                         help="Możesz zostawić ten tekst, jaki jest, albo dopisać coś swojego."
                         required />
                <button class="btn btn-primary w-full" type="submit">Podziękuj</button>
            </form>
        </div>

        <p class="m-0">
            <a class="btn btn-quiet" href="{{ route('cooked.show', $event) }}">Zobacz cały wpis</a>
        </p>
    </article>
</x-layout>
