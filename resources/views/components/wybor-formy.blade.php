{{--
    „Jak mamy do Ciebie pisać?” — wybór formy zwracania się (D-268, #1752).

    Jeden komponent na dwóch ekranach: `/ustawienia/profil` i krok „Gotowe”
    w onboardingu. Różni się tylko adres wysyłki i napis przycisku.

    CZEGO TU NIE MA, CELOWO
    - Słowa „płeć”. Pytamy o brzmienie tekstów, nie o człowieka (D-268).
    - Zachęty do wyboru. Forma neutralna jest domyślnie zaznaczona i jest
      pełnoprawną odpowiedzią — bez „Uzupełnij profil!” (DSA art. 25).
    - Ukośników i form „ugotowałaś/eś”. Przykłady stoją w trzeciej osobie,
      z imieniem tej osoby w mianowniku (bez odmiany — D-153), bo tak właśnie
      przeczytają o niej inni. To zdanie ma powiedzieć wprost, że forma jest
      WIDOCZNA DLA INNYCH, zanim ktoś ją wybierze.

    Działa bez JavaScriptu: zwykły formularz, zwykłe pola wyboru, cele
    dotyku `.choice` ≥ 48 px jak w każdym innym wyborze w serwisie.
--}}
@props([
    'profile',
    'akcja',
    'metoda' => 'POST',
    'przycisk' => 'Zapisz formę',
])
@php
    $zaznaczone = old('form_of_address', $profile->formOfAddressChoice());
    $imie = $profile->display_name;
@endphp
<form method="POST" action="{{ $akcja }}" class="wybor-formy" id="forma-zwracania">
    @csrf
    @if(strtoupper($metoda) !== 'POST')
        @method($metoda)
    @endif

    <fieldset class="field @error('form_of_address') has-error @enderror">
        <legend>Jak mamy do Ciebie pisać?</legend>
        <p class="field-help" id="forma-zwracania-pomoc">
            To pytanie o brzmienie tekstów, nie o płeć. Wybrana forma zmienia teksty do Ciebie
            i to, co o Tobie czytają inni. Możesz ją zmienić w każdej chwili.
        </p>

        <div class="choice-grid">
            <label class="choice">
                <input type="radio" name="form_of_address" id="f-form_of_address"
                       value="{{ \App\Models\Profile::FORM_FEMININE }}"
                       aria-describedby="forma-zwracania-pomoc"
                       @checked($zaznaczone === \App\Models\Profile::FORM_FEMININE)>
                <span>
                    <span class="choice-label">Forma żeńska</span>
                    <span class="choice-help">Inni przeczytają na przykład: „{{ $imie }} ugotowała rosół z tego przepisu”.</span>
                </span>
            </label>

            <label class="choice">
                <input type="radio" name="form_of_address"
                       value="{{ \App\Models\Profile::FORM_MASCULINE }}"
                       aria-describedby="forma-zwracania-pomoc"
                       @checked($zaznaczone === \App\Models\Profile::FORM_MASCULINE)>
                <span>
                    <span class="choice-label">Forma męska</span>
                    <span class="choice-help">Inni przeczytają na przykład: „{{ $imie }} ugotował rosół z tego przepisu”.</span>
                </span>
            </label>

            <label class="choice">
                <input type="radio" name="form_of_address"
                       value="{{ \App\Models\Profile::FORM_NEUTRAL }}"
                       aria-describedby="forma-zwracania-pomoc"
                       @checked($zaznaczone === \App\Models\Profile::FORM_NEUTRAL)>
                <span>
                    <span class="choice-label">Forma neutralna</span>
                    <span class="choice-help">Bez formy żeńskiej ani męskiej, na przykład: „Co dziś gotujesz?”. Tak piszemy, dopóki nie wybierzesz inaczej.</span>
                </span>
            </label>
        </div>
        @error('form_of_address')<span class="field-error">{{ $message }}</span>@enderror
    </fieldset>

    <div class="form-actions">
        <button class="btn btn-secondary" type="submit">{{ $przycisk }}</button>
    </div>
</form>
