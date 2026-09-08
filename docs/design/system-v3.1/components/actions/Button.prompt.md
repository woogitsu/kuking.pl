Przycisk — uruchamia akcję i mówi, która akcja na tym ekranie jest najważniejsza.

```jsx
<ButtonRow>
  <Button waga="primary" type="submit">Opublikuj</Button>
  <Button waga="secondary" type="submit">Zapisz szkic</Button>
  <Button waga="quiet" href="/dodaj/przepis">Wstecz</Button>
</ButtonRow>
```

Warianty: `waga="primary" | "secondary" | "quiet" | "danger"`, modyfikatory
`duzy` (56 px, akcja główna na telefonie) i `pelny` (cała szerokość kolumny).

Zasady, które trzymają ten komponent:

- **Dokładnie jeden `primary` na ekran.** Dwa przyciski główne znaczą, że żaden
  nie jest główny.
- **Nigdy poniżej 48 px** i nigdy sama ikona — ani w karcie, ani w belce.
- **`disabled` zawsze z `wyjasnienie`**: `<Button waga="primary" disabled
  wyjasnienie="Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego
  opublikować.">Opublikuj</Button>`. Lepiej jednak nie wyłączać przycisku, a po
  naciśnięciu pokazać, czego brakuje.
- **„Usuń” nigdy obok „Zapisz”** — osobna sekcja `danger-zone` albo minimum
  32 px odstępu.
- Fokus rysuje halo w kolorze tła pod przyciskiem, więc przycisk na karcie ma
  własną regułę. Nie zastępuj tego niczym słabszym.
- Przycisk nie ma stanu błędu. Błąd należy do pola albo do formularza.
