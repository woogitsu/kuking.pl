{{--
    Etykieta „Konto przykładowe" (D-025, `docs/DECISIONS.md`).

    PO CO TO JEST
    Treść zalążkowa (`database/seeders/dane/tresc-zalazkowa.json`,
    `Database\Seeders\TrescZalazkowaSeeder`) wchodzi na produkcję pod
    warunkiem, że jest JAWNIE oznaczona — właściciel wprost: „Tak, ale
    jawnie oznaczone". D-025 stawia to oznaczenie PRZY KONCIE, nie tylko
    w regulaminie: „nikt nie czyta regulaminu, żeby dowiedzieć się, czy
    pisze do człowieka".

    DLATEGO JEDEN KOMPONENT, UŻYTY W CZTERECH MIEJSCACH
    Profil, karta wpisu, karta przepisu, komentarz — każde z tych miejsc już
    ma dostęp do `User` (autora) i wystarczy jedno `@if`. Jeden plik zamiast
    czterech kopii tego samego warunku i tego samego tekstu — inaczej
    zmiana treści etykiety wymagałaby pamiętania o czterech miejscach
    naraz (dokładnie ta klasa błędu, przed którą ostrzega `LimityTagow`).

    TEKST, NIE SAM ZNACZEK. Grupa 50+ nie ma zgadywać, co oznacza ikona —
    `.badge-przykladowe` jest większa (18px, nie 16px jak zwykłe odznaki)
    i ma ramkę, żeby NIE dało się jej przeoczyć obok odznaki „Ugotowane
    N razy" na tym samym tle (`resources/css/app.css`).

    BEZ `style=` (CSP) — kolor i rozmiar idą przez klasę, tak jak wszędzie
    indziej w tym repozytorium.
--}}
@props(['user'])
@if($user?->isSeeded())
    <span class="badge badge-przykladowe">Konto przykładowe — nie prawdziwa osoba</span>
@endif
