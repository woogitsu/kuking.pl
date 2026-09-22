@props(['action', 'wiersz', 'content' => null])
@php
    $zeszyty = app(\App\Domain\Collections\ZeszytyDoWyboru::class)->dla(request());
    $aktywny = \App\Support\WierszFormularza::jestAktywny($wiersz);
    $blad = $aktywny ? $errors->first('collection_id') : null;
    $wybrany = \App\Support\WierszFormularza::stareLubDomyslne('collection_id', $wiersz, '');
    $id = 'f-collection_id-'.str_replace(['[', ']', '.'], '-', $wiersz);
@endphp
<details class="wybor-zeszytu" @if($blad) open @endif>
    <summary class="btn btn-secondary">Wybierz zeszyt</summary>
    <div class="panel-formularza mt-3">
        <form method="POST" action="{{ $action }}">
            @csrf
            <input type="hidden" name="_wiersz" value="{{ $wiersz }}">
            @if($blad)
                <x-error-summary />
            @endif
            @if($zeszyty->isNotEmpty())
                <fieldset class="field wybor-zeszytu-lista">
                    <legend class="field-label">W którym zeszycie zapisać?</legend>
                    @foreach($zeszyty as $zeszyt)
                        <label class="wybor-zeszytu-opcja">
                            <input type="radio" name="collection_id" value="{{ $zeszyt->getKey() }}"
                                   @if($loop->first) id="{{ $id }}" @endif required
                                   @checked($wybrany === $zeszyt->getKey())
                                   @if($blad) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                            <span>{{ $zeszyt->name }}<small>{{ $zeszyt->isPublic() ? 'Widoczny dla wszystkich' : 'Tylko dla Ciebie' }}</small></span>
                        </label>
                    @endforeach
                    @if($blad)
                        <p class="field-error" id="{{ $id }}-error">{{ $blad }}</p>
                    @endif
                </fieldset>
                <button class="btn btn-secondary mt-3" type="submit">Zapisuję w tym zeszycie</button>
            @else
                @if($blad)
                    <p class="field-error" id="{{ $id }}" tabindex="-1">{{ $blad }}</p>
                @endif
                <p>Nie masz jeszcze zeszytu do wyboru. Załóż go, żeby wybrać miejsce zapisu.</p>
            @endif
        </form>
        <a class="inline-link mt-3" href="{{ route('collections.index', $content ? ['save_type' => $content instanceof \App\Models\Recipe ? 'recipe' : 'post', 'save_id' => $content->getKey()] : []) }}">Załóż nowy zeszyt</a>
    </div>
</details>
