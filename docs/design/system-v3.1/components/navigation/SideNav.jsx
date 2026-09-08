import React from "react";
import { Icon } from "../brand/Icon.jsx";

/* Nawigacja boczna. Stała lista sześciu miejsc serwisu na ekranie od 1024 px.

   Nie zwijaj jej do samych ikon na węższym ekranie: poniżej 1024 px znika cała
   i zastępuje ją dolny pasek. Nie zmieniaj kolejności pozycji między
   podstronami — nawigacja ma być przewidywalna, nie dowcipna. Nie dokładaj
   siódmej pozycji bez usunięcia innej.

   Bieżąca pozycja ma aria-current="page" I grubszy napis — kolor nie jest
   jedynym nośnikiem. */
export const POZYCJE_NAWIGACJI = [
  { href: "/home", label: "Start", ikona: "dom", klucz: "start" },
  { href: "/szukaj", label: "Szukaj", ikona: "lupa", klucz: "szukaj" },
  { href: "/dodaj", label: "Dodaj", ikona: "plus", klucz: "dodaj" },
  { href: "/zeszyt", label: "Zeszyt", ikona: "zeszyt", klucz: "zeszyt" },
  { href: "/powiadomienia", label: "Powiadomienia", ikona: "dzwonek", klucz: "powiadomienia" },
  { href: "/moje", label: "Moje", ikona: "osoba", klucz: "moje" },
];

export function SideNav({ biezaca, pozycje = POZYCJE_NAWIGACJI, onPrzejdz, className }) {
  return (
    <nav className={className ? "side-nav " + className : "side-nav"} aria-label="Nawigacja główna">
      <ul className="side-nav-lista">
        {pozycje.map((p) => (
          <li key={p.klucz}>
            <a
              className="side-nav-item"
              href={p.href}
              aria-current={biezaca === p.klucz ? "page" : undefined}
              onClick={
                onPrzejdz
                  ? (e) => {
                      e.preventDefault();
                      onPrzejdz(p.klucz);
                    }
                  : undefined
              }
            >
              <Icon name={p.ikona} className="side-nav-ikona" />
              {p.label}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  );
}
