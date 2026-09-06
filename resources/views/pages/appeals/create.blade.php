{{--
    Odwołanie od decyzji moderacyjnej (issue #10, DSA art. 17 i 20).

    Człowiek, który tu trafia, jest zdenerwowany i zwykle nie wie dokładnie,
    co się stało. Dlatego ekran zaczyna się od PRZYPOMNIENIA DECYZJI wraz
    z tym, co mu wtedy napisaliśmy — a dopiero potem prosi o jego wersję.

    Jedno pole, jeden przycisk, bez JavaScriptu (D-007). Bez gry słowem
    „kuKING" — D-009 zabrania jej w tekstach moderacyjnych.
--}}
<x-layout title="Odwołanie od decyzji" :noindex="true">
    <h1>Odwołanie od decyzji</h1>

    <x-error-summary />

    <article class="card">
        <h2 style="margin-top:0; font-size:var(--text-title-sm);">Czego dotyczy sprawa</h2>
        <p class="meta">
            {{ $decyzja->label() }} ·
            {{ \App\Support\Czas::data($decyzja->created_at, 'j F Y') }}
        </p>
        @if($decyzja->user_message)
            <p class="whitespace-pre-line">„{{ $decyzja->user_message }}”</p>
        @else
            <p>Nie zapisaliśmy przy tej decyzji osobnej wiadomości do Ciebie.</p>
        @endif
    </article>

    @if($odwolanie !== null)
        {{-- Sprawa już u nas leży albo jest zamknięta. Człowiek ma zobaczyć,
             co napisał i co z tego wyszło — nie pusty formularz, który przy
             wysłaniu powie „już się odwoływałeś". --}}
        <article class="card mt-5">
            <h2 style="margin-top:0; font-size:var(--text-title-sm);">Twoje odwołanie</h2>
            <p class="meta">Złożone {{ \App\Support\Czas::data($odwolanie->created_at, 'j F Y') }} · {{ $odwolanie->statusLabel() }}</p>
            <p class="whitespace-pre-line">{{ $odwolanie->body }}</p>

            @if($odwolanie->isOpen())
                <p><strong>Czekamy na rozpatrzenie.</strong>
                    Odpowiadamy w ciągu {{ config('kuking.moderation.appeal_response_working_days') }} dni roboczych.
                    Odpowiedź zobaczysz w powiadomieniach.</p>
            @else
                <h3 style="font-size:var(--text-title-sm);">Nasza odpowiedź</h3>
                <p class="meta">{{ \App\Support\Czas::data($odwolanie->decided_at, 'j F Y') }}</p>
                <p class="whitespace-pre-line">{{ $odwolanie->decision_note }}</p>
                <p class="meta">
                    Odwołanie rozpatrujemy raz. Jeśli pojawiły się nowe okoliczności,
                    napisz na {{ config('kuking.community.contact_email') }}.
                </p>
            @endif
        </article>
    @elseif(! $decyzja->isAppealable())
        <article class="card mt-5">
            <h2 style="margin-top:0; font-size:var(--text-title-sm);">Tej decyzji nie da się już zakwestionować tutaj</h2>
            <p>
                Na odwołanie jest {{ config('kuking.moderation.appeal_days') }} dni od decyzji.
                Ten termin minął {{ \App\Support\Czas::data($decyzja->appealDeadline(), 'j F Y') }}.
            </p>
            <p>
                Jeśli pojawiły się nowe okoliczności, napisz na
                {{ config('kuking.community.contact_email') }} — przeczytamy.
            </p>
        </article>
    @else
        <form class="card mt-5" method="POST" action="{{ route('appeals.store', $decyzja) }}">
            @csrf

            <h2 style="margin-top:0; font-size:var(--text-title-sm);">Napisz, dlaczego to pomyłka</h2>
            <p>
                Wystarczy kilka zdań własnymi słowami. Napisz, co się według Ciebie
                wydarzyło naprawdę — to trafi do osoby, która obejrzy sprawę drugi raz.
            </p>

            <x-field name="body" label="Twoje wyjaśnienie" type="textarea" :rows="6" required
                     help="Od 10 do 2000 znaków." />

            <p class="meta">
                Co będzie dalej: przeczytamy odwołanie i odpowiemy w ciągu
                {{ config('kuking.moderation.appeal_response_working_days') }} dni roboczych.
                Odpowiedź — podtrzymanie albo cofnięcie decyzji, zawsze z wyjaśnieniem —
                zobaczysz w powiadomieniach. Odwołanie od jednej decyzji składa się raz,
                więc napisz od razu wszystko, co ważne.
            </p>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Wyślij odwołanie</button>
                <a class="btn btn-quiet" href="{{ route('notifications.index') }}">Wróć do powiadomień</a>
            </div>
        </form>
    @endif
</x-layout>
