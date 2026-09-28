{{--
    INFORMACJA PRZED ZGODĄ „ODCZYT AI” I FORMULARZ ZGODY (D-296, issue #2033).

    Jedyne źródło tej treści. Używają go ekran „Przepisz z kartki”
    (`pages/import/zgoda`) i ustawienia prywatności — żadne z tych miejsc nie
    ma własnej wersji tekstu ani własnego przycisku zgody. Blok z atrybutem
    `data-informacja-odczyt-ai` porównuje test
    `tests/Feature/Zgody/InformacjaPrzedZgodaOdczytuAiTest.php`: ma być
    identyczny na obu stronach.

    Zmiana faktów (odbiorca, miejsce, co wysyłamy, skutek) = podbij
    `App\Domain\Zgody\InformacjaOdczytuAi::WERSJA`. Fakty zgodne z D-296
    i `docs/legal/projekty/POLITYKA_ODCZYT_AI.md`.

    Props:
    - `skad`   — `import` wraca po zgodzie do zdjęcia; `ustawienia` do ustawień.
    - `odmowa` — adres przycisku „Nie” albo `null`, gdy odmową jest po prostu
                 niekliknięcie (ustawienia).
    - `glowny` — czy „Zgadzam się” to główny przycisk strony.
--}}
@props(['skad' => 'ustawienia', 'odmowa' => null, 'glowny' => true])
@php
    $bladZgody = $errors->getBag(\App\Domain\Zgody\InformacjaOdczytuAi::WOREK_BLEDOW)->first(\App\Domain\Zgody\InformacjaOdczytuAi::POLE);
@endphp
<div data-informacja-odczyt-ai="{{ \App\Domain\Zgody\InformacjaOdczytuAi::WERSJA }}" class="stack">
    <p class="mt-0">
        Żeby przepisać przepis z Twojej kartki, wyślemy jej zdjęcie do firmy <strong>OpenAI</strong> w USA.
        Jej komputer odczyta pismo, a my wpiszemy tekst do Twojego prywatnego szkicu.
    </p>
    <ul class="stack-tight">
        <li>Wyślemy <strong>samo zdjęcie kartki</strong> — bez Twojego imienia, adresu e-mail i danych z aparatu (także bez miejsca, w którym zrobiono zdjęcie).</li>
        <li>Jeśli na kartce są czyjeś dane — nazwisko, telefon, adres, informacja o zdrowiu — <strong>zasłoń je przed zrobieniem zdjęcia</strong>.</li>
        <li>Tekst odczytany przez komputer trafi tylko do Twojego szkicu. Nic się nie opublikuje, dopóki nie sprawdzisz tekstu i nie klikniesz „Opublikuj”.</li>
        <li>Bez zgody nie wysyłamy żadnego zdjęcia. Zgodę możesz wycofać w każdej chwili w <a href="{{ route('settings.privacy') }}#odczyt-ai">ustawieniach prywatności</a>. Zdjęcia kartek dalej wtedy dodasz — tylko tekst wpiszesz ręcznie. Szkice odczytane wcześniej zostaną.</li>
    </ul>
</div>

@if($bladZgody)
    <p class="field-error" id="f-{{ \App\Domain\Zgody\InformacjaOdczytuAi::POLE }}" role="alert">{{ $bladZgody }}</p>
@endif

<form method="POST" action="{{ route('zgoda.odczyt-ai.udziel') }}" class="form-actions">
    @csrf
    <input type="hidden" name="{{ \App\Domain\Zgody\InformacjaOdczytuAi::POLE }}" value="{{ \App\Domain\Zgody\InformacjaOdczytuAi::WERSJA }}">
    @if($skad === 'import')
        <input type="hidden" name="skad" value="import">
    @endif
    <button class="btn {{ $glowny ? 'btn-primary' : 'btn-secondary' }}" type="submit">Zgadzam się, odczytujcie moje kartki</button>
    @if($odmowa)
        <a class="btn btn-secondary" href="{{ $odmowa }}">Nie, wpiszę przepis ręcznie</a>
    @endif
</form>
