import React from "react";

/* Tekst na zdjęciu. Kładzie tytuł na zdjęciu tam, gdzie zdjęcie jest większe
   od karty: hero strony powitalnej i główka przepisu.

   PODKŁAD JEST ZAWSZE — także wtedy, gdy zdjęcie akurat jest ciemne, bo
   następne nie będzie. Reguła „nigdy” nr 7. Biały napis na podkładzie ma
   18.37:1 w motywie jasnym i 21.00:1 w ciemnym, niezależnie od tego, co jest
   na talerzu.

   Podkład jest pełny u dołu i przechodzi w przezroczystość ku górze, więc
   działa także na białym talerzu. Nie rozjaśniaj go „żeby było ładniej” —
   kontrast jest tu policzony, nie dobrany na oko.

   Napis i podpis MUSZĄ być elementami blokowymi (p), bo ich odstępy są
   marginesami. W span skleją się w jeden wiersz.

   Na zdjęciu nie kładziemy przycisków. Akcja stoi pod kadrem. */
export function PhotoWithCaption({ src, alt, napis, podpis, href, className }) {
  const Element = href ? "a" : "div";
  return (
    <Element
      className={className ? "zdjecie-z-napisem " + className : "zdjecie-z-napisem"}
      href={href}
    >
      <img src={src} alt={alt} />
      <div className="zdjecie-podklad">
        <p className="zdjecie-napis">{napis}</p>
        {podpis ? <p className="zdjecie-podpis">{podpis}</p> : null}
      </div>
    </Element>
  );
}
