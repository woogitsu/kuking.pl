{{--
    POTWIERDZENIE „UKRYĆ WERSJĘ N?” (issue #2270). Zwykła strona z formularzem,
    bez JavaScriptu i bez okienka „czy na pewno”. Przycisk ukrycia stoi osobno,
    w strefie akcji zmieniających widok innych (AGENTS.md §5).

    MODERACJA (decyzja właściciela z 30.09.2026): ukrycie wersji przez
    moderację jest decyzją moderacyjną w rozumieniu DSA, więc formularz ma te
    same pola co „Zdejmij z urzędu” (`pages/admin/z-urzedu`): podstawę
    z zamkniętej listy, OBOWIĄZKOWE uzasadnienie dla autora i notatkę
    wewnętrzną. Autor ukrywa własną wersję jednym przyciskiem, bez tego.
--}}
<x-layout :title="'Ukryć wersję '.$wersja->version_number.'?'" :noindex="true">
    <div class="stack kolumna-czytania">
        <p><a class="btn btn-quiet" href="{{ route('recipes.history', $recipe->slug) }}">Wróć do historii zmian</a></p>

        <h1>Ukryć wersję {{ $wersja->version_number }}?</h1>
        <p class="text-lead">{{ $recipe->title }}</p>

        <x-error-summary />

        <div class="notice stack" id="skutek-ukrycia">
            <p class="m-0">
                Wersja {{ $wersja->version_number }} z {{ $wersja->created_at->locale('pl')->isoFormat('D MMMM YYYY') }}
                zniknie z historii zmian dla wszystkich poza autorem przepisu i moderacją.
                Porównanie zmian ją pominie i powie, że coś pominęło.
            </p>
            <p class="m-0">Sam przepis i pozostałe wersje się nie zmienią.</p>
            @if($strona === \App\Models\RecipeVersion::UKRYLA_MODERACJA)
                <p class="m-0">
                    Ukrywasz ją jako moderacja: autor zobaczy ją z napisem „Ukryta przez moderację”
                    i nie przywróci jej sam. Przywrócić ją może tylko moderacja.
                </p>
                <p class="m-0">
                    To decyzja moderacyjna, tak jak zdjęcie treści z urzędu. Autor dostanie powiadomienie
                    z podstawą i Twoim uzasadnieniem i będzie mógł się odwołać. Jeśli przyznamy mu rację,
                    wersja wróci do historii zmian.
                </p>
            @else
                <p class="m-0">W każdej chwili możesz ją przywrócić z historii zmian.</p>
            @endif
        </div>

        <p><a class="btn btn-secondary" href="{{ route('recipes.history.version', [$recipe->slug, $wersja->version_number]) }}">Najpierw zobacz wersję {{ $wersja->version_number }}</a></p>
        <p><a class="btn btn-secondary" href="{{ route('recipes.history', $recipe->slug) }}">Nie ukrywaj — wróć</a></p>

        @if($strona === \App\Models\RecipeVersion::UKRYLA_MODERACJA)
            @php($bladPodstawy = $errors->first('reason_code'))
            <form class="stack" method="POST" action="{{ route('recipes.history.hide.store', [$recipe->slug, $wersja->version_number]) }}">
                @csrf
                <div class="field @if($bladPodstawy) has-error @endif">
                    <label for="f-reason_code">
                        Podstawa decyzji <span class="meta">(wymagane)</span>
                    </label>
                    <span class="field-help" id="f-reason_code-help">
                        Autor zobaczy to jako „Podstawą tej decyzji jest punkt N zasad Kuking”.
                    </span>
                    <select class="field-input" id="f-reason_code" name="reason_code" required
                            aria-describedby="f-reason_code-help @if($bladPodstawy) f-reason_code-error @endif"
                            @if($bladPodstawy) aria-invalid="true" @endif>
                        <option value="">— wybierz podstawę —</option>
                        @foreach(\App\Domain\Moderation\PodstawaDecyzji::dlaFormularza() as $kod => $etykieta)
                            <option value="{{ $kod }}" @selected(old('reason_code') === $kod)>{{ $etykieta }}</option>
                        @endforeach
                    </select>
                    @if($bladPodstawy)
                        <span class="field-error" id="f-reason_code-error">{{ $bladPodstawy }}</span>
                    @endif
                </div>

                <x-field name="user_message" label="Uzasadnienie dla autora" type="textarea" :rows="4" required
                         help="Co konkretnie w tej wersji narusza zasady — własnymi słowami. Numer wersji i nazwę przepisu,
                               informację, że nikt tego nie zgłosił, i termin odwołania powiadomienie dopisze samo." />
                <x-field name="note" label="Notatka wewnętrzna" type="textarea" :rows="2"
                         help="Nieobowiązkowa. Widzi ją tylko moderacja." />

                <div class="danger-zone stack">
                    <button class="btn btn-danger" type="submit" aria-describedby="skutek-ukrycia">Tak, ukryj wersję {{ $wersja->version_number }}</button>
                </div>
            </form>
        @else
            <div class="danger-zone stack">
                <form method="POST" action="{{ route('recipes.history.hide.store', [$recipe->slug, $wersja->version_number]) }}">
                    @csrf
                    <button class="btn btn-danger" type="submit" aria-describedby="skutek-ukrycia">Tak, ukryj wersję {{ $wersja->version_number }}</button>
                </form>
            </div>
        @endif
    </div>
</x-layout>
