import React from "react";

/* Pole zaznaczenia. Włącza albo wyłącza jedną rzecz, zawsze niezależną od
   pozostałych — inaczej to jest grupa wyboru, nie pole zaznaczenia.

   Klikalny jest CAŁY wiersz, bo input siedzi w label. Wiersz ma minimum 44 px
   wysokości, kwadracik 24 px — palec trafia w wiersz.

   Tekst etykiety jest pełnym zdaniem, nie hasłem. Przy rzeczy nieodwracalnej
   zdanie mówi wprost, co się stanie. */
export function Checkbox({ id, disabled = false, powodWylaczenia, className, children, ...reszta }) {
  return (
    <label className={className ? "pole-zaznaczenia " + className : "pole-zaznaczenia"} htmlFor={id}>
      <input type="checkbox" id={id} name={reszta.name || id} disabled={disabled || undefined} {...reszta} />
      <span>
        {children}
        {disabled && powodWylaczenia ? <span className="pomoc"> ({powodWylaczenia})</span> : null}
      </span>
    </label>
  );
}
