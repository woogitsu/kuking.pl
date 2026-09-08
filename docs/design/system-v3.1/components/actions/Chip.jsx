import React from "react";

/* Chip zakresu. Jest przyciskiem, więc ma 48 px, nie 32 — rząd chipów to rząd
   celów dotyku, a nie ozdoba nad wynikami. Bieżący zakres poznaje się po
   aria-current I po grubości napisu; kolor nie jest jedynym nośnikiem. */
export function Chip({ href, biezacy = false, className, children, ...reszta }) {
  const klasy = className ? "chip " + className : "chip";
  if (href) {
    return (
      <a className={klasy} href={href} aria-current={biezacy ? "true" : undefined} {...reszta}>
        {children}
      </a>
    );
  }
  return (
    <button className={klasy} type="submit" aria-pressed={biezacy ? "true" : "false"} {...reszta}>
      {children}
    </button>
  );
}

/* Rząd chipów jest nawigacją z etykietą, żeby czytnik nazwał go, zanim zacznie
   wyliczać. Chipy ZAWIJAJĄ się do drugiego wiersza — nigdy nie przewijają się
   w poziomie. */
export function ChipRow({ etykieta = "Zakres wyników", className, children }) {
  return (
    <nav className={className ? "chipsy " + className : "chipsy"} aria-label={etykieta}>
      {children}
    </nav>
  );
}
