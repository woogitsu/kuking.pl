import React from "react";
import { Icon } from "../brand/Icon.jsx";

const WAGI = {
  cichy: "badge-cichy",       /* bez tła i ramki, 15 px — to, co powtarza się w każdym elemencie listy */
  spokojny: "badge-spokojny", /* tło wgłębione — domyślna: „Szkic”, „Tylko ja”, „nowe” */
  cooked: "badge-cooked",     /* akcent — „Ugotowano 12 razy”. RAZ na ekran */
  sukces: "badge-sukces",
  blad: "badge-blad",
};

/* Plakietka. Dokłada do elementu jedno słowo o jego stanie albo pochodzeniu.

   REGUŁA, KTÓRA RZĄDZI TYM KOMPONENTEM (D-103): plakietkę głośną wolno użyć
   RAZ na ekran. Jeśli coś powtarza się w każdym elemencie listy, to
   z definicji jest ciche.

   Plakietka nie ma stanów. Nie da się jej nacisnąć, sfokusować ani wyłączyć.
   Jeśli musi być klikalna — to jest chip, nie plakietka. */
export function Badge({ waga = "spokojny", ikona, className, children }) {
  const klasy = ["badge", WAGI[waga] || WAGI.spokojny, className || ""].filter(Boolean).join(" ");
  return (
    <span className={klasy}>
      {ikona ? <Icon name={ikona} className="dana-przepisu-ikona" /> : null}
      {children}
    </span>
  );
}
