import React from "react";
import { Icon } from "../brand/Icon.jsx";

/* Cztery wagi, sześć stanów. Zawsze tekst — ikona najwyżej mu towarzyszy.

   button do akcji, a do przejścia pod adres. Link stylizowany na przycisk
   zostaje linkiem: da się go otworzyć w nowej karcie, a czytnik czyta
   „odnośnik”, nie „przycisk”.

   Wyłączony przycisk NIGDY nie jest cichy — obok zawsze stoi zdanie, co
   zrobić, żeby go odblokować (reguła „nigdy” nr 6). */
export function Button({
  waga = "secondary",
  duzy = false,
  pelny = false,
  href,
  type = "button",
  disabled = false,
  ariaDisabled = false,
  wyjasnienie,
  ikona,
  className,
  children,
  ...reszta
}) {
  const klasy = [
    "btn",
    "btn-" + waga,
    duzy ? "btn-duzy" : "",
    pelny ? "btn-pelny" : "",
    className || "",
  ]
    .filter(Boolean)
    .join(" ");

  const wnetrze = (
    <>
      {ikona ? <Icon name={ikona} className="side-nav-ikona" /> : null}
      {children}
    </>
  );

  const kontrolka = href ? (
    <a className={klasy} href={href} aria-disabled={ariaDisabled ? "true" : undefined} {...reszta}>
      {wnetrze}
    </a>
  ) : (
    <button
      className={klasy}
      type={type}
      disabled={disabled}
      aria-disabled={ariaDisabled ? "true" : undefined}
      {...reszta}
    >
      {wnetrze}
    </button>
  );

  if (!wyjasnienie) return kontrolka;
  return (
    <span>
      {kontrolka}
      <span className="btn-wyjasnienie">{wyjasnienie}</span>
    </span>
  );
}
