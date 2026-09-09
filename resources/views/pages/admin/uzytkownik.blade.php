{{--
    Karta jednego konta — panel moderacji.

    KAŻDE WEJŚCIE TUTAJ ZOSTAWIA WPIS W `audit_log` (`admin.user_viewed`).
    To jest miejsce, w którym moderator czyta dane JEDNEJ, wskazanej osoby
    w komplecie: pełny adres e-mail, całą historię decyzji, kiedy ostatnio tu
    była. Decyzja 3.2 z `docs/INSPIRATION_DECISIONS.md` mówi wprost, że na
    pytanie „kto oglądał moje dane" odpowiedź „nie wiemy" jest zła —
    odpowiada na nie właśnie ten wpis. Pełne uzasadnienie, razem z tym,
    dlaczego LISTY nie logujemy: nagłówek `UzytkownicyController`.

    ADRES E-MAIL W CAŁOŚCI, w odróżnieniu od listy. Tu moderator odpowiada
    na pytanie „napisz do tej osoby" albo „czy to ten sam adres, co
    w zgłoszeniu", a maska `j***@wp.pl` na to nie odpowiada. Cena tej pełni
    jest zapłacona wpisem do dziennika o linijkę wyżej.

    ŻADNEGO PRZYCISKU ZMIENIAJĄCEGO KONTO — powód w nagłówku kontrolera.
    Rola: `kuking:nadaj-role` z powłoki. Zawieszenie i blokada: przez
    zgłoszenie, z powodem i z prawem do odwołania.
--}}
<x-layout title="{{ $uzytkownik->displayName() }} — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Użytkownicy" />

    <p class="meta"><a href="{{ route('admin.users') }}">← Wróć do listy kont</a></p>

    <div class="flex items-center gap-4 mb-5">
        <x-avatar :user="$uzytkownik" :size="64" />
        <h1 class="mt-0 mb-0">{{ $uzytkownik->displayName() }}</h1>
    </div>

    <p class="meta">
        <span class="stan-konta {{ [
            \App\Models\User::STATUS_ACTIVE => 'stan-konta-aktywne',
            \App\Models\User::STATUS_SUSPENDED => 'stan-konta-uwaga',
            \App\Models\User::STATUS_PENDING_DELETE => 'stan-konta-uwaga',
            \App\Models\User::STATUS_BANNED => 'stan-konta-ciezki',
            \App\Models\User::STATUS_ERASED => 'stan-konta-ciezki',
        ][$uzytkownik->status] ?? '' }}">{{ $uzytkownik->statusLabel() }}</span>
        · {{ $uzytkownik->roleLabel() }}
    </p>

    <div class="card mb-5">
        <h2 class="mt-0 text-title-sm">Konto</h2>

        <dl class="dane-konta">
            <dt>Nazwa konta</dt>
            <dd>
                @if($uzytkownik->profile)
                    @if($uzytkownik->jestWidocznyJakoOsoba())
                        <a href="{{ route('profile.show', $uzytkownik->profile->username) }}">&commat;{{ $uzytkownik->profile->username }}</a>
                    @else
                        {{-- Konto zamknięte nie jest widoczne jako osoba
                             (`User::jestWidocznyJakoOsoba()`), więc odnośnik
                             prowadziłby prosto w 403. --}}
                        &commat;{{ $uzytkownik->profile->username }} (profil niedostępny)
                    @endif
                @else
                    <span class="drobne">bez profilu</span>
                @endif
            </dd>

            <dt>Adres e-mail</dt>
            <dd>
                @if($uzytkownik->isErased())
                    {{-- D-022: `EraseAccountData` wstawia tu adres techniczny.
                         Pokazanie go sugerowałoby, że dane wciąż są. --}}
                    <span class="drobne">wymazany razem z kontem</span>
                @else
                    {{ $uzytkownik->email }}
                    @unless($uzytkownik->hasVerifiedEmail())
                        <span class="drobne">adres jeszcze niepotwierdzony</span>
                    @endunless
                @endif
            </dd>

            <dt>Zarejestrowane</dt>
            <dd>{{ \App\Support\Czas::data($uzytkownik->created_at, 'j F Y, H:i') }}</dd>

            <dt>Ostatnio tutaj</dt>
            <dd>
                @if($uzytkownik->ostatnio_widziany_at)
                    {{ \App\Support\Czas::data($uzytkownik->ostatnio_widziany_at, 'j F Y, H:i') }}
                @else
                    <span class="drobne">nigdy</span>
                @endif
            </dd>

            <dt>Weryfikacja dwuetapowa</dt>
            <dd>{{ $uzytkownik->hasTwoFactorConfirmed() ? 'Włączona' : 'Wyłączona' }}</dd>

            @if($uzytkownik->status_expires_at)
                <dt>Kara mija</dt>
                <dd>{{ \App\Support\Czas::data($uzytkownik->status_expires_at, 'j F Y, H:i') }}</dd>
            @endif

            @if($uzytkownik->delete_requested_at)
                <dt>Zgłoszone do usunięcia</dt>
                <dd>
                    {{ \App\Support\Czas::data($uzytkownik->delete_requested_at, 'j F Y') }}
                    <span class="drobne">
                        zakres: {{ $uzytkownik->chceUsunacTresci() ? 'razem z treściami' : 'same dane osobowe' }}
                    </span>
                </dd>
            @endif

            @if($uzytkownik->data_erased_at)
                <dt>Dane wymazane</dt>
                <dd>{{ \App\Support\Czas::data($uzytkownik->data_erased_at, 'j F Y') }}</dd>
            @endif

            @if($uzytkownik->isSeeded())
                <dt>Pochodzenie</dt>
                <dd>Konto przykładowe (D-025)</dd>
            @endif
        </dl>
    </div>

    <div class="card mb-5">
        <h2 class="mt-0 text-title-sm">Co to konto tu zrobiło</h2>

        {{-- LICZBY, NIE WYKRESY I NIE PUNKTY. To jest kontekst do rozmowy
             („osoba z dwoma wpisami od wczoraj" to inna sprawa niż „osoba
             z dwustoma przepisami"), a nie miara wartości człowieka.
             Żadnego rankingu, żadnego porównania z innymi (AGENTS.md §12). --}}
        <dl class="dane-konta">
            <dt>Wpisy</dt>
            <dd>{{ $uzytkownik->wpisow_count }}</dd>

            <dt>Przepisy</dt>
            <dd>{{ $uzytkownik->przepisow_count }}</dd>

            <dt>Komentarze</dt>
            <dd>{{ $uzytkownik->komentarzy_count }}</dd>

            <dt>Ugotowane</dt>
            <dd>{{ $uzytkownik->ugotowan_count }}</dd>
        </dl>
    </div>

    <div class="card">
        <h2 class="mt-0 text-title-sm">Decyzje moderacyjne</h2>

        @forelse($decyzje as $decyzja)
            <article class="mb-5">
                <p class="mb-0"><strong>{{ $decyzja->label() }}</strong></p>
                <p class="meta">
                    {{ \App\Support\Czas::data($decyzja->created_at, 'j F Y, H:i') }}
                    @if($decyzja->moderator)
                        · {{ $decyzja->moderator->displayName() }}
                    @else
                        · moderator, którego konta już nie ma
                    @endif
                    @if($decyzja->reason_code)
                        · powód: {{ $decyzja->reason_code }}
                    @endif
                </p>

                {{-- WIADOMOŚĆ, KTÓRĄ TA OSOBA NAPRAWDĘ DOSTAŁA (DSA art. 17).
                     Bez niej nie da się ocenić, czy rozmawiamy o tym samym,
                     co jej kiedyś powiedzieliśmy — ten sam powód, dla którego
                     pokazuje ją kolejka odwołań. --}}
                @if($decyzja->user_message)
                    <p class="whitespace-pre-line">{{ $decyzja->user_message }}</p>
                @endif

                @if($decyzja->note)
                    <p class="meta whitespace-pre-line">Notatka moderatora: {{ $decyzja->note }}</p>
                @endif
            </article>
        @empty
            <p class="mb-0">Żadnej decyzji. To konto nigdy nie było przedmiotem sprawy.</p>
        @endforelse
    </div>
</x-layout>
