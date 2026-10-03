{{--
    IMPORT PRZEPISU Z PLIKU PDF (V2, D-300).

    Plik z tekstem odczytujemy u siebie. Skan bez tekstu może być odczytany
    modelem po wyraźnej zgodzie. Limity stron i rozmiaru stoją przed wyborem.
--}}
<x-layout title="Przepis z pliku PDF" :noindex="true">
    <x-zakladki-dodawania aktywna="przepis" />

    <h1>Przepis z pliku PDF</h1>
    <p>
        Wybierz plik PDF z przepisem. Odczytamy z niego tekst i zapiszemy jako
        <strong>szkic, który widzisz tylko Ty</strong>. PDF z warstwą tekstową odczytamy u siebie.
        Jeśli to skan, wyślemy do OpenAI w USA obrazy stron bez metadanych, tylko za Twoją zgodą.
    </p>
    <p class="mb-5">Plik może mieć najwyżej {{ $maksMb }} MB i {{ $maksStron }} stron.</p>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('recipes.import.pdf.store') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        <div class="field @error('plik') has-error @enderror">
            {{-- Ten sam wzorzec pola pliku co w kreatorze (D-035): pole schowane
                 klasą, klikalna jest duża etykieta z ikoną i napisem. --}}
            <input class="visually-hidden pole-zdjecia-input" id="f-plik" type="file" name="plik" accept="application/pdf,.pdf"
                   aria-labelledby="f-plik-tytul"
                   aria-describedby="f-plik-help @error('plik') f-plik-error @enderror"
                   @error('plik') aria-invalid="true" @enderror>
            <label class="pole-zdjecia" for="f-plik">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="book" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-plik-tytul">Wybierz plik PDF z przepisem</span>
            </label>
            <span class="field-help" id="f-plik-help">Po wybraniu pliku kliknij „Zapisz jako szkic”.</span>
            @error('plik')<span class="field-error" id="f-plik-error">{{ $message }}</span>@enderror
        </div>
        <x-zgoda-zrodlo-ai zrodlo="pdf" />

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Zapisz jako szkic</button>
        </div>

        {{-- Wybór stron przed odczytem (#2535): ten sam plik, ale najpierw podgląd stron
             i wybór tych z jednym przepisem. Nic nie jest odczytywane ani wysyłane,
             dopóki nie zatwierdzisz wyboru; zgodę na wysłanie skanu zaznaczysz tam. --}}
        <div class="stack mt-6">
            <p class="m-0">Plik ma kilka przepisów albo zbędne strony? Najpierw zobaczysz strony i wybierzesz te, które mają być odczytane.</p>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary" formaction="{{ route('recipes.import.pdf.wybor.przyjmij') }}">Najpierw wybiorę strony z przepisem</button>
            </div>
        </div>
    </form>

    <p class="mt-6"><a href="{{ route('recipes.create') }}">Wolę wpisać przepis ręcznie</a></p>
</x-layout>
