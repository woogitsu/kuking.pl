<x-layout title="Zgłoszenia" :noindex="true">
    <h1>Zgłoszenia</h1>

    <nav class="tabs" aria-label="Filtr zgłoszeń">
        <a class="tab" href="{{ route('admin.reports', ['status' => 'open']) }}" @if($status === 'open') aria-current="page" @endif>Nowe ({{ $counts['open'] }})</a>
        <a class="tab" href="{{ route('admin.reports', ['status' => 'reviewing']) }}" @if($status === 'reviewing') aria-current="page" @endif>W trakcie ({{ $counts['reviewing'] }})</a>
        <a class="tab" href="{{ route('admin.reports', ['status' => 'resolved']) }}" @if($status === 'resolved') aria-current="page" @endif>Rozpatrzone ({{ $counts['resolved'] }})</a>
        <a class="tab" href="{{ route('admin.reports', ['status' => 'wszystkie']) }}" @if($status === 'wszystkie') aria-current="page" @endif>Wszystkie</a>
    </nav>

    @forelse($reports as $report)
        <article class="card" style="margin-bottom:var(--spacing-5);">
            <h2 style="margin-top:0; font-size:var(--text-title-sm);">{{ $report->reasonLabel() }}</h2>
            <p class="meta">
                {{ $report->target_type }} · {{ $report->target_id }} ·
                zgłoszone {{ $report->created_at->translatedFormat('j F Y, H:i') }}
                @if($report->reporter) przez {{ $report->reporter->displayName() }} @else przez usunięte konto @endif
            </p>

            @if($report->details)
                <p style="white-space:pre-line;">{{ $report->details }}</p>
            @endif

            @if($report->isOpen())
                <form method="POST" action="{{ route('admin.reports.decide', $report) }}">
                    @csrf
                    <fieldset style="border:0; padding:0;">
                        <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Decyzja</legend>
                        <div class="choice-grid">
                            @foreach([
                                'no_action' => 'Bez działania',
                                'hide' => 'Ukryj treść',
                                'remove' => 'Usuń treść',
                                'warn' => 'Ostrzeżenie dla autora',
                                'suspend' => 'Zawieś konto',
                                'ban' => 'Zablokuj konto na stałe',
                            ] as $value => $label)
                                <label class="choice">
                                    <input type="radio" name="action" value="{{ $value }}">
                                    <span class="choice-label">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    {{--
                        Długość zawieszenia (issue #40).

                        Bez tego pola każde zawieszenie było bezterminowe, bo nie
                        było gdzie zapisać terminu — a przy jednym moderatorze
                        nikt nie odklikuje kary po tygodniu ręcznie. Playbook
                        obiecywał blokady czasowe, których system nie umiał zrobić.

                        Pole nie jest ukrywane skryptem przy innych decyzjach:
                        D-007 mówi, że ważne funkcje działają bez JavaScriptu,
                        a kontroler i tak ignoruje tę wartość dla decyzji innych
                        niż „Zawieś konto".
                    --}}
                    <fieldset style="border:0; padding:0; margin-top:var(--spacing-4);">
                        <legend style="font-weight:700; margin-bottom:var(--spacing-3);">
                            Na jak długo — jeśli zawieszasz konto
                        </legend>
                        <div class="choice-grid">
                            @foreach([
                                '1' => 'Na 1 dzień',
                                '7' => 'Na 7 dni',
                                '30' => 'Na 30 dni',
                                'bezterminowo' => 'Bezterminowo, do mojej decyzji',
                            ] as $value => $label)
                                <label class="choice">
                                    <input type="radio" name="suspend_days" value="{{ $value }}"
                                           @checked(old('suspend_days') === $value)>
                                    <span class="choice-label">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="meta" style="margin-top:var(--spacing-2);">
                            Konto wraca samo po upływie terminu. Bez wyboru zawieszenie jest bezterminowe.
                        </p>
                    </fieldset>

                    <x-field name="reason_code" label="Powód decyzji (kod wewnętrzny)" required
                             placeholder="spam_link" help="Krótki, powtarzalny kod. Ułatwia późniejsze statystyki." />
                    <x-field name="note" label="Notatka wewnętrzna" type="textarea" :rows="2" />
                    <x-field name="user_message" label="Wiadomość do użytkownika" type="textarea" :rows="3"
                             help="Wymóg DSA: jeśli ograniczasz treść, autor musi wiedzieć dlaczego i że może się odwołać." />

                    <button class="btn btn-primary" type="submit" style="margin-top:var(--spacing-4);">Zapisz decyzję</button>
                </form>
            @else
                <p class="badge">{{ $report->status }} · {{ $report->resolver?->displayName() }}</p>
                @if($report->resolution_note)
                    <p class="meta">{{ $report->resolution_note }}</p>
                @endif
            @endif
        </article>
    @empty
        <x-empty-state title="Nic tu nie ma">Brak zgłoszeń w tej kategorii.</x-empty-state>
    @endforelse

    <div style="margin-top:var(--spacing-6);">{{ $reports->links() }}</div>
</x-layout>
