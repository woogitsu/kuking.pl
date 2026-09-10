<x-layout title="Twój profil" :noindex="true">
    <h1>Twój profil</h1>
    <x-error-summary />

    {{-- Bez `enctype="multipart/form-data"`: ten formularz nie przyjmuje już
         pliku. Zdjęcie profilowe ma własny ekran (`/ustawienia/zdjecie`). --}}
    <form class="card" method="POST" action="{{ route('settings.profile') }}">
        @csrf @method('PUT')

        <x-field name="display_name" label="Jak mamy Cię nazywać?" required :value="$profile->display_name" />
        <x-field name="username" label="Nazwa użytkownika" required :value="$profile->username"
                 help="Zmiana nazwy zmienia adres Twojego profilu. Stare linki przestaną działać." />
        <x-field name="bio" label="Kilka słów o sobie" type="textarea" :rows="4" :value="$profile->bio"
                 help="Na przykład: „Gotuję od czterdziestu lat. Najlepiej wychodzą mi zupy i ciasto drożdżowe.”" />
        <x-field name="region" label="Skąd jesteś" :value="$profile->region" placeholder="Podkarpacie"
                 help="Sam region wystarczy. Nie podawaj dokładnego adresu." />
        <x-field name="speciality" label="Na czym się znasz" :value="$profile->speciality" placeholder="zupy i kiszonki" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
            <a class="btn btn-quiet" href="{{ route('profile.show', $profile->username) }}">Zobacz swój profil</a>
        </div>
    </form>

    {{--
        ZDJĘCIE PROFILOWE MA WŁASNY EKRAN — i to jest tu napisane wprost,
        a nie zostawione domysłowi.

        Pole pliku stało dotąd w środku tego formularza. Kto szukał go tutaj
        po raz drugi, ma zobaczyć, dokąd poszło — znikające pole bez słowa
        wyjaśnienia jest gorsze od pola stojącego nie tam, gdzie trzeba.

        Podgląd obok odnośnika, bo „zdjęcie" bez pokazania, JAKIE, każe wejść
        na osobny ekran tylko po to, żeby sprawdzić, czy w ogóle jakieś jest.
    --}}
    <section class="card zdjecie-profilowe-skrot">
        <x-avatar :user="$profile->user" :size="64" />
        <div>
            <h2 class="mt-0 mb-2">Zdjęcie profilowe</h2>
            <p class="mb-4">
                @if($profile->avatar?->isReady())
                    Twoje zdjęcie widać przy wpisach, przepisach i komentarzach.
                @else
                    Nie masz jeszcze zdjęcia — wszędzie stoi pierwsza litera Twojego imienia.
                @endif
            </p>
            <a class="btn btn-secondary" href="{{ route('settings.avatar') }}">
                {{ $profile->avatar?->isReady() ? 'Zmień zdjęcie profilowe' : 'Dodaj zdjęcie profilowe' }}
            </a>
        </div>
    </section>

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="profile" />
    </x-slot:rail>
</x-layout>
