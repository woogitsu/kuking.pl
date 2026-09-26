<x-layout :title="'Wspólny zeszyt: '.$collection->name" :noindex="true">
    {{--
        WSPÓLNY ZESZYT — EKRAN WŁAŚCICIELA (#1743, D-302).

        Jedna kolumna, od góry: co to znaczy, kto ma dostęp, oczekujące
        zaproszenia, dwa sposoby zaproszenia. Akcje odbierające dostęp
        idą przez `x-confirm-button` (potwierdzenie bez JavaScriptu) i stoją
        przy osobie, której dotyczą — nazwa w mianowniku osobno, pytanie
        pełnym zdaniem (COPY_STYLE: nie doklejamy odmiany do cudzych słów).
    --}}
    <div class="marka-zeszyt">
    <h1>Wspólny zeszyt „{{ $collection->name }}”</h1>

    <x-error-summary />

    @if($collection->is_default)
        <p>Zeszytu „{{ $collection->name }}” nie da się udostępnić — tu trafia wszystko, co zapisujesz jednym kliknięciem.
            Załóż osobny zeszyt, na przykład „Obiady rodzinne”, i zaproś do niego bliską osobę.</p>
        <a class="btn btn-primary" href="{{ route('collections.index') }}">Wróć do zeszytów</a>
    @else
        <section class="panel-formularza mb-6" aria-labelledby="co-moze-osoba">
            <h2 id="co-moze-osoba" class="mt-0">Co może zaproszona osoba</h2>
            <ul>
                <li>zapisywać w tym zeszycie przepisy i wpisy oraz je stąd wyjmować,</li>
                <li>czytać i pisać notatki przy pozycjach — także te, które powstały wcześniej,</li>
                <li>widzieć, kto co dodał.</li>
            </ul>
            <p>Nie może zmienić nazwy ani tego, kto widzi zeszyt, i nie może go usunąć. Widzi tylko te przepisy i wpisy, które sama może oglądać w serwisie.
                Dostęp możesz odebrać w każdej chwili, a ona może sama odejść.</p>
            <p class="meta m-0">{{ $collection->isPublic() ? 'Ten zeszyt widzą wszyscy — zaproszenie tego nie zmienia.' : 'Zeszytu dalej nie widzi nikt poza Tobą i osobami, które zaprosisz.' }}</p>
        </section>

        <h2>Kto ma dostęp</h2>
        @if($czlonkowie->isEmpty())
            <p>Na razie tylko Ty.</p>
        @else
            <ul class="stack list-none p-0" data-czlonkowie>
                @foreach($czlonkowie as $czlonek)
                    <li class="card">
                        <p class="m-0"><strong>{{ $czlonek->displayName() }}</strong>
                            @if($czlonek->profile?->username)
                                <span class="meta">{{ '@'.$czlonek->profile->username }}</span>
                            @endif
                        </p>
                        <p class="meta mt-1">Ma dostęp od {{ $czlonek->pivot->created_at?->locale('pl')->isoFormat('D MMMM YYYY') }}.</p>
                        <x-confirm-button
                            :action="route('collections.members.destroy', ['collection' => $collection, 'member' => $czlonek->getKey()])"
                            label="Odbierz dostęp"
                            question="Odebrać tej osobie dostęp do zeszytu? To, co dodała, zostanie w zeszycie."
                            :name="$czlonek->displayName()" />
                    </li>
                @endforeach
            </ul>
        @endif

        @if($zaproszenia->isNotEmpty())
            <h2 class="mt-8">Czekają na odpowiedź</h2>
            <ul class="stack list-none p-0" data-zaproszenia>
                @foreach($zaproszenia as $zaproszenie)
                    <li class="card">
                        <p class="m-0">
                            @if($zaproszenie->jestLinkiem())
                                <strong>Link-zaproszenie</strong>
                            @else
                                <strong>{{ $zaproszenie->invitee?->displayName() ?? 'Zaproszona osoba' }}</strong>
                            @endif
                        </p>
                        <p class="meta mt-1">Ważne do {{ $zaproszenie->expires_at->locale('pl')->isoFormat('D MMMM YYYY') }}.</p>
                        <x-confirm-button
                            :action="route('collections.invitations.destroy', ['collection' => $collection, 'invitation' => $zaproszenie])"
                            :label="$zaproszenie->jestLinkiem() ? 'Odwołaj link' : 'Odwołaj zaproszenie'"
                            :question="$zaproszenie->jestLinkiem() ? 'Odwołać ten link? Nikt już z niego nie dołączy.' : 'Odwołać to zaproszenie? Ta osoba nie będzie mogła już dołączyć.'" />
                    </li>
                @endforeach
            </ul>
        @endif

        @if($mozeZapraszac)
            <p class="meta mt-6">Dostęp może mieć najwyżej {{ $limit }} {{ \App\Support\Odmiana::rzeczownik($limit, 'osoba', 'osoby', 'osób') }} poza Tobą, razem z oczekującymi zaproszeniami.</p>

            <section class="panel-formularza mt-4" aria-labelledby="zapros-po-nazwie">
                <h2 id="zapros-po-nazwie" class="mt-0">Zaproś osobę z Kuking</h2>
                <form method="POST" action="{{ route('collections.invitations.store', $collection) }}">
                    @csrf
                    <x-field name="nazwa" label="Nazwa konta" required
                             help="Jest na profilu tej osoby, po znaku @ — na przykład halina.k. Dostanie powiadomienie i sama zdecyduje." />
                    <button class="btn btn-primary" type="submit">Wyślij zaproszenie</button>
                </form>
            </section>

            <section class="panel-formularza mt-6" aria-labelledby="zapros-linkiem">
                <h2 id="zapros-linkiem" class="mt-0">Zaproś linkiem</h2>
                <p>Dla osoby, której nazwy konta nie znasz albo która dopiero założy konto. Link działa raz, przez
                    {{ (int) config('kuking.collections.link_days') }} dni. Kto go otworzy, musi się zalogować i potwierdzić, że dołącza.</p>
                @if(session('link_zaproszenia'))
                    <div class="field">
                        <label class="field-label" for="link-zaproszenia">Twój link — skopiuj go i wyślij jednej osobie</label>
                        <input class="field-input" id="link-zaproszenia" type="text" readonly value="{{ session('link_zaproszenia') }}" data-link-zaproszenia>
                        <p class="field-help">Ten link pokazujemy tylko teraz. Gdy go zgubisz, odwołaj go niżej i utwórz nowy.</p>
                    </div>
                @endif
                @error('link')
                    <p class="field-error" role="alert">{{ $message }}</p>
                @enderror
                <form method="POST" action="{{ route('collections.invitations.link', $collection) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit">Utwórz link-zaproszenie</button>
                </form>
            </section>
        @else
            <p class="notice mt-6">Teraz nie możesz zapraszać nowych osób. Odebrać dostęp albo odwołać zaproszenie możesz zawsze.</p>
        @endif
    @endif

    <a class="btn btn-secondary mt-8" href="{{ route('collections.show', $collection) }}">Wróć do zeszytu</a>
    </div>
</x-layout>
