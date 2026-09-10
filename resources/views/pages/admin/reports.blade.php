<x-layout title="Zgłoszenia — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Zgłoszenia" />

    <h1>Zgłoszenia</h1>

    {{--
        WIDOK „SPRAWY PILNE" (D-070) — wejście z oznaczenia „P0
        nieprzejrzane" w pasku panelu.

        Zakładki i filtr źródła NIE OBOWIĄZUJĄ w tym widoku i trzeba to
        powiedzieć wprost, bo inaczej moderator zobaczy listę, która nie
        zgadza się z podświetloną zakładką, i uzna to za usterkę. Powód, dla
        którego widok ignoruje filtry, stoi w `ModerationController::reports()`:
        liczba na alarmie musi być równa długości tej listy.
    --}}
    @if($pilne)
        <div class="alarm-pilne" role="alert">
            <p class="alarm-pilne-naglowek">
                <x-ikona nazwa="shield" :rozmiar="22" />
                <span>Sprawy krytyczne (P0), które czekają na przejrzenie</span>
            </p>
            <p class="alarm-pilne-tresc">
                Ta lista pokazuje WSZYSTKIE nieprzejrzane sprawy P0 — także oznaczenia automatu,
                jeśli ktoś podniósł im priorytet ręcznie. Zakładki i filtr źródła jej nie zawężają.
            </p>
            <a href="{{ route('admin.reports', ['status' => 'open']) }}">Wróć do całej kolejki zgłoszeń</a>
        </div>
    @endif

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
        domyślać, na który patrzy. Liczniki nad zakładkami dotyczą zawsze
        spraw OD LUDZI, więc przy widoku automatu trzeba powiedzieć wprost,
        że liczby mówią o czym innym niż lista.
    --}}
    @if($zrodlo === \App\Models\Report::SOURCE_AUTOMAT)
        <p class="notice">
            Patrzysz na <strong>oznaczenia automatu</strong>. Nikt ich nie zgłosił, a treści są
            widoczne w serwisie normalnie. Liczby przy zakładkach dotyczą zgłoszeń od ludzi.
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
        formularzami. Wspólny komponent robi z każdego błędu odnośnik do
        `#f-nazwa`, a taka kotwica jest tu wieloznaczna — prowadziłaby do
        pierwszego pola o tej nazwie, czyli zwykle do INNEGO zgłoszenia.
        Podsumowanie w każdym formularzu z kolei dałoby dwadzieścia pięć
        `role="alert"` na jeden błąd, a czytnik ekranu przeczytałby je
        wszystkie.
    --}}
    @if($errors->any())
        <div class="error-summary" role="alert" tabindex="-1">
            <p class="error-summary-title">
                @if($errors->count() === 1)
                    Jednej rzeczy jeszcze brakuje
                @else
                    Kilku rzeczy jeszcze brakuje
                @endif
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
            <h2 class="mt-0 text-title-sm">{{ $report->reasonLabel() }}</h2>

            {{--
                PRIORYTET NA KARCIE (D-070).

                Plakietka NIE STOI SAMA: obok skrótu („P0") jest pełna
                etykieta i cel czasowy z podręcznika, bo „P2" bez słowa
                „standardowy" jest dla nowej osoby tym samym, czym ikona bez
                podpisu (`AGENTS.md` §5). Kolejność na ekranie wynika z tej
                samej liczby, więc moderator widzi, DLACZEGO ta sprawa leży
                właśnie tutaj.
            --}}
            <p class="priorytet-wiersz">
                <span class="badge badge-priorytet badge-priorytet-{{ $report->priorytet }}">{{ $report->nazwaPriorytetu() }}</span>
                <span class="priorytet-opis">
                    {{ $report->etykietaPriorytetu() }} — {{ \App\Domain\Moderation\PriorytetSprawy::cel((int) $report->priorytet) }}
                </span>
            </p>

            @if($report->priorytetZmienionyRecznie())
                {{-- Zmiana ręczna musi być widoczna razem z powodem i osobą.
                     Bez tego kolejna osoba czytająca sprawę nie ma jak
                     odróżnić „tak wynika z kategorii" od „ktoś przeczytał
                     treść i przesunął" — a to jest cała wartość tej zmiany.
                     Priorytet z mapowania podajemy obok, żeby było widać, CO
                     zostało zmienione, nie tylko że coś. --}}
                <p class="meta">
                    Priorytet zmieniony ręcznie
                    {{ \App\Support\Czas::data($report->priorytet_zmieniony_o, 'j F Y, H:i') }}
                    @if($report->priorytetZmienilo)
                        przez {{ $report->priorytetZmienilo->displayName() }}
                    @endif
                    · z kategorii wynikałoby
                    {{ \App\Domain\Moderation\PriorytetSprawy::nazwa($report->priorytetZPowodu()) }}
                </p>
                <p class="whitespace-pre-line">Powód zmiany: {{ $report->priorytet_powod }}</p>
            @endif

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
                        @if($report->target_type === 'unknown')
                            <strong>— nie rozpoznaliśmy, o którą treść chodzi.</strong>
                        @endif
                    </p>
                @endif

                @if($report->illegality_explanation)
                    <p class="whitespace-pre-line">{{ $report->illegality_explanation }}</p>
                @endif
            @endif

            @if($report->details)
                <p class="whitespace-pre-line">{{ $report->details }}</p>
            @endif

            {{--
                ====================================================
                 WCZEŚNIEJSZE SANKCJE AUTORA (D-070, znalezisko MOD-02)
                ====================================================

                Podręcznik moderacji eskaluje regułami „2. wystąpienie",
                „3. → blokada trwała" — a panel tych wystąpień nie
                pokazywał, więc eskalacja zależała od pamięci człowieka
                i przy zmianie moderatora wypadała z procesu całkowicie.

                WĄSKA OŚ CZASU, NIE TECZKA. Data, decyzja, podstawa, wynik
                odwołania — i nic więcej. Czego tu świadomie NIE MA (treści
                tamtych spraw, notatek wewnętrznych, danych zgłaszających,
                spraw odrzuconych) i dlaczego: `App\Domain\Moderation\HistoriaSankcji`.

                Liczba zapytań NIE ROŚNIE z długością tej listy ani z liczbą
                spraw na stronie — całość jest policzona raz, w kontrolerze,
                dla wszystkich autorów naraz.
            --}}
            @php($historiaAutora = $historia[$report->id] ?? null)
            @if($historiaAutora !== null)
                <div class="historia-sankcji">
                    <h3 class="historia-sankcji-naglowek">
                        Wcześniejsze decyzje wobec tej osoby:
                        @if($historiaAutora->wystapienia === 0)
                            same cofnięte w odwołaniu
                        @elseif($historiaAutora->wystapienia === 1)
                            1 wystąpienie
                        @else
                            {{ $historiaAutora->wystapienia }} wystąpienia
                        @endif
                    </h3>
                    <p class="meta">
                        Podręcznik moderacji eskaluje po liczbie wystąpień. Decyzje cofnięte
                        w odwołaniu są tu widoczne, ale do tej liczby się NIE liczą — to były
                        nasze pomyłki, nie przewinienia tej osoby.
                    </p>
                    <ul class="historia-sankcji-lista">
                        @foreach($historiaAutora->pozycje as $wczesniejsza)
                            <li>
                                <span class="historia-sankcji-data">
                                    {{ \App\Support\Czas::data($wczesniejsza->created_at, 'j F Y') }}
                                </span>
                                <span class="historia-sankcji-decyzja">{{ $wczesniejsza->label() }}</span>
                                <span class="meta">
                                    · podstawa: {{ $wczesniejsza->reason_code }}
                                    @php($wynik = \App\Domain\Moderation\HistoriaAutora::wynikOdwolania($wczesniejsza))
                                    @if($wynik !== null)
                                        · {{ $wynik }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if($historiaAutora->przyciete())
                        <p class="meta">
                            Pokazane {{ count($historiaAutora->pozycje) }} z {{ $historiaAutora->wszystkich }}
                            decyzji — najnowsze pierwsze.
                        </p>
                    @endif
                </div>
            @endif

            {{--
                ====================================================
                 PRZEGLĄD SPRAWY (D-070, znalezisko MOD-04)
                ====================================================

                Do 10 września status `reviewing` istniał w schemacie i nic
                go nie nadawało, więc zakładka „W trakcie" była stale pusta.
                Te dwa przyciski są całą jego obsługą — i są potrzebne nawet
                przy jednym moderatorze, bo bez „podejmuję sprawę" nie da się
                uciszyć alarmu P0 inaczej niż wydając decyzję, której się
                jeszcze nie podjęło.

                Osobny formularz od formularza decyzji i osobny adres: to nie
                jest decyzja moderacyjna, więc nie ma tu podstawy z DSA
                art. 17, nie powstaje wpis w `moderation_actions` i autor
                niczego się nie dowiaduje.
            --}}
            @php($bladPrzegladu = $errors->first('przeglad'))
            @if($report->status === \App\Models\Report::STATUS_REVIEWING)
                <p class="meta">
                    W przeglądzie
                    @if($report->przegladajacy)
                        u {{ $report->przegladajacy->displayName() }}
                    @endif
                    od {{ \App\Support\Czas::data($report->przeglad_zaczety_o, 'j F Y, H:i') }}.
                    @if(\App\Domain\Moderation\Przeglad::wygasl($report))
                        <strong>Minęło ponad {{ \App\Domain\Moderation\Przeglad::WYGASA_PO_GODZINACH }}
                        godzin i sprawa wróciła do kolejki jako nieprzejrzana.</strong>
                    @endif
                </p>
                <form method="POST" action="{{ route('admin.reports.release', $report) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit">Oddaj do kolejki</button>
                </form>
            @elseif($report->status === \App\Models\Report::STATUS_OPEN)
                <form method="POST" action="{{ route('admin.reports.take', $report) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit">Wziąłem do przeglądu</button>
                </form>
            @endif
            @if($bladPrzegladu)
                <span class="field-error">{{ $bladPrzegladu }}</span>
            @endif

            @if($report->isOpen())
                {{--
                    ====================================================
                     RĘCZNA ZMIANA PRIORYTETU (D-070, znalezisko MOD-01)
                    ====================================================

                    Automat czyta wyłącznie KATEGORIĘ wybraną przez
                    zgłaszającego, nie treść. Dlatego człowiek musi móc
                    priorytet PODNIEŚĆ („dane osobowe" okazały się adresem
                    domowym z wezwaniem, żeby tam pojechać) i OBNIŻYĆ
                    („dotyczy dziecka" okazało się zdjęciem wnuka przy
                    torcie) — zawsze z uzasadnieniem, bo bez niego zmiana
                    jest w logu nieodróżnialna od pomyłki.

                    OSOBNY FORMULARZ, NIE POLE W FORMULARZU DECYZJI. Trzy
                    powody: priorytet ustala kolejność, nie wyrok, więc nie
                    może wymagać podstawy z DSA art. 17; zmiana priorytetu
                    ma działać NATYCHMIAST (sprawa ma się przesunąć w tej
                    samej minucie, nie po wydaniu decyzji); a formularz
                    decyzji przyjmuje jedno wysłanie na sprawę i drugiego już
                    nie przyjmie.

                    BŁĄD I WPISANA TREŚĆ WRACAJĄ POD KLUCZEM Z ID SPRAWY.
                    Ta strona stawia do dwudziestu pięciu takich formularzy
                    naraz — błąd pod wspólną nazwą pola pokazałby się pod
                    KAŻDYM z nich. To ten sam kształt usterki, który dla
                    formularza decyzji naprawia osobno issue #243; tu
                    rozstrzyga go klucz z identyfikatorem, bez zależności od
                    tamtej zmiany.

                    BEZ JEDNEJ LINII JAVASCRIPTU — zwykły `<select>`
                    i `<textarea>`, jak w formularzu decyzji obok.
                --}}
                @php($bladPriorytetu = $errors->first('priorytet_'.$report->id))
                <form class="zmiana-priorytetu" method="POST" action="{{ route('admin.reports.priority', $report) }}">
                    @csrf
                    <h3 class="text-title-sm">Priorytet w kolejce</h3>
                    <p class="meta">
                        Priorytet wynika z kategorii wybranej przez zgłaszającego, a ta nie mówi nic
                        o treści. Zmień go, jeśli po przeczytaniu sprawa jest pilniejsza albo mniej
                        pilna, niż wynikałoby z kategorii. Zmiana przesuwa sprawę w kolejce —
                        nie jest decyzją w sprawie.
                    </p>

                    <div class="field @if($bladPriorytetu) has-error @endif">
                        <label for="priorytet-{{ $report->id }}">
                            Nowy priorytet <span class="meta">(wymagane)</span>
                        </label>
                        <select class="field-input" id="priorytet-{{ $report->id }}" name="priorytet" required
                                @if($bladPriorytetu) aria-invalid="true" @endif>
                            @foreach(\App\Domain\Moderation\PriorytetSprawy::dlaFormularza() as $wartosc => $etykieta)
                                <option value="{{ $wartosc }}" @selected((int) $report->priorytet === $wartosc)>{{ $etykieta }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field @if($bladPriorytetu) has-error @endif">
                        <label for="priorytet-powod-{{ $report->id }}">
                            Dlaczego <span class="meta">(wymagane)</span>
                        </label>
                        <span class="field-help" id="priorytet-powod-{{ $report->id }}-help">
                            Jedno zdanie o tym, czego nie było widać w kategorii. Zostaje przy sprawie
                            razem z Twoim nazwiskiem — czyta to osoba, która weźmie tę sprawę po Tobie.
                        </span>
                        <textarea class="field-input" id="priorytet-powod-{{ $report->id }}"
                                  name="powod" rows="2" required
                                  aria-describedby="priorytet-powod-{{ $report->id }}-help"
                                  @if($bladPriorytetu) aria-invalid="true" @endif>{{ session('priorytet_powod_'.$report->id, '') }}</textarea>
                        @if($bladPriorytetu)
                            <span class="field-error">{{ $bladPriorytetu }}</span>
                        @endif
                    </div>

                    <button class="btn btn-secondary" type="submit">Zapisz priorytet</button>
                </form>

                <form method="POST" action="{{ route('admin.reports.decide', $report) }}">
                    @csrf

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
                                         niż za pierwszym razem. --}}
                                    <input type="radio" name="action" value="{{ $value }}"
                                           @checked(old('action') === $value)>
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
                    @php($bladTerminu = $errors->first('suspend_days'))
                    @php($bladDni = $errors->first('suspend_days_custom'))
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
                                           @checked(old('suspend_days', \App\Domain\Moderation\DlugoscZawieszenia::BRAK) === $value)>
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
                                <span class="meta">(wymagane, jeśli wybrałeś „Własny termin”)</span>
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
                                   value="{{ old('suspend_days_custom') }}"
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
                    @php($bladPodstawy = $errors->first('reason_code'))
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
                                <option value="{{ $kod }}" @selected(old('reason_code') === $kod)>{{ $etykieta }}</option>
                            @endforeach
                        </select>
                        @if($bladPodstawy)
                            <span class="field-error">{{ $bladPodstawy }}</span>
                        @endif
                    </div>
                    <x-field name="note" label="Notatka wewnętrzna" type="textarea" :rows="2" />
                    <x-field name="user_message" label="Wiadomość do użytkownika" type="textarea" :rows="3"
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
                        <h3 class="text-title-sm">Przywróć treść</h3>
                        <p class="meta">
                            Treść wróci do stanu SPRZED ukrycia — szkic zostanie szkicem,
                            opublikowany wróci opublikowany. Autor dostanie powiadomienie.
                        </p>

                        <x-field name="reason_code" label="Powód przywrócenia (kod wewnętrzny)" required
                                 placeholder="autor_poprawil"
                                 help="Krótki, powtarzalny kod. Cofnięcie kary też zostaje w logu." />
                        <x-field name="user_message" label="Wiadomość do użytkownika" type="textarea" :rows="2"
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
