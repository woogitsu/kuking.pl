<x-layout title="Zgłoszenia — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Zgłoszenia" />

    <h1>Zgłoszenia</h1>

    {{-- Zakładki niosą aktualne `zrodlo`, inaczej przełączenie stanu
         wyrzucałoby moderatora z listy oznaczeń automatu z powrotem do spraw
         od ludzi — bez słowa wyjaśnienia, dlaczego lista nagle się zmieniła. --}}
    <nav class="tabs" aria-label="Filtr zgłoszeń">
        <a class="tab" href="{{ route('admin.reports', ['status' => 'open', 'zrodlo' => $zrodlo]) }}" @if($status === 'open') aria-current="page" @endif>Nowe ({{ $counts['open'] }})</a>
        <a class="tab" href="{{ route('admin.reports', ['status' => 'reviewing', 'zrodlo' => $zrodlo]) }}" @if($status === 'reviewing') aria-current="page" @endif>W trakcie ({{ $counts['reviewing'] }})</a>
        <a class="tab" href="{{ route('admin.reports', ['status' => 'resolved', 'zrodlo' => $zrodlo]) }}" @if($status === 'resolved') aria-current="page" @endif>Rozpatrzone ({{ $counts['resolved'] }})</a>
        <a class="tab" href="{{ route('admin.reports', ['status' => 'wszystkie', 'zrodlo' => $zrodlo]) }}" @if($status === 'wszystkie') aria-current="page" @endif>Wszystkie</a>
    </nav>

    {{--
        DWA ŹRÓDŁA, DWA EKRANY — a tu jedno zdanie, żeby nikt nie musiał się
        domyślać, na który patrzy. Liczniki nad zakładkami liczą to samo
        źródło, co lista pod nimi (issue #990): przy widoku automatu —
        oznaczenia automatu, przy zwykłym — zgłoszenia od ludzi.
    --}}
    @if($zrodlo === \App\Models\Report::SOURCE_AUTOMAT)
        <p class="notice">
            Patrzysz na <strong>oznaczenia automatu</strong>. Nikt ich nie zgłosił, a treści są
            widoczne w serwisie normalnie. Liczby przy zakładkach dotyczą oznaczeń automatu.
            <a href="{{ route('admin.sygnaly') }}">Wróć do kolejki automatu</a> albo
            <a href="{{ route('admin.reports', ['status' => $status]) }}">pokaż zgłoszenia od ludzi</a>.
        </p>
    @elseif($sygnalow > 0)
        <p class="meta">
            Automat czeka z {{ $sygnalow }} {{ $sygnalow === 1 ? 'oznaczeniem' : 'oznaczeniami' }} do przejrzenia:
            <a href="{{ route('admin.sygnaly') }}">Sygnały automatu</a>.
        </p>
    @endif

    {{--
        PODSUMOWANIE BŁĘDÓW — JEDNO NA EKRAN, NAD LISTĄ.

        UX_50_PLUS.md wymaga błędu przy polu ORAZ w podsumowaniu, nigdy
        tylko jednego z dwóch. Do tej pory tej strony nie dotyczyło ani
        jedno: błąd `action` („to zgłoszenie zostało już rozstrzygnięte")
        nie miał gdzie się pokazać i moderator klikał „Zapisz decyzję"
        w formularz, który milczał.

        DLACZEGO NIE `<x-error-summary>` I NIE JEDNO W KAŻDYM FORMULARZU:
        ten ekran to jedna strona z maksymalnie dwudziestoma pięcioma
        formularzami. Podsumowanie w każdym formularzu z osobna dałoby
        dwadzieścia pięć `role="alert"` na jeden błąd, a czytnik ekranu
        przeczytałby je wszystkie.

        `id` pól JEST dziś unikalny na wiersz (issue #243, `:wiersz` w
        `x-field`, patrz `App\Support\WierszFormularza`) — `<x-error-summary>`
        umiałby więc trafić w dobre pole dla `note` i `user_message`. Nie
        umiałby trafić w `reason_code` ani `suspend_days_custom`: te dwa pola
        stoją tu bez `x-field`, z ręcznie zbudowanym `id` w innej konwencji
        (`podstawa-{id}`, `wlasny-termin-{id}`, nie `f-{nazwa}-{id}`), więc
        wspólny komponent wysłałby moderatora pod nieistniejącą kotwicę.
        Płaska lista zostaje, dopóki te dwa pola nie przejdą na `x-field`.
    --}}
    @if($errors->any())
        <div class="error-summary" role="alert" tabindex="-1">
            <p class="error-summary-title">
                Sprawdź formularz
            </p>
            <ul>
                @foreach($errors->all() as $blad)
                    <li>{{ $blad }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @forelse($reports as $report)
        <article class="card mb-5">
            {{--
                PILNOŚĆ WIDAĆ NAD KATEGORIĄ, NIE POD NIĄ.

                Kolejność w bazie ustawia sprawy tak, żeby najpilniejsze były
                pierwsze — ale sam porządek tego nie MÓWI. Moderator, który
                wchodzi na drugą stronę albo na zakładkę „Wszystkie", widzi
                listę bez początku i nie ma skąd wiedzieć, czy to, na co
                patrzy, jest ciężkie, czy zwykłe.

                Napis, nie sam kolor (`docs/UX_50_PLUS.md`): kolor jest tu
                dodatkiem do zdania, a nie jedynym nośnikiem różnicy.
            --}}
            {{-- Tylko sprawa, która CZEKA: zamknięte P0 z napisem „Nie może
                 czekać" byłoby nieprawdą (`PriorytetSprawy::wKolejce`). --}}
            @php($priorytet = \App\Domain\Moderation\PriorytetSprawy::wKolejce($report))
            @if($priorytet !== null && ($napisPriorytetu = \App\Domain\Moderation\PriorytetSprawy::napis($priorytet)))
                <p class="meta mt-0 mb-2">
                    <strong class="priorytet priorytet-{{ $priorytet }}">{{ $napisPriorytetu }}</strong>
                </p>
            @endif

            <h2 class="mt-0 text-title-sm">{{ $report->reasonLabel() }}</h2>
            <p class="meta">
                {{ $report->target_type }}@if($report->target_id) · {{ $report->target_id }}@endif ·
                zgłoszone {{ \App\Support\Czas::data($report->created_at, 'j F Y, H:i') }}
                @if($report->jestZgloszeniemPrawnym())
                    {{-- Zgłoszenie prawne wolno złożyć bez podania danych
                         (art. 16 ust. 2 lit. c DSA, migracja
                         `allow_anonymous_legal_notices`). Bez tego warunku
                         kolejka pokazywałaby „przez " i puste miejsce, co
                         wygląda jak błąd danych, a jest poprawnym
                         zgłoszeniem. --}}
                    @if($report->notifier_name)
                        przez {{ $report->notifier_name }} (zgłoszenie prawne, DSA art. 16)
                    @else
                        bez podania danych zgłaszającego (zgłoszenie prawne, DSA art. 16)
                    @endif
                @elseif($report->wykrylAutomat())
                    {{-- Bez tej gałęzi oznaczenie automatu (`reporter_id` jest
                         puste z założenia) czytałoby się jako „przez usunięte
                         konto" — czyli moderator szukałby zgłaszającego, który
                         nigdy nie istniał. --}}
                    <strong>wskazane przez automat</strong> — nikt tego nie zgłosił
                @elseif($report->reporter)
                    przez {{ $report->reporter->displayName() }}
                @else
                    przez usunięte konto
                @endif
            </p>

            {{--
                ZGŁOSZENIE PRAWNE POKAZUJE WIĘCEJ i musi.

                Przy zgłoszeniu społecznościowym wystarczy typ i identyfikator
                treści — przycisk stał pod nią, więc cel jest pewny. Tutaj cel
                bywa nierozpoznany (ktoś wkleił link z pamięci), a decyzja
                zapada na podstawie UZASADNIENIA, nie samej kategorii. Bez
                tych dwóch rzeczy na ekranie moderator miał zamknąć sprawę,
                której treści nie widział.
            --}}
            @if($report->jestZgloszeniemPrawnym())
                @if($report->target_url)
                    <p class="meta">
                        Wskazany adres:
                        <span class="kod-do-przepisania">{{ $report->target_url }}</span>
                        {{-- Rodzaj adresu liczony z samej wartości, nie z `target_type`:
                             zgłoszenie sprzed #1636 mogło przypiąć obcy adres do naszej
                             treści, a moderator ma to zobaczyć przed decyzją. --}}
                        @switch(\App\Domain\Moderation\AdresZgloszenia::rodzaj((string) $report->target_url))
                            @case(\App\Domain\Moderation\AdresZgloszenia::ZEWNETRZNY)
                                <strong>— to adres spoza Kuking albo w nietypowej postaci. Nie łączymy go z żadną naszą treścią — sprawdź go ręcznie, zanim podejmiesz decyzję.</strong>
                                @break
                            @case(\App\Domain\Moderation\AdresZgloszenia::NIEPOPRAWNY)
                                <strong>— to nie jest adres strony, tylko opis. Poszukaj tej treści ręcznie.</strong>
                                @break
                            @default
                                @if($report->target_type === 'unknown')
                                    <strong>— adres Kuking, ale nie rozpoznaliśmy, o którą treść chodzi.</strong>
                                @endif
                        @endswitch
                    </p>
                @endif

                @if($report->illegality_explanation)
                    <p class="whitespace-pre-line">{{ $report->illegality_explanation }}</p>
                @endif
            @endif

            @if($report->details)
                <p class="whitespace-pre-line">{{ $report->details }}</p>
            @endif

            @if($report->isOpen())
                <form method="POST" action="{{ route('admin.reports.decide', $report) }}">
                    @csrf
                    {{-- Identyfikator TEGO wiersza (issue #243): ta strona stawia do
                         dwudziestu pięciu takich formularzy naraz, wszystkie z polami
                         o tych samych nazwach (`action`, `note`, `user_message`...).
                         Bez tego pola `old()` po nieudanej walidacji JEDNEGO zgłoszenia
                         wypełniałby te same pola przy WSZYSTKICH pozostałych —
                         patrz `App\Support\WierszFormularza`. --}}
                    <input type="hidden" name="{{ \App\Support\WierszFormularza::POLE }}" value="{{ $report->id }}">

                    <fieldset class="border-0 p-0">
                        <legend class="font-bold mb-3">Decyzja</legend>
                        <div class="choice-grid">
                            {{-- Tylko decyzje sensowne dla TEGO typu zgłoszenia
                                 (ModerationAction::DOZWOLONE). Przy zgłoszeniu
                                 osoby nie ma tu „Usuń treść" — ten przycisk
                                 kasował całe konto bezpowrotnie, a jego napis
                                 tego nie zdradzał. --}}
                            @foreach(\App\Models\ModerationAction::dozwoloneDla($report->target_type) as $value => $label)
                                <label class="choice">
                                    {{-- `old()` także tutaj: po nieudanej walidacji
                                         wybrana decyzja wracała czysta, więc moderator
                                         musiał ją klikać drugi raz i mógł kliknąć inną
                                         niż za pierwszym razem. Ograniczone do TEGO
                                         wiersza (issue #243) — bez tego zaznaczenie
                                         przy zgłoszeniu, którego walidacja padła,
                                         wracało też przy wszystkich INNYCH zgłoszeniach
                                         na stronie. --}}
                                    <input type="radio" name="action" value="{{ $value }}"
                                           @checked(\App\Support\WierszFormularza::stareLubDomyslne('action', $report->id) === $value)>
                                    <span class="choice-label">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    {{--
                        Długość zawieszenia (issue #40, poprawione po zgłoszeniu
                        właściciela).

                        Bez tego pola każde zawieszenie było bezterminowe, bo nie
                        było gdzie zapisać terminu — a przy jednym moderatorze
                        nikt nie odklikuje kary po tygodniu ręcznie. Playbook
                        obiecywał blokady czasowe, których system nie umiał zrobić.

                        DWIE RZECZY DOŁOŻONE PÓŹNIEJ, obie z tego samego powodu —
                        grupa radio bez pozycji zerowej to pułapka bez wyjścia:

                        1. „BEZ ZAWIESZENIA" JAKO PIERWSZA I DOMYŚLNA POZYCJA.
                           Wcześniej zaznaczonego „Na 1 dzień" nie dało się
                           odkliknąć — jedyną drogą powrotu było odświeżenie
                           strony, a razem z nim ginęło uzasadnienie i notatka
                           (AGENTS.md §5: poprawne dane nigdy nie znikają).
                           Pod grupą stało przy tym „Bez wyboru zawieszenie jest
                           bezterminowe", czyli zaniechanie dawało NAJSUROWSZĄ
                           karę. Teraz jest odwrotnie: domyślnie nie ma kary,
                           a bezterminowość wymaga jawnego kliknięcia.

                        2. WŁASNY TERMIN W DNIACH. Trzy gotowe liczby nie były
                           wszystkimi sensownymi wyborami, a dziura między
                           30 dniami i bezterminowością zamykała się jedynym
                           dostępnym narzędziem: karą bez terminu.

                        BEZ JEDNEJ LINII JAVASCRIPTU. Pole liczby nie jest
                        wyszarzane ani ukrywane przy innych wyborach — o tym, czy
                        liczba jest potrzebna i czy jest sensowna, rozstrzyga
                        serwer (`ModerationController::decide()`). D-053
                        pozwoliłoby wymagać skryptu tam, gdzie chroni serwis,
                        ale panel moderacji to nie Turnstile: tu prostota jest
                        warta więcej niż wygoda, a martwe pole przy słabym
                        zasięgu byłoby gorsze od jednego pola więcej.
                    --}}
                    {{-- Oba błędy ograniczone do TEGO wiersza (issue #243): bez tego
                         błąd „wpisz liczbę dni" przy jednym zgłoszeniu pokazywałby
                         się pod polem KAŻDEGO innego zgłoszenia na stronie. --}}
                    @php($bladTerminu = \App\Support\WierszFormularza::jestAktywny($report->id) ? $errors->first('suspend_days') : null)
                    @php($bladDni = \App\Support\WierszFormularza::jestAktywny($report->id) ? $errors->first('suspend_days_custom') : null)
                    <fieldset class="border-0 p-0 mt-4">
                        <legend class="font-bold mb-3">
                            Na jak długo — jeśli zawieszasz konto
                        </legend>
                        <div class="choice-grid">
                            @foreach(\App\Domain\Moderation\DlugoscZawieszenia::dlaFormularza() as $value => $label)
                                <label class="choice">
                                    {{-- Domyślnie „Bez zawieszenia": drugi argument
                                         `old()` jest tu całą różnicą między pomyłką
                                         odwracalną i nieodwracalną. --}}
                                    <input type="radio" name="suspend_days" value="{{ $value }}"
                                           @checked(\App\Support\WierszFormularza::stareLubDomyslne('suspend_days', $report->id, \App\Domain\Moderation\DlugoscZawieszenia::BRAK) === $value)>
                                    <span class="choice-label">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if($bladTerminu)
                            <span class="field-error">{{ $bladTerminu }}</span>
                        @endif

                        <div class="field mt-3 @if($bladDni) has-error @endif">
                            <label for="wlasny-termin-{{ $report->id }}">
                                Własny termin — liczba dni
                                <span class="meta">(wymagane przy „Własnym terminie”)</span>
                            </label>
                            <span class="field-help" id="wlasny-termin-{{ $report->id }}-help">
                                Od {{ \App\Domain\Moderation\DlugoscZawieszenia::MIN_DNI }}
                                do {{ \App\Domain\Moderation\DlugoscZawieszenia::MAX_DNI }} dni.
                                Przy innym wyborze niż „Własny termin” ta liczba nie ma znaczenia —
                                zignorujemy ją, nie musisz jej czyścić.
                            </span>
                            <input class="field-input" id="wlasny-termin-{{ $report->id }}"
                                   name="suspend_days_custom" type="number" inputmode="numeric"
                                   min="{{ \App\Domain\Moderation\DlugoscZawieszenia::MIN_DNI }}"
                                   max="{{ \App\Domain\Moderation\DlugoscZawieszenia::MAX_DNI }}" step="1"
                                   value="{{ \App\Support\WierszFormularza::stareLubDomyslne('suspend_days_custom', $report->id) }}"
                                   aria-describedby="wlasny-termin-{{ $report->id }}-help"
                                   @if($bladDni) aria-invalid="true" @endif>
                            @if($bladDni)
                                <span class="field-error">{{ $bladDni }}</span>
                            @endif
                        </div>

                        <p class="meta mt-2">
                            Konto z terminem wraca samo, gdy termin minie. „Bez zawieszenia” znaczy,
                            że nie zawieszasz konta — a zawieszenie bez terminu trwa do Twojej
                            decyzji i musisz je wybrać wprost.
                        </p>
                    </fieldset>

                    {{--
                        PODSTAWA DECYZJI — TO ZDANIE PRZECZYTA AUTOR TREŚCI.

                        Do dziś było tu wolne pole „Powód decyzji (kod
                        wewnętrzny)": moderator wpisywał `spam_link` albo
                        `nekanie`, a autor treści nie dowiadywał się o podstawie
                        NICZEGO — mimo że DSA art. 17 ust. 3 lit. d i e wymaga
                        albo punktu zasad, albo podstawy prawnej.

                        Lista jest zamknięta, bo zdanie „naruszyłeś punkt 4
                        zasad" wolno wysłać wyłącznie wtedy, gdy numer bierze
                        się z odwzorowania (`PodstawaDecyzji`), a nie ze
                        zgadywania. Zwykły <select>, bez JavaScriptu (D-007),
                        z widoczną etykietą.
                    --}}
                    {{-- Ograniczone do TEGO wiersza (issue #243) — patrz komentarz
                         przy `$bladTerminu` wyżej. --}}
                    @php($bladPodstawy = \App\Support\WierszFormularza::jestAktywny($report->id) ? $errors->first('reason_code') : null)
                    <div class="field @if($bladPodstawy) has-error @endif">
                        <label for="podstawa-{{ $report->id }}">
                            Podstawa decyzji <span class="meta">(wymagane)</span>
                        </label>
                        <span class="field-help" id="podstawa-{{ $report->id }}-help">
                            Autor treści zobaczy to jako „Podstawą tej decyzji jest punkt N zasad Kuking”.
                            Przy „treść niezgodna z prawem” wiadomość niżej jest OBOWIĄZKOWA — to w niej
                            piszesz, czego dotyczy naruszenie prawa.
                        </span>
                        <select class="field-input" id="podstawa-{{ $report->id }}" name="reason_code" required
                                aria-describedby="podstawa-{{ $report->id }}-help"
                                @if($bladPodstawy) aria-invalid="true" @endif>
                            <option value="">— wybierz podstawę —</option>
                            @foreach(\App\Domain\Moderation\PodstawaDecyzji::dlaFormularza() as $kod => $etykieta)
                                <option value="{{ $kod }}" @selected(\App\Support\WierszFormularza::stareLubDomyslne('reason_code', $report->id) === $kod)>{{ $etykieta }}</option>
                            @endforeach
                        </select>
                        @if($bladPodstawy)
                            <span class="field-error">{{ $bladPodstawy }}</span>
                        @endif
                    </div>
                    <x-field name="note" label="Notatka wewnętrzna" type="textarea" :rows="2" :wiersz="$report->id" />
                    <x-field name="user_message" label="Wiadomość do użytkownika" type="textarea" :rows="3"
                             :wiersz="$report->id"
                             help="Co konkretnie się stało — własnymi słowami. Podstawę, informację o zgłoszeniu,
                                   brak automatu, termin odwołania i drogę do organu pozasądowego oraz sądu
                                   powiadomienie dopisuje samo (DSA art. 17 ust. 3)." />

                    <button class="btn btn-primary mt-4" type="submit">Zapisz decyzję</button>
                </form>
            @else
                <p class="badge">{{ $report->status }} · {{ $report->resolver?->displayName() }}</p>
                @if($report->resolution_note)
                    <p class="meta">{{ $report->resolution_note }}</p>
                @endif

                {{--
                    Przywrócenie treści (issue #65).

                    Do tej pory decyzja „Ukryj treść" była nieodwracalna z
                    poziomu serwisu — jedyną drogą powrotu był UPDATE w
                    produkcyjnej bazie, czyli operacja, której AGENTS.md §6
                    zabrania bez zgody właściciela. Boli to najbardziej tam,
                    gdzie podręcznik każe ukrywać TYMCZASOWO („najpierw ukryć,
                    dać szansę poprawy" przy prawach autorskich): autor
                    poprawiał tekst i nie miał kto zdjąć ukrycia.

                    Przycisk pokazuje się tylko wtedy, gdy naprawdę jest co
                    przywracać — treść istnieje i nadal jest schowana.
                --}}
                @if($przywracalne[$report->id] ?? false)
                    <form class="mt-4" method="POST" action="{{ route('admin.reports.restore', $report) }}">
                        @csrf
                        {{-- Ten sam identyfikator wiersza co w formularzu decyzji
                             wyżej (issue #243) — obie postacie formularza nigdy nie
                             współistnieją dla JEDNEGO zgłoszenia, więc dzielenie
                             wartości jest bezpieczne, a strona z wieloma rozpatrzonymi
                             zgłoszeniami dalej ma unikalne `id` i własny `old()`
                             na każdy wiersz. --}}
                        <input type="hidden" name="{{ \App\Support\WierszFormularza::POLE }}" value="{{ $report->id }}">
                        <h3 class="text-title-sm">Przywróć treść</h3>
                        <p class="meta">
                            Treść wróci do stanu SPRZED ukrycia — szkic zostanie szkicem,
                            opublikowany wróci opublikowany. Autor dostanie powiadomienie.
                        </p>

                        <x-field name="reason_code" label="Powód przywrócenia (kod wewnętrzny)" required
                                 placeholder="autor_poprawil" :wiersz="$report->id"
                                 help="Krótki, powtarzalny kod. Cofnięcie kary też zostaje w logu." />
                        <x-field name="user_message" label="Wiadomość do użytkownika" type="textarea" :rows="2"
                                 :wiersz="$report->id"
                                 help="Nieobowiązkowa. Bez niej wyślemy zdanie domyślne." />

                        <button class="btn btn-secondary" type="submit">Przywróć treść</button>
                    </form>
                @endif
            @endif
        </article>
    @empty
        <x-empty-state title="Nic tu nie ma">Brak zgłoszeń w tej kategorii.</x-empty-state>
    @endforelse

    <div class="mt-6">{{ $reports->links() }}</div>
</x-layout>
