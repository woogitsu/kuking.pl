import React from "react";

/* „Skąd ten przepis” — NAJCZĘŚCIEJ CZYTANA część przepisu i jedyne miejsce na
   tym ekranie z ciepłym tłem marki.

   Jest section z nagłówkiem, bo to samodzielna część dokumentu, po której
   ludzie skaczą.

   Nie rób z tego cytatu ozdobnego. To treść, którą ludzie czytają najczęściej,
   a nie ramka na dekorację. */
export function RecipeOrigin({ tytul = "Skąd ten przepis", poKim, className, children }) {
  return (
    <section className={className ? "pochodzenie " + className : "pochodzenie"}>
      <h2 className="pochodzenie-tytul">{tytul}</h2>
      {poKim ? <p className="pochodzenie-po-kim">{poKim}</p> : null}
      {children}
    </section>
  );
}
