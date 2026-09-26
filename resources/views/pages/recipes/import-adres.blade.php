{{--
    IMPORT PRZEPISU Z ADRESU STRONY (V2, D-300).

    Granice z decyzji właściciela z 26.09.2026, widoczne dla człowieka ZANIM
    kliknie: wynik to prywatny szkic, adres zostaje jako źródło, zdjęć ze
    strony nie bierzemy, a opis przed publikacją trzeba napisać po swojemu.
    Jeden adres na raz — pola na listę adresów celowo nie ma.
--}}
<x-layout title="Przepis ze strony internetowej" :noindex="true">
    <x-zakladki-dodawania aktywna="przepis" />

    <h1>Przepis ze strony internetowej</h1>
    <p>
        Wklej adres strony, na której jest przepis. Zapiszemy go jako <strong>szkic, który widzisz tylko Ty</strong>.
        Adres strony zostanie przy przepisie jako źródło.
    </p>
    <ul class="stack-tight mb-5">
        <li>Zdjęć ze strony nie pobieramy — dodasz własne, kiedy ugotujesz.</li>
        <li>Tekst odczyta komputer. Przed publikacją porównaj go ze stroną, a opis przygotowania napisz własnymi słowami.</li>
        <li>Niektóre strony nie pozwalają pobierać przepisów — wtedy powiemy, co zrobić.</li>
    </ul>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('recipes.import.url.store') }}">
        @csrf
        <x-field name="adres" label="Adres strony z przepisem" type="url" required :bezOznaczenia="true"
                 inputmode="url" autocomplete="url"
                 help="Skopiuj go z paska adresu przeglądarki. Zaczyna się od https://" />

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Zapisz jako szkic</button>
        </div>
    </form>

    <p class="mt-6"><a href="{{ route('recipes.create') }}">Wolę wpisać przepis sam</a></p>
</x-layout>
