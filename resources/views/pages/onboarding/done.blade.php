<x-layout title="Gotowe" :noindex="true">
    <p class="wizard-steps">
        <span class="wizard-steps-current">Krok 3 z 3</span>
        <span class="wizard-steps-track" aria-hidden="true">
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot" data-done="true"></span>
            <span class="wizard-steps-dot" data-done="true"></span>
        </span>
    </p>

    <h1>Wszystko gotowe, {{ $name }}</h1>

    <p class="text-lead">
        {{-- Zdanie z `docs/brand/COPY_STYLE.md` §6 („koniec onboardingu"),
             BEZ ostatniego zdania („Jedno i drugie jest w porządku") — zdjęte
             na decyzję właściciela w grupie C4: dwa równorzędne przyciski niżej
             mówią to samo bez tłumaczenia intencji, a ta sama konstrukcja
             stała w serwisie trzy razy (`/pomoc`, `/dodaj`, tutaj). Brzmienie
             do podmiany w §6 jest w raporcie. Stało tu też „ugotowałaś"
             i zostało zamienione na formę z hasła głównego.

             SPROSTOWANIE (issue #274): pierwotny komentarz twierdził, że była
             to JEDYNA forma żeńska w interfejsie. Nie była — inwentaryzacja
             z #274 znalazła ich kilkanaście, od ustawień prywatności
             („co gotowałam") przez profil („będziesz mogła") po ukośniki
             rodzajowe („Zrobiłam/zrobiłem"). Dlatego reguła nie stoi już na
             czyjejś pamięci, tylko na teście `TekstyNiePrzypisujaPlciTest`. --}}
        {{-- D-268: w formie wybranej przez tę osobę; bez wyboru — bez rodzaju. --}}
        Konto jest założone. Możesz od razu pokazać, co dziś {{ \App\Support\Forma::dla(auth()->user(), 'ugotowałaś', 'ugotowałeś', 'gotujesz') }} —
        albo najpierw się rozejrzeć.
    </p>

    {{-- Dwa równorzędne wyjścia. Nie wymuszamy publikacji (docs/UX_50_PLUS.md). --}}
    <div class="form-actions">
        <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj pierwsze zdjęcie</a>
        <a class="btn btn-secondary" href="{{ route('home') }}">Na razie tylko pooglądam</a>
    </div>

    {{-- Pomijalne pytanie o formę zwracania się (D-268, #1752). Stoi POD dwoma
         wyjściami, nie przed nimi: kto nie chce odpowiadać, po prostu idzie
         dalej, a forma neutralna zostaje. Bez przypominania później. --}}
    @if($profile)
        <section class="ramka-pomocnicza mt-8">
            <h2 class="mt-0">Jedno pytanie, jeśli chcesz</h2>
            <x-wybor-formy :profile="$profile" :akcja="route('onboarding.form_of_address')" />
        </section>
    @endif

    <section class="ramka-pomocnicza mt-8">
        <h2>Trzy rzeczy, które warto wiedzieć</h2>
        <ul class="pl-6">
            <li><strong>Tekst da się powiększyć.</strong> W <a href="{{ route('settings.accessibility') }}">Ustawieniach</a> możesz ustawić większy tekst — na stałe, na każdym urządzeniu.</li>
            <li><strong>Możesz decydować, kto widzi Twoje wpisy.</strong> Przy każdym wpisie wybierasz: wszyscy, tylko obserwujący albo tylko Ty.</li>
            <li><strong>Twoje dane są Twoje.</strong> W każdej chwili możesz je pobrać na swój komputer albo usunąć konto.</li>
        </ul>
    </section>
</x-layout>
