{{--
    Etykieta „konto przykładowe" (D-032, `docs/DECISIONS.md` — WAGA i TREŚĆ
    poniżej to odwrócenie/skrócenie D-025 przez właściciela).

    PO CO TO JEST
    Treść zalążkowa (`database/seeders/dane/tresc-zalazkowa.json`,
    `Database\Seeders\TrescZalazkowaSeeder`) wchodzi na produkcję pod
    warunkiem, że jest JAWNIE oznaczona — właściciel wprost: „Tak, ale
    jawnie oznaczone". D-025 stawia to oznaczenie PRZY KONCIE, nie tylko
    w regulaminie: „nikt nie czyta regulaminu, żeby dowiedzieć się, czy
    pisze do człowieka".

    DLATEGO JEDEN KOMPONENT, UŻYTY W PIĘCIU MIEJSCACH
    Profil, karta wpisu, karta przepisu, komentarz i odpowiedź na komentarz —
    każde z tych miejsc już ma dostęp do `User` (autora) i wystarczy jedno
    `@if`. Jeden plik zamiast pięciu kopii tego samego warunku i tego samego
    tekstu — inaczej zmiana treści etykiety wymagałaby pamiętania o pięciu
    miejscach naraz (dokładnie ta klasa błędu, przed którą ostrzega
    `LimityTagow`).

    `waga`: CICHA (domyślna) CZY GŁOŚNA — dwie decyzje właściciela naraz
    -------------------------------------------------------------------
    1) CISZEJ, NIE GŁOŚNIEJ (odwrócenie D-025). W strumieniu ta sama
       plakietka powtarzała się kilkanaście razy na ekranie w kolorze marki
       i przestawała cokolwiek znaczyć — a przy okazji zjadała budżet uwagi,
       który miały dostać zdjęcia potraw. Domyślna waga to więc `cicha`:
       `.badge-cichy`, bez tła i ramki, w wierszu metadanych po kropce, obok
       daty — czytelna dokładnie wtedy, gdy ktoś patrzy na autora.
       Głośna (`.badge-przykladowe`, wygląd bez zmian: 18px, tło, ramka)
       zostaje wyłącznie na profilu konta przykładowego (`waga="glosna"`),
       bo głośną plakietkę wolno pokazać raz na ekran, nie kilkanaście razy.
    2) TREŚĆ SKRÓCONA DO JEDNEGO BRZMIENIA W CAŁYM SERWISIE: „konto
       przykładowe" — bez dopisku „— nie prawdziwa osoba". Skrócenie nie
       kasuje informacji: pełne zdanie stoi RAZ, jako osobny akapit
       (`.notice`) na profilu konta przykładowego
       (`pages/profile/show.blade.php`), obok tej samej plakietki, już
       głośnej. Bez tego jedno zdanie skrócenie odbierałoby ostrzeżenie,
       zamiast je tylko przenieść.

    TEKST, NIE SAM ZNACZEK. Grupa 50+ nie ma zgadywać, co oznacza ikona —
    obie wagi niosą ten sam czytelny napis, różni je tylko to, jak głośno
    stoi na stronie.

    BEZ `style=` (CSP) — kolor i rozmiar idą przez klasę, tak jak wszędzie
    indziej w tym repozytorium.
--}}
@props(['user', 'waga' => 'cicha', 'kropka' => true])
@if($user?->isSeeded())
    {{-- Kropka-separator wyłącznie w wersji cichej: ta plakietka siedzi
         w wierszu metadanych obok daty/widoczności, tym samym wzorcem co
         istniejące „· publicznie" w `post-card.blade.php` — więc kropkę
         rysuje TA plakietka, a nie każde miejsce wywołania osobno. Głośna
         wersja stoi sama, u góry profilu, i kropki nie potrzebuje.

         `kropka=false` jest dla jednego przypadku: strony przepisu, gdzie
         wiersz metadanych składa się z samej daty publikacji — a przepis
         nieopublikowany widzi jeszcze jego autor i moderator
         (`RecipePolicy::view`). Wtedy przed plakietką nie ma czego oddzielać
         i sama kropka na początku wiersza wyglądałaby jak usterka. --}}
    @if($waga === 'cicha' && $kropka) · @endif<span class="badge {{ $waga === 'glosna' ? 'badge-przykladowe' : 'badge-cichy' }}">konto przykładowe</span>
@endif
