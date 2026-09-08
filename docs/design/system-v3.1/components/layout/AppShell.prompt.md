Szkielet ekranu zalogowanego — belka, trzy kolumny, stopka.

```jsx
<AppShell
  naglowek={<TopBar imie="Basia" />}
  nawigacja={<><SideNav biezaca="start" /><BottomNav biezaca="start" /></>}
  szyna={<RailModule tytul="kuKINGi na dziś">…</RailModule>}
  stopka={<SiteFooter />}
>
  <Section tytul="Świeżo z Kuking">…</Section>
</AppShell>
```

- **Jedna szerokość dla trzech warstw.** Belka, siatka i stopka zaczynają się
  w tym samym miejscu na każdej podstronie.
- Kolumny: nawigacja 240 px · treść 720 px · szyna 352 px = 1424 px od 80rem;
  1040 px między 64 i 80rem; 768 px u gościa (`solo`).
- **Trzecia kolumna istnieje zawsze od 80rem, także pusta** (D-102).
- Trzy progi, nie pięć: 320 px, 64rem (nawigacja boczna), 80rem (szyna).
- Pierwszym elementem jest „Przejdź do treści”; `<main id="tresc">` jest jego
  celem.
- `<body>` ma zapas na dole równy wysokości dolnego paska.
