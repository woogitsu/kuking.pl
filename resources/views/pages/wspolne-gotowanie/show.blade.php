@php
    $razem = $steps->count();
    $adresPrzepisu = route('recipes.show', $recipe->slug);
    $adresGotowania = route('cooking.show', $recipe->slug);
    $pomocnik = $pomocnicy->first();
@endphp
<x-layout :title="'Gotujemy razem: '.$recipe->title" :noindex="true">
    {{--
        WSPÓLNE GOTOWANIE (#2385) — EKRAN SESJI.

        Jedna kolumna, od góry: kto gotuje, ile zrobione, lista kroków z jednym
        dużym przyciskiem przy każdym, potem składniki, a na dole to, co robi
        tylko gospodarz. Wszystko zwykłymi formularzami — działa bez
        JavaScriptu; skrypt tylko co jakiś czas pyta o numer rewizji i, gdy
        druga osoba coś zmieniła, odkrywa pas z odnośnikiem „Odśwież”
        (D-053: bez skryptu pas zostaje ukryty, a przycisk „Odśwież” stoi
        zawsze). Bez WebSocketów i bez automatycznego przeładowania — osoba
        czytająca krok nie traci miejsca.

        Stan słowem, nie kolorem: „Zrobione” / „Do zrobienia”.
        Minutniki są osobne dla każdej osoby (tak jak w trybie gotowania).
    --}}
    <article class="stack max-w-[38rem] mx-auto">
        <h1 class="m-0">Gotujemy razem: {{ $recipe->title }}</h1>

        <x-error-summary />

        <div class="cook-sync-zmiana stack" role="status" hidden
             data-postep-synchronizacja data-postep-rewizja="{{ $sesja->revision }}"
             data-postep-adres="{{ route('wspolne-gotowanie.stan', $sesja) }}"
             data-postep-komunikat-zmiana="Druga osoba zmieniła postęp albo skład sesji."
             data-postep-komunikat-koniec="Ta sesja już się skończyła albo nie masz do niej dostępu.">
            <p class="m-0" data-postep-tekst>Druga osoba zmieniła postęp albo skład sesji.</p>
            <a class="btn btn-secondary" href="{{ route('wspolne-gotowanie.show', $sesja) }}">Odśwież</a>
        </div>

        <section class="panel-formularza" aria-labelledby="wg-kto">
            <h2 id="wg-kto" class="mt-0">Kto gotuje</h2>
            <ul class="stack list-none p-0 m-0">
                <li><strong>{{ $gospodarz->displayName() }}</strong> — gospodarz{{ $jestGospodarzem ? ' (to Ty)' : '' }}</li>
                @if($pomocnik)
                    <li><strong>{{ $pomocnik->displayName() }}</strong> — pomocnik{{ $pomocnik->getKey() === auth()->id() ? ' (to Ty)' : '' }}</li>
                @else
                    <li>Pomocnika jeszcze nie ma.</li>
                @endif
            </ul>
            <p class="meta mb-0">Zrobione kroki: <strong>{{ $zrobioneLiczba }} z {{ $razem }}</strong>. Sesja wygasa {{ \App\Support\Czas::data($sesja->expires_at, 'j F, H:i') }} — wtedy znika razem z odhaczeniami.</p>
            <p class="m-0"><a class="btn btn-secondary" href="{{ route('wspolne-gotowanie.show', $sesja) }}">Odśwież</a></p>
        </section>

        @unless($mozeZapisywac)
            <p class="notice" role="note">Konto jest zawieszone, więc możesz tylko czytać. Odhaczać kroki nie możesz.</p>
        @endunless

        <section aria-labelledby="wg-kroki">
            <h2 id="wg-kroki" class="mt-0">Kroki</h2>
            <ol class="stack list-none p-0 m-0">
                @foreach($steps as $i => $krok)
                    @php($wpis = $zrobione[(string) $krok->getKey()] ?? null)
                    @php($etykietaMinutnika = $krok->timerLabel(afterNa: true))
                    <li id="krok-{{ $krok->getKey() }}" class="card stack" data-krok-zrobiony="{{ $wpis ? '1' : '0' }}">
                        <p class="meta m-0">Krok {{ $i + 1 }} z {{ $razem }}</p>
                        <p class="cook-step-tekst m-0">{{ $krok->instruction }}</p>
                        @if($etykietaMinutnika)
                            <p class="meta m-0">Ustaw sobie kuchenny minutnik na {{ $etykietaMinutnika }}. Minutniki nie są wspólne — każda osoba ustawia własny.</p>
                        @endif
                        @if($wpis)
                            <p class="m-0"><strong>Zrobione</strong> — odhaczone przez: {{ $wpis['kto'] ?? 'osobę, która usunęła konto' }}, {{ \App\Support\Czas::data($wpis['kiedy'], 'H:i') }}.</p>
                        @else
                            <p class="m-0"><strong>Do zrobienia</strong></p>
                        @endif
                        @if($mozeZapisywac)
                            <form method="POST" action="{{ route('wspolne-gotowanie.krok', $sesja) }}">
                                @csrf
                                <input type="hidden" name="krok_id" value="{{ $krok->getKey() }}">
                                <input type="hidden" name="rewizja" value="{{ $sesja->revision }}">
                                @if($wpis)
                                    <input type="hidden" name="zrobiono" value="0">
                                    <button type="submit" class="btn btn-secondary">Cofnij: ten krok nie jest zrobiony</button>
                                @else
                                    <input type="hidden" name="zrobiono" value="1">
                                    <button type="submit" class="btn btn-primary">Zrobione</button>
                                @endif
                            </form>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>

        <details class="cook-ingredients">
            <summary>Składniki ({{ $recipe->ingredients->count() }})</summary>
            @if($recipe->ingredients->isEmpty())
                <p class="meta">Autor jeszcze nie dodał składników.</p>
            @else
                @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($recipe->ingredients) as $grupa)
                    @if($grupa['nazwa'] !== null)
                        <h3 class="naglowek-grupy">{{ $grupa['nazwa'] }}</h3>
                    @endif
                    <ul class="ingredient-list">
                        @foreach($grupa['skladniki'] as $skladnik)
                            <li>
                                {{ $skladnik->ingredient_text }}
                                @if($skladnik->no_amount && ! str_contains(mb_strtolower($skladnik->ingredient_text), 'do smaku'))<span class="meta"> — do smaku</span>@endif
                                @if($skladnik->note)<span class="meta"> — {{ $skladnik->note }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
        </details>

        <p class="m-0"><a class="btn btn-secondary" href="{{ $adresGotowania }}">Gotuj sam w trybie krok po kroku</a></p>
        <p class="m-0"><a class="btn btn-secondary" href="{{ $adresPrzepisu }}">Wróć do przepisu</a></p>

        @if($jestGospodarzem)
            <section class="panel-formularza stack" aria-labelledby="wg-zapros">
                <h2 id="wg-zapros" class="mt-0">Zaproś pomocnika</h2>

                @if($ostrzezenieOWidocznosci)
                    <p class="notice" role="note">{{ $ostrzezenieOWidocznosci }}</p>
                @endif

                <p class="m-0">Link działa raz i wygasa po {{ (int) config('kuking.wspolne_gotowanie.link_godziny') }} godzinach. Kto go otworzy, musi się zalogować, widzieć ten przepis i potwierdzić, że dołącza. Pomocnik może odhaczać kroki; zaprosić kogoś albo zakończyć sesję możesz tylko Ty.</p>

                @if(session('link_zaproszenia'))
                    <div class="field">
                        <label class="field-label" for="wg-link">Twój link — skopiuj go i wyślij jednej osobie</label>
                        <input class="field-input" id="wg-link" type="text" readonly value="{{ session('link_zaproszenia') }}" data-link-zaproszenia>
                        <p class="field-help">Ten link pokazujemy tylko teraz. Gdy go zgubisz, utwórz nowy — poprzedni przestanie działać.</p>
                    </div>
                @endif
                @error('link')
                    <p class="field-error" role="alert">{{ $message }}</p>
                @enderror

                @if($oczekujace)
                    <p class="meta m-0">Jest link, na który nikt jeszcze nie odpowiedział (ważny do {{ \App\Support\Czas::data($oczekujace->expires_at, 'j F, H:i') }}).</p>
                    <x-confirm-button
                        :action="route('wspolne-gotowanie.link.destroy', $sesja)"
                        label="Odwołaj link"
                        question="Odwołać ten link? Nikt już z niego nie dołączy." />
                @endif

                @if($jestMiejsce)
                    <form method="POST" action="{{ route('wspolne-gotowanie.link.store', $sesja) }}">
                        @csrf
                        <button class="btn btn-primary" type="submit">{{ $oczekujace ? 'Utwórz nowy link (stary przestanie działać)' : 'Utwórz link' }}</button>
                    </form>
                @else
                    <p class="m-0">Sesja ma już pomocnika. Żeby zaprosić kogoś innego, najpierw usuń obecnego pomocnika z sesji.</p>
                @endif
            </section>

            @if($pomocnik)
                <section class="panel-formularza stack" aria-labelledby="wg-pomocnik">
                    <h2 id="wg-pomocnik" class="mt-0">Pomocnik</h2>
                    <p class="m-0"><strong>{{ $pomocnik->displayName() }}</strong></p>
                    <x-confirm-button
                        :action="route('wspolne-gotowanie.pomocnik.destroy', ['cookingSession' => $sesja, 'user' => $pomocnik->getKey()])"
                        label="Odbierz dostęp pomocnikowi"
                        question="Odebrać tej osobie dostęp do sesji? Odhaczone kroki zostaną."
                        :name="$pomocnik->displayName()" />
                </section>
            @endif

            <div class="danger-zone stack">
                @if($zrobioneLiczba > 0)
                    <x-confirm-button
                        :action="route('wspolne-gotowanie.od-poczatku', $sesja)"
                        method="POST"
                        label="Zacznij od początku"
                        question="Usunąć odhaczenia wszystkich kroków dla obu osób? Przepis zostaje bez zmian." />
                @endif
                <x-confirm-button
                    :action="route('wspolne-gotowanie.destroy', $sesja)"
                    label="Zakończ sesję"
                    question="Zakończyć sesję? Wspólny postęp i link zostaną usunięte, a druga osoba straci dostęp. Przepis i Twoje konto zostają bez zmian." />
            </div>
        @else
            <div class="danger-zone stack">
                <x-confirm-button
                    :action="route('wspolne-gotowanie.leave', $sesja)"
                    label="Wyjdź z sesji"
                    question="Wyjść z tej sesji? Odhaczone kroki zostaną u gospodarza, a Ty stracisz dostęp do sesji." />
            </div>
        @endif

        <p class="meta">Sesja jest prywatna dla dwóch osób. Nie ma w niej wiadomości ani publikacji, a zakończenie albo wygaśnięcie usuwa jej dane. Nie zapisuje też „Ugotowałem” — to robisz osobno na stronie przepisu.</p>
    </article>
</x-layout>
