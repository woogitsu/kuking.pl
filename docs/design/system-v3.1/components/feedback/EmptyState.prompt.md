Pusty stan — co się tu pojawi i co zrobić, żeby się pojawiło.

```jsx
<EmptyState
  tytul="Zeszyt jest jeszcze pusty"
  opis="Kiedy znajdziesz przepis, który chcesz zachować, kliknij przy nim „Zapisuję”. Trafi tutaj i zawsze go znajdziesz."
>
  <Button waga="primary" href="/odkryj">Zobacz przepisy</Button>
</EmptyState>
```

- **Nigdy „Brak danych” ani „Nic tu nie ma” bez ciągu dalszego.** Pusty stan bez
  akcji jest ślepym zaułkiem.
- Tytuł jest `<p>`, nie nagłówkiem — nagłówkiem jest tytuł sekcji nad nim.
- Znak jest ozdobą (`aria-hidden`). Znak 64 px wystarczy; **nie rysuj dużej
  ilustracji** — obrazek na pół ekranu spycha akcję poniżej krawędzi.
- Akcja jest zawsze jedna i zawsze konkretna.
- Nie żartuj z tego, że ktoś jeszcze nic nie dodał.
- `znak="lupa"` przy braku wyników szukania.
