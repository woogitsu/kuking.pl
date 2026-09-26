{{--
    „Ukrywasz wpisy N osób. Zmień" (#1811) — pod nagłówkiem „Świeżo z Kuking"
    i na stronie „Jak dobieramy wpisy". Pusto, gdy nic nie jest ukryte.
    Ukryte osoby mają pierwszeństwo, bo to one zmieniają najwięcej; same
    pojedyncze wpisy dostają własne zdanie. Odmiana przez `Odmiana`
    (dopełniacz: 1 osoby, 2 osób, 5 osób). Liczby tylko TEGO widza
    (`Ukrycia::ileOsob()`, `ileWpisow()`) — nic o cudzych ukryciach.
--}}
@props(['osoby' => 0, 'wpisy' => 0])
@if($osoby > 0)
    <p {{ $attributes->merge(['class' => 'meta']) }} data-linia-ukryc>
        Ukrywasz wpisy {{ $osoby }} {{ \App\Support\Odmiana::rzeczownik($osoby, 'osoby', 'osób', 'osób') }}.
        <a href="{{ route('settings.hidden') }}">Zmień</a>
    </p>
@elseif($wpisy > 0)
    <p {{ $attributes->merge(['class' => 'meta']) }} data-linia-ukryc>
        Ukrywasz {{ $wpisy }} {{ \App\Support\Odmiana::rzeczownik($wpisy, 'wpis', 'wpisy', 'wpisów') }}.
        <a href="{{ route('settings.hidden') }}">Zmień</a>
    </p>
@endif
