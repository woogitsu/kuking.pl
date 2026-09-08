<x-layout title="Powiadomienia" :noindex="true">
    <h1>Powiadomienia</h1>

    @if($notifications->total() > 0)
        <form class="mb-5" method="POST" action="{{ route('notifications.read') }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Oznacz wszystkie jako przeczytane</button>
        </form>
    @endif

    {{--
        LISTA, nie luźny ciąg `<article>`. System projektowy v3.1 §13 daje
        powiadomieniom `<ul class="lista-wierszy">` z uzasadnieniem „jest ul,
        więc czytnik poda liczbę pozycji" — i to jest tu jedyny sposób, żeby
        człowiek na czytniku ekranu usłyszał „lista, 30 pozycji" zamiast
        trzydziestu niepowiązanych bloków.

        Bierzemy z tego SEMANTYKĘ, nie kształt: `.lista-naga` (klasa aplikacji)
        zdejmuje kropki i wcięcie, a karty zostają kartami. Kształt wiersza
        (`.lista-wierszy` + `.wiersz`) to osobna decyzja — patrz komentarz przy
        `<article class="card">` niżej.
    --}}
    {{-- `count()` to liczba pozycji NA TEJ STRONIE — dokładnie ten sam warunek,
         który miał wcześniej `@forelse`. `total()` z przycisku wyżej liczy
         wszystkie i na ostatniej stronie dałby pustą listę w ramce. --}}
    @if($notifications->count() > 0)
    <ul class="lista-naga">
        @foreach($notifications as $notification)
        @php
            $actor = $notification->actor;
            $data = $notification->data ?? [];

            /*
             * UZASADNIENIE DECYZJI MODERACYJNEJ (DSA art. 17 ust. 3).
             *
             * Zdania powstają z WIERSZA DECYZJI, nie z zamrożonego tekstu
             * w `data`: termin na odwołanie liczy
             * `ModerationAction::appealDeadline()`, a zamrożona data
             * pokazywałaby po zmianie konfiguracji termin KRÓTSZY niż
             * prawdziwy — czyli odstraszałaby od odwołania, do którego
             * człowiek ma jeszcze prawo.
             *
             * Powiadomienia sprzed tej zmiany nie mają `action_id` i dla nich
             * lista jest pusta — zostaje wtedy dawne, krótsze zdanie niżej.
             * Wiersze decyzji wczytuje `NotificationController` jednym
             * zapytaniem na stronę, żeby nie było N+1.
             */
            $decyzjaModeracyjna = $notification->type === \App\Models\Notification::TYPE_MODERATION
                ? ($decyzjeModeracyjne[$data['action_id'] ?? ''] ?? null)
                : null;
            $uzasadnienie = $decyzjaModeracyjna === null
                ? []
                : \App\Domain\Moderation\UzasadnienieDecyzji::zdania($decyzjaModeracyjna);
        @endphp
        {{--
            KSZTAŁT KARTY ZOSTAJE — wygrywa aplikacja, wbrew §13 systemu.

            System chce tu `.wiersz` w `.lista-wierszy`, bo „to krótkie,
            jednorodne pozycje, a karta w tym miejscu udaje treść, której nie
            ma". U nas ta przesłanka jest nieprawdziwa: powiadomienie od
            moderacji niesie uzasadnienie decyzji z DSA art. 17 (kilka
            akapitów) i do dwóch przycisków — „Zobacz" oraz „Odwołanie od tej
            decyzji". Ten sam system zabrania wiersza z dwiema akcjami i chce,
            żeby cały wiersz był jednym linkiem; to jest zmiana ZACHOWANIA,
            nie wyglądu, więc nie rozstrzygam jej sam (pytanie do właściciela
            w raporcie).

            Zdjęte: martwa klasa `style-unread`. Nie ma dla niej reguły
            w żadnym arkuszu ani w żadnym teście od pierwszego commita.
        --}}
        <li><article class="card mb-3 @if($notification->isUnread()) notification-nieprzeczytane @endif">
            <div class="flex gap-3 items-start">
                @if($actor)
                    <x-avatar :user="$actor" :size="44" />
                @endif
                <div class="min-w-0">
                    <p class="m-0 mb-1">
                        {{--
                            NIEPRZECZYTANE MA NIEŚĆ SŁOWO, nie tylko kreskę
                            z boku (WCAG 1.4.1; §13 systemu mówi to wprost).
                            Do tej zmiany osoba na czytniku ekranu nie miała
                            skąd wiedzieć, że coś jest nowe — kreska jest
                            wyłącznie w CSS.

                            Plakietka stoi PRZED zdaniem, nie po nim jak
                            w systemie: tam wiersz ma jedną linijkę, u nas
                            list od moderacji ma kilkanaście i znacznik
                            wylądowałby daleko pod kreską, którą tłumaczy.
                            Czytnik ekranu też czyta wtedy „Nowe" na wejściu,
                            a nie na końcu akapitu.
                        --}}
                        @if($notification->isUnread())
                            <span class="badge">Nowe</span>
                        @endif
                        @switch($notification->type)
                            @case(\App\Models\Notification::TYPE_COOKED)
                                <strong>{{ $actor?->displayName() }} ugotowała/ugotował z Twojego przepisu</strong>
                                „{{ $data['recipe_title'] ?? 'przepis' }}”.
                                @if($data['has_photo'] ?? false) Jest zdjęcie. @endif
                                @break
                            @case(\App\Models\Notification::TYPE_COMMENT)
                                <strong>{{ $actor?->displayName() }} napisała/napisał komentarz.</strong>
                                @if(isset($data['excerpt'])) „{{ $data['excerpt'] }}” @endif
                                @break
                            @case(\App\Models\Notification::TYPE_REPLY)
                                <strong>{{ $actor?->displayName() }} odpowiedziała/odpowiedział.</strong>
                                @if(isset($data['excerpt'])) „{{ $data['excerpt'] }}” @endif
                                @break
                            @case(\App\Models\Notification::TYPE_FOLLOW)
                                <strong>{{ $actor?->displayName() }} zaczęła/zaczął Cię obserwować.</strong>
                                @break
                            @case(\App\Models\Notification::TYPE_SAVED)
                                <strong>{{ $actor?->displayName() }} zapisała/zapisał Twój przepis</strong>
                                „{{ $data['recipe_title'] ?? '' }}” do swojego zeszytu.
                                @break
                            @case(\App\Models\Notification::TYPE_FIRST_POST)
                                {{-- Powiadomienie dla GOSPODARZA, nie dla autora
                                     (issue #6). Pierwszy wpis to jedyna okazja,
                                     żeby ktoś poczuł, że jest tu ktoś po drugiej
                                     stronie — i mamy na to dobę. --}}
                                <strong>{{ $data['display_name'] ?? 'Ktoś' }} opublikowała pierwszy wpis.</strong>
                                Odpowiedz jak najszybciej — pierwszy wpis bez reakcji zwykle bywa ostatnim.
                                @break
                            @case(\App\Models\Notification::TYPE_WELCOME)
                                <strong>Witamy w Kuking, {{ $data['display_name'] ?? '' }}.</strong>
                                Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.
                                @break
                            @case(\App\Models\Notification::TYPE_MODERATION)
                                {{-- Nagłówek mówi, CO SIĘ STAŁO, a pod nim idzie treść
                                     napisana przez moderatora. Starsze powiadomienia
                                     (usunięcie komentarza przez autora treści) nie mają
                                     `title` — dla nich zostaje dawny nagłówek. --}}
                                <strong>{{ $data['title'] ?? 'Wiadomość od moderacji Kuking.' }}</strong>
                                {{ $data['message'] ?? '' }}
                                {{-- Prawo do odwołania (DSA art. 17) musi być NAPISANE,
                                     nie domyślne. Adres bierzemy z konfiguracji, żeby
                                     jego zmiana nie zostawiła starych powiadomień
                                     z martwym kontaktem. --}}
                                @if(($data['appeal'] ?? false) && $uzasadnienie === [])
                                    <br>
                                    @if($data['action_id'] ?? null)
                                        {{-- Odwołanie składa się w serwisie, nie mailem
                                             (issue #10). Przycisk jest niżej — tu zostaje
                                             samo zdanie, żeby człowiek wiedział, czego
                                             dotyczy. --}}
                                        <span>Jeśli uważasz, że to pomyłka, możesz się odwołać.
                                            Sprawdzimy decyzję jeszcze raz.</span>
                                    @else
                                        {{-- Powiadomienia sprzed issue #10 nie wiedzą,
                                             której decyzji dotyczą — dla nich zostaje
                                             adres e-mail. Adres bierzemy z konfiguracji,
                                             żeby jego zmiana nie zostawiła starych
                                             powiadomień z martwym kontaktem. --}}
                                        <span>Jeśli uważasz, że to pomyłka, możesz się odwołać:
                                            napisz na {{ config('kuking.community.contact_email') }}.
                                            Sprawdzimy decyzję jeszcze raz.</span>
                                    @endif
                                @endif
                                @break
                            @default
                                {{ $notification->type }}
                        @endswitch
                    </p>
                    {{--
                        Uzasadnienie w OSOBNYCH akapitach, nie jednym blokiem
                        rozdzielonym `<br>`. Sześć zdań prawnych zbitych
                        w jeden akapit jest nie do przeczytania, a to jest
                        dokładnie ten list, z którego 60-latek ma zrozumieć,
                        co zrobił nie tak i co może zrobić dalej
                        (`docs/UX_50_PLUS.md`).
                    --}}
                    @foreach($uzasadnienie as $zdanie)
                        <p class="m-0 mb-2">{{ $zdanie }}</p>
                    @endforeach

                    <p class="meta m-0">
                        <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ \App\Support\Czas::lokalnie($notification->created_at)->diffForHumans() }}</time>
                    </p>

                    @php
                        // Adres liczy model (`Notification::adresDocelowy()`),
                        // a nie ten widok. Ten sam `match` potrzebny jest
                        // w kontrolerze, który po oznaczeniu przeczytania
                        // musi odesłać w to samo miejsce — dwie kopie
                        // rozjechałyby się przy pierwszym nowym typie.
                        $link = $notification->adresDocelowy();
                    @endphp
                    @if($link)
                        {{--
                            FORMULARZ, NIE ODNOŚNIK — i to jest cała poprawka
                            do zgłoszenia „klikam Zobacz, a powiadomienie
                            dalej jest nieprzeczytane".

                            Kliknięcie zapisuje `read_at` dla tego jednego
                            powiadomienia i dopiero potem odsyła do treści.
                            Odnośnik `<a>` nie mógł tego zrobić, bo GET nie
                            ma prawa zmieniać stanu. Formularz działa też bez
                            JavaScriptu, więc nic nie tracimy.
                        --}}
                        <form class="mt-3 mx-0 mb-0" method="POST" action="{{ route('notifications.open', $notification) }}">
                            @csrf
                            <button class="btn btn-secondary" type="submit">Zobacz</button>
                        </form>
                    @endif

                    {{--
                        Droga do odwołania (issue #10, DSA art. 17 i 20).

                        Przycisk prowadzi do sprawy, nie do samego formularza:
                        ta sama strona pokazuje formularz, złożone już odwołanie
                        albo minięty termin. Dzięki temu nigdy nie prowadzi do
                        ściany 403 — a napis mówi, co się za nim kryje, bo ikona
                        nigdy nie jest jedynym opisem akcji (UX 50+).
                    --}}
                    @if(($data['appeal'] ?? false) && ($data['action_id'] ?? null))
                        <p class="mt-3 mx-0 mb-0">
                            <a class="btn btn-secondary" href="{{ route('appeals.show', $data['action_id']) }}">
                                Odwołanie od tej decyzji
                            </a>
                        </p>
                    @endif
                </div>
            </div>
        </article></li>
        @endforeach
    </ul>
    @else
        <x-empty-state title="Nie ma jeszcze żadnych powiadomień">
            Tu pojawi się informacja, kiedy ktoś ugotuje z Twojego przepisu albo napisze komentarz.
        </x-empty-state>
    @endif

    <x-show-more :paginator="$notifications" czego="powiadomień" />
</x-layout>
