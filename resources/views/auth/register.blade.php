<x-layout title="Załóż konto" description="Załóż darmowe konto w Kuking i pokaż, co dziś ugotowałeś.">
    <h1>Zostań <x-kuking-word forma="iem" /></h1>
    {{--
        Kontrakt projektowy 60+ (docs/research/AUDYT_60_PLUS.md, ranking
        pkt 3 i 9; test regresyjny: RejestracjaOnboardingKopiaTest).
        „Cztery pola i gotowe" musi zapowiadać, co będzie DALEJ — inaczej
        „Krok 1 z 3" zaraz potem czyta się jak „jednak coś nie wyszło".
    --}}
    <p class="mb-5 rejestracja-zapowiedz">
        Cztery pola i gotowe — konto zaczyna działać od razu. Potem zapytamy jeszcze
        o parę rzeczy, żeby dobrać Ci pierwsze wpisy, ale to całkiem opcjonalne
        i można to pominąć. Nie pytamy o numer telefonu ani o datę urodzenia.
    </p>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('register') }}">
        @csrf

        <x-field name="display_name" label="Jak mamy Cię nazywać?" required
                 autocomplete="name" placeholder="Basia"
                 help="Imię, przezwisko albo cokolwiek chcesz. To będzie widoczne dla innych." />

        <x-field name="username" label="Twoja nazwa użytkownika" required
                 autocomplete="username" placeholder="basia_z_podkarpacia"
                 help="Będzie w adresie Twojego profilu. Tylko litery bez polskich znaków, cyfry i podkreślnik." />

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

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Załóż konto</button>
        </div>
    </form>

    <p class="mt-6">Masz już konto? <a href="{{ route('login') }}">Zaloguj się</a>.</p>
</x-layout>
