@props(['recipe'])
{{--
    PODPIS „MOJEJ WERSJI" — NA PODSTAWIE CZYJEGO PRZEPISU (issue #23, D-301).

    Stoi nad tytułem wersji na stronie przepisu, w kreatorze i na formularzu
    szczegółów. NIE MA PRZYCISKU, KTÓRY GO ZDEJMUJE, i nie ma go mieć: to jest
    przypisanie autorstwa, a kolumna `forked_from_id` jest poza `$fillable`.

    NAZWY DOSŁOWNIE, BEZ ODMIANY (COPY_STYLE, „Nie doklejaj przyimka do cudzych
    słów"). Tytuł idzie po dwukropku, w cudzysłowie; nazwa konta w mianowniku,
    w osobnym członie po „·" — tak samo jak `Recipe::attributionLine()`.
    „od Basia" albo „przepisu Nasze smaki" wyglądałoby na zepsuty program.

    Oryginał, którego widz nie może zobaczyć (usunięty, ukryty przez
    moderację, zawężony, autor zablokowany albo zbanowany), zamienia link
    na zdanie „oryginał jest niedostępny". Sam podpis zostaje zawsze — wersja
    nie udaje wtedy przepisu własnego.
--}}
@php
    $oryginal = \App\Domain\Recipes\MojaWersja::oryginalDlaWidza($recipe, auth()->user());
@endphp
@if($recipe->jestWersja())
    <p class="meta na-podstawie-przepisu" data-test="na-podstawie">
        @if($oryginal)
            Na podstawie przepisu: <a href="{{ route('recipes.show', $oryginal->slug) }}">„{{ $oryginal->title }}”</a>
            · {{ $oryginal->author->displayName() }}
        @else
            Na podstawie przepisu innej osoby — oryginał jest niedostępny.
        @endif
    </p>
@endif
