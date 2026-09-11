{{--
    „Napisz do nas" — rozmowa, nie reklamacja.

    ZWYKŁA STRONA POD WŁASNYM ADRESEM, RENDEROWANA SERWEROWO.
    Nie dymek w rogu. Osoba, która pisze „coś nie działa", jest bardzo często
    tą samą osobą, której nie dociągnął się JavaScript — dymek byłby wtedy
    przyciskiem, który nic nie robi po kliknięciu (AGENTS.md §5).

    BEZ `noindex`, świadomie. Ktoś, kto nie może się zalogować, wpisuje
    „kuking kontakt" w wyszukiwarkę, a nie szuka stopki.

    TON: sąsiedzki, bez słowa „reklamacja", bez „zgłoszenia serwisowego"
    i bez formularza z gwiazdkami przy polach wymaganych. Pierwsze zdanie
    ma powiedzieć, że po drugiej stronie jest człowiek.
--}}
<x-layout title="Napisz do nas"
          description="Napisz do nas, jeśli coś nie działa, masz pomysł albo po prostu chcesz coś powiedzieć. Czyta to człowiek.">
    {{--
        PRAWA SZYNA (issue #205).

        DWIE RZECZY, KTÓRE MOGĄ SPRAWIĆ, ŻE PISANIE NIE BĘDZIE POTRZEBNE.
        Człowiek pisze do nas najczęściej dlatego, że czegoś nie znalazł —
        a najczęstszym „czegoś" jest logowanie. Odnośnik do odzyskania hasła
        stoi więc na wierzchu i TYLKO dla osoby niezalogowanej; zalogowanej
        byłby podpowiedzią do problemu, którego nie ma.

        To NIE jest zniechęcanie do napisania. Formularz zostaje tam, gdzie
        był, w środku ekranu, i nic go nie przykrywa — tekst niżej mówi
        wprost, że wiadomość jest w porządku także wtedy, gdy odpowiedź
        gdzieś tam jest.

        ZERO ZAPYTAŃ DO BAZY.
    --}}
    <x-slot:rail>
        <x-szyna-blok tytul="Może odpowiedź już tu jest" id="szyna-pomoc" ikona="chat">
            <x-szyna-linki :pozycje="array_values(array_filter([
                auth()->check() ? null : [
                    'href' => route('password.request'),
                    'nazwa' => 'Nie możesz się zalogować',
                    'podpis' => 'Ustawimy nowe hasło — potrzebny jest tylko adres e-mail.',
                ],
                [
                    'href' => route('help'),
                    'nazwa' => 'Pomoc',
                    'podpis' => 'Pytania, które wracają najczęściej.',
                ],
                [
                    'href' => route('rules'),
                    'nazwa' => 'Zasady',
                    'podpis' => 'Czego się tu po sobie spodziewamy.',
                ],
            ]))" />

            <p class="mb-0">Jeśli tego tam nie ma — pisz.</p>
        </x-szyna-blok>
    </x-slot:rail>

    <h1>Napisz do nas</h1>

    <p class="mb-5">
        Napisz, jeśli coś nie działa, jeśli masz pomysł albo jeśli chcesz nam coś
        powiedzieć. Nie musisz mieć konta w Kuking i nie musisz pisać ładnie —
        wystarczy, żebyśmy zrozumieli, o co chodzi.
    </p>

    {{--
        ROZDZIAŁ OD ZGŁASZANIA CUDZYCH TREŚCI — NA WIERZCHU, PRZED FORMULARZEM.

        To jest jedyne miejsce, w którym da się zawczasu zatrzymać człowieka,
        który chce zgłosić czyjś wpis, a trafił na formularz techniczny.
        Odwrotnie też: ten sam blok stoi na formularzu z DSA art. 16 i wskazuje
        tutaj. Bez tego jedna kolejka zapycha drugą, a zgłoszenie treści
        wpadałoby tam, gdzie nie ma decyzji, od której można się odwołać.
    --}}
    <div class="ramka-pomocnicza mb-5">
        <h2 class="mt-0">Chodzi o czyjś wpis, przepis albo komentarz?</h2>
        <p>
            Pod każdą treścią jest przycisk <strong>Zgłoś</strong> — użyj go,
            jeśli ktoś kogoś obraża, wrzuca spam albo doradza coś niebezpiecznego.
        </p>
        <p class="mb-0">
            Jeśli treść Twoim zdaniem łamie prawo, wypełnij
            <a href="{{ route('zglos.nielegalna') }}">zgłoszenie treści niezgodnej z prawem</a>.
            Tamten formularz ma własne terminy i kończy się decyzją, od której
            możesz się odwołać. Ten — nie; tutaj po prostu rozmawiamy.
        </p>
    </div>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('kontakt.store') }}">
        @csrf

        {{-- Tożsamość TEGO wysłania formularza (D-027). Zwykłe ukryte pole,
             bez JavaScriptu. Nazwa bez fragmentu „token", inaczej pole ginie
             na ekranie 419 (ADR §1.4.4). --}}
        @if(($kluczWyslania ?? null) !== null)
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        @endif

        {{-- Strona, z której człowiek przyszedł. Przy „coś nie działa" to jest
             połowa diagnozy, a wpisywanie adresu z pamięci jest dokładnie tą
             pracą, której nie chcemy nikomu zlecać. Kontroler przycina to do
             samej ścieżki z naszego serwisu — patrz `oczyscSciezke()`. --}}
        @if(($sciezka ?? null) !== null)
            <input type="hidden" name="page_path" value="{{ $sciezka }}">
        @endif

        <fieldset class="border-0 p-0">
            <legend class="font-bold mb-3">Czego dotyczy?</legend>
            <div class="stack-tight">
                @foreach($rodzaje as $wartosc => $etykieta)
                    <label class="choice">
                        <input type="radio" name="kind" value="{{ $wartosc }}" @checked(old('kind') === $wartosc)>
                        <span class="choice-label">{{ $etykieta }}</span>
                    </label>
                @endforeach
            </div>
            @error('kind')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>

        <x-field name="message" label="Co chcesz nam powiedzieć?"
                 type="textarea" :rows="8" required :value="old('message')"
                 help="Napisz własnymi słowami. Jeśli coś nie działa, przydaje się jedno zdanie o tym, co się działo tuż przedtem — i czy to było na telefonie, czy na komputerze." />

        @guest
            <x-field name="contact_email" label="Twój adres e-mail" type="email"
                     :value="old('contact_email')" autocomplete="email"
                     help="Zostaw, jeśli chcesz dostać odpowiedź — bez adresu nie mamy jak odpisać. Możesz też zostawić puste; wiadomość i tak przeczytamy." />
        @else
            <p class="meta">
                Nie pytamy o adres — odpiszemy na ten, który jest przypisany
                do Twojego konta.
            </p>
        @endguest

        <x-turnstile miejsce="kontakt" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij wiadomość</button>
        </div>
    </form>

    <div class="ramka-pomocnicza mt-5">
        <h2 class="mt-0">Co się stanie dalej</h2>
        <p>
            Wiadomość zapisuje się w Kuking od razu — nawet gdyby akurat nie działała
            poczta, nie zginie.
        </p>
        <p class="mb-0">
            Czyta je {{ config('kuking.community.host_name') }}. Kuking prowadzi na razie
            jedna osoba, więc nie ma tu całodobowego dyżuru — czasem odpowiedź przyjdzie
            tego samego dnia, czasem po weekendzie. Przeczytana zostanie każda.
        </p>
    </div>

    <p class="meta mt-5">
        Wolisz zwykłego e-maila? Napisz na
        <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a>.
        Sprawy dotyczące Twoich danych osobowych możesz kierować na
        <a href="mailto:{{ config('kuking.podmiot.email') }}">{{ config('kuking.podmiot.email') }}</a>.
    </p>
</x-layout>
