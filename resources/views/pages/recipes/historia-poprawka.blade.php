{{--
    „Zastosuj jako nową poprawkę” (#2525, V2, D-333 — paczka E): ODCZYTOWY podgląd.

    Samo otwarcie tego ekranu niczego nie zapisuje — ani przepisu, ani wersji.
    Zapis dopiero po zaznaczeniu części i kliknięciu przycisku. To NIE jest
    „przywróć wszystko” ani cofnięcie czasu: powstaje NOWA wersja, a
    dotychczasowe zostają. Zdjęć i pochodzenia nie przywracamy (lista niżej).
--}}
@php
    $d = $podglad['dane'];
    $s = $podglad['skladniki'];
    $k = $podglad['kroki'];
    $nic = ! $d['dostepna'] && ! $s['dostepna'] && ! $k['dostepna'];
    $zaznaczone = (array) old('sekcje', []);
    $stany = ['zmieni' => 'zmieni się', 'bez_zmian' => 'bez zmian', 'brak_danych' => 'brak danych w tej wersji — zostanie, jak jest dziś'];
@endphp
<x-layout :title="'Zastosuj wersję '.$wersja->version_number.' jako nową poprawkę'" :noindex="true">
    <p>
        <a class="btn btn-quiet" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Wróć do wersji {{ $wersja->version_number }}</a>
        <a class="btn btn-quiet" href="{{ route('recipes.history', $recipe->slug) }}">Wróć do historii zmian</a>
    </p>

    <h1>Zastosuj wersję {{ $wersja->version_number }} jako nową poprawkę</h1>
    <p>
        Zapisana {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}@if($wersja->change_note) — {{ $wersja->change_note }}@endif.
        To <strong>podgląd</strong>: dopóki nie klikniesz przycisku na dole, nic się nie zapisuje i publiczny przepis się nie zmienia.
    </p>
    <p class="notice">
        To nie jest cofnięcie czasu. Wybrane części z tej wersji zostaną zapisane w <strong>dzisiejszym przepisie</strong> jako <strong>nowa poprawka</strong>
        (nowa wersja po obecnej najnowszej). Wersja {{ $wersja->version_number }} i wszystkie inne zostają bez zmian, a wykonania innych osób nadal wskazują swoje wersje.
    </p>

    <x-error-summary :field-ids="['sekcje' => 'f-sekcja-dane', 'sekcje.*' => 'f-sekcja-dane', 'rewizja' => 'f-sekcja-dane']" />

    <section class="sekcja-strony" aria-labelledby="pp-nie">
        <h2 id="pp-nie">Czego nie przywracamy</h2>
        <ul>
            @foreach($podglad['nie_przywracamy'] as $pozycja)
                <li>{{ $pozycja }}</li>
            @endforeach
        </ul>
    </section>

    <form class="panel-formularza" method="POST" action="{{ route('recipes.history.apply.store', [$recipe->slug, $wersja->version_number]) }}" novalidate>
        @csrf
        <input type="hidden" name="rewizja" value="{{ $rewizja }}">

        <fieldset class="field @error('sekcje') has-error @enderror">
            <legend class="field-label">Co zastosować?</legend>

            <section class="sekcja-strony" aria-labelledby="pp-dane">
                <h2 id="pp-dane">Dane przepisu</h2>
                <dl class="historia-dane">
                    @foreach($d['wiersze'] as $wiersz)
                        <div class="historia-dane-wiersz">
                            <dt>{{ $wiersz['etykieta'] }} — {{ $stany[$wiersz['stan']] }}</dt>
                            <dd class="whitespace-pre-line">
                                @if($wiersz['stan'] === 'zmieni')
                                    Dziś: {{ $wiersz['teraz'] ?? 'nie podano' }}<br>Z wersji {{ $wersja->version_number }}: {{ $wiersz['z_wersji'] ?? 'nie podano' }}
                                @else
                                    {{ $wiersz['teraz'] ?? 'nie podano' }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
                @if($d['dostepna'])
                    <label class="wybor-dnia-opcja"><input type="checkbox" id="f-sekcja-dane" name="sekcje[]" value="dane" @checked(in_array('dane', $zaznaczone, true))><span>Zastosuj dane przepisu ({{ $d['zmian'] }} {{ \App\Support\Odmiana::rzeczownik($d['zmian'], 'zmiana', 'zmiany', 'zmian') }})</span></label>
                @else
                    <p class="meta meta-samodzielne">Dane przepisu są takie same jak dziś albo ta wersja ich nie zapisała — nie ma czego stosować.</p>
                @endif
            </section>

            <section class="sekcja-strony" aria-labelledby="pp-skladniki">
                <h2 id="pp-skladniki">Składniki</h2>
                @if($s['powod'] !== null)
                    <p class="meta meta-samodzielne">{{ $s['powod'] }}</p>
                @else
                    <p>Dziś: {{ count($s['teraz']) }} {{ \App\Support\Odmiana::rzeczownik(count($s['teraz']), 'pozycja', 'pozycje', 'pozycji') }}. W wersji {{ $wersja->version_number }}: {{ count($s['z_wersji']) }}.</p>
                    <details>
                        <summary class="btn btn-secondary">Pokaż składniki z wersji {{ $wersja->version_number }}</summary>
                        <ul class="ingredient-list">
                            @foreach($s['z_wersji'] as $linia)<li>{{ $linia }}</li>@endforeach
                        </ul>
                    </details>
                    @if($s['dostepna'])
                        <p class="meta">Cała lista składników zostanie zastąpiona (wiersze powstaną od nowa). Oznaczenie alergenów trzeba będzie sprawdzić ponownie, a składniki nie mają w zapisie wersji powiązań z bazą składników.</p>
                        <label class="wybor-dnia-opcja"><input type="checkbox" name="sekcje[]" value="skladniki" @checked(in_array('skladniki', $zaznaczone, true))><span>Zastosuj składniki z wersji {{ $wersja->version_number }}</span></label>
                    @else
                        <p class="meta meta-samodzielne">Składniki są takie same jak dziś — nie ma czego stosować.</p>
                    @endif
                @endif
            </section>

            <section class="sekcja-strony" aria-labelledby="pp-kroki">
                <h2 id="pp-kroki">Przygotowanie (kroki)</h2>
                @if($k['powod'] !== null)
                    <p class="notice">{{ $k['powod'] }}</p>
                @else
                    <p>Dziś: {{ count($k['teraz']) }} {{ \App\Support\Odmiana::rzeczownik(count($k['teraz']), 'krok', 'kroki', 'kroków') }}. W wersji {{ $wersja->version_number }}: {{ count($k['z_wersji']) }}.</p>
                    <details>
                        <summary class="btn btn-secondary">Pokaż kroki z wersji {{ $wersja->version_number }}</summary>
                        <ol class="step-list">
                            @foreach($k['z_wersji'] as $linia)<li><div><p class="m-0 whitespace-pre-line">{{ $linia }}</p></div></li>@endforeach
                        </ol>
                    </details>
                    @if($k['dostepna'])
                        <p class="meta">Wszystkie kroki zostaną zastąpione nowymi (bez zdjęć — dziś żaden krok nie ma zdjęcia). Zapamiętany postęp gotowania i odhaczenia we wspólnych sesjach przestaną pasować do nowych kroków.</p>
                        <label class="wybor-dnia-opcja"><input type="checkbox" name="sekcje[]" value="kroki" @checked(in_array('kroki', $zaznaczone, true))><span>Zastosuj kroki z wersji {{ $wersja->version_number }}</span></label>
                    @else
                        <p class="meta meta-samodzielne">Kroki są takie same jak dziś — nie ma czego stosować.</p>
                    @endif
                @endif
            </section>

            @error('sekcje')<p class="field-error">{{ $message }}</p>@enderror
        </fieldset>

        @if($nic)
            <p class="notice">Nic z tej wersji nie różni się od dzisiejszego przepisu (albo nie da się tego bezpiecznie przywrócić), więc nie ma czego stosować.</p>
        @else
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Zastosuj zaznaczone jako nową poprawkę</button>
                <a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Nie stosuj</a>
            </div>
            <p class="meta">Zaznaczone części staną się publiczne od razu, tak jak przy zwykłym zapisaniu zmian w przepisie.</p>
        @endif
    </form>
</x-layout>
