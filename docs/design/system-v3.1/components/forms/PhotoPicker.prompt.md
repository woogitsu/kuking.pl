Wybór zdjęcia — duży obszar, po polsku, bez natywnego przycisku pliku.

```jsx
<PhotoPicker id="zdjecie" />
```

- Nie ma wariantu „mały”: to jest **główna akcja** ekranów dodawania.
- **Nigdy natywny przycisk pliku** — rysuje „Choose File / No file chosen” po
  angielsku i żaden atrybut tego nie zmienia (D-107).
- Pole jest schowane dla oka, ale zostaje w drzewie: klawiatura i czytnik widzą
  je normalnie. **Nigdy `display: none`.**
- Kolejność w kodzie jest wymuszona — pole stoi bezpośrednio przed etykietą,
  inaczej znika widoczny fokus.
- `accept` nie zastępuje walidacji po stronie serwera ani komunikatu błędu po
  polsku.
- Przeciąganie pliku myszą jest dodatkiem, nigdy jedynym sposobem.
