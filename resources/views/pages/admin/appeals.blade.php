{{--
    Kolejka odwołań (issue #10, DSA art. 20).

    Najstarsze otwarte na górze — termin odpowiedzi liczy się od złożenia,
    więc „najnowsze pierwsze" chowałoby przeterminowane najgłębiej.
--}}
<x-layout title="Odwołania" :noindex="true">
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
        <article class="card mb-5">
            <h2 class="mt-0 text-title-sm">
                Odwołanie od decyzji „{{ $decyzja->label() }}”
                @if($appeal->isFromReporter())
                    <span class="badge">od zgłaszającego</span>
                @endif
            </h2>
            <p class="meta">
                @if($appeal->isFromReporter())
                    {{-- Zgłaszający może nie mieć konta wcale (art. 16 ust. 2
                         lit. c) — jedyna tożsamość, jaką mamy, leży na jego
                         zgłoszeniu, nie na koncie. --}}
                    {{ $appeal->report?->notifier_name ?? 'zgłaszający bez podanych danych' }}
                    (zgłoszenie {{ $appeal->report ? \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) $appeal->report->getKey(), 0, 8)) : '—' }}) ·
                @else
                    {{ $appeal->user?->displayName() ?? 'usunięte konto' }} ·
                @endif
                złożone {{ \App\Support\Czas::data($appeal->created_at, 'j F Y, H:i') }} ·
                @if($appeal->isOverdue())
                    <strong>termin odpowiedzi minął {{ \App\Support\Czas::data($appeal->responseDeadline(), 'j F Y') }}</strong>
                @else
                    odpowiedz do {{ \App\Support\Czas::data($appeal->responseDeadline(), 'j F Y') }}
                @endif
            </p>

            <h3 class="text-title-sm">Co pisze ta osoba</h3>
            <p class="whitespace-pre-line">{{ $appeal->body }}</p>

            <h3 class="text-title-sm">Decyzja, od której się odwołuje</h3>
            <p class="meta">
                {{ \App\Support\Czas::data($decyzja->created_at, 'j F Y, H:i') }} ·
                powód: {{ $decyzja->reason_code }} ·
                decyzję podjął(-ęła) {{ $decyzja->moderator?->displayName() ?? 'usunięte konto' }} ·
                cel: {{ $decyzja->target_type }} {{ $decyzja->target_id }}
            </p>
            {{-- DOKŁADNIE to, co ta osoba wtedy dostała. Bez tego nie da się
                 ocenić, czy pisze o tym samym, co jej powiedzieliśmy. --}}
            <p class="whitespace-pre-line">
                Wysłane wtedy do niej: {{ $decyzja->user_message ?: '— (nie napisano nic)' }}
            </p>
            @if($decyzja->note)
                <p class="meta whitespace-pre-line">Notatka wewnętrzna: {{ $decyzja->note }}</p>
            @endif

            @if($appeal->isOpen())
                @php($skutekCofniecia = match(true) {
                    in_array($decyzja->action, [\App\Models\ModerationAction::ACTION_HIDE, \App\Models\ModerationAction::ACTION_REMOVE], true) => 'Cofam decyzję — treść wraca',
                    in_array($decyzja->action, [\App\Models\ModerationAction::ACTION_SUSPEND, \App\Models\ModerationAction::ACTION_BAN], true) => 'Cofam decyzję — konto wraca',
                    default => 'Cofam decyzję',
                })
                <form method="POST" action="{{ route('admin.appeals.resolve', $appeal) }}">
                    @csrf
                    <fieldset class="border-0 p-0">
                        <legend class="font-bold mb-3">Odpowiedź</legend>
                        <div class="choice-grid">
                            <label class="choice">
                                <input type="radio" name="outcome" value="upheld"
                                       @checked(old('outcome') === 'upheld')>
                                <span class="choice-label">Podtrzymuję decyzję</span>
                            </label>
                            <label class="choice">
                                <input type="radio" name="outcome" value="overturned"
                                       @checked(old('outcome') === 'overturned')>
                                <span class="choice-label">{{ $skutekCofniecia }}</span>
                            </label>
                        </div>
                    </fieldset>
                    @if($appeal->isFromReporter() && $decyzja->action === \App\Models\ModerationAction::ACTION_NONE)
                        <p class="meta">
                            Ta decyzja to „bez działania" — system nie umie sam podjąć nowej
                            decyzji na już rozstrzygniętym zgłoszeniu. Jeśli cofasz, napisz
                            w uzasadnieniu, co konkretnie zrobisz z treścią, i zrób to osobno.
                        </p>
                    @endif

                    <x-field name="decision_note" label="Uzasadnienie dla tej osoby" type="textarea" :rows="4" required
                             help="To jest odpowiedź, którą ona przeczyta. Wymóg DSA art. 20: wynik bez wyjaśnienia nie jest odpowiedzią." />

                    <p class="meta">
                        Własną decyzję możesz cofnąć od razu. Podtrzymać —
                        dopiero po {{ config('kuking.moderation.appeal_self_uphold_hours') }} godzinach
                        od jej podjęcia (podręcznik moderacji, sekcja 3).
                    </p>

                    <button class="btn btn-primary mt-4" type="submit">Wyślij odpowiedź</button>
                </form>
            @else
                <h3 class="text-title-sm">Odpowiedź</h3>
                <p class="badge">{{ $appeal->statusLabel() }}</p>
                <p class="meta">
                    {{ \App\Support\Czas::dataLubNic($appeal->decided_at, 'j F Y, H:i') }} ·
                    {{ $appeal->decider?->displayName() ?? 'usunięte konto' }}
                </p>
                <p class="whitespace-pre-line">{{ $appeal->decision_note }}</p>
            @endif
        </article>
    @empty
        <x-empty-state title="Nic tu nie ma">Brak odwołań w tej kategorii.</x-empty-state>
    @endforelse

    <div class="mt-6">{{ $appeals->links() }}</div>
</x-layout>
