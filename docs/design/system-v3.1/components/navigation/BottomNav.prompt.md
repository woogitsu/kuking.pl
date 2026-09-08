Dolny pasek nawigacji — pięć miejsc, telefon.

```jsx
<BottomNav biezaca="start" onPrzejdz={setEkran} />
```

- **Dokładnie pięć pozycji**, zawsze te same: Start, Szukaj, Dodaj, Zeszyt, Moje.
- „Dodaj” jest jedyną pozycją z kolorem marki — i **kółko ma podpis**.
- Pasek ma 60 px wysokości: pozycja dotykowa jest większa niż zwykły cel 48 px,
  bo klika się ją w ruchu. `padding-bottom: env(safe-area-inset-bottom)`.
- Bieżąca pozycja ma kreskę u góry **oraz** kolor **i** grubszy napis — trzy
  sygnały, z których każdy działa osobno.
- **Nie dokładaj szóstej pozycji**: pięć podpisów przy 320 px i skali 140% ma
  razem około 346 px.
- **Nie zamieniaj podpisów na same ikony**, żeby zrobić miejsce.
- **Nie ukrywaj paska przy przewijaniu** — znikająca nawigacja jest u tej grupy
  odbiorców odbierana jako awaria.
- `<body>` ma zapas na dole (`app-body`), żeby ostatni wpis nie chował się pod
  paskiem.

> Uwaga do rozstrzygnięcia: `KOMPONENTY.md` podaje piątkę Start · Szukaj ·
> Dodaj · Zeszyt · Moje, a `CZEGO-NIE-ZMIENIAM.md` §7 — Start · Szukaj · Dodaj ·
> Moje · Profil. Kod trzyma się pierwszej wersji; do potwierdzenia przez
> właściciela.
