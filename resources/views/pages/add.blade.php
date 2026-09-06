@php
    // Niedokończone szkice przepisów. Pokazujemy je od razu na wejściu,
    // bo najczęstsze pytanie po przerwanym kreatorze brzmi „gdzie to jest?”.
    $niedokonczoneSzkice = auth()->user()
        ?->recipes()
        ->where('status', \App\Models\Recipe::STATUS_DRAFT)
        ->orderByDesc('updated_at')
        ->limit(3)
        ->get() ?? collect();
@endphp

<x-layout title="Dodaj" :noindex="true">
    <h1>Co chcesz dodać?</h1>
    <p class="mb-6">Nie musisz od razu pisać całego przepisu. Samo zdjęcie też jest w porządku.</p>

    @if($niedokonczoneSzkice->isNotEmpty())
        <div class="notice">
            <p class="mt-0">
                <strong>{{ $niedokonczoneSzkice->count() === 1 ? 'Masz niedokończony przepis.' : 'Masz niedokończone przepisy.' }}</strong>
                Nic z nich nie zginęło — możesz wrócić do pisania.
            </p>
            <ul class="stack-tight list-none p-0 m-0">
                @foreach($niedokonczoneSzkice as $szkic)
                    <li>
                        <a class="btn btn-secondary" href="{{ route('recipes.create', ['szkic' => $szkic->getKey()]) }}">
                            Dokończ: {{ $szkic->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="stack">
        <a class="card block no-underline text-inherit" href="{{ route('posts.create') }}">
            <h2 class="mt-0">Zdjęcie i kilka słów</h2>
            <p class="mb-0">Najprostsza rzecz. Wybierasz zdjęcie, piszesz jedno zdanie i gotowe. Zajmuje niecałą minutę.</p>
        </a>

        <a class="card block no-underline text-inherit" href="{{ route('recipes.create') }}">
            <h2 class="mt-0">Cały przepis</h2>
            <p class="mb-0">Składniki i przygotowanie, żeby ktoś inny mógł to u siebie zrobić. Możesz zapisać szkic i wrócić później.</p>
        </a>
    </div>
</x-layout>
