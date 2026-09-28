{{--
    Ekran „Potwierdź adres e-mail”.

    ZDANIE O NIEUDANEJ WYSYŁCE (issue #234, D-062) pokazuje się tylko wtedy,
    gdy w `mail_failures` leży ślad przepadłego potwierdzenia dla TEGO konta,
    świeższy niż `kuking.poczta.okno_prawdy_godzin`. Dopóki go nie ma, ekran
    wygląda dokładnie jak dotąd.

    Zdanie mówi o ZDARZENIU Z PRZESZŁOŚCI, z datą i godziną („wysłaliśmy ją
    wtedy, dostawca jej nie przyjął”), a nie o stanie („listy do Ciebie nie
    wychodzą”). Różnica jest istotna: po udanym ponowieniu zdanie o zdarzeniu
    zostaje prawdziwe, a zdanie o stanie stałoby się nowym kłamstwem — tylko
    w drugą stronę. Dzięki temu nie potrzeba w bazie żadnego znacznika
    „już naprawione”.

    Rada „Zajrzyj do folderu «Spam»” jest w tym wypadku PODMIENIONA, nie
    dołożona: wysyłanie kogoś na poszukiwanie wiadomości, o której wiemy, że
    nie powstała, to dokładnie ta usterka, którą naprawia issue #234.

    Bez nowych klas CSS — `.notice` jest tym samym pudełkiem, którym ten ekran
    mówi już „Możesz już korzystać z Kuking”.
--}}
<x-layout title="Potwierdź adres e-mail" :noindex="true">
    <h1>Potwierdź swój adres e-mail</h1>

    @if (! $pocztaDziala)
        {{-- Issue #1335: poczta nie wysyła, więc żadnej obietnicy listu,
             żadnego przycisku ponowienia i żadnej rady o „Spamie”. --}}
        <p class="notice">
            <strong>Wiadomość z potwierdzeniem nie przyjdzie.</strong>
            Nie wysyłamy teraz wiadomości e-mail, więc nie czekaj na nią i nie szukaj jej w skrzynce.
            Jeśli potwierdzenie adresu <strong>{{ auth()->user()->email }}</strong> jest Ci potrzebne,
            napisz na <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a>
            — odpisuje człowiek i potwierdzimy adres inaczej.
        </p>
    @else
        <p>
            Wysłaliśmy wiadomość na <strong>{{ auth()->user()->email }}</strong>.
            Kliknij w niej link, żeby potwierdzić, że ten adres należy do Ciebie.
        </p>
    @endif

    @if ($pocztaDziala && $nieudanaWysylka !== null)
        <p class="notice">
            <strong>Ostatnia wiadomość nie dotarła.</strong>
            Wysłaliśmy ją {{ \App\Support\Czas::lokalnie($nieudanaWysylka->failed_at)->format('j.m.Y') }}
            o {{ \App\Support\Czas::lokalnie($nieudanaWysylka->failed_at)->format('H:i') }}, ale nasz dostawca poczty jej nie przyjął —
            więc nie ma jej ani w Twojej skrzynce, ani w folderze „Spam”.
            Kliknij niżej „Wyślij wiadomość jeszcze raz”. Jeśli znów nie przyjdzie,
            napisz na <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a>
            — odpisuje człowiek i pomożemy potwierdzić adres inaczej.
        </p>
    @endif

    <p class="notice">
        <strong>Możesz już korzystać z <x-kuking-word />.</strong> Potwierdzenie adresu nie jest potrzebne,
        żeby dodać zdjęcie czy przepis. Przyda się dopiero wtedy, gdy zapomnisz hasła
        albo zechcesz pobrać wszystkie swoje dane.
    </p>

    <div class="flex gap-3 flex-wrap mt-6">
        <a class="btn btn-primary" href="{{ route('home') }}"><span class="btn-napis">Przejdź do <x-kuking-word /></span></a>
        @if ($pocztaDziala)
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button class="btn btn-secondary" type="submit">Wyślij wiadomość jeszcze raz</button>
            </form>
        @endif
    </div>

    @if ($pocztaDziala && $nieudanaWysylka === null)
        <p class="meta mt-5">Wiadomość nie przyszła? Zajrzyj do folderu „Spam”.</p>
    @endif
</x-layout>
