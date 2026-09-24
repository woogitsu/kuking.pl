@php
    /*
        TEN SAM WARUNEK CO W `x-avatar`, I TO JEST CAŁY SENS (#448).

        Dla człowieka „mam zdjęcie" znaczy „widzę je na stronie", więc to
        zdanie i obrazek obok muszą wychodzić z JEDNEJ odpowiedzi. Stało tu
        `$zdjecie->isReady()`, czyli pytanie o STAN WIERSZA — a obrazek obok
        pokazuje PLIK. Po wgraniu zdjęcia wiersz jest jeszcze `pending`, więc
        ekran pisał „przygotowuje się" nawet wtedy, gdy plik do pokazania już
        był.

        Pytanie zadaje teraz `Profile` i robi to raz: `zdjecieDoPokazania()`
        mówi, czy jest co pokazać, `zdjecieSieJeszczePrzygotowuje()` — czy
        jest jeszcze na co czekać. Dwie kopie tej reguły rozjechałyby się
        przy pierwszej zmianie listy wariantów, a rozjazd znaczy tutaj:
        obrazek pokazuje twarz, a podpis pod nim twierdzi, że jej nie ma.
    */
    $gotowe = $profile->zdjecieDoPokazania() !== null;
    $wPrzygotowaniu = $profile->zdjecieSieJeszczePrzygotowuje();
    // Obróbka skończona odmową, obrazka brak (#891) — tu nie ma na co czekać.
    $nieUdaloSie = $profile->zdjecieNieUdaloSiePrzygotowac();
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
                @elseif($nieUdaloSie)
                    Nie udało się przygotować Twojego zdjęcia. Wybierz inne zdjęcie poniżej
                    i kliknij „Zapisz zdjęcie” — do tego czasu wszędzie stoi pierwsza litera Twojego imienia.
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
                       {{-- Przy błędzie opis pola rośnie o TREŚĆ BŁĘDU, żeby
                            czytnik ekranu przeczytał ją razem z etykietą. --}}
                       @error('avatar') aria-invalid="true" aria-describedby="f-avatar-help f-avatar-error" @else aria-describedby="f-avatar-help" @enderror>
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

    {{-- `$gotowe || $wPrzygotowaniu`, a nie samo istnienie wiersza: wiersz
         przejęty do skasowania (`deleted`) nie jest już zdjęciem tej osoby,
         a ekran mówi wtedy wprost „nie masz jeszcze swojego zdjęcia".
         Przycisk „Usuń zdjęcie" pod takim zdaniem przeczyłby mu w tej samej
         chwili (#448). --}}
    @if($gotowe || $wPrzygotowaniu || $nieUdaloSie)
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
                question="Na pewno usunąć swoje zdjęcie? Pliku nie da się potem odzyskać.">
                <input type="hidden" name="avatar_media_id" value="{{ $profile->avatar_media_id }}">
            </x-confirm-button>
        </div>
    @endif

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="avatar" />
    </x-slot:rail>
</x-layout>
