import React from "react";
import { Avatar } from "../content/Avatar.jsx";

/* Karta wpisu. Pokazuje jedno danie: kto je ugotował, co to jest, jak wygląda
   i co można z tym zrobić. To najczęściej powtarzany element serwisu, więc każdy
   jego głośny szczegół mnoży się przez piętnaście.

   Hierarchia: tytuł 24 px / 800, treść 20 px, autor 18 px / 700, metadane
   15 px w atramencie stonowanym. Kolor marki NIE bierze w tym udziału.

   NIE rób z całej karty jednego linku: czytnik przeczytałby całą kartę jako
   jedną nazwę odnośnika, a w środku i tak są przyciski.

   Zdjęcie jest DOWODEM, że przepis wyszedł u zwykłego człowieka — nigdy
   miniatura, nigdy więcej niż dwie kolumny. */
export function PostCard({
  autor,
  autorHref,
  avatarSrc,
  czas,
  plakietka,
  tytul,
  tytulHref,
  tresc,
  zdjecie,
  alt,
  bezZdjecia,
  zwarta = false,
  poziomTytulu = "h2",
  dane,
  akcje,
  className,
  children,
}) {
  const Tytul = poziomTytulu;
  const klasy = ["karta-wpisu", zwarta ? "karta-wpisu--zwarta" : "", className || ""]
    .filter(Boolean)
    .join(" ");

  return (
    <article className={klasy}>
      {autor ? (
        <div className="karta-glowka">
          <Avatar src={avatarSrc} imie={autor} />
          <div className="karta-glowka-tekst">
            {autorHref ? (
              <a className="karta-autor" href={autorHref}>
                {autor}
              </a>
            ) : (
              <span className="karta-autor">{autor}</span>
            )}
            <div className="karta-meta">
              {czas ? <span>{czas}</span> : null}
              {czas && plakietka ? (
                <span className="karta-meta-kropka" aria-hidden="true">
                  ·
                </span>
              ) : null}
              {plakietka}
            </div>
          </div>
        </div>
      ) : null}

      {tytul ? (
        <Tytul className="karta-tytul">
          {tytulHref ? <a href={tytulHref}>{tytul}</a> : tytul}
        </Tytul>
      ) : null}

      {tresc ? <p className="karta-tresc">{tresc}</p> : null}

      {zdjecie ? <img className="karta-zdjecie" src={zdjecie} alt={alt} /> : null}
      {!zdjecie && bezZdjecia ? <div className="karta-bez-zdjecia">{bezZdjecia}</div> : null}

      {dane && dane.length ? <div className="karta-przepisu-dane">{dane}</div> : null}

      {akcje ? <div className="karta-stopka">{akcje}</div> : null}
      {children}
    </article>
  );
}
