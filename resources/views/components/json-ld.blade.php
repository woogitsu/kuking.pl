@props(['data'])

{{--
    Jedno miejsce, w którym powstaje JSON-LD (audyt A01).

    Nie wstawiaj `{!! json_encode(...) !!}` wprost w widoku: w zwykłym JSON-ie
    ciąg `</script>` jest poprawną wartością, ale w HTML kończy element skryptu
    i wypuszcza treść użytkownika do dokumentu. Szczegóły i uzasadnienie
    wyboru flag: App\Support\JsonLd.

    NONCE JEST TU KONIECZNY, MIMO ŻE TO NIE JEST WYKONYWALNY KOD (issue #12).
    Przeglądarka sprawdza `script-src` na KAŻDYM elemencie `<script>`, nie
    tylko na tych, które umie wykonać. Bez podpisu Chrome po prostu wyrzuca
    ten blok z dokumentu — strona wygląda normalnie, a Google przestaje
    widzieć przepis jako przepis. Awaria bez żadnego objawu na ekranie.
--}}
<script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{!! \App\Support\JsonLd::encode($data) !!}</script>
