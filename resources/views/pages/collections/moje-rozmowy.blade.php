{{--
    „Moje rozmowy” w „Moje” (#2432). Rozmowy, w których jest Twój komentarz
    albo odpowiedź — każda raz, według Twojej ostatniej wypowiedzi, od
    najnowszej. Prywatne, bez powiadomień i bez liczników.

    Lista pokazuje tylko to, co dziś możesz zobaczyć na ekranie rozmowy;
    treści niedostępne dla Ciebie znikają bez śladu. Bez JavaScriptu: zwykłe
    odnośniki, a „Następna strona” działa jako link (`x-show-more`).
--}}
<x-layout title="Moje rozmowy" :noindex="true">
    <div class="marka-zeszyt">

    <p class="mb-3"><a href="{{ route('collections.index') }}">Wróć do zeszytu</a></p>

    <h1>Moje rozmowy</h1>
    <p class="mb-5">Rozmowy, w których dopisujesz komentarze i odpowiedzi — od tej, w której pisano ostatnio. Odnośnik prowadzi do Twojej ostatniej wypowiedzi, także gdy nikt jeszcze nie odpowiedział. Tę listę widzisz tylko Ty.</p>

    @if(count($rozmowy->items()) === 0)
        <x-empty-state title="Nie ma tu jeszcze żadnej rozmowy" action="Zobacz wpisy" :href="route('home')">
            Gdy dopiszesz komentarz pod wpisem, przepisem albo ugotowanym daniem, znajdziesz tu drogę z powrotem do swojej wypowiedzi.
        </x-empty-state>
    @endif

    {{-- Kontener zostaje także po opróżnieniu listy: „Pokaż więcej” rozpoznaje koniec. --}}
    <div class="stack" id="lista-moich-rozmow">
        @foreach($rozmowy->items() as $pozycja)
            <article class="card" data-klucz="moja-rozmowa-{{ $pozycja->idKomentarza }}" data-moja-rozmowa>
                <h2 class="mt-0 mb-2 text-xl">{{ $pozycja->kontekst }}</h2>
                <p class="m-0">
                    {{ $pozycja->jestOdpowiedzia ? 'Twoja odpowiedź' : 'Twój komentarz' }}:
                    „{{ $pozycja->fragment }}”
                </p>
                <p class="meta m-0 mt-2">
                    <time datetime="{{ $pozycja->data->toIso8601String() }}">{{ \App\Support\Czas::dataWpisu($pozycja->data) }}</time>
                </p>
                <p class="m-0 mt-3">
                    <a class="btn btn-secondary" href="{{ $pozycja->adres }}">Wróć do rozmowy</a>
                </p>
            </article>
        @endforeach
    </div>

    <x-show-more :paginator="$rozmowy" czego="rozmów" lista="lista-moich-rozmow" />
    </div>
</x-layout>
