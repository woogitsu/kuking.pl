## D-259 — Własne „Ugotowałem” otwiera się kucharzowi mimo blokady z autorem przepisu; przepis zostaje zamknięty (#1394, PR #1503, 24 września 2026)

**Data:** 24 września 2026 · **Decyzja właściciela 24.09.2026** · Status: **obowiązuje**

**Co.** Kucharz widzi własne wykonanie („Ugotowałem”) — zdjęcie, notatkę,
czas — także wtedy, gdy między nim a autorem przepisu jest blokada,
w którąkolwiek stronę. Dotyczy to karty na własnej zakładce „Ugotowane”,
strony szczegółu (`cooked.show`) i zdjęcia. Przy blokadzie widok nie
pokazuje tytułu ani adresu przepisu (`components/cooked-card`,
`przepisZaBlokada`, tytuł strony), a sam przepis dalej odpowiada kucharzowi
403 (`RecipePolicy::view()`). Komentarze tnie jak dotąd
`Comment::widoczneDla()`.

**Co zostaje zamknięte.**

- **Przepis** — dla kucharza objętego blokadą bez zmian: 403, bez tytułu
  i adresu na karcie wykonania.
- **Autor przepisu** objęty blokadą nie widzi wykonania kucharza (lista
  i szczegół, jak dotąd).
- **Moderator** objęty blokadą z autorem przepisu nie wchodzi w wykonanie
  (`CookedEventPolicy::view()`, gałąź moderatora bez zmian).
- Obcy widz nie widzi wykonań z przepisów, których sam nie widzi
  (`ProfileController::tylkoZWidocznychPrzepisow()`).

**Dlaczego.** Dotychczasowa reguła zamykała kucharzowi wejście w jego własne
wykonanie przy blokadzie z autorem przepisu. Skutek zmierzony: własna
zakładka „Ugotowane” (lista niefiltrowana dla właściciela) dalej pokazywała
kartę z przyciskiem „Zobacz i skomentuj”, a przycisk i zdjęcie kończyły się
**403** — martwy przycisk, a do tego lista i polityka odpowiadały inaczej.
Zdjęcie i notatka są treścią kucharza („poprawne dane nigdy nie znikają”,
AGENTS.md §5); musi je móc zobaczyć i skasować. Blokada chroni treść osoby,
z którą wiąże — czyli przepis — i ta treść dalej się nie pokazuje: granica
przeszła z wejścia do widoku, nie zniknęła.

**Relacja do wcześniejszych rozstrzygnięć.** Odwraca rozstrzygnięcie
z commitu `c26de5f3` („blokada ma pierwszeństwo przed prawem do własnej
treści” — jedyny wyjątek od furtki na własne wykonanie w
`CookedEventPolicy::view()`). Pierwszeństwo blokady (AGENTS.md §4) i
jej porządek na parze osób z **D-080** obowiązują bez zmian: blokada dalej
wyklucza obserwowanie, powstanie nowego wykonania (AGENTS.md §1, granica 3)
i pokazanie treści drugiej strony. Zmienia się tylko to, że **własna** treść
kucharza nie jest już zakładnikiem blokady.

**Dowody:** `tests/Feature/WykonaniePoBlokadzieAutoraPrzepisuTest.php`
(macierz: lista, szczegół i zdjęcie w obu kierunkach blokady; autor przepisu
dalej bez dostępu), test
`test_wlasne_wykonanie_po_blokadzie_z_autorem_przepisu_otwiera_sie_bez_przepisu`
w `tests/Feature/UgotowalemWlasneWykonanieNieZnikaTest.php`, mutacja
„własne wykonanie otwiera się kucharzowi mimo blokady” w
`tests/mutacje/autoryzacja.txt`.

**Co musiałoby się stać, żeby to zmienić:** pokazanie, że widok wykonania
przy blokadzie ujawnia treść autora przepisu (tytuł, adres, komentarze),
albo decyzja właściciela, że blokada ma zamykać także własną treść.

### Wycofanie
Odwrócić commity PR #1503. Schemat bazy się nie zmienia; danych nie trzeba
cofać.
