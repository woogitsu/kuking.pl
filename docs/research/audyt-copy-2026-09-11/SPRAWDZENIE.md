# Sprawdzenie audytu copy przy plikach — 11 września 2026

Audyt zewnętrzny to **hipoteza o kodzie, nie prawda o nim**. Ten plik zapisuje,
co z listy P0 sprawdziłem przy pliku i z jakim wynikiem — żeby następna osoba
nie musiała tego powtarzać i żeby nie wzięła na wiarę jedynej pozycji, która
się nie potwierdziła.

Ten sam zabieg przy audycie z 10.09 wyłapał jeden nieprawdziwy P0
(`docs/research/audyt-2026-09-10/SPRAWDZENIE.md`); audytor przyjął korektę
i usunął go z czterech raportów.

**Snapshot audytu:** `88e718a` — czyli sprzed dwunastu PR-ów scalonych w nocy
z 10 na 11.09. Kilka jego uwag o CI i o pomiarze dostępności jest już
nieaktualnych z tego powodu, nie z powodu błędu autora.

## Wynik: dziewięć z dziesięciu potwierdzone

| Zarzut P0 | Wynik | Uwaga |
|---|---|---|
| „To zostaje w rodzinie" przy przepisie mogącym być publicznym | **potwierdzony** | `recipe-wizard.blade.php`; `Recipe` ma kolumnę `visibility` i filtr `where('visibility', 'public')` |
| „Podgląd: tak zobaczą to inni" przy przepisie prywatnym | **potwierdzony** | `recipe-wizard.blade.php:1257` |
| „zapisz szkic" obok autozapisu | **potwierdzony** | dwa modele działania na jednym ekranie |
| „Potrzebny tylko wtedy, gdy zapomnisz hasła" o adresie | **potwierdzony** | `register.blade.php`; nieprawda, bo jest logowanie linkiem (D-056) i zmiana adresu |
| Sprzeczność na 419 | **potwierdzony, i gorszy** | patrz niżej |
| „To najczęściej czytana część przepisu" | **potwierdzony** | twierdzenie analityczne bez pomiaru |
| „Zajmie minutę" | **potwierdzony** | `landing.blade.php` |
| „Jutro będzie tu ktoś inny" | **potwierdzony, i gorszy** | patrz niżej |
| Przykład hasła `zielonapietruszkarano` | **potwierdzony** | w DWÓCH miejscach: `register.blade.php` i `settings/security.blade.php` — audyt wymienia jedno |
| „Obserwuj" dla gościa w `pages/search.blade.php` | **ZŁY PLIK** | patrz niżej |

## Trzy ustalenia poza tym, co napisał audyt

### 1. Sprzeczność na 419 jest bezwarunkowa po jednej stronie

Audyt pisze o dwóch zdaniach, które się kłócą. W kodzie jest gorzej:
zdanie **„Twój tekst jest na miejscu — nic nie przepadło"** stoi w linii 41
**bez żadnego warunku**, a ostrzeżenie **„nie wszystko udało się przenieść"**
w linii 120 jest pod `@if($formularz->obciete)`.

Przy obciętym formularzu strona mówi **jednocześnie obie rzeczy** — w chwili,
gdy człowiek ma za moment wysłać coś, co właśnie napisał.

### 2. „Jutro będzie tu ktoś inny" to obietnica bez mechanizmu

Sprawdzone: **żadna komenda w `routes/console.php` ani w `app/Console/Commands/`
nie zasila `DailyPick`**, a wariant automatyczny (`DailyBoard::automaticPosts()`)
sortuje po `published_at DESC`.

Czyli jutro będzie tam ktoś inny **tylko wtedy, gdy ktoś w nocy opublikuje**.
Przy starcie opisanym w `docs/product/COLD_START.md` to jest dokładnie ten
moment, w którym nie opublikuje nikt — a wtedy zdanie kłamie najbardziej.

**Czego audyt nie zauważył:** ta stopka **nie jest ozdobą**. Komentarz w pliku
mówi wprost, że jest częścią funkcji — sygnalizuje, że tablica nie jest tabelą
wyników, co wprost służy zakazowi rankingów z `AGENTS.md` §12. **Nie wolno jej
więc po prostu usunąć** — trzeba ją zastąpić zdaniem, które robi to samo i nie
obiecuje harmonogramu.

### 3. „Obserwuj" dla gościa — trafny zarzut, zły adres

`resources/views/pages/search.blade.php` **nie ma przycisku „Obserwuj" wcale**.
Jedyna wzmianka to komentarz mówiący, że ta strona celowo NIE jest listą osób
z licznikami obserwujących.

Przycisk jest w **`resources/views/components/kuking-board.blade.php:71`**:

```blade
@else
    <a class="btn btn-secondary" href="{{ route('register') }}">Obserwuj</a>
@endauth
```

Gość widzi etykietę obiecującą akcję, a trafia na rejestrację. Zarzut jest
słuszny — i dotyczy każdej strony, na której stoi prawa szyna, nie tylko
wyszukiwarki.

## Czego NIE zmieniam bez decyzji właściciela

Tabela P1 zawiera pozycje zderzające się z rzeczami rozstrzygniętymi na piśmie:

- **„Zostań kuKINGiem"** — audyt każe zamienić na „Załóż darmowe konto",
  a `AGENTS.md` §11 mówi wprost: *„Wolno «Zostań kuKINGiem»"*. Argument audytu
  (żart marki w funkcjonalnym CTA plus dwie informacje naraz) jest rozsądny,
  ale to zmiana zasady, nie poprawka.
- Kilka zdań, które audyt nazywa „terapeutycznymi", powstało pod `AGENTS.md`
  §5: *„błędy po polsku, mówiące CO ZROBIĆ"*. Skracanie ich do samego faktu
  może ten wymóg naruszyć.

Podział przyjęty przy wdrożeniu: **P0 to fakty** (strona przecząca sama sobie,
obietnica bez mechanizmu, przycisk kłamiący o tym, co robi) — idą od razu.
**P1 to głos marki** — lista do odhaczenia przez właściciela stoi w
`LISTA_DO_ODHACZENIA.md` obok.
