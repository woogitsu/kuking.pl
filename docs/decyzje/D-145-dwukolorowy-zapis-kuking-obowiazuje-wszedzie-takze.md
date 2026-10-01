## D-145 · Dwukolorowy zapis `kuKING` obowiązuje wszędzie, także jako nazwa serwisu w tekście bieżącym

**Data:** 11 września 2026 · **Decyzja właściciela** (issue #392, B2) · PR #398 ·
Status: **obowiązuje** · zmienia D-009 i D-015

### Co zostało odwrócone

Dwie rzeczy zapisane wcześniej przestają obowiązywać w części o zapisie nazwy:

1. **limit „maksymalnie raz na ekran"** (D-009, `AGENTS.md` §11, `COPY_STYLE.md` §2);
2. **podział na rejestry z D-015** — do tej pory tekst ciągły pisał `Kuking`,
   a `kuKING` był zarezerwowany dla człowieka.

Od teraz `kuKING` w zapisie dwukolorowym — **„ku" w kolorze tekstu, „KING"
w kolorze marki** — stoi wszędzie, gdzie nazwa jest czytana jako nazwa:
w nagłówku, w tekście bieżącym, w nawigacji, w stopce, w zaproszeniu.

**Co z D-015 zostaje w mocy:** logotyp `KuKing.pl` jest znakiem i rządzi się
swoim prawem. Akcent koloru w logotypie leży dziś wyłącznie na „King" — „Ku"
i „.pl" biorą kolor tekstu (PR #394).

### Dlaczego limit „raz na ekran" nie został podniesiony, a usunięty

Sufit był liczbą, a liczba nie jest tym, co ta reguła chroniła — chroniła
**czytania**. Ekran z jednym żartem w komunikacie błędu jest gorszy niż ekran
z trzema w dobrych miejscach. Podbicie sufitu z 1 na 6 byłoby udawaniem reguły,
więc `test_gra_slowem_wystepuje_najwyzej_raz_na_ekranie` został **usunięty, nie
wyłączony**, i zastąpiony przez `test_nazwa_nie_powtarza_sie_w_jednym_bloku_tekstu`.

W miejsce limitu wchodzą **dwa kryteria**:

> **Charakter marki wolno tam, gdzie nie konkuruje z zadaniem.** Konkuruje,
> jeśli stoi między człowiekiem a przyciskiem, którego szuka; wydłuża zdanie,
> które ma być wykonane, nie przeczytane; opisuje ton zamiast podać informację;
> albo trzeba go zrozumieć, żeby pójść dalej.

> **W jednym akapicie, nagłówku albo punkcie listy nazwa pojawia się raz.** Nie
> dlatego, że dwa to „za dużo" — dlatego, że dwa dwukolorowe słowa w polu
> jednego spojrzenia migoczą, a to jest już koszt czytania.

### Pięć miejsc, w których nazwa zostaje zwykłym „Kuking"

„Wszędzie" ma granicę i to nie jest cofanie decyzji. Cztery z pięciu wyjątków
stały w D-009 i w `COPY_STYLE.md` §2 na długo przed tą decyzją i mówią o czymś
innym niż zasięg nazwy — **ta część D-009 zostaje w mocy**. Piąty bierze się
z tego, że dwukolorowości fizycznie tam nie ma.

| # | Gdzie | Dlaczego |
|---|---|---|
| 1 | `alt`, `title`, `aria-label`, `<title>`, `meta`, JSON-LD, temat listu, pliki eksportu, tekst tylko dla czytnika | **koloru tam nie ma**, a znacznik w atrybucie wypisze się dosłownie; wersaliki w środku wyrazu bez koloru czytają się jak literówka |
| 2 | błąd, moderacja, tekst prawny, ekran bezpieczeństwa, list techniczny | hierarchia tonu; zakaz jest bezwarunkowy i **starszy** niż ta decyzja — człowiek ma wtedy problem, nie ochotę na markę |
| 3 | powiadomienie o cudzej aktywności | „Halina — ugotowane z Twojego przepisu" jest doskonałe; to zdanie należy do Haliny, nie do marki |
| 4 | pole formularza, który ktoś właśnie wypełnia (etykieta, podpowiedź, walidacja) | tam marka konkuruje z zadaniem. Nagłówek **tego samego** ekranu wolno — „Zostań kuKINGiem" nad `/register` zostaje |
| 5 | tło w kolorze marki (przycisk podstawowy) | czerwień na czerwieni ma kontrast **1,00:1** i nie ma odcienia, który to naprawia; „KING" bierze kolor otoczenia, a nośnikiem zostają wersaliki (`kuking-word--bez-koloru`) |

### Kontrast — policzony, nie założony

„KING" jest pisane kolorem, więc jest **tekstem**, nie dekoracją: obowiązuje go
WCAG 2.2 AA, kryterium 1.4.3 (**4,50**). Zmierzone dla `--color-brand` =
`#B3401F` (motyw jasny) i `#F2986A` (ciemny):

| Tło | Jasny | Ciemny |
|---|---:|---:|
| `--color-surface` (strona) | 5,31 | 7,80 |
| `--color-surface-raised` (karta, stopka) | 5,72 | 6,92 |
| `--color-surface-sunken` (ramka, pole) | 4,83 | 8,49 |
| `--color-surface-brand-wash` (ciepły pas) | 4,83 | 7,03 |
| `--color-brand-tint` (podkład marki) | **4,64** | 6,55 |
| tło w kolorze marki (przycisk podstawowy) | **1,00** | **1,00** |

**Najciaśniej jest w motywie jasnym na `brand-tint`: 4,64 przy progu 4,50, czyli
zapas 0,14.** Jedno rozjaśnienie `--color-brand` ten zapas zabiera — dlatego
liczby stoją w teście, a nie tylko w dokumencie. Kontrola samego licznika
kontrastu (21:1, 1:1, symetria, para, która ma oblać) jest w tym samym pliku.

**Kolor nie jest jedynym nośnikiem znaczenia** (WCAG 1.4.1): słowo czyta się
identycznie bez koloru, bo grę niosą wersaliki.

### Gęstość po wdrożeniu — zmierzona, nie oszacowana

| Ekran | na stronę | z tego w stopce | na 1000 znaków | maks. w jednym akapicie |
|---|---:|---:|---:|---:|
| `/` | 6 | 2 | 2,6 | **1** |
| `/o-kuking` | 6 | 2 | 2,9 | **1** |
| `/odkryj` | 4 | 2 | 5,4 | **1** |
| `/home`, `/szukaj` | 4 | 2 | 3,4–4,0 | **1** |

Ani jeden akapit nie ma dwóch. Uczciwe zastrzeżenie do kolumny „na 1000 znaków":
licznik bierze też tekst dla czytnika ekranu — na stronie powitalnej 42 znaki
z 2336, czyli **1,8%** zaniżenia. Na wniosek to nie wpływa.

### Cena, wprost

Na przycisku podstawowym dwukolorowości **nie widać i widać nie może** — dotyczy
to także flagowego przycisku na stronie powitalnej. Jedyne wyjście, gdyby miała
być widoczna, to przycisk na tle `surface` (5,72:1); **nierozstrzygnięte, do
decyzji właściciela.**

Drugi koszt został zmierzony i naprawiony po drodze: pierwsza wersja reguły
łamania wyrazu użyła `white-space: nowrap` i **oblała skan dostępności** — na
`/register` przy oknie 320 px i czcionce przeglądarki 200% samo słowo brało
**315 px** zaczynając od x = 32, czyli strona przewijała się w bok o **27 px**
(naruszenie 1.4.10 Reflow). Obowiązuje `overflow-wrap: anywhere`: łamie wyraz
wyłącznie wtedy, gdy inaczej wyszedłby poza wiersz.

### Gdzie to stoi

Zapis żyje w jednym komponencie — `resources/views/components/kuking-word.blade.php`
— i nigdzie indziej; odmiana idzie atrybutem `forma`, a wersja dla czytnika
ekranu jest osobnym tekstem, bo `aria-label` na `<span>` czytniki **ignorują**
(specyfikacja „ARIA in HTML" zakazuje go na roli `generic`).

**Zmiana wymaga:** zmierzonej trudności w czytaniu u prawdziwych użytkowników
(#15) albo liczby 2 w kolumnie „maks. w jednym akapicie" — ta druga nie jest
decyzją do podjęcia w locie: test oblewa, a rozstrzyga właściciel.

📄 `docs/brand/GLOS_MARKI.md` §2 i §4 · `docs/brand/COPY_STYLE.md` §2 ·
`AGENTS.md` §11 · `tests/Feature/TekstyWedlugCopyStyleTest.php` · D-009 · D-015
