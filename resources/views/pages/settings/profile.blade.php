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

        <div class="field">
            <label for="f-avatar">Zdjęcie profilowe</label>
            <span class="field-help" id="f-avatar-help">Nieobowiązkowe. Bez niego pokazujemy pierwszą literę Twojego imienia.</span>
            <input class="field-input" id="f-avatar" type="file" name="avatar"
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                   aria-describedby="f-avatar-help">
            @error('avatar')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
            <a class="btn btn-quiet" href="{{ route('profile.show', $profile->username) }}">Zobacz swój profil</a>
        </div>
    </form>

    <x-ustawienia-nawigacja aktywne="profile" />
</x-layout>
