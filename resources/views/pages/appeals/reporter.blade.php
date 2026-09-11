<?php /** @var \App\Models\Report $zgloszenie */ /** @var \App\Models\ModerationAction $decyzja */ /** @var \App\Models\Appeal|null $odwolanie */ ?>
{{--
    Odwołanie od decyzji moderacyjnej — droga dla ZGŁASZAJĄCEGO (issue #23,
    DSA art. 20 ust. 1). Ten sam wzorzec co appeals/create.blade.php po
    stronie autora: przypomnienie sprawy, potem stan (już złożone / termin
    minął / formularz).

    Jedno pole, jeden przycisk, bez JavaScriptu (D-007). Bez gry słowem
    „kuKING" — D-009.
--}}
<x-layout title="Odwołanie od decyzji" :noindex="true">
    <h1>Odwołanie od decyzji</h1>

    <x-error-summary />

    <article class="sekcja-strony">
        <h2 class="mt-0 text-title-sm">Twoje zgłoszenie</h2>
        <p class="meta">
            Numer sprawy {{ $zgloszenie->numer_sprawy }} ·
            {{ \App\Support\Czas::data($decyzja->created_at, 'j F Y') }}
        </p>
        <p>{{ $decyzja->label() }}.</p>
    </article>

    @if($odwolanie !== null)
        <article class="sekcja-strony mt-5">
            <h2 class="mt-0 text-title-sm">Twoje odwołanie</h2>
            <p class="meta">Złożone {{ \App\Support\Czas::data($odwolanie->created_at, 'j F Y') }} · {{ $odwolanie->statusLabel() }}</p>
            <p class="whitespace-pre-line">{{ $odwolanie->body }}</p>

            @if($odwolanie->isOpen())
                <p><strong>Czekamy na rozpatrzenie.</strong>
                    Odpowiadamy w ciągu {{ config('kuking.moderation.appeal_response_working_days') }} dni roboczych,
                    na adres e-mail, z którego przyszło Twoje zgłoszenie.</p>
            @else
                <h3 class="text-title-sm">Nasza odpowiedź</h3>
                <p class="meta">{{ \App\Support\Czas::data($odwolanie->decided_at, 'j F Y') }}</p>
                <p class="whitespace-pre-line">{{ $odwolanie->decision_note }}</p>
                <p class="meta">
                    Odwołanie rozpatrujemy raz. Jeśli pojawiły się nowe okoliczności,
                    napisz na {{ config('kuking.community.contact_email') }}.
                </p>
            @endif
        </article>
    @elseif(! $decyzja->isAppealableByReporter())
        <article class="ramka-pomocnicza mt-5">
            <h2 class="mt-0 text-title-sm">Tej decyzji nie da się już zakwestionować tutaj</h2>
            <p>
                Na odwołanie jest sześć miesięcy od decyzji.
                Ten termin minął {{ \App\Support\Czas::data($decyzja->appealDeadline(), 'j F Y') }}.
            </p>
            <p>
                Jeśli pojawiły się nowe okoliczności, napisz na
                {{ config('kuking.community.contact_email') }} — przeczytamy.
            </p>
        </article>
    @else
        <form class="panel-formularza mt-5" method="POST" action="{{ url()->full() }}">
            @csrf

            <h2 class="mt-0 text-title-sm">Napisz, dlaczego się nie zgadzasz</h2>
            <p>
                Wystarczy kilka zdań własnymi słowami. Napisz, co Twoim zdaniem
                przemawia za inną decyzją — to trafi do osoby, która obejrzy
                sprawę drugi raz.
            </p>

            <x-field name="body" label="Twoje wyjaśnienie" type="textarea" :rows="6" required
                     help="Od 10 do 2000 znaków." />

            <p class="meta">
                Co będzie dalej: przeczytamy odwołanie i odpowiemy w ciągu
                {{ config('kuking.moderation.appeal_response_working_days') }} dni roboczych —
                na adres e-mail, z którego przyszło Twoje zgłoszenie. Odpowiedź,
                podtrzymanie albo zmiana decyzji, zawsze z wyjaśnieniem. Odwołanie
                od jednej decyzji składa się raz, więc napisz od razu wszystko,
                co ważne.
            </p>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Wyślij odwołanie</button>
            </div>
        </form>
    @endif
</x-layout>
