import React from "react";

/* Kroki numerowane licznikiem CSS.

   Kroki są ol, a numer RYSUJE LICZNIK CSS, nie treść. Dzięki temu zmiana
   kolejności nie wymaga przepisywania numerów, a czytnik i tak poda „lista,
   3 pozycje” i numer każdej.

   Nie wpisuj numeru kroku w treść („1. Wymieszaj…”): czytnik przeczyta go dwa
   razy, a przy zmianie kolejności numery kłamią.

   Numer kroku jest atramentem stonowanym, nie kolorem marki: na jednym ekranie
   kolor marki ma przycisk główny, a nie pięć numerków (D-110).
   Wyjątek: na stronie publicznej przepisu numer jest w kolorze marki i większy,
   bo przy garnku szuka się wzrokiem numeru, a nie pierwszego słowa. */
export function StepList({ kroki = [], className }) {
  return (
    <ol className={className ? "kroki " + className : "kroki"}>
      {kroki.map((k, i) => (
        <li key={i}>{k}</li>
      ))}
    </ol>
  );
}
