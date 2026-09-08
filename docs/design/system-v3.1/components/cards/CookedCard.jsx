import React from "react";
import { Avatar } from "../content/Avatar.jsx";

/* Karta „Ugotowałem”. Pokazuje, że przepis wyszedł u kogoś w domu. To
   najmilsza część dla autora przepisu i jedyny dowód, że przepis w ogóle
   działa.

   Zdanie „ugotowała z przepisu X” jest TEKSTEM, nie ikoną garnka — czytnik
   przeczyta pełne zdanie, a nie „garnek, odnośnik”.

   NIE dawaj tu głośnej plakietki „Ugotowałem”: cała sekcja nazywa się „Komu
   wyszło”, a plakietka powtórzona w każdej karcie przestaje cokolwiek znaczyć
   (D-103).

   To nie jest ocena. Bez gwiazdek, bez „wyszło / nie wyszło”, bez procentów. */
export function CookedCard({
  autor,
  autorHref,
  avatarSrc,
  przepis,
  przepisHref,
  forma = "ugotowała",
  czas,
  tresc,
  zdjecie,
  alt,
  className,
}) {
  return (
    <article className={className ? "karta-wpisu " + className : "karta-wpisu"}>
      <div className="karta-glowka">
        <Avatar src={avatarSrc} imie={autor} />
        <div className="karta-glowka-tekst">
          <a className="karta-autor" href={autorHref}>
            {autor}
          </a>
          <div className="karta-meta">
            <span>
              {forma} z przepisu{" "}
              {przepisHref ? <a href={przepisHref}>{przepis}</a> : przepis}
              {czas ? (
                <span style={{ whiteSpace: "nowrap" }}>
                  {" "}
                  <span className="karta-meta-kropka" aria-hidden="true">
                    ·
                  </span>{" "}
                  {czas}
                </span>
              ) : null}
            </span>
          </div>
        </div>
      </div>
      {tresc ? <p className="karta-tresc">{tresc}</p> : null}
      {zdjecie ? <img className="karta-zdjecie" src={zdjecie} alt={alt} /> : null}
    </article>
  );
}
