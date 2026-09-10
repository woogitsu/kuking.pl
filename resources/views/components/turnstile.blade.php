{{--
    Widget Cloudflare Turnstile — sprawdzenie „czy to człowiek", które
    w trybie Managed zwykle przechodzi bez klikania w cokolwiek.

    NIC SIĘ NIE RENDERUJE, GDY TURNSTILE NIE DZIAŁA (brak kluczy albo miejsce
    wyłączone w `config/kuking.php`). Jedno pytanie, `Turnstile::dziala()`,
    rozstrzyga to samo dla widgetu i dla reguły walidacji — nie da się mieć
    widgetu bez walidacji ani walidacji bez widgetu. Dotyczy to także
    `<noscript>` niżej: bez kluczy formularz przechodzi bez tokenu, więc
    straszenie wtedy człowieka brakiem JavaScriptu byłoby nieprawdą.

    UX 50+ (docs/UX_50_PLUS.md):

    - WIDGET NIE JEST JEDYNYM NOŚNIKIEM INFORMACJI. Nad nim stoi zdanie po
      polsku mówiące, co to jest i że zwykle nie trzeba nic robić — bez tego
      osoba 60+ widzi obcą ramkę w środku formularza i nie wie, czy czekać.
    - Nie ruszamy przycisku wysyłki ani układu formularza: to jest osobny
      blok NAD `.form-actions`, więc „Załóż konto" zostaje tam, gdzie był,
      i zostaje przy swoich 48 px.
    - Błąd (brak tokenu ALBO token odrzucony) pokazujemy PRZY tym bloku,
      a `<x-error-summary>` pokazuje ten sam tekst na górze formularza.
      Kotwica z podsumowania celuje w `id` niżej.

    `<noscript>` — NAJWAŻNIEJSZA CZĘŚĆ TEGO PLIKU, NIE OZDOBA
    Od 9 września 2026 brak tokenu ODRZUCA wysłanie (D-050, decyzja
    właściciela). Bez `<noscript>` osoba z wyłączonym skryptem klikałaby
    „Załóż konto" i dostawała komunikat o czymś, czego nie widzi na ekranie —
    czyli martwy przycisk i cicha utrata użytkownika. Zdanie jest tu OSOBNE
    DLA KAŻDEGO Z SZEŚCIU FORMULARZY (`Turnstile::zdanieBezJavaScriptu()`),
    bo człowiek ma się dowiedzieć, czego konkretnie nie da się teraz zrobić,
    a nie jakiej technologii wymagamy. Pod spodem stoi adres e-mail, bo dla
    kogoś, kto nie może włączyć JavaScriptu, jest to jedyna droga dalej —
    `/napisz-do-nas` nią nie jest, bo ma to samo sprawdzenie.

    `nonce` przy skrypcie jest wymagane: CSP tego serwisu nie ma
    `unsafe-inline` ani otwartego `script-src` (`ApplySecurityHeaders`).
--}}
@props(['miejsce'])

@if(\App\Support\Turnstile::dziala($miejsce))
    @php($blad = $errors->first(\App\Support\Turnstile::POLE))

    <div class="field @if($blad !== '') has-error @endif"
         id="f-{{ \App\Support\Turnstile::POLE }}">
        <p class="field-help">
            Zanim wyślesz, sprawdzamy, że formularza nie wypełnia automat.
            Zwykle dzieje się to samo i nie musisz nic robić.
        </p>

        <div class="cf-turnstile"
             data-sitekey="{{ \App\Support\Turnstile::kluczPubliczny() }}"
             data-language="pl"
             data-theme="light"
             data-size="normal"></div>

        <noscript>
            <div class="notice">
                <p>{{ \App\Support\Turnstile::zdanieBezJavaScriptu($miejsce) }}</p>
                <p>
                    Jeśli nie możesz włączyć JavaScriptu, napisz do nas na
                    <a href="mailto:{{ \App\Support\Turnstile::adresKontaktowy() }}">{{ \App\Support\Turnstile::adresKontaktowy() }}</a>
                    — odpisuje człowiek i załatwimy sprawę pocztą.
                </p>
            </div>
        </noscript>

        @if($blad !== '')
            <span class="field-error">{{ $blad }}</span>
        @endif
    </div>

    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js"
            nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"
            async defer></script>
@endif
