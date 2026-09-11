@php
    $zdjecie = $profile->avatar;

    // Ten sam warunek co w `x-avatar`: dla człowieka „mam zdjęcie" znaczy
    // „widzę je na stronie". Zdjęcie w trakcie przetwarzania jeszcze się nie
    // pokazuje (AGENTS.md §7 — dopóki `ProcessUploadedImage` nie przekoduje
    // pliku, w EXIF-ie siedzi lokalizacja kuchni), więc ma tu osobne zdanie,
    // a nie ciszę.
    $gotowe = $zdjecie !== null && $zdjecie->isReady();
    $wPrzygotowaniu = $zdjecie !== null && ! $zdjecie->isReady();
@endphp
<x-layout title="Zdjęcie profilowe" :noindex="true">
    <h1>Zdjęcie profilowe</h1>
    <x-error-summary />

    <div class="panel-formularza">
        <div class="zdjecie-profilowe-stan">
            <x-avatar :user="$profile->user" :size="88" />

            <p class="zdjecie-profilowe-opis">
                @if($gotowe)
                    To jest Twoje zdjęcie. Widzą je inni przy Twoich wpisach, przepisach i komentarzach.
                @elseif($wPrzygotowaniu)
                    Twoje nowe zdjęcie się przygotowuje. Odśwież tę stronę za chwilę —
                    do tego czasu wszędzie stoi pierwsza litera Twojego imienia.
                @else
                    Nie masz jeszcze swojego zdjęcia. Zamiast niego wszędzie pokazujemy
                    pierwszą literę Twojego imienia.
                @endif
            </p>
        </div>

        {{--
            ZWYKŁY FORMULARZ HTML, BEZ WARUNKU W POSTACI SKRYPTU.

            Wybór pliku otwiera kliknięcie w `<label for>` — tak działa HTML.
            Podgląd miniatury po wybraniu zdjęcia dorysowuje `resources/js/app.js`
            (wspólny dla wszystkich pól plikowych w serwisie) i jest to
            ULEPSZENIE: gdy skrypt się nie dociągnie, formularz działa dalej,
            a nikt nie zostaje z przyciskiem, który nic nie robi.

            Pole ma tę samą postać co na „Dodaj zdjęcie" i w formularzu przepisu
            (D-035): `<input class="visually-hidden pole-zdjecia-input">` stoi
            BEZPOŚREDNIO PRZED swoją etykietą, bo na tym sąsiedztwie stoi reguła
            widocznego fokusu w ekran-dodawania.css.
        --}}
        <form method="POST" action="{{ route('settings.avatar.update') }}" enctype="multipart/form-data">
            @csrf

            <div class="field @error('avatar') has-error @enderror">
                <span class="pole-zdjecia-nazwa" id="f-avatar-etykieta">
                    {{ $gotowe ? 'Nowe zdjęcie' : 'Twoje zdjęcie' }}
                </span>
                <input class="visually-hidden pole-zdjecia-input" id="f-avatar" type="file" name="avatar"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                       aria-labelledby="f-avatar-etykieta f-avatar-tytul"
                       aria-describedby="f-avatar-help">
                <label class="pole-zdjecia" for="f-avatar">
                    <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                    <span class="pole-zdjecia-tytul" id="f-avatar-tytul">
                        {{ $gotowe ? 'Wybierz inne zdjęcie' : 'Wybierz zdjęcie' }}
                    </span>
                    <span class="field-help" id="f-avatar-help">
                        Najlepiej takie, na którym widać Twoją twarz.
                        Formaty: {{ \App\Support\LimityZdjec::formatyDlaCzlowieka() }}.
                        Do {{ \App\Support\LimityZdjec::maksMegabajtowDoKomunikatu() }} MB.
                    </span>
                </label>
                @error('avatar')<span class="field-error" id="f-avatar-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Zapisz zdjęcie</button>
                <a class="btn btn-quiet" href="{{ route('profile.show', $profile->username) }}">Wróć na swój profil</a>
            </div>
        </form>
    </div>

    @if($zdjecie !== null)
        {{-- Usunięcie ODSUNIĘTE od zwykłych akcji i z potwierdzeniem
             (AGENTS.md §5). `x-confirm-button` robi to bez JavaScriptu,
             na `<details>`. --}}
        <div class="danger-zone">
            <h2>Usunięcie zdjęcia</h2>
            <p>
                Możesz zostać bez zdjęcia — wtedy wszędzie wraca pierwsza litera
                Twojego imienia. Nowe zdjęcie dodasz, kiedy tylko zechcesz.
            </p>
            <x-confirm-button
                :action="route('settings.avatar.destroy')"
                label="Usuń zdjęcie"
                question="Na pewno usunąć swoje zdjęcie? Pliku nie da się potem odzyskać." />
        </div>
    @endif

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="avatar" />
    </x-slot:rail>
</x-layout>
