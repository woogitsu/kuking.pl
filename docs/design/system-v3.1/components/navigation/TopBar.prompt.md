Belka górna — dwa warianty: gość i zalogowany.

```jsx
<TopBar imie="Basia" avatarSrc="/zdjecia/basia.png" />
<TopBar gosc />
```

- Belka jest w **tej samej siatce** co treść pod spodem: pole nigdy nie jest
  szersze od kolumny, którą opisuje.
- **Poniżej 1024 px pole szukania znika w całości** — znak, pole i dwie akcje
  z napisami mają razem około 600 px. Na telefonie szukanie jest pozycją
  dolnego paska.
- **Nie zdejmuj napisów z akcji, żeby się zmieściły.** Ikona bez podpisu jest
  zakazana; jeśli coś się nie mieści, ma zniknąć całe.
- **Poniżej 40rem znikają ikony obu akcji konta**, a napisy zostają. Reguła
  „nigdy” nr 1 działa tu od właściwej strony: ikona jest **ozdobą**, więc ona
  ustępuje pierwsza. Bez ikon „Powiadomienia” i „Konto” mają razem 280 px
  i wchodzą w jeden wiersz; z ikonami miały 348 px przy 332 px miejsca, więc
  druga akcja schodziła niżej i belka rosła do 209 px. Napisy zostają
  w całości — „Powiadomienia” nie ma gdzie się podziać, bo nie ma jej w pięciu
  pozycjach dolnego paska.
- Zapis nazwy idzie w jeden `<span>` — inaczej `gap` rozjeżdża go na „Ku King .pl”.
- Awatar w akcji „Konto” ma `alt=""`, bo napis „Konto” stoi obok.
- Pierwszym elementem strony przed belką jest odnośnik „Przejdź do treści”
  (`tylko-dla-czytnika`, wraca na ekran przy fokusie).
- Na stronie publicznej belka **nie jest przyklejona** — przyklejona zabiera
  wiersz tekstu przy każdym przewinięciu.
