@php $p = $profile; @endphp
<x-layout
    :title="$p->display_name.' (@'.$p->username.')'"
    :description="$p->bio ?: $p->display_name.' gotuje w Kuking.'"
    :noindex="$stats['posts'] === 0 && $stats['recipes'] === 0"
    {{-- Avatar, a nie zdjęcie potrawy: link do profilu ma pokazać CZŁOWIEKA.
         Bez avatara wchodzi karta zapasowa — lepsza niż cudza fotografia,
         która sugerowałaby, że to profil o tym daniu. --}}
    :image="$p->avatar"
    ogType="profile">

    <x-slot:head>
        @if($stats['posts'] > 0 || $stats['recipes'] > 0)
            @php
                $profileJsonLd = [
                '@context' => 'https://schema.org',
                '@type' => 'ProfilePage',
                'mainEntity' => [
                    '@type' => 'Person',
                    'name' => $p->display_name,
                    'alternateName' => '@'.$p->username,
                    'description' => $p->bio,
                    'url' => route('profile.show', $p->username),
                ],
            ];
            @endphp
            <x-json-ld :data="$profileJsonLd" />
        @endif
    </x-slot:head>

    {{--
        PRAWA SZYNA (issue #205). Treść i uzasadnienie: `szyna-profilu`.

        Slot stoi TUTAJ, na górze pliku, a mimo to w gotowym dokumencie
        `<aside class="app-rail">` renderuje się PO `<main>` — Blade wstawia
        zawartość slotu tam, gdzie ten slot stoi w LAYOUCIE. Kolejność `Tab`
        i czytnika ekranu się więc nie zmienia (ten sam mechanizm co przy
        spisie ustawień, #209), i dlatego nie ma tu żadnego CSS-owego `order`.
    --}}
    <x-slot:rail>
        <x-szyna-profilu :profile="$p" :isOwner="$isOwner" :zeszyty="$zeszytySzyny" :tagi="$tagiSzyny" />
    </x-slot:rail>

    <header class="card mb-6">
        {{--
            UKŁAD Z KITU (UI kit v2, ekran 04): awatar i kolumna z imieniem
            razem, LICZNIKI POD OPISEM — nie osobnym pełnoszerokim wierszem
            pod całym nagłówkiem, jak dawniej. `.profil-glowka-tresc`
            w ekran-profilu.css robi z tego siatkę (awatar | treść) i wraca
            do jednej kolumny poniżej `--breakpoint-md`, żeby przy 320 px
            awatar nie ściskał opisu do wąskiego paska tekstu.
        --}}
        <div class="profil-glowka-tresc">
            {{--
                WŁASNY AWATAR JEST ODNOŚNIKIEM DO USTAWIENIA ZDJĘCIA.

                To jest miejsce, w które człowiek klika instynktownie — a do
                tej zmiany nie robiło ono nic. Zdjęcie profilowe stało jako
                szóste pole w formularzu `/ustawienia/profil`, czyli za: menu
                → Ustawienia (a te otwierają się na „Czytelności") → Profil
                → przewinięciem pod pięcioma polami, których nikt nie
                zamierzał ruszać.

                PODPIS JEST WIDOCZNY, NIE TYLKO DLA CZYTNIKA EKRANU.
                Sam obrazek, który coś robi po kliknięciu, to „ikona bez
                opisu" (AGENTS.md §5) — a bez zdjęcia stoi tu w ogóle sama
                litera i nic nie mówi, że da się to zmienić. Zachęta jest
                więc treścią strony, a nie podpowiedzią po najechaniu myszą:
                hover na telefonie nie istnieje.

                „ZDJĘCIE PROFILOWE", A NIE „SWOJE ZDJĘCIE": wiersz niżej stoi
                przycisk „Dodaj zdjęcie", który prowadzi do DODANIA WPISU ze
                zdjęciem potrawy. Dwa podobnie brzmiące „dodaj zdjęcie" jeden
                pod drugim byłyby gorsze niż dłuższa nazwa.

                ROZMIAR 128 px, NIE 88: na profilu awatar jest zdjęciem
                CZŁOWIEKA, o którym jest cała strona, a nie znaczkiem przy
                cudzym wpisie — i miejsce na niego jest, bo kolumna awatara
                i tak musi pomieścić przycisk pod nim. Prośba właściciela
                brzmiała dosłownie: „zdjęcie profilowe może brać więcej
                miejsca, bo jest na to miejsce".
            --}}
            @if($isOwner)
                <a class="profil-awatar-zmiana" href="{{ route('settings.avatar') }}">
                    <x-avatar :user="$owner" :size="128" />
                    {{--
                        PODPIS WYGLĄDA JAK AKCJA, BO JEST AKCJĄ.

                        Do tej zmiany był zwykłym podkreślonym tekstem tuż
                        pod obrazkiem — czyli dokładnie tam, gdzie stoi
                        PODPIS ZDJĘCIA, i tak też się czytał („to nazwa
                        tego, co widzę"), mimo że na telefonie jest jedyną
                        drogą do zmiany zdjęcia (prawa szyna z tym samym
                        skrótem chowa się poniżej 64rem).

                        `btn btn-secondary`, a nie nowa klasa z własnym
                        obrysem: przycisk w tym serwisie ma już policzony
                        kontrast, 48 px wysokości, `max-width: 100%`
                        i `overflow-wrap: anywhere` — czyli obronę przed
                        wypchnięciem strony w bok przy czcionce przeglądarki
                        200%. Druga taka klasa byłaby drugim miejscem, w
                        którym trzeba by o tym wszystkim pamiętać.

                        NADAL JEDEN ODNOŚNIK, nie dwa: `<span>` w środku
                        `<a>`, a nie osobne łącze obok awatara. Dwa
                        odnośniki do tego samego miejsca czytnik ekranu
                        czyta dwa razy (D-054).
                    --}}
                    <span class="btn btn-secondary profil-awatar-zmiana-akcja">
                        {{ $p->avatar?->isReady() ? 'Zmień zdjęcie profilowe' : 'Dodaj zdjęcie profilowe' }}
                    </span>
                </a>
            @else
                <x-avatar :user="$owner" :size="128" />
            @endif
            <div class="min-w-0">
                <h1 class="m-0 mb-1">{{ $p->display_name }}</h1>
                @if($owner->isSeeded())
                    {{-- `waga="glosna"`: profil jest JEDYNYM miejscem, gdzie
                         ta plakietka wolno stoi głośno — wszędzie indziej
                         (karta wpisu, karta przepisu, komentarz, strona
                         przepisu) jest cicha, patrz `x-konto-przykladowe`. --}}
                    <p class="mb-3"><x-konto-przykladowe :user="$owner" waga="glosna" /></p>
                    {{--
                        D-025 (odwrócona co do wagi i treści plakietki, nie
                        co do tego akapitu): oznaczenie MUSI stać przy
                        koncie, nie tylko w regulaminie. Skrócenie plakietki
                        do „konto przykładowe" (D-032) przenosi pełne zdanie
                        TUTAJ — profil jest jedynym miejscem w całym serwisie,
                        gdzie ono stoi wprost, więc skrócenie gdzie indziej
                        nie kasuje informacji, tylko ją przenosi.
                    --}}
                    <p class="notice">
                        To konto jest przykładowe: nie ma za nim prawdziwej osoby.
                        Treści dodała redakcja Kuking, żeby na początek było tu
                        co poczytać.
                    </p>
                @endif
                <p class="meta m-0 mb-3">
                    &#64;{{ $p->username }}
                    @if($p->region) · {{ $p->region }} @endif
                </p>
                @if($p->speciality)
                    <p class="m-0 mb-3"><span class="badge badge-cooked">Zna się na: {{ $p->speciality }}</span></p>
                @endif
                @if($p->bio)
                    <p class="whitespace-pre-line mb-4">{{ $p->bio }}</p>
                @endif

                {{--
                    LICZNIKI: PIĘĆ RÓWNYCH WIERSZY, NIE RZĄD ZAWIJANY DO
                    KOŃCA WIERSZA.

                    Do tej zmiany było tu pięć pozycji w kontenerze
                    `flex-wrap`, więc szerokość każdej brała się z długości
                    podpisu, a wiersze wychodziły „3 + 2" albo „2 + 3"
                    zależnie od okna i skali tekstu. Właściciel nazwał to
                    wprost: nieczytelne i niesymetryczne.

                    Teraz każdy licznik to jeden wiersz „liczba + odmieniony
                    podpis" („2 wpisy"), wszystkie zaczynają się w tej samej
                    linii i mają tę samą wysokość. Dlaczego JEDNA kolumna,
                    a nie dwie — z pomiarem szerokości tej karty: komentarz
                    przy `.profil-liczniki` w `ekran-profilu.css`.

                    Kolejność w HTML zostaje ta sama co dawniej, bo to ona
                    decyduje o kolejności czytania i `Tab`.

                    §12: to są liczby o WŁASNEJ treści tej osoby, bez
                    porównania z kimkolwiek. Nie ma tu miejsca w tabeli,
                    nie ma „więcej niż 80% kuKINGów" i nie będzie.
                --}}
                <ul class="profil-liczniki" aria-label="Liczby tego profilu">
                    <x-licznik-profilu rodzaj="wpisy" :ile="$stats['posts']" />
                    <x-licznik-profilu rodzaj="przepisy" :ile="$stats['recipes']" />
                    <x-licznik-profilu rodzaj="ugotowania" :ile="$stats['cooked']" />
                    <x-licznik-profilu
                        rodzaj="obserwujacy"
                        :ile="$stats['followers']"
                        :href="route('social.followers', $p->username)" />
                    <x-licznik-profilu
                        rodzaj="obserwowani"
                        :ile="$stats['following']"
                        :href="route('social.following', $p->username)" />
                </ul>
            </div>
        </div>

        <div class="flex gap-3 flex-wrap mt-5">
            @if($isOwner)
                <a class="btn btn-secondary" href="{{ route('settings.profile') }}">Zmień swój profil</a>
                <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj zdjęcie</a>
                {{-- WYLOGOWANIE NA TELEFONIE MA TYLKO TĘ DROGĘ.
                     `.side-nav` ma `display: none` poniżej 64rem, a pasek
                     dolny nie ma pozycji „Ustawienia" — własny profil jest
                     więc jedynym ekranem z obsługą konta w zasięgu kciuka.
                     Ten sam składnik co w nawigacji bocznej. --}}
                <x-wyloguj />
            {{--
                KONTO WYMAZANE (`erased`, D-022) NIE PRZYJMUJE ŻADNEJ AKCJI.

                Profil takiego konta jest dostępny celowo — to adres, pod
                który prowadzi podpis „Użytkownik usunięty" pod każdą
                zanonimizowaną treścią. Ale „Obserwuj", „Zgłoś" i „Zablokuj"
                nie mają tu żadnego sensu: `UserPolicy::follow()` wymaga konta
                aktywnego, a zgłaszać i blokować nie ma już kogo. Przycisk,
                który zawsze kończy się 403 albo niczym, jest gorszy niż jego
                brak — to ta sama klasa błędu co karta osoby z linkiem do 403
                (audyt W5-08).

                Ta gałąź łapie też konto `banned`/`pending_delete` OGLĄDANE
                PRZEZ MODERATORA (jedyny, kogo `viewProfile` tam wpuszcza) —
                dlatego komunikat rozróżnia te dwa przypadki. Powiedzenie
                moderatorowi „to konto zostało usunięte" przy koncie
                zablokowanym byłoby nieprawdą.
            --}}
            @elseif(auth()->check() && ! $owner->jestWidocznyJakoOsoba())
                @if($owner->isErased())
                    <p class="mb-0">To konto zostało usunięte. Nie da się go już obserwować ani zgłosić.</p>
                @else
                    <p class="mb-0">To konto jest zablokowane albo zgłoszone do usunięcia. Widzisz je, bo jesteś moderatorem.</p>
                @endif
            @elseif(auth()->check())
                @if($isFollowing)
                    <form method="POST" action="{{ route('social.unfollow', $p->username) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-secondary" type="submit">Przestań obserwować</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('social.follow', $p->username) }}">
                        @csrf
                        <button class="btn btn-primary" type="submit">Obserwuj</button>
                    </form>
                @endif
                <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'user', 'id' => $p->username]) }}">Zgłoś</a>
                @if($hasBlocked)
                    <form method="POST" action="{{ route('social.unblock', $p->username) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-quiet" type="submit">Zdejmij blokadę</button>
                    </form>
                @else
                    <x-confirm-button
                        :action="route('social.block', $p->username)"
                        method="POST"
                        label="Zablokuj"
                        :question="'Zablokować '.$p->display_name.'? Nie zobaczycie już wzajemnie swoich treści.'" />
                @endif
            @else
                <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto, żeby obserwować</a>
            @endif
        </div>
    </header>

    <nav class="tabs" aria-label="Zakładki profilu">
        <a class="tab" href="{{ route('profile.show', $p->username) }}" @if($tab === 'wszystko') aria-current="page" @endif>Wszystko</a>
        <a class="tab" href="{{ route('profile.show', ['username' => $p->username, 'zakladka' => 'przepisy']) }}" @if($tab === 'przepisy') aria-current="page" @endif>Przepisy</a>
        <a class="tab" href="{{ route('profile.show', ['username' => $p->username, 'zakladka' => 'ugotowane']) }}" @if($tab === 'ugotowane') aria-current="page" @endif>Ugotowane</a>
    </nav>

    @if($tab === 'wszystko')
        @if($posts->count() === 0)
            <x-empty-state :title="$isOwner ? 'Twoje archiwum jest jeszcze puste' : 'Ta osoba jeszcze nic nie pokazała'"
                           :action="$isOwner ? 'Dodaj pierwsze zdjęcie' : null"
                           :href="$isOwner ? route('posts.create') : null">
                @if($isOwner)
                    Od pierwszego zdjęcia zaczyna się Twoje archiwum. Za rok zobaczysz tu, co gotujesz dzisiaj.
                @endif
            </x-empty-state>
        @else
            @if(($lata ?? collect())->count() > 1)
                {{--
                    NAWIGACJA PO LATACH (issue #34).

                    Archiwum ma działać jak stary fotoblog, a fotoblog ma lata
                    w bocznej kolumnie. Bez tego jedyną drogą do września sprzed
                    trzech lat jest klikanie „starsze" dwadzieścia razy — czyli
                    droga, której nikt nie przejdzie.

                    Pokazujemy dopiero od DWÓCH lat: jeden rok to nie wybór,
                    tylko rząd przycisków udający wybór.

                    Zwykłe odnośniki, bez skryptu.
                --}}
                <nav class="lata-archiwum" aria-label="Lata w archiwum">
                    <a class="tab" href="{{ route('profile.show', $p->username) }}"
                       @if(! ($rok ?? null)) aria-current="page" @endif>Wszystko</a>

                    @foreach($lata as $rokZListy)
                        <a class="tab"
                           href="{{ route('profile.show', ['username' => $p->username, 'rok' => $rokZListy]) }}"
                           @if(($rok ?? null) === $rokZListy) aria-current="page" @endif>{{ $rokZListy }}</a>
                    @endforeach
                </nav>
            @endif

            {{-- Archiwum pogrupowane po miesiącach — jak stary fotoblog. --}}
            @php $currentMonth = null; @endphp
            <div class="stack">
                @foreach($posts as $post)
                    @php $month = \App\Support\Czas::dataLubNic($post->published_at, 'F Y'); @endphp
                    @if($month !== $currentMonth)
                        @php $currentMonth = $month; @endphp
                        <h2 class="mt-8">{{ \Illuminate\Support\Str::ucfirst($month) }}</h2>
                    @endif
                    <x-post-card :post="$post" />
                @endforeach
            </div>
            <x-show-more :paginator="$posts" />
        @endif
    @elseif($tab === 'przepisy')
        @if($recipes->count() === 0)
            <x-empty-state :title="$isOwner ? 'Nie masz jeszcze przepisów' : 'Brak przepisów'"
                           :action="$isOwner ? 'Dodaj przepis' : null"
                           :href="$isOwner ? route('recipes.create') : null" />
        @else
            <div class="stack">
                @foreach($recipes as $recipe)
                    <x-recipe-card :recipe="$recipe" />
                @endforeach
            </div>
            <x-show-more :paginator="$recipes" czego="przepisów" />
        @endif
    @else
        @if($cookedEvents->count() === 0)
            <x-empty-state :title="$isOwner ? 'Nie masz jeszcze żadnego wykonania' : 'Brak wykonań'">
                @if($isOwner)
                    Kiedy ugotujesz z czyjegoś przepisu, kliknij „Ugotowałem”. Autor się o tym dowie, a Ty będziesz mieć to zapisane.
                @endif
            </x-empty-state>
        @else
            <div class="stack">
                @foreach($cookedEvents as $event)
                    <x-cooked-card :event="$event" :showRecipe="true" />
                @endforeach
            </div>
            <x-show-more :paginator="$cookedEvents" czego="wykonań" />
        @endif
    @endif
</x-layout>
