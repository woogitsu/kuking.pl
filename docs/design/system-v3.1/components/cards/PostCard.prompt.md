Karta wpisu — najczęściej powtarzany element serwisu.

```jsx
<PostCard
  autor="Halina"
  autorHref="/@halina"
  avatarSrc="/zdjecia/halina.jpg"
  czas="wczoraj, 18:40"
  plakietka={<Badge waga="cichy">konto przykładowe</Badge>}
  tytul="Pierogi ruskie po babci"
  tytulHref="/dania/123"
  tresc="Robione w niedzielę, z kapustą i grzybami."
  zdjecie="/zdjecia/123.jpg"
  alt="Talerz pierogów polanych zesmażoną cebulką i posypanych szczypiorkiem"
  akcje={
    <>
      <Button waga="primary" type="submit">Ugotowałem</Button>
      <Button waga="quiet" type="submit">Zapisz</Button>
      <Button waga="quiet" className="karta-stopka-odstep" href="/dania/123#komentarze">8 komentarzy</Button>
    </>
  }
/>
```

Warianty: pełna, `zwarta` (zdjęcie 16:9, bez treści i stopki — profil, tag,
szukanie, zeszyt) i bez zdjęcia (`bezZdjecia="…"` — ciepłe pole z tekstem 22 px,
żeby wpis nie wyglądał na uszkodzony).

- **Nie rób z całej karty jednego linku.** Czytnik przeczyta ją wtedy jako jedną
  nazwę odnośnika, a w środku i tak są przyciski.
- **Alt opisuje, co jest na talerzu.** To jedyna rzecz, którą osoba niewidoma
  dostaje zamiast treści tej karty.
- **Nie powtarzaj głośnej plakietki w każdej karcie** — w strumieniu jest cicha,
  przy dacie (D-103).
- **Nie zmniejszaj zdjęcia do miniatury** i nie rób więcej niż dwóch kolumn
  (`siatka-kart`).
- W stopce dokładnie jedna akcja ma wagę; `karta-stopka-odstep` odsuwa ostatnią
  pozycję do prawej.
- Karta nie ma stanu „wyłączona”. Wpis albo jest widoczny, albo go nie ma.
