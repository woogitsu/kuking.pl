Chip zakresu — zawęża listę wyników. Ekran szukania i strona tagu.

```jsx
<ChipRow etykieta="Zakres wyników">
  <Chip href="?zakres=wszystko" biezacy>Wszystko</Chip>
  <Chip href="?zakres=przepisy">Przepisy</Chip>
  <Chip href="?zakres=dania">Dania</Chip>
  <Chip href="?zakres=osoby">Osoby</Chip>
</ChipRow>
```

- Chip jest przyciskiem, więc ma **48 px**, nie 32.
- Bieżący poznaje się po `aria-current` **i po grubszym napisie**, nie po kolorze.
- **Maksimum pięć chipów.** Szósty znaczy, że potrzebna jest inna nawigacja.
- Chipy zawijają się do drugiego wiersza. Nigdy pasek przewijany w bok:
  przewijana lista chowa część możliwości i wymaga gestu.
- Zakres bez wyników pokazuje pusty stan, a nie wyłączony chip.
