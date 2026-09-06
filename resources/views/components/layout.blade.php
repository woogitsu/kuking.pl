{{--
    Szkielet każdej strony Kuking.

    Rzeczy, które MUSZĄ tu zostać:
    - `data-text-scale` z konta użytkownika — ustawienie rozmiaru tekstu
      przetrwa zmianę przeglądarki (docs/UX_50_PLUS.md);
    - link „Przejdź do treści” dla klawiatury;
    - komunikaty w `aria-live`, żeby czytnik ekranu ogłosił „Szkic zapisany”;
    - podpis tekstowy pod każdą ikoną w nawigacji.
--}}
@props([
    'title' => null,
    'description' => null,
    'noindex' => false,
    'wide' => false,
    // Livewire dociągamy TYLKO na stronach, które go naprawdę używają
    // (dziś: kreator przepisu). Reszta serwisu działa bez tego skryptu
    // i nie ma powodu, żeby go pobierała — AGENTS.md → JavaScript jest
    // ulepszeniem, nie warunkiem.
    'livewire' => false,
    // Zdjęcie do karty w mediach społecznościowych (issue #14). Przekazujemy
    // model Media, a nie gotowy adres — komponent sam wybiera wariant i zna
    // wymiary, których Facebook i WhatsApp wymagają, żeby nie przycinać
    // obrazka na ślepo.
    'image' => null,
    'ogType' => 'website',
])

@php
    $user = auth()->user();
    $scale = $user?->text_scale ?? 100;
    $unread = $user?->unreadNotificationsCount() ?? 0;
    $pageTitle = $title ? $title.' — Kuking' : 'Kuking — pokaż, co dziś ugotowałeś';

    // ------------------------------------------------------------------
    //  KARTA DO WYSŁANIA RODZINIE (issue #14)
    //
    //  Link do przepisu wklejony w Messengera albo WhatsAppa pokazywał
    //  do tej pory sam tytuł, bez zdjęcia. W serwisie o gotowaniu to jest
    //  strata najważniejszej rzeczy: nikt nie klika w link do jedzenia,
    //  którego nie widać.
    //
    //  Adres MUSI być bezwzględny. Scrapery Facebooka i WhatsAppa nie mają
    //  kontekstu strony, więc `/storage/media/...` jest dla nich niczym —
    //  cicho pomijają taki obrazek i karta wraca do postaci bez zdjęcia.
    //  Dysk lokalny zwraca ścieżkę względną, R2 zwraca pełny adres, więc
    //  sprawdzamy, co dostaliśmy, zamiast zakładać.
    // ------------------------------------------------------------------
    $ogImage = $image?->url('large');

    if ($ogImage !== null && ! str_starts_with($ogImage, 'http')) {
        $ogImage = url($ogImage);
    }

    // Zapasowa karta dla stron bez zdjęcia. SVG tu NIE ZADZIAŁA: Facebook,
    // WhatsApp i Signal go nie renderują i pokazują pustą ramkę. Stąd PNG
    // w formacie 1200×630, czyli tym, którego wszyscy oczekują.
    $ogImage ??= asset('icons/kuking-udostepnianie.png');
@endphp

