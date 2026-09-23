{{--
    Wejście do „Zdejmij z urzędu” przy treści (G31, D-251).

    Rysuje się WYŁĄCZNIE temu, komu Policy pozwala (`removeExOfficio`:
    czynny moderator albo administrator z potwierdzonym 2FA, autor niższej
    roli). Zwykły odnośnik (GET) do ekranu panelu — tam jest formularz
    z podstawą i uzasadnieniem, a usunięcie następuje dopiero tam. Dzięki
    temu działa bez JavaScriptu i bez hover, a klasa `.btn` daje 48 px.
    Stoi w `.danger-zone`, odsunięty od zwykłych akcji.

    Nie rysuje się też przy treści już zdjętej ani niewidocznej dla innych
    (`ZdejmijZUrzedu::dostepna()`) — ekran i tak by odmówił, a martwy
    przycisk to obietnica bez pokrycia.
--}}
@props(['tresc', 'typ'])
@auth
    @if(\App\Domain\Moderation\Actions\ZdejmijZUrzedu::dostepna($tresc) && auth()->user()->can('removeExOfficio', $tresc))
        <div class="danger-zone">
            <a class="btn btn-quiet" href="{{ route('admin.z-urzedu.create', ['typ' => $typ, 'id' => $tresc->getKey()]) }}">Zdejmij z urzędu</a>
        </div>
    @endif
@endauth
