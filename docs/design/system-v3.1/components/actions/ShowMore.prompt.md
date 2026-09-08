Stronicowanie „Pokaż więcej” na końcu listy.

```jsx
<ShowMore pokazano={10} wszystkich={48} rzeczownik="wpisów" action="/home" nastepnaStrona={2} />
```

- Licznik ma `aria-live="polite"` — po dołożeniu porcji czytnik powie
  „Pokazujemy 20 z 48 wpisów”, nie przerywając czytania.
- **Licznik mówi, ile zostało.** Lista bez widocznego końca jest u tej grupy
  odbiorców powodem, żeby przestać przewijać.
- Bez skryptu to zwykły `GET` z numerem strony i kotwicą do miejsca, w którym
  człowiek stanął. Nie gub pozycji przewijania po dołożeniu porcji.
- **Nigdy przewijanie bez końca** (reguła „nigdy” nr 3): nieskończona lista nie
  ma stopki, a w stopce są zasady i przełącznik motywu.
- Nie zamieniaj tego na numerowane strony — „Pokaż więcej” nie wymaga celowania
  w cyfrę 3.
- `koniec` chowa przycisk i zostawia samo zdanie.
