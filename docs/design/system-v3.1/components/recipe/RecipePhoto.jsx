import React from "react";

/* Jedyne zdjęcie w serwisie poza kartą: 3:2 zamiast 4:3, bo pod nim stoi
   panel, a nie tekst wpisu. */
export function RecipePhoto({ src, alt, className }) {
  return <img className={className ? "przepis-zdjecie " + className : "przepis-zdjecie"} src={src} alt={alt} />;
}
