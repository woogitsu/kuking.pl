Wątek komentarzy pod przepisem albo daniem.

```jsx
<CommentThread
  komentarze={[
    { id: 1, autor: "Halina", autorHref: "/@halina", tresc: "U mnie ciasto zawsze się rwie. Ile trzymasz je pod ściereczką?", czas: "wczoraj, 20:10", odpowiedzHref: "/dania/123/odpowiedz/9" },
    { id: 2, autor: "Basia", autorHref: "/@basia", wOdpowiedziDo: "Haliny", tresc: "Pół godziny. I nie wałkuję od razu całego, tylko po kawałku." },
  ]}
/>
```

- **Jeden poziom zagnieżdżenia** i ani jeden więcej.
- Odpowiedź jest oznaczona **słowami** („w odpowiedzi do Haliny”); wcięcie
  z kreską jest dodatkiem dla oka, nie zamiennikiem.
- „Odpowiedz” prowadzi pod adres z formularzem — działa bez skryptu.
- **Nigdy sortowania po popularności.** Kolejność jest chronologiczna.
- Nie zwijaj wątku pod „pokaż 8 odpowiedzi” bez adresu, pod który da się wejść
  bez skryptu.
- Pusty wątek: „Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo
  pierwszy.”
