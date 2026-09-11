{{--
    WEJŚCIE KONTEM GOOGLE — blok na ekranie logowania i rejestracji
    (issue #258, D-069).

    NIC SIĘ NIE RENDERUJE, GDY TA DROGA NIE DZIAŁA (brak `GOOGLE_CLIENT_ID`
    albo `GOOGLE_CLIENT_SECRET`, albo `KUKING_WEJSCIE_GOOGLE=false`). Jedno
    pytanie — `Google::dziala()` — odpowiada tak samo widokowi i kontrolerowi,
    więc nie da się dojść do stanu „przycisk jest, droga nie działa". To jest
    dokładnie ten martwy przycisk, którego zakazuje D-053, i dlatego to
    pytanie ma jedno miejsce, a nie dwa (`App\Support\Google`).

    UX 50+ (docs/UX_50_PLUS.md):

    - IKONA NIE JEST TU JEDYNYM OPISEM AKCJI — nie ma jej wcale. Na
      przycisku stoi zdanie „Wejdź kontem Google", a nie kolorowe „G",
      którego osoba 65-letnia nie musi kojarzyć z niczym.
    - Przycisk jest zwykłym odnośnikiem w klasie `.btn`, więc ma te same
      48 px wysokości i ten sam rozmiar tekstu co „Zaloguj się".
    - Nad przyciskiem stoi zdanie mówiące, PO CO to jest i co się stanie po
      kliknięciu (przeniesienie na stronę Google). Przeniesienie na obcą
      domenę bez ostrzeżenia wygląda dla tej grupy jak phishing, przed
      którym ostrzegają banki.
    - Pod przyciskiem stoi zdanie o tym, czego NIE bierzemy z Google —
      bo pierwsze pytanie po „zaloguj się przez Google" brzmi „a co oni
      o mnie zobaczą".

    Bez JavaScriptu działa w całości: to jest odnośnik i przekierowania po
    stronie serwera. Ta droga jest więc jedyną na ekranie logowania, która
    NIE potrzebuje skryptu (Turnstile go potrzebuje, D-050) — i to jest
    argument za nią, nie przeciw.
--}}
@props(['naglowek' => 'Masz konto Google? Wejdź jednym kliknięciem'])

@if(\App\Support\Google::dziala())
    <div class="sekcja-strony mt-6">
        <h2>{{ $naglowek }}</h2>
        <p>
            Nie musisz wymyślać ani pamiętać hasła. Przeniesiemy Cię na stronę Google,
            tam potwierdzisz, że to Ty, i wrócisz do Kuking.
        </p>
        <p class="form-actions">
            <a class="btn btn-secondary" href="{{ route('google.start') }}">Wejdź kontem Google</a>
        </p>
        <p class="meta">
            Z Google dostajemy tylko Twój adres e-mail i imię. Nie bierzemy zdjęcia,
            nie bierzemy listy kontaktów i nie mamy dostępu do Twojej poczty.
        </p>
    </div>
@endif
