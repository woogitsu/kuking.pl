<x-layout title="Wszystkie szkice" :noindex="true">
    <h1>Wszystkie szkice</h1>
    <p>Wybierz przepis, żeby wrócić do pisania. Szkice widzisz tylko Ty.</p>

    {{-- Odłożone na później (#2550): ta sama lista, osobny widok. Odłożenie niczego nie usuwa ani nie publikuje. --}}
    <nav class="tabs" aria-label="Które szkice pokazujemy">
        <a class="tab" href="{{ route('recipes.drafts') }}" @if(! $odlozone) aria-current="page" @endif>Bieżące</a>
        <a class="tab" href="{{ route('recipes.drafts', ['odlozone' => 1]) }}" @if($odlozone) aria-current="page" @endif>Odłożone na później ({{ $liczbaOdlozonych }})</a>
    </nav>

    @if($odlozone)
        <p>Te szkice są odłożone na bok. Nic z nich nie zniknęło i nikt poza Tobą ich nie widzi. Możesz je dokończyć albo przywrócić do bieżących.</p>
    @endif

    <ul class="stack list-none p-0">
        @forelse($drafts as $draft)
            <li class="stack-tight">
                <a class="btn btn-secondary" href="{{ route('recipes.create', ['szkic' => $draft->getKey()]) }}">Dokończ: {{ $draft->title }}</a>
                @if($odlozone)
                    <form method="POST" action="{{ route('recipes.drafts.resume', $draft->getKey()) }}">
                        @csrf @method('DELETE')
                        <input type="hidden" name="stan" value="{{ \App\Domain\Recipes\Actions\OdlozSzkicPrzepisu::znacznik($draft) }}">
                        <button class="btn btn-secondary" type="submit">Wróć do pracy<span class="visually-hidden">: {{ $draft->title }}</span></button>
                    </form>
                @else
                    <form method="POST" action="{{ route('recipes.drafts.postpone', $draft->getKey()) }}">
                        @csrf
                        <input type="hidden" name="stan" value="">
                        <button class="btn btn-secondary" type="submit">Odłóż na później<span class="visually-hidden">: {{ $draft->title }}</span></button>
                    </form>
                @endif
            </li>
        @empty
            @if($odlozone)
                <li>Nie masz teraz odłożonych szkiców. Szkic odłożysz przyciskiem „Odłóż na później” na liście bieżących.</li>
            @elseif($liczbaOdlozonych > 0)
                <li>Wszystkie Twoje szkice są odłożone na później. Otwórz zakładkę „Odłożone na później”, żeby do nich wrócić.</li>
            @else
                <li>Nie masz teraz niedokończonych przepisów.</li>
            @endif
        @endforelse
    </ul>
    @if($drafts->hasMorePages())
        <p><a class="btn btn-secondary" href="{{ $drafts->withQueryString()->nextPageUrl() }}">Pokaż więcej</a></p>
    @endif
    <p><a class="btn btn-secondary" href="{{ route('add') }}">Wróć do dodawania</a></p>
</x-layout>
