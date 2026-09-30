{{--
    Wersja sprzed początku archiwum (#2220; dziś tylko polityka prywatności,
    daty przed 25 września 2026). Taką datę mógł zapisać dziennik zgód
    (`dziennik_zgod.wersja_polityki`), więc adres nie kończy się gołym „nie ma
    strony”: mówimy, jak dostać to brzmienie. Status 404 — treści tu nie ma.
--}}
@php($slownie = \App\Domain\Zgody\ArchiwumDokumentu::dataSlownie($data))
<x-layout
    :title="$archiwum->mianownik.' — wersja z '.$slownie"
    :description="$archiwum->mianownik.' Kuking w wersji z '.$slownie.' — wydajemy ją na prośbę.'"
    :noindexFollow="true"
>
    <h1>{{ $archiwum->mianownik }} — wersja z {{ $slownie }}</h1>
    <p class="text-lead">Tej wersji nie ma w archiwum na stronie.</p>

    @include('pages.static.partials.dokument-starsze-na-prosbe', ['archiwum' => $archiwum])

    <p class="historia-akcje">
        <a class="btn btn-secondary" href="{{ route($archiwum->trasa.'.versions') }}">Wszystkie wersje {{ $archiwum->dopelniaczKrotko }}</a>
        <a class="btn btn-quiet" href="{{ route($archiwum->trasa) }}">Obecny tekst {{ $archiwum->dopelniaczKrotko }}</a>
    </p>
</x-layout>
