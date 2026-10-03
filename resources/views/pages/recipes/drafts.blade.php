<x-layout title="Wszystkie szkice" :noindex="true">
    <h1>Wszystkie szkice</h1>
    <p>Wybierz przepis, żeby wrócić do pisania. Szkice widzisz tylko Ty.</p>

    {{-- Odłożone na później (#2550): ta sama lista, osobny widok. Odłożenie niczego nie usuwa ani nie publikuje. --}}
    <nav class="tabs" aria-label="Które szkice pokazujemy">
        <a class="tab" href="{{ route('recipes.drafts', $fraza->aktywna() ? ['szukaj' => $fraza->fraza] : []) }}" @if(! $odlozone) aria-current="page" @endif>Bieżące</a>
        <a class="tab" href="{{ route('recipes.drafts', ['odlozone' => 1] + ($fraza->aktywna() ? ['szukaj' => $fraza->fraza] : [])) }}" @if($odlozone) aria-current="page" @endif>Odłożone na później ({{ $liczbaOdlozonych }})</a>
    </nav>

    {{-- „Szukaj w szkicach” (#2435): zwykły GET po tytule, tylko Twoje szkice, bez skryptu.
         Zakładka idzie ukrytym polem; nowa fraza zaczyna listę od początku. --}}
    <form class="panel-formularza mb-6" method="GET" action="{{ route('recipes.drafts') }}" role="search" aria-label="{{ \App\Domain\Recipes\FrazaWSzkicach::ETYKIETA }}" novalidate>
        @if($odlozone)<input type="hidden" name="odlozone" value="1">@endif
        <div class="field @if($fraza->blad) has-error @endif">
            <label for="f-szukaj-szkice">{{ \App\Domain\Recipes\FrazaWSzkicach::ETYKIETA }}</label>
            <span class="field-help" id="f-szukaj-szkice-help">Wpisz kawałek tytułu szkicu. Polskie znaki nie mają znaczenia — „zurek” znajdzie „Żurek”.</span>
            <input class="field-input" id="f-szukaj-szkice" name="szukaj" type="search" value="{{ $fraza->fraza }}"
                   maxlength="{{ \App\Domain\Search\SearchQuery::MAX_PHRASE_LENGTH }}"
                   aria-describedby="f-szukaj-szkice-help{{ $fraza->blad ? ' f-szukaj-szkice-error' : '' }}"
                   @if($fraza->blad) aria-invalid="true" @endif>
            @if($fraza->blad)
                <span class="field-error" id="f-szukaj-szkice-error">{{ $fraza->blad }}</span>
            @endif
        </div>
        <button class="btn btn-primary mt-4" type="submit">Szukaj</button>
        @if($fraza->fraza !== '')
            <a class="btn btn-secondary mt-4" href="{{ route('recipes.drafts', $odlozone ? ['odlozone' => 1] : []) }}">Wyczyść szukanie</a>
        @endif
    </form>

    @if($odlozone)
        <p>Te szkice są odłożone na bok. Nic z nich nie zniknęło i nikt poza Tobą ich nie widzi. Możesz je dokończyć albo przywrócić do bieżących.</p>
    @endif

    @if($fraza->aktywna() && $drafts->isNotEmpty())
        <p class="meta" data-wyniki-w-szkicach>Pokazujemy szkice z „{{ $fraza->fraza }}” w tytule, od ostatnio poprawianego.</p>
    @endif

    <ul class="stack list-none p-0">
        @forelse($drafts as $draft)
            <li class="stack-tight">
                <a class="btn btn-secondary" href="{{ route('recipes.create', ['szkic' => $draft->getKey()]) }}">Dokończ: {{ $draft->title }}</a>
                {{-- „Zrób kopię” (#2507): ekran potwierdzenia zakresu, nic nie zapisuje samo z listy. --}}
                <a class="btn btn-secondary" href="{{ route('recipes.drafts.copy', $draft->getKey()) }}">Zrób kopię<span class="visually-hidden">: {{ $draft->title }}</span></a>
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
            @if($fraza->aktywna())
                {{-- Brak dopasowań to nie brak szkiców — mówimy, czego nie znaleźliśmy i co zrobić. --}}
                <li data-brak-wynikow-szkicow>
                    Nie znaleźliśmy wśród {{ $odlozone ? 'odłożonych' : 'bieżących' }} szkiców tytułu z „{{ $fraza->fraza }}”.
                    @if($wDrugiejZakladce > 0)
                        Pasujące szkice ({{ $wDrugiejZakladce }}) są w zakładce „{{ $odlozone ? 'Bieżące' : 'Odłożone na później' }}”.
                    @else
                        Spróbuj krótszego kawałka tytułu albo wyczyść szukanie.
                    @endif
                </li>
            @elseif($odlozone)
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
