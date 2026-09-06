# Materiały projektowe — który plik jest czym

W tym katalogu leżą **trzy różne rzeczy** i pomylenie ich kosztowało już
kilka godzin pracy. Stąd ten plik.

## `kit-v2/` — TO JEST OBOWIĄZUJĄCY WYGLĄD

Paczka od właściciela produktu (UI kit v2). Zawiera tokeny, logo, dwanaście
wizualizacji w PNG, ich wersje w HTML oraz `IMPLEMENTATION_GUIDE.md` z mapą
wdrożenia do tego repozytorium.

**Zaczynaj od `kit-v2/IMPLEMENTATION_GUIDE.md`.** Mówi wprost, czego NIE robić:
nie budować drugiego frontendu obok aplikacji, tylko przestylować istniejące
komponenty Blade.

Stan wdrożenia opisuje `docs/design/STAN_WDROZENIA_KITU.md`.

## `prototype/` — SZKIC Z PIERWSZEGO DNIA, NIE WYGLĄD PRODUKTU

Sto linijek szarego HTML-a (Arial, tło `#f7f7f5`, granatowy przycisk) z pierwszego
commitu repozytorium. Powstał, zanim istniała identyfikacja wizualna, i **nie ma
nic wspólnego z kitem v2**. Dopasowanie produktu do tego szkicu byłoby cofnięciem
się, nie postępem.

Zostaje wyłącznie jako ślad, od czego zaczynaliśmy.

## `DESIGN_SYSTEM.md`, `COMPONENTS_BLADE.md`, `A11Y_CHECKLIST.md`

Opis systemu **wdrożonego w kodzie**: policzone pary kontrastu, skala tekstu
użytkownika, tryb ciemny. To są rzeczy, których kit nie miał, a które muszą
przeżyć każde przestylowanie — patrz `IMPLEMENTATION_GUIDE.md` §4 punkt 4:
„nie zmieniaj kontrastów bez ponownego przeliczenia".
