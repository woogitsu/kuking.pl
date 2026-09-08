import React from "react";
import { Icon } from "../brand/Icon.jsx";

/* Dolny pasek nawigacji. To samo co nawigacja boczna, na telefonie: pięć miejsc
   w zasięgu kciuka. Poniżej 1024 px.

   DOKŁADNIE PIĘĆ POZYCJI, zawsze te same. „Dodaj” jest jedyną pozycją
   z kolorem marki, bo to główna akcja produktu — i kółko „Dodaj” MA PODPIS.

   Nie dokładaj szóstej pozycji: pięć podpisów przy 320 px i skali 140% ma
   razem około 346 px, czyli więcej niż okno. Nie zamieniaj podpisów na same
   ikony, żeby zrobić miejsce. Nie ukrywaj paska przy przewijaniu — znikająca
   nawigacja jest u tej grupy odbiorców odbierana jako awaria.

   Bieżąca pozycja ma trzypikselową kreskę u góry ORAZ kolor i grubszy napis —
   trzy sygnały, z których każdy działa osobno. */
export const POZYCJE_DOLNE = [
  { href: "/home", label: "Start", ikona: "dom", klucz: "start" },
  { href: "/szukaj", label: "Szukaj", ikona: "lupa", klucz: "szukaj" },
  { href: "/dodaj", label: "Dodaj", ikona: "plus", klucz: "dodaj", glowna: true },
  { href: "/zeszyt", label: "Zeszyt", ikona: "zeszyt", klucz: "zeszyt" },
  { href: "/moje", label: "Moje", ikona: "osoba", klucz: "moje" },
];

export function BottomNav({ biezaca, pozycje = POZYCJE_DOLNE, onPrzejdz, className }) {
  return (
    <nav className={className ? "bottom-nav " + className : "bottom-nav"} aria-label="Nawigacja dolna">
      {pozycje.map((p) => (
        <a
          key={p.klucz}
          className={p.glowna ? "bottom-nav-item bottom-nav-item-glowna" : "bottom-nav-item"}
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
          {p.glowna ? (
            <span className="bottom-nav-kolko">
              <Icon name={p.ikona} className="bottom-nav-ikona" />
            </span>
          ) : (
            <Icon name={p.ikona} className="bottom-nav-ikona" />
          )}
          {p.label}
        </a>
      ))}
    </nav>
  );
}
