@php
    $razem = $steps->count();
    $adresPrzepisu = route('recipes.show', $recipe->slug);
    $adresGotowania = route('cooking.show', $recipe->slug);
    $mojeId = auth()->id();
@endphp
<x-layout :title="'Gotujemy razem: '.$recipe->title" :noindex="true">
    {{--
        WSPÓLNE GOTOWANIE (#2385) — EKRAN SESJI.

        Jedna kolumna, od góry: kto gotuje, ile zrobione, lista kroków z jednym
        dużym przyciskiem przy każdym, potem składniki, a na dole to, co robi
        tylko gospodarz. Wszystko zwykłymi formularzami — działa bez
        JavaScriptu; skrypt tylko co jakiś czas pyta o numer rewizji i, gdy
        ktoś z sesji coś zmienił, odkrywa pas z odnośnikiem „Odśwież”
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
             data-postep-komunikat-zmiana="Ktoś z sesji zmienił postęp albo skład sesji."
             data-postep-komunikat-koniec="Ta sesja już się skończyła albo nie masz do niej dostępu.">
            <p class="m-0" data-postep-tekst>Ktoś z sesji zmienił postęp albo skład sesji.</p>
            <a class="btn btn-secondary" href="{{ route('wspolne-gotowanie.show', $sesja) }}">Odśwież</a>
        </div>

        <section class="panel-formularza" aria-labelledby="wg-kto">
            <h2 id="wg-kto" class="mt-0">Kto gotuje</h2>
            <ul class="stack list-none p-0 m-0">
                <li><strong>{{ $gospodarz->displayName() }}</strong> — gospodarz{{ $jestGospodarzem ? ' (to Ty)' : '' }}</li>
                @forelse($pomocnicy as $pomocnik)
                    <li><strong>{{ $pomocnik->displayName() }}</strong> — pomocnik{{ $pomocnik->getKey() === $mojeId ? ' (to Ty)' : '' }}</li>
                @empty
                    <li>Pomocników jeszcze nie ma.</li>
                @endforelse
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
                <h2 id="wg-zapros" class="mt-0">Zaproś pomocnika ({{ $pomocnicy->count() }} z {{ $maxPomocnikow }})</h2>

                @if($ostrzezenieOWidocznosci)
                    <p class="notice" role="note">{{ $ostrzezenieOWidocznosci }}</p>
                @endif

                <p class="m-0">Jeden link wpuści do {{ $maxPomocnikow }} osób i wygasa po {{ (int) config('kuking.wspolne_gotowanie.link_godziny') }} godzinach. Możesz go odwołać w każdej chwili. Kto go otworzy, musi się zalogować, widzieć ten przepis i potwierdzić, że dołącza. Pomocnicy mogą odhaczać kroki; zapraszać, usuwać kogoś i kończyć sesję możesz tylko Ty.</p>

                <p class="notice" role="note">Każdy, kto dostanie ten link, może dołączyć — do {{ $maxPomocnikow }} osób.</p>

                @if(session('link_zaproszenia'))
                    <div class="field">
                        <label class="field-label" for="wg-link">Twój link — skopiuj go i wyślij osobom, które zapraszasz</label>
                        <input class="field-input" id="wg-link" type="text" readonly value="{{ session('link_zaproszenia') }}" data-link-zaproszenia>
                        <p class="field-help">Ten link pokazujemy tylko teraz. Gdy go zgubisz, utwórz nowy — poprzedni przestanie działać.</p>
                    </div>
                @endif
                @error('link')
                    <p class="field-error" role="alert">{{ $message }}</p>
                @enderror

                @if($oczekujace)
                    <p class="meta m-0">Jest działający link (ważny do {{ \App\Support\Czas::data($oczekujace->expires_at, 'j F, H:i') }}).</p>
                    <x-confirm-button
                        :action="route('wspolne-gotowanie.link.destroy', $sesja)"
                        label="Odwołaj link"
                        question="Odwołać ten link? Nikt już z niego nie dołączy; osoby, które są już w sesji, zostają." />
                @endif

                @if($jestMiejsce)
                    <form method="POST" action="{{ route('wspolne-gotowanie.link.store', $sesja) }}">
                        @csrf
                        <button class="btn btn-primary" type="submit">{{ $oczekujace ? 'Utwórz nowy link (stary przestanie działać)' : 'Utwórz link' }}</button>
                    </form>
                @else
                    <p class="m-0">W sesji jest już komplet pomocników. Żeby zaprosić kogoś innego, najpierw usuń jednego z nich z sesji.</p>
                @endif
            </section>

            @if($pomocnicy->isNotEmpty())
                <section class="panel-formularza stack" aria-labelledby="wg-pomocnik">
                    <h2 id="wg-pomocnik" class="mt-0">Pomocnicy</h2>
                    @foreach($pomocnicy as $pomocnik)
                        <div class="stack">
                            <p class="m-0"><strong>{{ $pomocnik->displayName() }}</strong></p>
                            <x-confirm-button
                                :action="route('wspolne-gotowanie.pomocnik.destroy', ['cookingSession' => $sesja, 'user' => $pomocnik->getKey()])"
                                label="Odbierz dostęp pomocnikowi"
                                question="Odebrać tej osobie dostęp do sesji? Odhaczone kroki zostaną."
                                :name="$pomocnik->displayName()" />
                        </div>
                    @endforeach
                </section>
            @endif

            <div class="danger-zone stack">
                @if($zrobioneLiczba > 0)
                    <x-confirm-button
                        :action="route('wspolne-gotowanie.od-poczatku', $sesja)"
                        method="POST"
                        label="Zacznij od początku"
                        question="Usunąć odhaczenia wszystkich kroków dla wszystkich osób w sesji? Przepis zostaje bez zmian." />
                @endif
                <x-confirm-button
                    :action="route('wspolne-gotowanie.destroy', $sesja)"
                    label="Zakończ sesję"
                    question="Zakończyć sesję? Wspólny postęp i link zostaną usunięte, a pomocnicy stracą dostęp. Przepis i Twoje konto zostają bez zmian." />
            </div>
        @else
            <div class="danger-zone stack">
                <x-confirm-button
                    :action="route('wspolne-gotowanie.leave', $sesja)"
                    label="Wyjdź z sesji"
                    question="Wyjść z tej sesji? Odhaczone kroki zostaną u gospodarza, a Ty stracisz dostęp do sesji." />
            </div>
        @endif

        <p class="meta">Sesja jest prywatna dla osób, które w niej gotują: gospodarza i najwyżej {{ $maxPomocnikow }} pomocników. Nie ma w niej wiadomości ani publikacji, a zakończenie albo wygaśnięcie usuwa jej dane. Nie zapisuje też „Ugotowałem” — to robisz osobno na stronie przepisu.</p>
    </article>
</x-layout>
