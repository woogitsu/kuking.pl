{{--
    „Gotowanie zapamiętane na koncie” (#2439, V2).

    Prywatna lista własnych, niewygasłych stanów trybu gotowania, które osoba
    SAMA kazała zapamiętać na koncie. To NIE jest lista „niedokończonych”
    gotowań: zapamiętany stan istnieje także bez żadnego odhaczenia, a wszystkie
    kroki mogą być już zaznaczone — dlatego ekran opisuje tylko odhaczenia
    i nie nazywa ich ukończeniem ani „Ugotowałem”. Czytanie listy i otwarcie
    linku niczego nie przedłuża. „Pokaż więcej” to zwykły odnośnik z `?od=`.
--}}
<x-layout title="Gotowanie zapamiętane na koncie" :noindex="true">
    <h1>Gotowanie zapamiętane na koncie</h1>

    <p>
        Przepisy, w których włączono zapamiętywanie postępu na koncie. Zapamiętany stan znika sam
        po czasie: {{ $godziny }} h od ostatniej zmiany. Widzisz to tylko Ty.
    </p>

    @if($pozycje->isEmpty())
        <x-empty-state title="Nic tu jeszcze nie ma" action="Wróć do zeszytu" :href="route('collections.index')">
            Zapamiętywanie postępu włącza się w trybie gotowania, przy wybranym przepisie.
            Po włączeniu przepis pojawi się tutaj. Samo wejście na tę stronę niczego nie włącza.
        </x-empty-state>
    @else
        <ol class="lista-naga stack-tight" start="{{ $od + 1 }}" data-rola="gotowanie-zapamietane">
            @foreach($pozycje as $pozycja)
                <li>
                    <x-recipe-card :recipe="$pozycja['recipe']" />
                    <p class="mt-2 mb-0" data-stan-postepu><strong>{{ $pozycja['stan'] }}</strong></p>
                    <p class="meta m-0">
                        Zapamiętany stan zmieniono: {{ \App\Support\Czas::data($pozycja['postep']->updated_at, 'j F Y, H:i') }}.
                    </p>
                    <p class="mt-2">
                        <a class="btn btn-secondary" href="{{ route('cooking.show', ['recipe' => $pozycja['recipe']->slug]) }}">Otwórz tryb gotowania: {{ $pozycja['recipe']->title }}</a>
                    </p>
                </li>
            @endforeach
        </ol>

        <p class="meta">Pokazujemy tylko przepisy, które nadal możesz otworzyć. Resztę pomijamy.</p>

        @if($jest_wiecej)
            <p class="text-center mt-6">
                <a class="btn btn-secondary" href="{{ route('collections.cooking-progress', ['od' => $nastepne]) }}">Pokaż więcej</a>
            </p>
        @endif
    @endif
</x-layout>
