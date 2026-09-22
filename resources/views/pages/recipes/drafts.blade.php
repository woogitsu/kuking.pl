<x-layout title="Wszystkie szkice" :noindex="true">
    <h1>Wszystkie szkice</h1>
    <p>Wybierz przepis, żeby wrócić do pisania. Szkice widzisz tylko Ty.</p>
    <ul class="stack list-none p-0">
        @forelse($drafts as $draft)
            <li><a class="btn btn-secondary" href="{{ route('recipes.create', ['szkic' => $draft->getKey()]) }}">Dokończ: {{ $draft->title }}</a></li>
        @empty
            <li>Nie masz teraz niedokończonych przepisów.</li>
        @endforelse
    </ul>
    @if($drafts->hasMorePages())
        <p><a class="btn btn-secondary" href="{{ $drafts->nextPageUrl() }}">Pokaż więcej</a></p>
    @endif
    <p><a class="btn btn-secondary" href="{{ route('add') }}">Wróć do dodawania</a></p>
</x-layout>
