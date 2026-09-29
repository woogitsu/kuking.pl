{{--
    WCZYTANIE WŁASNEJ PACZKI Z DANYMI — krok 1: wybór pliku (#1985).

    Plik jest tylko sprawdzany; nic się nie zapisze, dopóki człowiek nie
    zobaczy podglądu i nie kliknie „Wczytaj zaznaczone”.
--}}
<x-layout title="Wczytaj swoją paczkę z danymi" :noindex="true">
    <h1>Wczytaj swoją paczkę z danymi</h1>
    <p>
        Masz paczkę pobraną z Kuking w ustawieniach, w „Twoich danych”?
        Możesz z niej przywrócić swoje przepisy, własne wpisy i zeszyty — na przykład na nowym koncie.
    </p>
    <p>
        Najpierw pokażemy, co jest w paczce i co z tego wczytamy. <strong>Nic nie zapiszemy, dopóki nie klikniesz „Wczytaj zaznaczone”.</strong>
        Wszystko, co wczytamy, będzie <strong>prywatne</strong> — o publikacji zdecydujesz później.
        Zdjęć z paczki na razie nie przenosimy, a pytań z Poradźcie nie wczytujemy — pytanie jest zawsze publiczne.
    </p>
    <p class="mb-5">Plik może mieć najwyżej {{ $maksMb }} MB.</p>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('settings.data.import.check') }}" enctype="multipart/form-data">
        @csrf
        <div class="field @error('plik') has-error @enderror">
            {{-- Ten sam wzorzec pola pliku co przy imporcie z PDF (D-035): pole schowane
                 klasą, klikalna jest duża etykieta z ikoną i napisem. --}}
            <input class="visually-hidden pole-zdjecia-input" id="f-plik" type="file" name="plik" accept=".zip,application/zip"
                   aria-labelledby="f-plik-tytul"
                   aria-describedby="f-plik-help @error('plik') f-plik-error @enderror"
                   @error('plik') aria-invalid="true" @enderror>
            <label class="pole-zdjecia" for="f-plik">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="book" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-plik-tytul">Wybierz plik ZIP z paczką</span>
            </label>
            <span class="field-help" id="f-plik-help">Wybierz plik bez rozpakowywania i bez zmian. Po wybraniu kliknij „Pokaż, co jest w paczce”.</span>
            @error('plik')<span class="field-error" id="f-plik-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Pokaż, co jest w paczce</button>
        </div>
    </form>

    <p class="mt-6"><a href="{{ route('settings.data') }}">Wróć do „Twoich danych”</a></p>
</x-layout>
