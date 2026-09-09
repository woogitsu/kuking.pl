{{--
    Widget Cloudflare Turnstile — sprawdzenie „czy to człowiek", które
    w trybie Managed zwykle przechodzi bez klikania w cokolwiek.

    NIC SIĘ NIE RENDERUJE, GDY TURNSTILE NIE DZIAŁA (brak kluczy albo miejsce
    wyłączone w `config/kuking.php`). Jedno pytanie, `Turnstile::dziala()`,
    rozstrzyga to samo dla widgetu i dla reguły walidacji — nie da się mieć
    widgetu bez walidacji ani walidacji bez widgetu.

    UX 50+ (docs/UX_50_PLUS.md):

    - WIDGET NIE JEST JEDYNYM NOŚNIKIEM INFORMACJI. Nad nim stoi zdanie po
      polsku mówiące, co to jest i że zwykle nie trzeba nic robić — bez tego
      osoba 60+ widzi obcą ramkę w środku formularza i nie wie, czy czekać.
    - Nie ruszamy przycisku wysyłki ani układu formularza: to jest osobny
      blok NAD `.form-actions`, więc „Załóż konto" zostaje tam, gdzie był,
      i zostaje przy swoich 48 px.
    - Błąd (gdy token przyjdzie i zostanie odrzucony) pokazujemy PRZY tym
      bloku, a `<x-error-summary>` pokazuje ten sam tekst na górze formularza.
      Kotwica z podsumowania celuje w `id` niżej.
    - Bez JavaScriptu ten blok w ogóle się nie pojawi na ekranie (skrypt nie
      wstawi ramki), a formularz działa dalej — `App\Rules\TurnstileNieJestPodrobiony`.

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

        @if($blad !== '')
            <span class="field-error">{{ $blad }}</span>
        @endif
    </div>

    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js"
            nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"
            async defer></script>
@endif
