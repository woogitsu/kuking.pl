<x-layout title="Twój profil" :noindex="true">
    <h1>Twój profil</h1>
    <x-error-summary />

    <form class="card" method="POST" action="{{ route('settings.profile') }}" enctype="multipart/form-data">
        @csrf @method('PUT')

        <x-field name="display_name" label="Jak mamy Cię nazywać?" required :value="$profile->display_name" />
        <x-field name="username" label="Nazwa użytkownika" required :value="$profile->username"
                 help="Zmiana nazwy zmienia adres Twojego profilu. Stare linki przestaną działać." />
        <x-field name="bio" label="Kilka słów o sobie" type="textarea" :rows="4" :value="$profile->bio"
                 help="Na przykład: „Gotuję od czterdziestu lat. Najlepiej wychodzą mi zupy i ciasto drożdżowe.”" />
        <x-field name="region" label="Skąd jesteś" :value="$profile->region" placeholder="Podkarpacie"
                 help="Sam region wystarczy. Nie podawaj dokładnego adresu." />
        <x-field name="speciality" label="Na czym się znasz" :value="$profile->speciality" placeholder="zupy i kiszonki" />

        {{-- Ten sam obszar wyboru zdjęcia co na „Dodaj zdjęcie" i w formularzu
             przepisu (`.pole-zdjecia`, resources/css/ekran-dodawania.css).
             Do tej zmiany stał tu goły `<input type="file">` z angielskim
             „Choose File / No file chosen" — czyli druga, inna wersja tej samej
             czynności. Po D-035 wybór pliku wygląda i działa wszędzie tak samo:
             pole schowane dla oka, ale obecne pod klawiaturą i w drzewie
             dostępności, a klikalna jest etykieta. --}}
        <div class="field @error('avatar') has-error @enderror">
            <span class="pole-zdjecia-nazwa" id="f-avatar-etykieta">Zdjęcie profilowe</span>
            <input class="visually-hidden pole-zdjecia-input" id="f-avatar" type="file" name="avatar"
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                   aria-labelledby="f-avatar-etykieta f-avatar-tytul"
                   aria-describedby="f-avatar-help">
            <label class="pole-zdjecia" for="f-avatar">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-avatar-tytul">Dodaj zdjęcie</span>
                <span class="field-help" id="f-avatar-help">Nieobowiązkowe. Bez niego pokazujemy pierwszą literę Twojego imienia.</span>
            </label>
            @error('avatar')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
            <a class="btn btn-quiet" href="{{ route('profile.show', $profile->username) }}">Zobacz swój profil</a>
        </div>
    </form>

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="profile" />
    </x-slot:rail>
</x-layout>