<!DOCTYPE html>
<html lang="pl" @if($scale !== 100) data-text-scale="{{ $scale }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>

    @if($description)
        <meta name="description" content="{{ $description }}">
    @endif

    @if($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif

    <meta property="og:site_name" content="Kuking">
    <meta property="og:title" content="{{ $pageTitle }}">
    @if($description)
        <meta property="og:description" content="{{ $description }}">
    @endif
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:locale" content="pl_PL">
    {{-- Adres kanoniczny bez parametrów zapytania: inaczej ten sam przepis
         wysłany z „?zakladka=..." liczy się jako osobna strona i zbiera
         własne polubienia zamiast dołożyć do wspólnej puli. --}}
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:alt" content="{{ $title ?? 'Kuking' }}">
    @if($image?->width('large') && $image?->height('large'))
        {{-- Wymiary podane wprost pozwalają pokazać kartę, ZANIM obrazek się
             pobierze. Bez nich Messenger rezerwuje miejsce dopiero po
             pobraniu i link przez chwilę wygląda na pusty. --}}
        <meta property="og:image:width" content="{{ $image->width('large') }}">
        <meta property="og:image:height" content="{{ $image->height('large') }}">
    @endif

    {{-- Duża karta tylko wtedy, gdy naprawdę jest zdjęcie. Przy zapasowym
         logo duży format to wielka plama koloru z małym znaczkiem. --}}
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">

    <link rel="canonical" href="{{ url()->current() }}">
    <meta name="theme-color" content="#B3401F">

    <link rel="icon" href="{{ asset('icons/kuking-mark.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/kuking-icon-192.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if($livewire)
        @livewireStyles
    @endif
    {{ $head ?? '' }}
</head>
<body>
    <a class="skip-link" href="#tresc">Przejdź do treści</a>

    <header class="topbar">
        <div class="topbar-inner">
            <a class="wordmark" href="{{ $user ? route('home') : route('landing') }}">
                {{-- Znak wklejony wprost, nie przez <img> — inaczej nie
                     dziedziczy koloru i w trybie ciemnym zostaje czarny. --}}
                <x-kuking-mark :rozmiar="36" />
                {{-- Logotyp rozbity na dwa elementy jest dla czytnika ekranu
                     dwoma osobnymi napisami. Podajemy mu jeden, całą nazwę. --}}
                <span aria-hidden="true">KuKing<span class="wordmark-tld">.pl</span></span>
                <span class="visually-hidden">Kuking — strona główna</span>
            </a>

            <div style="display:flex; gap:var(--spacing-2); align-items:center;">
                @auth
                    <a class="btn btn-quiet" href="{{ route('notifications.index') }}">
                        Powiadomienia
                        @if($unread > 0)
                            <span class="badge badge-cooked">{{ $unread }}</span>
                            <span class="visually-hidden">nieprzeczytanych</span>
                        @endif
                    </a>
                    <a class="btn btn-primary" href="{{ route('add') }}">Dodaj</a>
                @else
                    <a class="btn btn-quiet" href="{{ route('login') }}">Zaloguj się</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto</a>
                @endauth
            </div>
        </div>
    </header>

    <div class="app-shell">
        {{-- `app-body-solo` MUSI iść w parze z brakiem <nav class="side-nav">
             niżej: siatka na desktopie rezerwuje pierwszą kolumnę na
             nawigację, więc bez niej treść wpadłaby w kolumnę szeroką na
             15rem. Pilnuje tego test UkladGosciaTest. --}}
        <div class="app-body @guest app-body-solo @endguest">
            @auth
                <nav class="side-nav" aria-label="Nawigacja główna">
                    <ul class="stack-tight" style="list-style:none; padding:0; margin:0;">
                        <li><a class="side-nav-item" href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif><x-ikona nazwa="home" /> Start</a></li>
                        <li><a class="side-nav-item" href="{{ route('search') }}" @if(request()->routeIs('search')) aria-current="page" @endif><x-ikona nazwa="search" /> Szukaj</a></li>
                        <li><a class="side-nav-item" href="{{ route('add') }}" @if(request()->routeIs('add')) aria-current="page" @endif><x-ikona nazwa="plus" /> Dodaj</a></li>
                        <li><a class="side-nav-item" href="{{ route('collections.index') }}" @if(request()->routeIs('collections.*')) aria-current="page" @endif><x-ikona nazwa="book" /> Zeszyt</a></li>
                        <li><a class="side-nav-item" href="{{ route('profile.show', $user->profile->username) }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif><x-ikona nazwa="user" /> Mój profil</a></li>
                        <li><a class="side-nav-item" href="{{ route('discover') }}" @if(request()->routeIs('discover')) aria-current="page" @endif><x-ikona nazwa="chef" /> Świeżo z Kuking</a></li>
                        <li><a class="side-nav-item" href="{{ route('settings.accessibility') }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif><x-ikona nazwa="settings" /> Ustawienia</a></li>
                        @if($user->isModerator())
                            <li><a class="side-nav-item" href="{{ route('admin.reports') }}" @if(request()->routeIs('admin.reports')) aria-current="page" @endif><x-ikona nazwa="shield" /> Zgłoszenia</a></li>
                            {{-- Odwołania dostają ikonę „chat", a nie wagę szalkową: odwołanie
                                 to pismo od człowieka, a nie wyrok. Zestaw ikon nie ma szalek
                                 i nie dokładam ich tutaj — nowy kształt to zmiana w komponencie
                                 ikon, która należy do prac nad UI kitem. --}}
                            <li><a class="side-nav-item" href="{{ route('admin.appeals') }}" @if(request()->routeIs('admin.appeals')) aria-current="page" @endif><x-ikona nazwa="chat" /> Odwołania</a></li>
                            <li><a class="side-nav-item" href="{{ route('admin.daily-board') }}" @if(request()->routeIs('admin.daily-board')) aria-current="page" @endif><x-ikona nazwa="pin" /> Tablica na dziś</a></li>
                        @endif
                    </ul>
                </nav>
            @endauth

            <main class="app-main" id="tresc">
                {{-- Komunikaty zwrotne. aria-live, żeby czytnik ekranu je ogłosił. --}}
                <div aria-live="polite">
                    @if(session('status'))
                        <p class="flash">{{ session('status') }}</p>
                    @endif
                </div>

                {{--
                    Stan zawieszenia widoczny na KAŻDYM ekranie (issue #40).

                    Osoba zawieszona musi wiedzieć dwie rzeczy bez szukania:
                    że nie może publikować i DO KIEDY. Pokazywanie tego dopiero
                    przy nieudanej próbie publikacji znaczyłoby, że najpierw
                    pisze wpis, a dopiero potem dowiaduje się, że nie ma to sensu.

                    Data po polsku, nie ISO — docs/UX_50_PLUS.md.
                    Bez gry słowem „kuKING" — D-009 zabrania jej w wiadomościach
                    moderacyjnych.
                --}}
                @if(auth()->user()?->isSuspended())
                    <div class="notice" role="status">
                        @if(auth()->user()->status_expires_at)
                            <strong>Twoje konto jest zawieszone do
                                {{ auth()->user()->status_expires_at->translatedFormat('j F Y') }}.</strong>
                            <span>Do tego czasu możesz czytać, ale nie opublikujesz wpisu ani komentarza.
                                Konto wróci samo — nie musisz nic robić.</span>
                        @else
                            <strong>Twoje konto jest zawieszone.</strong>
                            <span>Do odwołania możesz czytać, ale nie opublikujesz wpisu ani komentarza.
                                Napisz do nas: {{ config('kuking.community.contact_email') }}</span>
                        @endif
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>

        <footer class="site-footer">
            <div class="site-footer-inner">
                <span>Kuking — gotujemy po swojemu.</span>
                <a href="{{ route('about') }}">O Kuking</a>
                <a href="{{ route('help') }}">Pomoc</a>
                <a href="{{ route('rules') }}">Zasady</a>
                <a href="{{ route('terms') }}">Regulamin</a>
                <a href="{{ route('privacy') }}">Prywatność</a>

                {{-- Wersja: etap produktu + skrót wdrożonego commita.
                     Widoczna zawsze, żeby dało się jednym spojrzeniem
                     sprawdzić, co dokładnie działa na tej stronie.

                     Bez `title` z pełnym skrótem: na telefonie nie ma najazdu
                     kursorem, a informacja dostępna tylko przez hover jest
                     dla części osób niedostępna w ogóle (UX_50_PLUS). --}}
                <span class="site-version">{{ \App\Support\Wersja::pelna() }}</span>
            </div>
        </footer>

        {{--
            Powiększone zdjęcie.

            Natywny `<dialog>`, nie własna nakładka z div-ów. Przeglądarka daje
            za darmo rzeczy, które robi się źle ręcznie: pułapkę focusu,
            zamykanie klawiszem Escape, poprawną rolę dla czytników ekranu
            i tło blokujące klikanie tego, co pod spodem.

            Element jest PUSTY do momentu otwarcia — zdjęcie wstawia skrypt.
            Dzięki temu strona nie pobiera dużych wariantów wszystkich zdjęć
            „na zapas", co przy feedzie z dwudziestoma pozycjami byłoby
            kilkunastoma megabajtami na łączu, którego nikt nie prosił.

            Bez JavaScriptu ten `<dialog>` nigdy się nie otwiera i nie
            przeszkadza — kliknięcie w zdjęcie po prostu otwiera duży wariant
            na osobnej stronie.
        --}}
        <dialog id="powiekszenie" class="lightbox" aria-label="Powiększone zdjęcie">
            <img class="lightbox-obraz" src="" alt="">

            {{-- Przycisk z NAPISEM, nie samym „×”. AGENTS.md: ikona nigdy sama. --}}
            <form method="dialog" class="lightbox-akcje">
                <button class="btn btn-secondary" type="submit">Zamknij</button>
            </form>
        </dialog>
    </div>

    @auth
        <nav class="bottom-nav" aria-label="Nawigacja główna">
            <a class="bottom-nav-item" href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>
                <x-ikona nazwa="home" class="bottom-nav-icon" :rozmiar="26" /> Start
            </a>
            <a class="bottom-nav-item" href="{{ route('search') }}" @if(request()->routeIs('search')) aria-current="page" @endif>
                <x-ikona nazwa="search" class="bottom-nav-icon" :rozmiar="26" /> Szukaj
            </a>
            <a class="bottom-nav-item" href="{{ route('add') }}" @if(request()->routeIs('add')) aria-current="page" @endif>
                <x-ikona nazwa="plus" class="bottom-nav-icon" :rozmiar="26" /> Dodaj
            </a>
            <a class="bottom-nav-item" href="{{ route('collections.index') }}" @if(request()->routeIs('collections.*')) aria-current="page" @endif>
                <x-ikona nazwa="book" class="bottom-nav-icon" :rozmiar="26" /> Zeszyt
            </a>
            <a class="bottom-nav-item" href="{{ route('profile.show', $user->profile->username) }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif>
                <x-ikona nazwa="user" class="bottom-nav-icon" :rozmiar="26" /> Profil
            </a>
        </nav>
    @endauth

    @if($livewire)
        @livewireScripts
    @endif
</body>
</html>
