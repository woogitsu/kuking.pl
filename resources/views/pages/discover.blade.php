{{-- `szynaWTresci` — TEN EKRAN UŻYWA KOLUMNY SZYNY (zgłoszenie właściciela:
     „tu się zepsuło albo nie było naprawione, prawa kolumna pusta wszystko
     na środku").

     Zmierzone przed poprawką (Chromium, dane demo, moderator):
       * okno 1512 px: rama 1424 px, kolumna czytania 720 px, TRZECIA KOLUMNA
         PUSTA — 452 px pustki po prawej, przy 68 px na `/` i na stronie
         przepisu;
       * okno 1920 px: 656 px pustki, przy 272 px na `/`;
       * gość: cała strona zwinięta do 768 px, bo bez szyny bierze
         `--container-strona-solo`.

     `/odkryj` i `/` to dwie zakładki jednej listy (`feed-tabs`
     w `pages/home.blade.php`), a tablica dnia stoi na `/` w szynie od etapu D.
     Tutaj stała w kolumnie czytania — czyli ta sama tablica, w tym samym
     serwisie, raz obok tekstu, raz nad nim. Teraz stoi tak samo w obu
     miejscach, a kolumna po prawej przestaje być pusta.

     DLACZEGO NIE `<x-slot:rail>`, SKORO TO JEST DOKŁADNIE TA KOLUMNA
     Bo slot renderuje się w kodzie ZA całym `<main>`. Tablica stoi tu dziś
     PRZED wpisami i na telefonie to jest jej miejsce: pod `<x-slot:rail>`
     zjechałaby pod czternaście kart wpisów i przycisk „Pokaż więcej", czyli
     zniknęłaby z ekranu komuś, kto tu wchodzi z telefonu. Kolejność w kodzie
     zostaje więc kolejnością z telefonu, a w bok przesuwa blok dopiero siatka
     samego ekranu (`.odkryj-uklad` w `resources/css/ekran-odkrywania.css`) —
     ten sam zabieg i z tego samego powodu co na stronie przepisu (issue #365,
     `.przepis-uklad`). --}}
<x-layout title="Świeżo z Kuking" description="Co ostatnio ugotowali ludzie w Kuking." :szynaWTresci="true">
    <div class="odkryj-uklad">
        <h1>Świeżo z <x-kuking-word /></h1>
        <p class="mb-6">
            Wszystko, co ludzie pokazali w ostatnich dniach — po kolei, od najnowszego.
            @if(config('kuking.questions.enabled'))
                <br><a href="{{ route('questions.index') }}">Poradźcie — pytania do innych</a>.
                Ktoś to już robił i chętnie powie, jak.
            @endif
        </p>

        {{-- Tablica dnia jest bezpośrednim dzieckiem siatki — inaczej
             `grid-column` z arkusza jej nie dotyczy. Owijka istnieje po to,
             żeby nie dokładać klasy do samego komponentu: ta sama tablica stoi
             w szynie `/home`, w wyszukiwarce i w pasie strony powitalnej. --}}
        <div class="odkryj-szyna">
            <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" />
        </div>

        @if($posts->count() === 0)
            {{--
                Etap D kitu v2 — naprawa pustego stanu.

                Ten pusty stan łamał WŁASNĄ regułę komponentu (patrz komentarz
                w `empty-state.blade.php`: „zawsze jeden przycisk z tekstem") —
                wcześniej wołany był bez `action`/`href`, więc kończył się na
                samym zdaniu, bez żadnej drogi dalej. Tekst wyjaśnienia też był
                inny niż w `docs/brand/COPY_STYLE.md` §6 „pusty feed", mimo że to
                dokładnie ten sam przypadek, który `home.blade.php` ma poprawnie.

                Przycisk zależy od tego, KTO patrzy: `/odkryj` działa też bez
                konta (FeedController::discover), a `/dodaj/zdjecie` konta
                wymaga — więc gość dostaje to samo zaproszenie co na stronie
                powitalnej (`landing.blade.php`, identyczny przypadek „nic tu
                jeszcze nie ma"), nie martwy odnośnik kończący się na logowaniu.

                PUSTY STAN NIE ZABIERA KOLUMNY SZYNY. Tablica dnia stoi obok
                niego dokładnie tak samo jak obok pełnej listy — to jedyna
                rzecz, która ratuje ten ekran, gdy nie ma ani jednego wpisu
                (docs/product/COLD_START.md).
            --}}
            @auth
                <x-empty-state title="Jeszcze nic tu nie ma" action="Dodaj pierwsze zdjęcie" :href="route('posts.create')">
                    Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.
                </x-empty-state>
            @else
                <x-empty-state title="Jeszcze nic tu nie ma" action="Załóż konto i pokaż swoje" :href="route('register')">
                    Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.
                </x-empty-state>
            @endauth
        @else
            <div class="stack">
                @foreach($posts as $post)
                    <x-post-card :post="$post" />
                @endforeach
            </div>
            <x-show-more :paginator="$posts" />
        @endif
    </div>
</x-layout>
