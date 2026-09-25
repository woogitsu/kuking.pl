{{--
    Ekran wyboru „Ukryj tę osobę" (issue #1810, D-278).

    Zwykła strona z formularzem (GET + POST), bez JavaScriptu. Nazwa konta
    stoi dosłownie, w osobnym członie z dwukropkiem — bez odmiany
    (COPY_STYLE, 11 września 2026). Każde zdanie o skutku mówi „tylko dla
    Ciebie", bo „ukryj" ma w serwisie drugie znaczenie: moderacja ukrywa
    treść wszystkim.

    Ostatnia linia prowadzi do blokady i zgłoszenia — ukrycie jest na
    nadmiar wpisów, nie na kogoś, kto dokucza.
--}}
<x-layout title="Ukryj tę osobę" :noindex="true">
    <h1>Ukryj tę osobę</h1>

    <x-error-summary />

    <p class="text-title-lg">Osoba: {{ $osoba->displayName() }}</p>

    @if($obserwuje)
        <div class="notice" role="status">
            <p>Obserwujesz tę osobę, więc jej wpisy są na Twoim Starcie z Twojego wyboru.
                Jeśli nie chcesz ich widzieć, przestań ją obserwować.</p>
            <form method="POST" action="{{ route('social.unfollow', $osoba->profile->username) }}">
                @csrf @method('DELETE')
                <input type="hidden" name="oczekiwany_id" value="{{ $osoba->getKey() }}">
                <button class="btn btn-secondary" type="submit">Przestań obserwować</button>
            </form>
        </div>
    @elseif($juzUkryta)
        <p>Już ukrywasz tę osobę tylko dla siebie. Datę końca zmienisz na liście
            <a href="{{ route('settings.hidden') }}">Ukryte</a>.</p>
    @else
        <section class="panel-formularza">
            <h2>Co się stanie</h2>
            <ul>
                <li>Przez {{ $dni }} dni nie zobaczysz wpisów tej osoby w „Świeżo z <x-kuking-word />”, na tablicy na dziś ani wśród propozycji osób.</li>
                <li>To działa tylko dla Ciebie. Inni widzą tę osobę jak dotąd.</li>
                <li>Nie powiadamiamy tej osoby.</li>
                <li>Jej profil, przepisy w wyszukiwarce i wpisy pod linkiem dalej otworzysz.</li>
                <li>Po {{ $dni }} dniach wpisy wrócą same. Wcześniej przywrócisz je na liście „Ukryte” w Ustawieniach.</li>
            </ul>

            @if($ostrzezenie)
                <p class="notice">Ukrywasz już co najmniej jedną trzecią osób, które ostatnio coś pokazały.
                    Zajrzyj na listę <a href="{{ route('settings.hidden') }}">Ukryte</a> — może część z nich warto przywrócić.</p>
            @endif

            <form method="POST" action="{{ route('social.hide', $osoba->profile->username) }}">
                @csrf
                <input type="hidden" name="oczekiwany_id" value="{{ $osoba->getKey() }}">
                <input type="hidden" name="wroc" value="{{ $wroc }}">
                <button class="btn btn-primary" type="submit">Ukryj tę osobę</button>
                <a class="btn btn-secondary" href="{{ $wroc }}">Anuluj</a>
            </form>
        </section>
    @endif

    <p class="mt-8">
        Ktoś Ci dokucza? Ukrycie tego nie zatrzyma.
        Zablokuj ją — przycisk „Zablokuj” jest na <a href="{{ route('profile.show', $osoba->profile->username) }}">jej profilu</a> —
        albo <a href="{{ route('reports.create', ['type' => 'user', 'id' => $osoba->profile->username]) }}">zgłoś ją nam</a>.
    </p>
</x-layout>
