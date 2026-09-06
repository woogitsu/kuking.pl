{{--
    Paginacja jako przycisk „Pokaż więcej”, nie infinite scroll.

    Infinite scroll uniemożliwia dojście do stopki, gubi pozycję po powrocie
    i jest nieprzewidywalny przy czytniku ekranu.
--}}
@props(['paginator'])
@if($paginator->hasMorePages())
    <p class="text-center mt-6">
        <a class="btn btn-secondary" href="{{ $paginator->nextPageUrl() }}">Pokaż więcej wpisów</a>
    </p>
@endif
