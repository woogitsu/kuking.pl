@props(['typ', 'id', 'etykieta' => 'Zgłoś', 'pelny' => false])
{{--
    „ZGŁOŚ” DLA GOŚCIA (issue #2221).

    Pomoc i „Napisz do nas” obiecują drogę zgłoszenia przy treści. Zwykłe
    zgłoszenie (`reports.create`) wymaga konta, więc gość dotąd nie widział
    niczego. Teraz widzi odnośnik, który mówi wprost, że poprosi o logowanie —
    trasa jest za `auth`, a po zalogowaniu wraca na formularz zgłoszenia.
    Działa bez JavaScriptu i bez hover; `.btn` daje 48 px.

    `pelny` (przepis, wpis): dodatkowo jedno zdanie o tym, że treść niezgodną
    z prawem można zgłosić BEZ konta formularzem z DSA (`zglos.nielegalna`).
    Zakresów nie mieszamy — tamten formularz ma własne terminy i decyzję
    z odwołaniem, nie zastępuje zgłoszenia spamu czy nękania.
--}}
<span class="zglos-goscia" data-zglos-goscia>
    <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => $typ, 'id' => $id]) }}">{{ $etykieta }} (po zalogowaniu)</a>
    @if($pelny)
        <span class="meta block">
            Zgłoszenie spamu, nękania albo niebezpiecznej porady wymaga konta — poprosimy Cię o zalogowanie.
            Bez konta możesz zgłosić <a href="{{ route('zglos.nielegalna') }}">treść niezgodną z prawem</a>.
        </span>
    @endif
</span>
