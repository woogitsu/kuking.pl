{{-- „Moje próby tego przepisu” (V2, #2412): prywatna historia i porównanie
     własnych wykonań jednego przepisu. Widok tylko do odczytu; dane liczy
     `ProbyPrzepisu` i zawsze wiąże je z osobą zalogowaną. Tytuł pokazujemy
     dopiero po `RecipePolicy::view` (kontroler). --}}
<x-layout :title="'Moje próby: '.$recipe->title" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a>
    </p>

    <h1>Moje próby tego przepisu</h1>
    <p class="meta meta-samodzielne">
        Przepis „{{ $recipe->title }}”. Widzisz tu tylko własne wykonania i tylko Ty je widzisz.
        Od najstarszej próby do najnowszej.
    </p>

    @if($razem === 0)
        <x-empty-state title="Nie ma tu jeszcze żadnej próby"
                       :action="auth()->user()?->can('cook', $recipe) ? 'Dodaj swoje wykonanie' : null"
                       :href="route('cooked.create', $recipe->slug)">
            Gdy ugotujesz ten przepis, zapiszesz tu datę, czas, notatkę i zdjęcie, a przy kolejnych próbach zobaczysz, co się zmieniło.
        </x-empty-state>
    @else
        <p>
            Liczba prób: <strong>{{ $razem }}</strong>.
        </p>

        <div id="lista-prob" class="stack">
            @foreach($proby as $proba)
                @php
                    $numer = $numeracja[(string) $proba->getKey()];
                    $wersja = $proba->recipe_version_id === null ? null : $wersje->get((string) $proba->recipe_version_id);
                @endphp
                <article class="card" id="proba-{{ $proba->getKey() }}" data-klucz="proba-{{ $proba->getKey() }}" aria-labelledby="naglowek-proba-{{ $proba->getKey() }}">
                    <h2 id="naglowek-proba-{{ $proba->getKey() }}">
                        Próba {{ $numer }}:
                        <time datetime="{{ $proba->cooked_at->toIso8601String() }}">{{ \App\Support\Czas::data($proba->cooked_at, 'j F Y') }}</time>
                    </h2>

                    @if($proba->dzien_gotowania !== null)
                        <p class="meta m-0">
                            Gotowane (widzisz tylko Ty):
                            <time datetime="{{ $proba->dzien_gotowania->format('Y-m-d') }}">{{ \App\Support\Czas::data($proba->dzien_gotowania) }}</time>.
                        </p>
                    @endif

                    <ul class="recipe-facts">
                        <li>
                            <span class="badge">
                                @if($wersja !== null)
                                    Wersja przepisu: {{ $wersja->version_number }}
                                @elseif($proba->recipe_version_id !== null)
                                    Wersji z tej próby nie możemy już pokazać
                                @else
                                    Wersja przepisu: nieznana
                                @endif
                            </span>
                        </li>
                        <li>
                            <span class="badge">
                                @if($proba->actual_minutes !== null)
                                    Zajęło mi {{ \App\Support\Czas::czasPrzepisu((int) $proba->actual_minutes) }}
                                @else
                                    Czas: nie podano
                                @endif
                            </span>
                        </li>
                    </ul>

                    <div class="notice" role="note">
                        <p class="mt-0 mb-1"><strong>Co się zmieniło od poprzedniej próby</strong></p>
                        <ul class="m-0">
                            @foreach($roznice[(string) $proba->getKey()] as $roznica)
                                <li>{{ $roznica }}</li>
                            @endforeach
                        </ul>
                    </div>

                    @if($proba->media->isNotEmpty())
                        @php $liczbaZdjec = $proba->media->count(); @endphp
                        <div class="photo-grid mt-3 mb-3 rounded-md overflow-hidden">
                            @foreach($proba->media as $index => $media)
                                <x-photo :media="$media" :alt="$media->alt_text ?: ($liczbaZdjec > 1 ? 'Zdjęcie '.($index + 1).' z '.$liczbaZdjec.' próby '.$numer : 'Zdjęcie z próby '.$numer)" />
                            @endforeach
                        </div>
                    @else
                        <p class="meta">Bez zdjęcia.</p>
                    @endif

                    @if($proba->note)
                        <p class="tekst-jak-napisano">{{ $proba->note }}</p>
                    @else
                        <p class="meta">Bez notatki.</p>
                    @endif

                    @if($proba->changes_note)
                        <p><strong>Po swojemu:</strong> {{ $proba->changes_note }}</p>
                    @endif

                    <div class="form-actions">
                        <a class="btn btn-secondary" href="{{ route('cooked.show', $proba) }}">Otwórz tę próbę</a>
                        @if($wersja !== null)
                            <a class="btn btn-secondary" href="{{ route('cooked.version', $proba) }}">Zobacz wersję {{ $wersja->version_number }}</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        <x-show-more :paginator="$proby" czego="prób" lista="lista-prob" />
    @endif
</x-layout>
