<x-layout title="Powiadomienia" :noindex="true">
    <h1>Powiadomienia</h1>

    {{--
        Ten sam przycisk stoi TU i jeszcze raz pod listą (issue #276).

        Przy trzech powiadomieniach o różnej długości — jedno z nich
        z kilkoma akapitami uzasadnienia decyzji moderacyjnej — przycisk
        u góry wychodzi z ekranu, zanim człowiek skończy czytać. Właściciel
        nie zarejestrował, że przycisk w ogóle tam jest. Powielenie go pod
        listą jest tańsze i pewniejsze niż `position: sticky` na pasku:
        żadna wysokość paska nie zostawia go bez akcji na końcu, a dla
        grupy 50+ nic tu nie może zależeć od zachowania przy przewijaniu.
    --}}
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

        `.lista-naga` zachowuje tę semantykę. D-212 przenosi kompozycję
        zwykłych zdarzeń z prototypu: awatar, treść i akcja obok siebie,
        gdy jest na to miejsce. Pełne decyzje zachowują własny układ.
    --}}
    {{-- `count()` to liczba pozycji NA TEJ STRONIE — dokładnie ten sam warunek,
         który miał wcześniej `@forelse`. `total()` z przycisku wyżej liczy
         wszystkie i na ostatniej stronie dałby pustą listę w ramce. --}}
    @if($notifications->count() > 0)
    <ul class="lista-naga marka-powiadomienia">
        @foreach($notifications as $notification)
        @php
            // Partia zapisów (D-070) pokazuje pierwszą WIDOCZNĄ osobę,
            // a nie `actor_id` — ten mógł zostać zablokowany po zapisie.
            $actor = $notification->type === \App\Models\Notification::TYPE_SAVED
                ? $notification->zapisujacyDoPokazania()
                : $notification->actor;
            $data = $notification->data ?? [];
            /*
             * ISSUE #758 / D-229 — WYCINEK KOMENTARZA JEST ŻYWY.
             *
             * Bierzemy go z mapy policzonej JEDNYM zapytaniem na całą stronę
             * (`Notification::zyweWycinkiKomentarzy()`), a nie z `data.excerpt`.
             * Zamrożona kopia z chwili publikacji cytowała treść sprzed
             * poprawki autora; stare wiersze dalej ją mają w bazie i właśnie
             * dlatego NIE MA tu planu zapasowego „weź `data.excerpt`, gdy mapa
             * milczy". Brak wpisu w mapie znaczy „komentarza nie ma, jest
             * usunięty albo ukryty" — czyli dokładnie ten przypadek, w którym
             * treści pokazać nie wolno (bramka z #757).
             */
            $wycinekKomentarza = $wycinkiKomentarzy[$notification->getKey()] ?? null;
            $zwykleZdarzenie = in_array($notification->type, [
                \App\Models\Notification::TYPE_COOKED,
                \App\Models\Notification::TYPE_COMMENT,
                \App\Models\Notification::TYPE_REPLY,
                \App\Models\Notification::TYPE_FOLLOW,
                \App\Models\Notification::TYPE_SAVED,
            ], true);

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
            D-212: tylko pięć nazwanych zwykłych zdarzeń dostaje akcję obok
            treści. Decyzje moderacyjne, zgłoszenia i pozostałe typy zachowują
            pełne akapity oraz wszystkie działania. Nowy typ nie dziedziczy
            wąskiego układu automatycznie. Karta nie staje się linkiem:
            odczyt nadal zapisuje prawdziwy formularz POST.
        --}}
        <li><article @class(['card mb-3', 'notification-nieprzeczytane' => $notification->isUnread(), 'marka-powiadomienie-zwykle' => $zwykleZdarzenie])>
            <div class="flex gap-3 items-start powiadomienie-wiersz">
                @if($actor)
                    <x-avatar :user="$actor" :size="$zwykleZdarzenie ? 48 : 44" />
                @endif
                <div class="min-w-0 powiadomienie-tresc">
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
                        {{--
                            ZDANIA BEZ ZAŁOŻENIA RODZAJU (issue #38).

                            Stało tu sześć zdań w postaci „ugotowała/ugotował",
                            „napisała/napisał", „zaczęła/zaczął". Ukośnik
                            oblewa pierwszy test z `docs/brand/COPY_STYLE.md`
                            §1 — tego nie da się przeczytać na głos — a §2
                            rozstrzyga to wprost: „Zamiast szukać żeńskiej
                            formy, zmieniamy konstrukcję zdania".

                            Polski czas przeszły zawsze niesie rodzaj, więc
                            zmiana idzie w dwie strony: albo imiesłów bierny
                            („ugotowane z Twojego przepisu"), albo czas
                            teraźniejszy („zaczyna Cię obserwować", „ma Twój
                            przepis w swoim zeszycie"). Obie formy są
                            bezrodzajowe i obie są krótsze od tego, co było.

                            Skutek uboczny jest wymierny: `resources/css/tokens.css`
                            i lista kontrolna dostępności systemu projektowego
                            wskazują „ugotowała/ugotował" jako NAJDŁUŻSZE słowo
                            w serwisie — to ono przy 320 px i skali tekstu 150%
                            wymuszało łamanie wyrazu w środku.

                            Bez gry słowem „kuKING": to jest powiadomienie
                            o cudzej aktywności, a §2 zabrania jej tutaj wprost.
                        --}}
                        @switch($notification->type)
                            @case(\App\Models\Notification::TYPE_COOKED)
                                <strong>{{ $actor?->displayName() ?? 'Ktoś' }} — ugotowane z Twojego przepisu</strong>
                                „{{ $data['recipe_title'] ?? 'przepis' }}”.
                                {{-- ISSUE #771: wykonanie usunięte po powiadomieniu. Bez
                                     obietnicy zdjęcia i bez „Zobacz" prowadzącego na 404. --}}
                                @if($notification->wykonanieUsuniete())
                                    To ugotowanie zostało usunięte.
                                @elseif($data['has_photo'] ?? false)
                                    Jest zdjęcie.
                                @endif
                                @break
                            @case(\App\Models\Notification::TYPE_COMMENT)
                                <strong>{{ $actor?->displayName() ?? 'Ktoś' }} — {{ ($data['question_answer'] ?? false) ? 'odpowiedź na Twoje pytanie.' : 'nowy komentarz.' }}</strong>
                                @if($questionTitle = ($questionTitles[$data['comment_id'] ?? ''] ?? null))
                                    <span class="block">Pytanie: „{{ $questionTitle }}”</span>
                                @endif
                                @if($wycinekKomentarza !== null) „{{ $wycinekKomentarza }}” @endif
                                @break
                            @case(\App\Models\Notification::TYPE_REPLY)
                                <strong>{{ $actor?->displayName() ?? 'Ktoś' }} — nowa odpowiedź.</strong>
                                @if($questionTitle = ($questionTitles[$data['comment_id'] ?? ''] ?? null))
                                    <span class="block">Pytanie: „{{ $questionTitle }}”</span>
                                @endif
                                @if($wycinekKomentarza !== null) „{{ $wycinekKomentarza }}” @endif
                                @break
                            @case(\App\Models\Notification::TYPE_FOLLOW)
                                <strong>{{ $actor?->displayName() ?? 'Ktoś' }} zaczyna Cię obserwować.</strong>
                                @break
                            @case(\App\Models\Notification::TYPE_SAVED)
                                {{--
                                    ZBIORCZE POWIADOMIENIE (issue #906, decyzja
                                    właściciela z 20.09.2026). Nagłówek —
                                    łącznie z polską odmianą liczebnika i
                                    ukrywaniem zablokowanych/zbanowanych osób
                                    z partii — stoi w JEDNYM miejscu,
                                    `Notification::naglowekZapisu()`, żeby
                                    widok i testy nie trzymały dwóch kopii tej
                                    samej odmiany, które prędzej czy później
                                    się rozjadą. Tytuł przepisu zostaje POZA
                                    `<strong>`, tak jak przy pozostałych typach
                                    wyżej (cytat/szczegół pod pogrubionym
                                    podmiotem zdania).
                                --}}
                                <strong>{{ $notification->naglowekZapisu() }}</strong>
                                {{ $notification->resztaZapisu() }}
                                @break
                            @case(\App\Models\Notification::TYPE_FIRST_POST)
                                {{-- Powiadomienie dla GOSPODARZA, nie dla autora
                                     (issue #6). Zachęca do odpowiedzi nowej osobie,
                                     bez obietnicy terminu ani tezy o retencji. --}}
                                <strong>{{ $data['display_name'] ?? 'Ktoś' }} — pierwszy wpis w Kuking.</strong>
                                To pierwszy wpis tej osoby. Warto odpowiedzieć szybko.
                                @break
                            @case(\App\Models\Notification::TYPE_APPEAL_FILED)
                                {{-- Zawiadomienie dla ADMINISTRATORA: ktoś złożył
                                     odwołanie i ma termin na odpowiedź (DSA art. 20).
                                     Termin stoi w treści, bo to jedyna rzecz, która
                                     odróżnia tę pozycję od „zajrzę tam kiedyś".
                                     Powiadomienie idzie tylko do tych, którzy mogą
                                     sprawę zamknąć — D-039, `PowiadomOOdwolaniu`. --}}
                                <strong>{{ ($data['od_zglaszajacego'] ?? false) ? 'Zgłaszający odwołał się od decyzji.' : 'Ktoś odwołał się od decyzji moderacji.' }}</strong>
                                Odwołanie od {{ $data['skladajacy'] ?? 'nieznanej osoby' }}.
                                @if($data['termin'] ?? null)
                                    Odpowiedz do {{ $data['termin'] }}.
                                @endif
                                @break
                            @case(\App\Models\Notification::TYPE_WELCOME)
                                <strong>Witamy w Kuking, {{ $data['display_name'] ?? '' }}.</strong>
                                Zacznij od zdjęcia tego, co dziś ugotowałeś.
                                @break
                            @case(\App\Models\Notification::TYPE_REPORT_RECEIVED)
                                {{-- POTWIERDZENIE PRZYJĘCIA ZGŁOSZENIA
                                     (DSA art. 16 ust. 4, issue #10). Numer
                                     sprawy jest tu, a nie tylko na karcie
                                     sprawy: to jest to, co człowiek poda,
                                     pisząc do nas. --}}
                                <strong>Mamy Twoje zgłoszenie.</strong>
                                Numer sprawy {{ $data['numer_sprawy'] ?? '' }}.
                                Sprawdzimy je i napiszemy, co postanowiliśmy.
                                @break
                            @case(\App\Models\Notification::TYPE_REPORT_DECIDED)
                                {{-- ROZSTRZYGNIĘCIE ZGŁOSZENIA (DSA art. 16
                                     ust. 5, issue #10). Skutek jest zamrożony
                                     w `data` — dotyczy TAMTEJ decyzji i ma
                                     brzmieć tak samo za rok. Pouczenie stoi
                                     na karcie sprawy, bo niesie AKTUALNY
                                     adres kontaktowy. --}}
                                <strong>{{ $data['naglowek'] ?? 'Mamy decyzję w sprawie Twojego zgłoszenia.' }}</strong>
                                {{ $data['reszta'] ?? '' }}
                                <br>
                                <span>Numer sprawy {{ $data['numer_sprawy'] ?? '' }}.
                                    Co możesz zrobić dalej, jeśli się z nami nie zgadzasz — napisaliśmy na karcie sprawy.</span>
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
                        // Model rozwiązuje adresy zbiorczo przed renderowaniem.
                        // Kliknięcie liczy je ponownie dla aktualnej widoczności.
                        $link = $destinationUrls[(string) $notification->getKey()] ?? null;
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
                    @elseif($notification->isUnread())
                        {{--
                            ISSUE #276 — DZIURA, NIE PRZEOCZENIE STYLISTYCZNE.

                            Część powiadomień („Sprawdziliśmy Twoje odwołanie.
                            Cofamy decyzję.", „Moderacja Kuking ukryła Twoją
                            treść.") nie ma dokąd prowadzić — cała informacja
                            stoi już w samej treści karty. Do tej poprawki takie
                            powiadomienie w ogóle nie miało przycisku, bo ten sam
                            `<form>` co wyżej stał wyłącznie pod `@if($link)`.
                            Jedyną drogą, żeby zgasić przy nim plakietkę, było
                            „oznacz wszystkie" — co dla kogoś, kto akurat czyta
                            resztę listy, oznacza zgaszenie też tego, czego
                            jeszcze nie widział.

                            `NotificationController::open()` już od 8 września
                            radzi sobie z brakiem celu: oznacza `read_at`
                            i (bez adresu) po prostu wraca na tę samą stronę
                            (`return back()`) — ten kod nigdy nie był zepsuty,
                            po prostu nie dało się go wywołać. Naprawa jest
                            więc TU, w widoku: ten sam formularz, ten sam POST,
                            inny napis — „Zobacz" kłamałoby, skoro nie ma dokąd
                            zaprowadzić.

                            Widoczny tylko dla NIEPRZECZYTANYCH: po kliknięciu
                            `read_at` już stoi, więc drugi przycisk obok tej
                            samej treści nic by więcej nie zrobił — a widoczna
                            akcja, która nie robi nic nowego, jest dokładnie tym
                            rodzajem „martwego przycisku", którego AGENTS.md §5
                            zabrania.
                        --}}
                        <form class="mt-3 mx-0 mb-0" method="POST" action="{{ route('notifications.open', $notification) }}">
                            @csrf
                            <button class="btn btn-secondary" type="submit">Oznacz jako przeczytane</button>
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

    {{--
        DRUGI PRZYCISK „OZNACZ WSZYSTKIE" — POD LISTĄ, PO PRZECZYTANIU.

        Ten sam formularz co na górze, z tym samym uzasadnieniem: przy
        długiej liście (uzasadnienia moderacyjne mają po kilka akapitów)
        przycisk sprzed listy jest dawno poza ekranem, gdy człowiek
        skończy czytać ostatnią kartę. Bez tego jedyna droga do „oznacz
        wszystkie" to przewinięcie z powrotem na górę.
    --}}
    <form class="mt-5" method="POST" action="{{ route('notifications.read') }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Oznacz wszystkie jako przeczytane</button>
    </form>
    @else
        <x-empty-state title="Nie ma jeszcze żadnych powiadomień">
            Tu pojawi się informacja, kiedy ktoś ugotuje z Twojego przepisu albo napisze komentarz.
        </x-empty-state>
    @endif

    <x-show-more :paginator="$notifications" czego="powiadomień" />
</x-layout>
