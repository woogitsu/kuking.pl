<x-layout title="Tablica na dziś — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Tablica na dziś" />

    <h1><x-kuking-word forma="i" /> na dziś</h1>
    <p class="mb-5">
        Zaznacz kilka osób i kilka wpisów, które dziś warto pokazać.
        Jeśli nic nie zaznaczysz, tablica dobierze treści sama — chronologicznie,
        maksymalnie jeden wpis od osoby.
    </p>

    <p class="notice">
        To nie jest ranking. Nie zaznaczaj „najlepszych" — zaznacz to, co ktoś
        chciałby zobaczyć. Dobrym wyborem jest pierwszy wpis nowej osoby.
    </p>

    <x-error-summary />

    @if($niedostepne > 0)
        <p class="notice">Niedostępne wyróżnienia zostaną pominięte przy zapisie.</p>
    @endif

    {{-- Panel na `<form>`, nie na sekcjach — ten sam powód co w
         `pages/recipes/create.blade.php`. Tutaj dochodzi trzeci: obie sekcje
         mają gałąź `@empty` („w ostatnich 7 dniach nikt nic nie opublikował"),
         a pusty tydzień to normalny stan panelu, nie awaria. Mocna obwódka
         obiecywałaby wtedy pola, których nie ma. --}}
    <form class="panel-formularza" method="POST" action="{{ route('admin.daily-board') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="_board_form" value="1">

        <div class="field">
            <label for="f-szukaj">Znajdź osobę po nazwie lub pseudonimie</label>
            <input class="field-input" id="f-szukaj" name="szukaj" value="{{ $szukaj }}" maxlength="100">
            <x-blad-grupy name="szukaj" />
            <button class="btn btn-secondary" type="submit" name="przegladaj" value="1">Szukaj osób</button>
            <p class="field-help">Pokazujemy do 40 osób. Zaznaczenia i notatki przy wybranych osobach zostają podczas szukania. Zatwierdź je przyciskiem „Zapisz tablicę na dziś”.</p>
        </div>

        <section class="form-section" id="f-osoby" tabindex="-1" @if($errors->has('osoby')) aria-invalid="true" aria-describedby="f-osoby-error" @endif>
            <h2 class="form-section-title">Osoby</h2>
            <p class="meta">Najwyżej 6. Przy każdej możesz dopisać jedno zdanie — pokaże się pod jej kartą.</p>
            <x-blad-grupy name="osoby" />

            @forelse($osoby as $osoba)
                <div class="wiersz-listy">
                    <label class="choice" for="osoba-{{ $osoba->getKey() }}">
                        <input id="osoba-{{ $osoba->getKey() }}" type="checkbox" name="osoby[]"
                               value="{{ $osoba->getKey() }}"
                               @checked(in_array($osoba->getKey(), $wybraneOsoby, true))>
                        <span class="flex gap-3 items-center flex-1">
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
                        <label for="f-notatki-{{ $osoba->getKey() }}">
                            Jedno zdanie o {{ $osoba->displayName() }}
                        </label>
                        <input class="field-input" id="f-notatki-{{ $osoba->getKey() }}"
                               name="notatki[{{ $osoba->getKey() }}]" type="text" maxlength="300"
                               @if($errors->has('notatki.'.$osoba->getKey())) aria-invalid="true" aria-describedby="f-notatki-{{ $osoba->getKey() }}-error" @endif
                               value="{{ $notatki[$osoba->getKey()] ?? '' }}"
                               placeholder="Halina pierwszy raz pokazała swój chleb">
                        <x-blad-grupy :name="'notatki.'.$osoba->getKey()" />
                    </div>
                </div>
            @empty
                <p class="meta">Nie znaleziono osób. Spróbuj wpisać inną nazwę lub pseudonim.</p>
            @endforelse
        </section>

        <section class="form-section" id="f-wpisy" tabindex="-1" @if($errors->has('wpisy')) aria-invalid="true" aria-describedby="f-wpisy-error" @endif>
            <h2 class="form-section-title">Wpisy z ostatnich 7 dni</h2>
            <p class="meta">Dzisiejsze wyróżnienia są na początku, także te starsze niż tydzień.</p>
            <p class="meta">Najwyżej 6.</p>
            <x-blad-grupy name="wpisy" />

            @forelse($wpisy as $wpis)
                <div class="wiersz-listy">
                    <label class="choice" for="wpis-{{ $wpis->getKey() }}">
                        <input id="wpis-{{ $wpis->getKey() }}" type="checkbox" name="wpisy[]"
                               value="{{ $wpis->getKey() }}"
                               @checked(in_array($wpis->getKey(), $wybraneWpisy, true))>
                        <span class="flex gap-3 items-start flex-1">
                            @php $foto = $wpis->media->first(fn ($m) => $m->isReady()); @endphp
                            @if($foto)
                                <img src="{{ $foto->url('thumb') }}" alt="" width="64" height="64"
                                     class="miniatura-64"
                                     loading="lazy">
                            @endif
                            <span class="min-w-0">
                                <span class="choice-label">{{ $wpis->author->displayName() }}</span>
                                <span class="choice-help">
                                    {{ \App\Support\Czas::dataLubNic($wpis->published_at, 'j F, H:i') }}
                                    @php $opis = $wpis->kind === \App\Models\Post::KIND_QUESTION ? $wpis->title : ($wpis->body ?: $wpis->recipe?->title); @endphp
                                    @if($opis) — {{ $wpis->kind === \App\Models\Post::KIND_QUESTION ? $opis : \Illuminate\Support\Str::limit($opis, 80) }} @endif
                                </span>
                            </span>
                        </span>
                    </label>

                    <div class="field mt-2">
                        <label for="f-notatki-{{ $wpis->getKey() }}">Jedno zdanie o tym wpisie</label>
                        <input class="field-input" id="f-notatki-{{ $wpis->getKey() }}"
                               name="notatki[{{ $wpis->getKey() }}]" type="text" maxlength="300"
                               @if($errors->has('notatki.'.$wpis->getKey())) aria-invalid="true" aria-describedby="f-notatki-{{ $wpis->getKey() }}-error" @endif
                               value="{{ $notatki[$wpis->getKey()] ?? '' }}"
                               placeholder="Chleb, nad którym Marek pracował dwa lata">
                        <x-blad-grupy :name="'notatki.'.$wpis->getKey()" />
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
