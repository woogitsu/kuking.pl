import React from "react";

/* Składniki: wiersz oddzielony kreską, bez pudełka w pudełku.

   Są LISTĄ, nie akapitem z myślnikami — czytnik podaje wtedy, ile ich jest,
   zanim zacznie czytać.

   Nie chowaj składników pod rozwijany panel: przy garnku mają być widoczne
   obok kroków, a nie za kliknięciem.

   Składniki pisze się tak, jak się mówi w kuchni: „szklanka mąki”, „2 duże
   cebule”, „mleko — ile weźmie”. Nic nie trzeba przeliczać na gramy. */
export function IngredientList({ skladniki = [], className }) {
  return (
    <ul className={className ? "lista-skladnikow " + className : "lista-skladnikow"}>
      {skladniki.map((s, i) => (
        <li key={i}>{s}</li>
      ))}
    </ul>
  );
}
