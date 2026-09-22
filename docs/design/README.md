# Materiały projektowe — który plik jest czym

## Aktualne źródło stylu

Najpierw przeczytaj [AGENTS.md](../../AGENTS.md), następnie
[aktualną konstytucję marki](../brand/KONSTYTUCJA_MARKI.md)
i [decyzje właściciela](../DECISIONS.md), w tym D-206–D-213.
Teksty określają [COPY_STYLE](../brand/COPY_STYLE.md) i
[GLOS_MARKI](../brand/GLOS_MARKI.md).
Starsze paczki nie zastępują tych zasad. Aktualny materiał referencyjny
wskazuje [audyt paczki marki](AUDYT_PACZKI_MARKI_508.md): zachowany ZIP
[KuKing-styl-wizualizacja-konstytucja.zip](references/KuKing-styl-wizualizacja-konstytucja.zip). Nie zmieniamy jego oryginału
ani historycznych materiałów w uploads. Bieżący zakres odbioru opisuje
[macierz kompletności](MACIERZ_KOMPLETNOSCI_517.md); sama obecność makiety nie dowodzi wdrożenia.

## Materiały historyczne

Poniższy opis dokumentuje wcześniejszy etap projektu. Dawne porównania
tokenów i stany wdrożenia odnoszą się do dat podanych w opisie, nie do
aktualnej aplikacji. Nie są poleceniem przywrócenia starej palety lub układu.

Historyczne kity pozostają materiałem porównawczym, a nie instrukcją
cofnięcia aktualnej identyfikacji.

### `kit-v2/` — historyczny UI kit

Paczka od właściciela produktu (UI kit v2). Zawiera tokeny, logo, dwanaście
wizualizacji w PNG, ich wersje w HTML oraz `IMPLEMENTATION_GUIDE.md` z mapą
wdrożenia do tego repozytorium.

`kit-v2/IMPLEMENTATION_GUIDE.md` opisuje ówczesne wdrożenie. Zachowuje ważne ograniczenie:
nie budować drugiego frontendu obok aplikacji, tylko przestylować istniejące
komponenty Blade.

Stan wdrożenia opisuje `docs/design/STAN_WDROZENIA_KITU.md`.

### `prototype/` — szkic z pierwszego dnia

Sto linijek szarego HTML-a (Arial, tło `#f7f7f5`, granatowy przycisk) z pierwszego
commitu repozytorium. Powstał, zanim istniała identyfikacja wizualna, i **nie ma
nic wspólnego z kitem v2**. Dopasowanie produktu do tego szkicu byłoby cofnięciem
się, nie postępem.

Zostaje wyłącznie jako ślad, od czego zaczynaliśmy.

## `DESIGN_SYSTEM.md`, `COMPONENTS_BLADE.md`, `A11Y_CHECKLIST.md`

Dokumentacja implementacji: pary kontrastu, skala tekstu
użytkownika, tryb ciemny. To są rzeczy, których kit nie miał, a które muszą
przeżyć każde przestylowanie — patrz `IMPLEMENTATION_GUIDE.md` §4 punkt 4:
„nie zmieniaj kontrastów bez ponownego przeliczenia".
