{{--
    Kolejka odwołań (issue #10, DSA art. 20).

    Najstarsze otwarte na górze — termin odpowiedzi liczy się od złożenia,
    więc „najnowsze pierwsze" chowałoby przeterminowane najgłębiej.

    ────────────────────────────────────────────────────────────────────────
     HIERARCHIA KARTY (zgłoszenie właściciela z 10 września: „ten panel jest
     nieczytelny, zlewa się cały tekst")
    ────────────────────────────────────────────────────────────────────────

    Ekran miał osiem bloków o JEDNEJ wadze i jednym odstępie: nagłówek karty,
    metryczka, „Co pisze ta osoba", treść odwołania, „Decyzja, od której się
    odwołuje", metryczka decyzji, „Wysłane wtedy do niej", formularz. Trzy
    z tych nagłówków były `text-title-sm` (24 px), czyli tej samej wielkości
    co tytuł karty — więc na oko karta miała cztery tytuły i żadnej treści.

    Moderator szuka tu DWÓCH rzeczy: co ta osoba napisała i jaka była
    pierwotna decyzja. Karta jest więc teraz podzielona na cztery poziomy:

     1. CO TO ZA SPRAWA — tytuł karty i metryczka. Jedyny 24-pikselowy napis.
     2. CO SIĘ CZYTA — własne słowa odwołującego się. Cytat
        (`.odwolanie-cytat`) w pełnym rozmiarze tekstu, z krawędzią w kolorze
        akcentu: to jest treść, po którą się na ten ekran przychodzi.
     3. TŁO SPRAWY — pierwotna decyzja i wiadomość, którą ta osoba wtedy
        dostała. Ta sama forma cytatu, ale krawędź neutralna i tło
        spokojniejsze, bo to materiał porównawczy, nie nowa treść.
     4. FORMULARZ — odcięty kreską i dużym odstępem
        (`.odwolanie-odpowiedz`), żeby nie wyglądał na kolejny akapit
        do przeczytania.

    Nagłówki sekcji zeszły z 24 px na 18 px i na `--color-ink-muted`: są
    ETYKIETAMI bloku, nie tytułami. Rolę nagłówka w znaczniku zachowują
    (`<h3>`, czytnik ekranu dalej ma po czym skakać), zmienia się waga
    wizualna. Poniżej 18 px nie schodzi ani jeden z nich — mniejsze
    (`.meta`, 16 px) są wyłącznie METADANE (AGENTS.md §5; ten sam wyjątek
    co przy metryczce wersji w stopce, D-051, a treść do CZYTANIA zostaje
    w normalnym rozmiarze).
--}}
<x-layout title="Odwołania — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Odwołania" />

    <h1>Odwołania</h1>

    <x-error-summary />

    <nav class="tabs" aria-label="Filtr odwołań">
        <a class="tab" href="{{ route('admin.appeals', ['status' => 'open']) }}" @if($status === 'open') aria-current="page" @endif>Do rozpatrzenia ({{ $counts['open'] }})</a>
        <a class="tab" href="{{ route('admin.appeals', ['status' => 'upheld']) }}" @if($status === 'upheld') aria-current="page" @endif>Podtrzymane ({{ $counts['upheld'] }})</a>
        <a class="tab" href="{{ route('admin.appeals', ['status' => 'overturned']) }}" @if($status === 'overturned') aria-current="page" @endif>Cofnięte ({{ $counts['overturned'] }})</a>
        <a class="tab" href="{{ route('admin.appeals', ['status' => 'wszystkie']) }}" @if($status === 'wszystkie') aria-current="page" @endif>Wszystkie</a>
    </nav>

    @forelse($appeals as $appeal)
        @php($decyzja = $appeal->moderationAction)
        <article class="card odwolanie">
            {{-- POZIOM 1: co to za sprawa. --}}
            <h2 class="mt-0 text-title-sm">
                Odwołanie od decyzji „{{ $decyzja->label() }}”
                @if($appeal->isFromReporter())
                    <span class="badge">od zgłaszającego</span>
                @endif
            </h2>
            <p class="meta odwolanie-metryczka">
                @if($appeal->isFromReporter())
                    {{-- Zgłaszający może nie mieć konta wcale (art. 16 ust. 2
                         lit. c) — jedyna tożsamość, jaką mamy, leży na jego
                         zgłoszeniu, nie na koncie. --}}
                    {{ $appeal->report?->notifier_name ?? 'zgłaszający bez podanych danych' }}
                    (zgłoszenie {{ $appeal->report->numer_sprawy ?? '—' }}) ·
                @else
                    {{ $appeal->user?->displayName() ?? 'usunięte konto' }} ·
                @endif
                złożone {{ \App\Support\Czas::data($appeal->created_at, 'j F Y, H:i') }} ·
                @if($appeal->isOverdue())
                    {{-- Przeterminowane wyróżnia się mocniej niż resztą
                         metryczki: to jedyna informacja na tej karcie, która
                         mówi „już jest za późno". --}}
                    <strong class="odwolanie-po-terminie">termin odpowiedzi minął {{ \App\Support\Czas::data($appeal->responseDeadline(), 'j F Y') }}</strong>
                @else
                    odpowiedz do {{ \App\Support\Czas::data($appeal->responseDeadline(), 'j F Y') }}
                @endif
            </p>

            {{-- POZIOM 2: własne słowa człowieka. Najważniejszy blok karty. --}}
            <h3 class="odwolanie-etykieta">Co pisze ta osoba</h3>
            <blockquote class="odwolanie-cytat whitespace-pre-line">{{ $appeal->body }}</blockquote>

            {{-- POZIOM 3: tło sprawy — decyzja, od której się odwołuje. --}}
            <section class="odwolanie-decyzja">
                <h3 class="odwolanie-etykieta">Decyzja, od której się odwołuje</h3>
                <p class="meta">
                    {{ \App\Support\Czas::data($decyzja->created_at, 'j F Y, H:i') }} ·
                    {{-- POLSKA NAZWA POWODU, NIE KOD Z BAZY. Stało tu wprost
                         „powód: tresci-dla-doroslych" — a nazwa („Nagość albo
                         przemoc (punkt 5)") leżała w `PodstawaDecyzji` od
                         początku i szła już w tej postaci do autora treści.
                         Panel jest ekranem roboczym, ale kod z bazy nie staje
                         się przez to czytelny: żeby go zrozumieć, trzeba znać
                         schemat. --}}
                    powód: {{ \App\Domain\Moderation\PodstawaDecyzji::etykieta($decyzja->reason_code) }} ·
                    {{-- „decyzję podjęto", nie „podjął(-ęła)". Konstrukcji
                         zakładającej rodzaj nie da się przeczytać na głos
                         (AGENTS.md §11, `docs/brand/COPY_STYLE.md` §2); forma
                         bezosobowa mówi dokładnie to samo. Ten sam zabieg co
                         w PR #235, który usunął jedenaście takich miejsc —
                         tego jednego tamten PR nie objął. --}}
                    decyzję podjęto — {{ $decyzja->moderator?->displayName() ?? 'usunięte konto' }} ·
                    cel: {{ \App\Models\Report::TARGET_LABELS[$decyzja->target_type] ?? $decyzja->target_type }}
                    ({{ $decyzja->target_id }})
                </p>

                {{-- DOKŁADNIE to, co ta osoba wtedy dostała. Bez tego nie da się
                     ocenić, czy pisze o tym samym, co jej powiedzieliśmy. --}}
                <h3 class="odwolanie-etykieta">Wiadomość wysłana wtedy do tej osoby</h3>
                <blockquote class="odwolanie-cytat odwolanie-cytat-cichy whitespace-pre-line">{{ $decyzja->user_message ?: '— (nie napisano nic)' }}</blockquote>

                @if($decyzja->note)
                    <p class="meta whitespace-pre-line">Notatka wewnętrzna: {{ $decyzja->note }}</p>
                @endif
            </section>

            {{-- POZIOM 4: to, co się na tym ekranie ROBI. --}}
            <div class="odwolanie-odpowiedz">
                @if($appeal->isOpen() && ! auth()->user()->can('resolveAppeals', \App\Models\User::class))
                    {{-- KOLEJKĘ WIDZI KAŻDY MODERATOR, ROZSTRZYGA ADMINISTRATOR
                         (`docs/DECISIONS.md` D-039, `UserPolicy::resolveAppeals`).

                         Formularz jest tu SCHOWANY, a nie tylko odrzucany przez
                         Policy: moderator bez roli administratora napisałby całe
                         uzasadnienie, kliknął „Wyślij odpowiedź" i dostał 403 —
                         czyli stracił swoją pracę na ekranie, który wyglądał, jakby
                         jej oczekiwał. `AGENTS.md` §5: poprawne dane nigdy nie
                         znikają, a komunikat ma mówić, co zrobić. --}}
                    <h3 class="odwolanie-etykieta">Odpowiedź</h3>
                    <p class="notice">
                        Tę sprawę zamyka administrator, nie moderator — po to, żeby
                        odwołania nie rozstrzygała ta sama rola, która wydała
                        decyzję. Jeśli masz coś do dodania, napisz to w notatce
                        wewnętrznej przy decyzji albo na <strong>kontakt@kuking.pl</strong>.
                    </p>
                @elseif($appeal->isOpen())
                    @php($skutekCofniecia = match(true) {
                        $appeal->wymagaNowejDecyzji() => 'Uznaję odwołanie — podejmuję nową decyzję (niżej)',
                        in_array($decyzja->action, [\App\Models\ModerationAction::ACTION_HIDE, \App\Models\ModerationAction::ACTION_REMOVE], true) => 'Cofam decyzję — treść wraca',
                        in_array($decyzja->action, [\App\Models\ModerationAction::ACTION_SUSPEND, \App\Models\ModerationAction::ACTION_BAN], true) => 'Cofam decyzję — zdejmuję tę karę z konta',
                        default => 'Cofam decyzję',
                    })
                    <form method="POST" action="{{ route('admin.appeals.resolve', $appeal) }}">
                        @csrf
                        {{-- Identyfikator TEGO wiersza (issue #243): kolejka pokazuje
                             formularz odpowiedzi dla KAŻDEGO otwartego odwołania na
                             stronie naraz, wszystkie z polami o tych samych nazwach
                             (`outcome`, `decision_note`). Bez tego pola `old()` po
                             nieudanej walidacji jednej odpowiedzi wypełniałby te same
                             pola przy WSZYSTKICH pozostałych odwołaniach — patrz
                             `App\Support\WierszFormularza`. --}}
                        <input type="hidden" name="{{ \App\Support\WierszFormularza::POLE }}" value="{{ $appeal->id }}">
                        @php($bladWyniku = \App\Support\WierszFormularza::jestAktywny($appeal->id) && $errors->has('outcome'))
                        @php($idWyniku = 'f-outcome-'.$appeal->id)
                        <fieldset class="border-0 p-0">
                            <legend class="odwolanie-etykieta">Odpowiedź</legend>
                            <div class="choice-grid">
                                <label class="choice">
                                    <input type="radio" name="outcome" value="upheld" id="{{ $idWyniku }}"
                                           @if($bladWyniku)
                                               aria-invalid="true" aria-describedby="{{ $idWyniku }}-error"
                                           @endif
                                           @checked(\App\Support\WierszFormularza::stareLubDomyslne('outcome', $appeal->id) === 'upheld')>
                                    <span class="choice-label">Podtrzymuję decyzję</span>
                                </label>
                                <label class="choice">
                                    <input type="radio" name="outcome" value="overturned"
                                           @if($bladWyniku)
                                               aria-invalid="true" aria-describedby="{{ $idWyniku }}-error"
                                           @endif
                                           @checked(\App\Support\WierszFormularza::stareLubDomyslne('outcome', $appeal->id) === 'overturned')>
                                    <span class="choice-label">{{ $skutekCofniecia }}</span>
                                </label>
                            </div>
                            @if($bladWyniku)
                                <p class="field-error" id="{{ $idWyniku }}-error">{{ $errors->first('outcome') }}</p>
                            @endif
                        </fieldset>
                        @if(in_array($decyzja->action, [\App\Models\ModerationAction::ACTION_SUSPEND, \App\Models\ModerationAction::ACTION_BAN], true))
                            {{-- #933: cofnięcie starej kary nie zdejmuje późniejszej. --}}
                            <p class="meta">
                                Konto wraca tylko wtedy, gdy trzyma je właśnie ta kara. Jeśli
                                później zapadła inna decyzja o zawieszeniu albo blokadzie,
                                zostaje w mocy — ta osoba dostanie o tym zdanie w odpowiedzi.
                            </p>
                        @endif
                        @if($appeal->wymagaNowejDecyzji())
                            {{-- #989, DSA art. 20 ust. 4: uznanie skargi na „Bez działania”
                                 to NOWA decyzja, wykonana tu i teraz — nie opis w uzasadnieniu.
                                 Te same pola co przy decyzji ze zgłoszenia; bez JavaScriptu,
                                 o tym, które są potrzebne, rozstrzyga serwer. Przy
                                 „Podtrzymuję” pola niżej są ignorowane. --}}
                            @php($aktywny = \App\Support\WierszFormularza::jestAktywny($appeal->id))
                            @php($bladNowej = $aktywny ? $errors->first('nowa_decyzja') : null)
                            <fieldset class="border-0 p-0 mt-4">
                                <legend class="odwolanie-etykieta">
                                    Nowa decyzja — jeśli uznajesz odwołanie
                                </legend>
                                <p class="meta">
                                    Zapisze się i wykona razem z odpowiedzią. Autor treści dostanie
                                    powiadomienie z uzasadnieniem i może się od niej odwołać.
                                </p>
                                <div class="choice-grid">
                                    @foreach(\App\Models\ModerationAction::dozwoloneDla($decyzja->target_type) as $value => $label)
                                        @continue($value === \App\Models\ModerationAction::ACTION_NONE)
                                        <label class="choice">
                                            <input type="radio" name="nowa_decyzja" value="{{ $value }}"
                                                   @if($bladNowej) aria-invalid="true" @endif
                                                   @checked(\App\Support\WierszFormularza::stareLubDomyslne('nowa_decyzja', $appeal->id) === $value)>
                                            <span class="choice-label">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if($bladNowej)
                                    <p class="field-error">{{ $bladNowej }}</p>
                                @endif
                            </fieldset>

                            @php($bladTerminu = $aktywny ? $errors->first('suspend_days') : null)
                            @php($bladDni = $aktywny ? $errors->first('suspend_days_custom') : null)
                            <fieldset class="border-0 p-0 mt-4">
                                <legend class="odwolanie-etykieta">Na jak długo — jeśli zawieszasz konto</legend>
                                <div class="choice-grid">
                                    @foreach(\App\Domain\Moderation\DlugoscZawieszenia::dlaFormularza() as $value => $label)
                                        <label class="choice">
                                            <input type="radio" name="suspend_days" value="{{ $value }}"
                                                   @checked(\App\Support\WierszFormularza::stareLubDomyslne('suspend_days', $appeal->id, \App\Domain\Moderation\DlugoscZawieszenia::BRAK) === (string) $value)>
                                            <span class="choice-label">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if($bladTerminu)
                                    <span class="field-error">{{ $bladTerminu }}</span>
                                @endif
                                <div class="field mt-3 @if($bladDni) has-error @endif">
                                    <label for="wlasny-termin-odwolanie-{{ $appeal->id }}">
                                        Własny termin — liczba dni
                                        <span class="meta">(wymagane przy „Własnym terminie”)</span>
                                    </label>
                                    <input class="field-input" id="wlasny-termin-odwolanie-{{ $appeal->id }}"
                                           name="suspend_days_custom" type="number" inputmode="numeric"
                                           min="{{ \App\Domain\Moderation\DlugoscZawieszenia::MIN_DNI }}"
                                           max="{{ \App\Domain\Moderation\DlugoscZawieszenia::MAX_DNI }}" step="1"
                                           value="{{ \App\Support\WierszFormularza::stareLubDomyslne('suspend_days_custom', $appeal->id) }}"
                                           @if($bladDni) aria-invalid="true" @endif>
                                    @if($bladDni)
                                        <span class="field-error">{{ $bladDni }}</span>
                                    @endif
                                </div>
                            </fieldset>

                            @php($bladPodstawy = $aktywny ? $errors->first('reason_code') : null)
                            <div class="field @if($bladPodstawy) has-error @endif">
                                <label for="podstawa-odwolanie-{{ $appeal->id }}">
                                    Podstawa nowej decyzji <span class="meta">(wymagane przy uznaniu)</span>
                                </label>
                                <select class="field-input" id="podstawa-odwolanie-{{ $appeal->id }}" name="reason_code"
                                        @if($bladPodstawy) aria-invalid="true" @endif>
                                    <option value="">— wybierz podstawę —</option>
                                    @foreach(\App\Domain\Moderation\PodstawaDecyzji::dlaFormularza() as $kod => $etykieta)
                                        <option value="{{ $kod }}" @selected(\App\Support\WierszFormularza::stareLubDomyslne('reason_code', $appeal->id) === $kod)>{{ $etykieta }}</option>
                                    @endforeach
                                </select>
                                @if($bladPodstawy)
                                    <span class="field-error">{{ $bladPodstawy }}</span>
                                @endif
                            </div>

                            <x-field name="user_message" label="Wiadomość dla autora treści" type="textarea" :rows="3"
                                     :wiersz="$appeal->id"
                                     help="Co konkretnie się stało — własnymi słowami. Autor nie zobaczy odwołania ani tego, kto zgłosił. Podstawę, termin i drogę odwołania powiadomienie dopisuje samo." />
                        @endif

                        <x-field name="decision_note" label="Uzasadnienie dla tej osoby" type="textarea" :rows="4" required
                                 :wiersz="$appeal->id"
                                 help="To jest odpowiedź, którą ona przeczyta. Wymóg DSA art. 20: wynik bez wyjaśnienia nie jest odpowiedzią." />

                        <p class="meta">
                            Własną decyzję możesz cofnąć od razu. Podtrzymać —
                            dopiero po {{ config('kuking.moderation.appeal_self_uphold_hours') }} godzinach
                            od jej podjęcia (podręcznik moderacji, sekcja 3).
                        </p>

                        <button class="btn btn-primary mt-4" type="submit">Wyślij odpowiedź</button>
                    </form>
                @else
                    <h3 class="odwolanie-etykieta">Odpowiedź</h3>
                    <p><span class="badge">{{ $appeal->statusLabel() }}</span></p>
                    <p class="meta">
                        {{ \App\Support\Czas::dataLubNic($appeal->decided_at, 'j F Y, H:i') }} ·
                        {{ $appeal->decider?->displayName() ?? 'usunięte konto' }}
                    </p>
                    <blockquote class="odwolanie-cytat odwolanie-cytat-cichy whitespace-pre-line">{{ $appeal->decision_note }}</blockquote>
                    @if($appeal->decisionAfterAppeal)
                        {{-- #989: powiązanie widoczne w panelu, nie tylko w bazie. --}}
                        <p class="meta">
                            Decyzja po odwołaniu: {{ $appeal->decisionAfterAppeal->label() }} ·
                            {{ \App\Domain\Moderation\PodstawaDecyzji::etykieta($appeal->decisionAfterAppeal->reason_code) }}
                        </p>
                    @endif
                @endif
            </div>
        </article>
    @empty
        <x-empty-state title="Nic tu nie ma">Brak odwołań w tej kategorii.</x-empty-state>
    @endforelse

    <div class="mt-6">{{ $appeals->links() }}</div>
</x-layout>
