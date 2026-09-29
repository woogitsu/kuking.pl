{{--
    POSTĘP IMPORTU Z PLIKU PDF (#28, etap 2) — słowami, jak przy adresie:
    szkic powstaje dopiero na końcu, więc żaden krok nie obiecuje „zapisanego
    zdjęcia”. Blok podmienia `resources/js/postep-importu.js` (`?fragment=1`);
    bez skryptu działa odnośnik „Sprawdź, czy już gotowe”.
--}}
@php($komunikat = \App\Domain\Import\KomunikatImportu::dla($import))
<div data-koncowy="{{ $import->jestKoncowy() ? '1' : '0' }}">
    <ol class="stack-tight lista-postepu">
        <li><strong>Plik przyjęty</strong> — Twoje wysłanie nie ginie, nawet gdy zamkniesz tę stronę.</li>
        @if($import->jestKoncowy())
            <li>
                <strong>{{ $komunikat['tytul'] }}</strong>
                <p class="m-0">{{ $komunikat['tresc'] }}</p>
            </li>
        @else
            <li aria-current="step">
                <strong>{{ $komunikat['tytul'] }}…</strong>
                <p class="m-0">{{ $komunikat['tresc'] }}</p>
            </li>
            <li class="meta">Szkic gotowy do sprawdzenia</li>
        @endif
    </ol>

    <div class="form-actions">
        @if($import->status === \App\Models\ImportPrzepisu::STATUS_GOTOWY && $szkic !== null)
            <a class="btn btn-primary" href="{{ route('recipes.create', ['szkic' => $szkic->getKey()]) }}">Sprawdź i popraw szkic</a>
            <a class="btn btn-secondary" href="{{ route('recipes.edit', $szkic) }}">Otwórz na jednej stronie</a>
        @elseif($import->jestKoncowy())
            @if(config('kuking.import.pdf.wlaczony'))
                <a class="btn btn-primary" href="{{ route('recipes.import.pdf') }}">Dodaj plik jeszcze raz</a>
            @endif
            <a class="btn {{ config('kuking.import.pdf.wlaczony') ? 'btn-secondary' : 'btn-primary' }}" href="{{ route('recipes.create') }}">Wpiszę przepis ręcznie</a>
        @else
            <a class="btn btn-secondary" href="{{ route('import.show', $import) }}">Sprawdź, czy już gotowe</a>
        @endif
    </div>
</div>
