@php($komunikat = \App\Domain\Import\KomunikatImportu::dla($import))
<div data-koncowy="{{ $import->jestKoncowy() ? '1' : '0' }}">
    <ol class="stack-tight lista-postepu">
        <li><strong>Zdjęcie przyjęte</strong> — zapisane w Twoim szkicu.</li>
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
            @if($import->moznaPonowic())
                <form method="POST" action="{{ route('import.ponow', $import) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Spróbuj jeszcze raz</button>
                </form>
            @endif
            @if($szkic !== null && $szkic->status === \App\Models\Recipe::STATUS_DRAFT)
                <a class="btn btn-secondary" href="{{ route('recipes.edit', $szkic) }}">Wpiszę przepis ręcznie</a>
            @endif
        @else
            <a class="btn btn-secondary" href="{{ route('import.show', $import) }}">Sprawdź, czy już gotowe</a>
        @endif
    </div>
</div>
