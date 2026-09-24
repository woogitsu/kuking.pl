{{--
    „Zdejmij z urzędu” — treść, której nikt nie zgłosił (G31, D-251).

    Formularz ma te same dwa pola, które niosą uzasadnienie z DSA art. 17
    przy każdej innej decyzji: podstawę z zamkniętej listy
    (`PodstawaDecyzji`) i wiadomość do autora — tu OBOWIĄZKOWĄ, bo przy
    decyzji z urzędu nie ma zgłoszenia, które tłumaczyłoby sprawę za nas.
    Działa bez JavaScriptu: zwykły <select> i zwykłe pola. Potwierdzeniem
    jest sam ten ekran — przycisk przy treści tylko tu prowadzi (GET), a
    usuwa dopiero przycisk niżej. Bez `<x-confirm-button>`: jego zwinięty
    `<details>` chowałby po nieudanej walidacji pola razem z błędami.
--}}
<x-layout :title="'Zdejmij z urzędu: '.$opis->nazwa.' — Panel moderacji'" :noindex="true">
    <x-panel-moderacji ekran="Zdejmij z urzędu" />

    <h1>Zdejmij z urzędu: {{ $opis->nazwa }}</h1>

    @if($opis->cytat)
        <p class="cel-zgloszenia-cytat mb-4">{{ $opis->cytat }}</p>
    @endif

    <x-error-summary />

    @if($zdjeta)
        <p class="notice" role="status">
            Ta treść jest już zdjęta z serwisu — nie ma czego zdejmować drugi raz.
            Jeśli zdjęła ją moderacja, decyzję znajdziesz w historii konta autora.
        </p>
    @else
        <p>
            Nikt tego nie zgłosił — decyzję podejmujesz z własnego przeglądu serwisu.
            Autor dostanie powiadomienie z podstawą i Twoim uzasadnieniem
            i będzie mógł się odwołać tak samo jak od decyzji ze zgłoszenia.
        </p>
        @if($typ === 'comment')
            <p class="meta">
                Komentarz zniknie tak samo jak po decyzji „Usuń” ze zgłoszenia.
                Jeśli autor wygra odwołanie, komentarz wróci na swoje miejsce.
            </p>
        @endif

        @php($bladPodstawy = $errors->first('reason_code'))
        <div class="danger-zone">
            <form method="POST" action="{{ route('admin.z-urzedu.store', ['typ' => $typ, 'id' => $cel->getKey()]) }}">
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
                         help="Co konkretnie narusza zasady — własnymi słowami. Informację, że nikt tego nie zgłosił,
                               brak automatu i termin odwołania powiadomienie dopisze samo." />
                <x-field name="note" label="Notatka wewnętrzna" type="textarea" :rows="2"
                         help="Nieobowiązkowa. Widzi ją tylko moderacja." />

                <p class="meta">Treść zniknie z serwisu od razu. Wróci, jeśli autor się odwoła i przyznamy mu rację.</p>
                <button class="btn btn-danger" type="submit">Zdejmij tę treść</button>
            </form>
        </div>
    @endif

    @if($powrot)
        <p class="mt-4"><a class="btn btn-quiet" href="{{ $powrot }}">Wróć do treści</a></p>
    @endif
</x-layout>
