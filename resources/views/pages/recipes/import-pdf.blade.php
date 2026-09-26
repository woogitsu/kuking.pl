{{--
    IMPORT PRZEPISU Z PLIKU PDF (V2, D-300).

    Plik z tekstem odczytujemy u siebie, bez wysyłania go dokądkolwiek.
    Skan bez tekstu dostaje komunikat, co zrobić (zdjęcie strony albo ręczne
    przepisanie). Limity stron i rozmiaru są napisane PRZED wyborem pliku.
--}}
<x-layout title="Przepis z pliku PDF" :noindex="true">
    <x-zakladki-dodawania aktywna="przepis" />

    <h1>Przepis z pliku PDF</h1>
    <p>
        Wybierz plik PDF z przepisem. Odczytamy z niego tekst i zapiszemy jako
        <strong>szkic, który widzisz tylko Ty</strong>. Pliku nigdzie nie wysyłamy.
    </p>
    <p class="mb-5">Plik może mieć najwyżej {{ $maksMb }} MB i {{ $maksStron }} stron.</p>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('recipes.import.pdf.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="field @error('plik') has-error @enderror">
            <label for="f-plik">Plik PDF z przepisem</label>
            <input id="f-plik" type="file" name="plik" accept="application/pdf,.pdf"
                   aria-describedby="f-plik-help @error('plik') f-plik-error @enderror"
                   @error('plik') aria-invalid="true" @enderror>
            <span class="field-help" id="f-plik-help">Po wybraniu pliku kliknij „Zapisz jako szkic”.</span>
            @error('plik')<span class="field-error" id="f-plik-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Zapisz jako szkic</button>
        </div>
    </form>

    <p class="mt-6"><a href="{{ route('recipes.create') }}">Wolę wpisać przepis ręcznie</a></p>
</x-layout>
