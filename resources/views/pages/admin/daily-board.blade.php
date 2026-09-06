<x-layout title="kuKINGi na dziś" :noindex="true">
    <h1><x-kuking-word forma="i" /> na dziś</h1>
    <p class="mb-5">
        Zaznacz kilka osób i kilka dań, które dziś warto pokazać.
        Jeśli nic nie zaznaczysz, tablica dobierze treści sama — chronologicznie,
        maksymalnie jedno danie od osoby.
    </p>

    <p class="notice">
        To nie jest ranking. Nie zaznaczaj „najlepszych" — zaznacz to, co ktoś
        chciałby zobaczyć. Dobrym wyborem jest pierwszy wpis nowej osoby.
    </p>

    <x-error-summary />

    <form method="POST" action="{{ route('admin.daily-board') }}">
        @csrf
        @method('PUT')

        <section class="form-section card">
            <h2 class="form-section-title">Osoby</h2>
            <p class="meta">Najwyżej 6. Przy każdej możesz dopisać jedno zdanie — pokaże się pod jej kartą.</p>

            @forelse($osoby as $osoba)
                <div style="border-bottom:1px solid var(--color-border); padding:var(--spacing-3) 0;">
                    <label class="choice" for="osoba-{{ $osoba->getKey() }}">
                        <input id="osoba-{{ $osoba->getKey() }}" type="checkbox" name="osoby[]"
                               value="{{ $osoba->getKey() }}"
                               @checked(in_array($osoba->getKey(), $wybraneOsoby, true))>
                        <span style="display:flex; gap:var(--spacing-3); align-items:center; flex:1;">
                            <x-avatar :user="$osoba" :size="44" />
                            <span>
                                <span class="choice-label">{{ $osoba->displayName() }}</span>
                                <span class="choice-help">&#64;{{ $osoba->profile->username }}
                                    @if($osoba->profile->speciality) · {{ $osoba->profile->speciality }} @endif
                                </span>
                            </span>
                        </span>
                    </label>

                    <div class="field mt-2">
                        <label for="nota-{{ $osoba->getKey() }}" class="visually-hidden">
                            Jedno zdanie o {{ $osoba->displayName() }}
                        </label>
                        <input class="field-input" id="nota-{{ $osoba->getKey() }}"
                               name="notatki[{{ $osoba->getKey() }}]" type="text" maxlength="300"
                               value="{{ $notatki[$osoba->getKey()] ?? '' }}"
                               placeholder="Halina pierwszy raz pokazała swój chleb">
                    </div>
                </div>
            @empty
                <p class="meta">Nie ma jeszcze nikogo, kto coś opublikował.</p>
            @endforelse
        </section>

        <section class="form-section card">
            <h2 class="form-section-title">Dania z ostatnich 7 dni</h2>
            <p class="meta">Najwyżej 6.</p>

            @forelse($wpisy as $wpis)
                <div style="border-bottom:1px solid var(--color-border); padding:var(--spacing-3) 0;">
                    <label class="choice" for="wpis-{{ $wpis->getKey() }}">
                        <input id="wpis-{{ $wpis->getKey() }}" type="checkbox" name="wpisy[]"
                               value="{{ $wpis->getKey() }}"
                               @checked(in_array($wpis->getKey(), $wybraneWpisy, true))>
                        <span style="display:flex; gap:var(--spacing-3); align-items:flex-start; flex:1;">
                            @php $foto = $wpis->media->first(fn ($m) => $m->isReady()); @endphp
                            @if($foto)
                                <img src="{{ $foto->url('thumb') }}" alt="" width="64" height="64"
                                     style="width:64px;height:64px;object-fit:cover;border-radius:var(--radius-sm);flex:none;"
                                     loading="lazy">
                            @endif
                            <span class="min-w-0">
                                <span class="choice-label">{{ $wpis->author->displayName() }}</span>
                                <span class="choice-help">
                                    {{ \App\Support\Czas::dataLubNic($wpis->published_at, 'j F, H:i') }}
                                    @if($wpis->body) — {{ \Illuminate\Support\Str::limit($wpis->body, 80) }} @endif
                                </span>
                            </span>
                        </span>
                    </label>

                    <div class="field mt-2">
                        <label for="nota-{{ $wpis->getKey() }}" class="visually-hidden">Jedno zdanie o tym wpisie</label>
                        <input class="field-input" id="nota-{{ $wpis->getKey() }}"
                               name="notatki[{{ $wpis->getKey() }}]" type="text" maxlength="300"
                               value="{{ $notatki[$wpis->getKey()] ?? '' }}"
                               placeholder="Chleb, nad którym Marek pracował dwa lata">
                    </div>
                </div>
            @empty
                <p class="meta">W ostatnich 7 dniach nikt nic nie opublikował.</p>
            @endforelse
        </section>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz tablicę na dziś</button>
            <a class="btn btn-quiet" href="{{ route('home') }}">Zobacz, jak wygląda</a>
        </div>
    </form>

    <div class="danger-zone">
        <h2>Wyczyść dzisiejszy wybór</h2>
        <p>Tablica wróci do trybu automatycznego i dobierze treści sama.</p>
        <x-confirm-button
            :action="route('admin.daily-board')"
            label="Wyczyść dzisiejszy wybór"
            question="Wyczyścić tablicę na dziś? Wróci do trybu automatycznego." />
    </div>
</x-layout>
