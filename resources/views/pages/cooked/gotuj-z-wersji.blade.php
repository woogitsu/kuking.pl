{{--
    „Gotuj z tej wersji” (#2491, V2, D-333 — paczka E). Prywatny, krokowy tryb
    z NIEZMIENNEJ migawki wersji przypiętej do własnego wykonania.

    Wszystko stąd — kroki, składniki, minutniki — pochodzi z migawki. Czego
    migawka nie ma, tego nie uzupełniamy dzisiejszym przepisem. Zdjęć nie
    pokazujemy. Odhaczenia żyją w sesji pod kluczem tej wersji (nie ruszają
    postępu bieżącego przepisu). Minutniki przeglądarki mają osobny klucz
    (`wersja-<wykonanie>`), więc nie mieszają się z gotowaniem bieżącej wersji.
--}}
@if($wersja === null)
    <x-layout title="Gotuj z tej wersji" :noindex="true">
        <p><a class="btn btn-quiet" href="{{ route('cooked.version', $event) }}">Wróć do wersji z tego gotowania</a></p>
        <h1>Gotuj z tej wersji</h1>
        <p class="notice">
            Tej wersji przepisu nie możemy Ci już pokazać. Mogła zostać usunięta przy porządkowaniu starych wersji
            albo przepis przestał być dla Ciebie dostępny. Twoje wykonanie, notatka i zdjęcia zostają bez zmian.
        </p>
        <p><a class="btn btn-secondary" href="{{ route('cooked.show', $event) }}">Wróć do wykonania</a></p>
    </x-layout>
@else
    @php
        $poprzedniKrok = $krok > 1 ? $krok - 1 : null;
        $nastepnyKrok = $krok > 0 && $krok < $razem ? $krok + 1 : null;
        $nazwa = $migawka->pole('title') ?? 'przepis';
        $dataWersji = $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY');
        $adres = fn (?int $numer = null): string => route('cooked.version.cook', array_filter(['cookedEvent' => $event, 'krok' => $numer], fn ($w) => $w !== null));
        $etykieta = $aktualny !== null ? $etykietaMinutnika($aktualny['timer_seconds']) : null;
        $pseudoSlug = 'wersja-'.$event->getKey();
    @endphp
    <x-layout :title="'Gotuję z wersji '.$wersja->version_number.': '.$nazwa" :noindex="true">
        <article class="stack max-w-[38rem] mx-auto">
            <div class="notice">
                <p class="mt-0"><strong>Gotujesz według starszej wersji {{ $wersja->version_number }} z {{ $dataWersji }}.</strong></p>
                <p>To wersja z Twojego wcześniejszego gotowania. Autor mógł później zmienić przepis, więc dzisiejszy przepis może się różnić. Widzisz tylko tekst i dane z tej wersji, bez zdjęć.</p>
                <p class="mb-0"><a class="btn btn-secondary" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do dzisiejszego przepisu</a></p>
            </div>

            <div class="cook-topbar">
                @if($razem > 0)
                    <p class="cook-progress" aria-live="polite">Krok {{ $krok }} z {{ $razem }}</p>
                @endif
                <a class="btn btn-secondary cook-exit" href="{{ route('cooked.version', $event) }}" data-minutniki-koniec>Zakończ gotowanie</a>
            </div>

            <details class="cook-ingredients">
                <summary>Składniki z tej wersji</summary>
                @php($skladniki = $migawka->skladniki())
                @if($skladniki === [])
                    <p class="meta meta-samodzielne">Ta wersja nie ma zapisanych składników.</p>
                @else
                    @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($skladniki) as $grupa)
                        @if($grupa['nazwa'] !== null)
                            <h3 class="naglowek-grupy">{{ $grupa['nazwa'] }}</h3>
                        @endif
                        <ul class="ingredient-list">
                            @foreach($grupa['skladniki'] as $skladnik)
                                <li>
                                    {{ $skladnik['text'] }}@if($skladnik['note']) <span class="meta"> — {{ $skladnik['note'] }}</span>@endif
                                    @if($skladnik['substitutes'])<span class="skladnik-zamiennik">Zamiast tego: {{ $skladnik['substitutes'] }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                @endif
            </details>

            @if($razem === 0)
                <p class="notice">Ta wersja nie ma opisanego przygotowania, więc nie ma kroków do przejścia. Możesz przeczytać jej dane na ekranie wersji albo wrócić do dzisiejszego przepisu.</p>
                <p><a class="btn btn-secondary" href="{{ route('cooked.version', $event) }}">Zobacz tę wersję w całości</a></p>
            @else
                <div class="cook-alarmy stack" data-alarmy-recipe="{{ $pseudoSlug }}" data-alarmy-krok="{{ $krok }}" data-alarmy-adres="{{ $adres() }}"
                     data-alarmy-kroki="{{ json_encode($tozsamoscKrokow, JSON_THROW_ON_ERROR) }}" hidden></div>

                <section class="cook-step" aria-label="Bieżący krok">
                    <p class="cook-step-numer" aria-hidden="true">Krok {{ $krok }} z {{ $razem }}</p>
                    @php($etapKroku = \App\Domain\Recipes\EtapyPrzygotowania::nazwaDlaKroku($kroki, $krok - 1))
                    @if($etapKroku !== null)
                        <p class="cook-step-etap">Etap: {{ $etapKroku }}</p>
                    @endif
                    <p class="cook-step-tekst">{{ $aktualny['instruction'] }}</p>

                    @if($etykieta)
                        <div class="cook-timer" data-timer-recipe="{{ $pseudoSlug }}" data-timer-krok="{{ $krok }}" data-timer-sekundy="{{ $aktualny['timer_seconds'] }}" data-timer-etykieta="{{ $etykieta }}"
                             data-timer-step-id="{{ $idKroku($krok - 1) }}" data-timer-fingerprint="{{ $odcisk($krok - 1) }}">
                            <p>Ustaw sobie kuchenny minutnik na {{ $etykieta }}.</p>
                            <button type="button" class="btn btn-secondary btn-cook cook-timer-start" hidden>
                                Uruchom minutnik w tej przeglądarce
                            </button>
                            <p class="cook-timer-odliczanie" role="timer" aria-label="Pozostały czas" aria-live="off" hidden></p>
                            <button type="button" class="btn btn-secondary btn-cook cook-timer-anuluj" hidden>
                                Anuluj minutnik
                            </button>
                            <p class="visually-hidden cook-timer-komunikat" aria-live="assertive"></p>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('cooked.version.cook.mark', $event) }}" class="cook-zaznacz">
                        @csrf
                        <input type="hidden" name="krok" value="{{ $krok }}">
                        <input type="hidden" name="zrobiono" value="{{ $krokZrobiony ? '0' : '1' }}">
                        <button type="submit" class="btn {{ $krokZrobiony ? 'btn-secondary' : 'btn-primary' }} btn-cook">
                            @if($krokZrobiony)
                                Zrobione ✓ — kliknij, żeby cofnąć
                            @else
                                Oznacz krok jako zrobiony
                            @endif
                        </button>
                    </form>
                </section>

                <nav class="cook-nav" aria-label="Nawigacja krokami tej wersji">
                    @if($nastepnyKrok)
                        <a class="btn btn-primary btn-cook" href="{{ $adres($nastepnyKrok) }}">Następny krok <span aria-hidden="true">→</span></a>
                    @endif
                    @if($poprzedniKrok)
                        <a class="btn btn-secondary btn-cook" href="{{ $adres($poprzedniKrok) }}"><span aria-hidden="true">←</span> Poprzedni krok</a>
                    @endif
                </nav>

                @if($nastepnyKrok === null)
                    <section class="cook-finish">
                        <h2 class="mt-0">To już ostatni krok.</h2>
                        <p>Koniec gotowania? Zapiszesz je jako nowe wykonanie, z jawnie wskazaną starszą wersją przepisu. Poprzednie wykonanie zostaje bez zmian.</p>
                        <a class="btn btn-primary btn-cook" href="{{ route('cooked.version.finish', $event) }}" data-minutniki-koniec>{{ \App\Support\Forma::dla(auth()->user(), 'Ugotowałam', 'Ugotowałem', 'Ugotowałem') }}</a>
                    </section>
                @endif

                @if($maPostep)
                    <div class="danger-zone">
                        <details class="confirm">
                            <summary class="btn btn-secondary">Zacznij od początku</summary>
                            <div class="confirm-body stack">
                                <p>Usunąć odhaczenia kroków tej wersji? Gotowanie dzisiejszego przepisu, inne wersje i zapisane wykonania zostaną bez zmian.</p>
                                <a class="btn btn-secondary" href="{{ $adres($krok) }}">Zostaw odhaczenia</a>
                                <form method="POST" action="{{ route('cooked.version.cook.restart', $event) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-danger">Usuń odhaczenia i zacznij od początku</button>
                                </form>
                            </div>
                        </details>
                    </div>
                @endif
            @endif
        </article>
    </x-layout>
@endif
