import React from "react";
import { PostCard } from "./PostCard.jsx";
import { Icon } from "../brand/Icon.jsx";

const IKONY = { czas: "zegar", porcje: "porcje", poziom: "czapka" };

/* Karta przepisu. To karta wpisu z jedną rzeczą więcej: paskiem danych, który
   odpowiada na trzy pytania zadawane PRZED gotowaniem — ile to trwa, na ile
   osób i czy dam radę.

   Każda dana ma ikonę I PODPIS: „90 minut”, nie zegarek z liczbą 90. Jednostki
   pełnym słowem, liczby cyframi.

   TRZY DANE TO MAKSIMUM. Czwarta zaczyna wyglądać jak tabela.

   Poziomu trudności NIE zamieniamy na gwiazdki ani kropki: „Średnie” jest
   słowem, które każdy rozumie tak samo; trzy z pięciu gwiazdek nie. */
export function RecipeCard({ czasPrzygotowania, porcje, poziom, ...reszta }) {
  const dane = [
    czasPrzygotowania ? ["czas", czasPrzygotowania] : null,
    porcje ? ["porcje", porcje] : null,
    poziom ? ["poziom", poziom] : null,
  ].filter(Boolean);

  return (
    <PostCard
      {...reszta}
      dane={dane.map(([rodzaj, tekst]) => (
        <span className="dana-przepisu" key={rodzaj}>
          <Icon name={IKONY[rodzaj]} className="dana-przepisu-ikona" />
          {tekst}
        </span>
      ))}
    />
  );
}
