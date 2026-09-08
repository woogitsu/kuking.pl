import React from "react";

/* Rząd przycisków. Zawija się przy 320 px i skali 140% zamiast wystawać. */
export function ButtonRow({ className, children, ...reszta }) {
  return (
    <div className={className ? "rzad-przyciskow " + className : "rzad-przyciskow"} {...reszta}>
      {children}
    </div>
  );
}
