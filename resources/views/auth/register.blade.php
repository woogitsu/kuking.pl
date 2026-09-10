<x-layout title="Załóż konto" description="Załóż darmowe konto w Kuking i pokaż, co dziś ugotowałeś.">
    <h1>Zostań <x-kuking-word forma="iem" /></h1>
    <p class="mb-5">Cztery pola i gotowe. Nie pytamy o numer telefonu ani o datę urodzenia.</p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('register') }}">
        @csrf

        <x-field name="display_name" label="Jak mamy Cię nazywać?" required
                 autocomplete="name" placeholder="Basia"
                 help="Imię, przezwisko albo cokolwiek chcesz. To będzie widoczne dla innych." />

        {{-- POMOC MÓWI, PO CO TO POLE JEST, A NIE JAKIE ZNAKI SĄ DOZWOLONE.
             Stary tekst („tylko litery bez polskich znaków, cyfry
             i podkreślnik") mówił językiem reguły i odbił od rejestracji
             63-letnią osobę z grupy docelowej. Zapis poprawia teraz serwis
             (`NazwaUzytkownika`), więc lista dozwolonych znaków przestała być
             informacją, którą trzeba komuś podawać z góry. --}}
        <x-field name="username" label="Nazwa, która będzie w adresie Twojego profilu" required
                 autocomplete="username" placeholder="Basia z Podkarpacia"
                 help="Może być imię, przezwisko albo imię i miejscowość. Polskie litery i spacje są w porządku — poprawimy zapis za Ciebie." />

        <x-field name="email" label="Twój adres e-mail" type="email" required
                 autocomplete="email"
                 help="Potrzebny tylko wtedy, gdy zapomnisz hasła. Nie pokażemy go nikomu." />

        <x-field name="password" label="Hasło" type="password" required
                 autocomplete="new-password"
                 help="Co najmniej 10 znaków. Najprościej wpisać trzy słowa razem, na przykład: zielonapietruszkarano." />

        <div class="field @error('age_confirmed') has-error @enderror mt-6">
            <label class="choice" for="f-age_confirmed">
                <input id="f-age_confirmed" type="checkbox" name="age_confirmed" value="1" @checked(old('age_confirmed'))>
                <span class="choice-label">Mam co najmniej {{ config('kuking.account.min_age') }} lat</span>
            </label>
            @error('age_confirmed')<span class="field-error" id="f-age_confirmed-error">{{ $message }}</span>@enderror
        </div>

        <div class="field @error('terms_accepted') has-error @enderror">
            <label class="choice" for="f-terms_accepted">
                <input id="f-terms_accepted" type="checkbox" name="terms_accepted" value="1" @checked(old('terms_accepted'))>
                <span class="choice-label">
                    Znam <a href="{{ route('rules') }}">zasady Kuking</a>
                    i <a href="{{ route('terms') }}">regulamin</a>
                </span>
            </label>
            @error('terms_accepted')<span class="field-error" id="f-terms_accepted-error">{{ $message }}</span>@enderror
        </div>

        <x-turnstile miejsce="rejestracja" />

        {{--
            ZDANIE O BŁĘDZIE TAM, GDZIE CZŁOWIEK PATRZY, GDY KLIKA.

            Podsumowanie błędów stoi na górze formularza i tak ma zostać — ale
            po wysłaniu przeglądarka zostawia człowieka w tym samym miejscu, na
            dole. 63-latka, która odbiła się o walidację nazwy użytkownika, nie
            zobaczyła ani podsumowania, ani czerwonego tekstu przy polu:
            zobaczyła to, co miała przed oczami.

            JavaScript przewija teraz do podsumowania (`resources/js/app.js`),
            ale to jest DODATEK. To zdanie jest wersją bez JavaScriptu i nie
            wolno go usuwać razem z nim.
        --}}
        @if($errors->any())
            <p class="field-error mb-4">
                Formularz nie został wysłany —
                <a href="#tresc">na górze jest napisane, czego jeszcze brakuje</a>.
            </p>
        @endif

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Załóż konto</button>
        </div>
    </form>

    <p class="mt-6">Masz już konto? <a href="{{ route('login') }}">Zaloguj się</a>.</p>
</x-layout>
