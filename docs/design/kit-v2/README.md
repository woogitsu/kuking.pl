# KuKing.pl — UI kit v2

Paczka zawiera komplet wizualizacji, ich wersje HTML oraz dokumentację wdrożeniową.

## Start

Najwygodniej:

```bash
python3 -m http.server 8080
```

i otworzyć `http://localhost:8080/`.

## Zawartość

- `index.html` — galeria i wejście do wszystkich ekranów;
- `html/` — wersje HTML korzystające ze wspólnego CSS;
- `standalone-html/` — samodzielne wersje referencyjne wygenerowanych ekranów;
- `mockups/` — wszystkie PNG/JPG wygenerowane wcześniej;
- `BRAND_IDENTITY.md` — pełna identyfikacja wizualna, kolory, fonty, zdjęcia, dostępność i research;
- `IMPLEMENTATION_GUIDE.md` — mapa wdrożenia do aktualnego repo Laravel/Blade/Livewire/Tailwind;
- `css/tokens.css` — plain CSS tokens;
- `css/tailwind-theme.css` — skrót do Tailwind CSS 4;
- `css/prototype.css` — CSS dokładnie odwzorowujący mockupy;
- `design-tokens.json` — wersja dla agentów/skryptów;
- `assets/logo/` — logo 01 „Uśmiech” + warianty inverse;
- `assets/photos/` — obrazy użyte w prototypach.

## Ważne

`html/` i `standalone-html/` są materiałem implementacyjnym/referencyjnym. Nie należy budować produkcji jako osobnego statycznego frontendu. Aktualna aplikacja ma istniejące komponenty Blade/Livewire i powinna zostać **przestylowana zgodnie z tym systemem**.
