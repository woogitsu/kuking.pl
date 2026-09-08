import React from "react";
import { Avatar } from "./Avatar.jsx";

/* Wiersz autora pod tytułem przepisu. karta-glowka ma wcięcia liczone dla
   wnętrza karty, więc poza kartą rozjeżdża się z tytułem — stąd osobna klasa. */
export function AuthorRow({ imie, href, avatarSrc, dopisek, className }) {
  return (
    <div className={className ? "wiersz-autora " + className : "wiersz-autora"}>
      <Avatar src={avatarSrc} imie={imie} rozmiar="sm" />
      {href ? (
        <a className="karta-autor" href={href}>
          {imie}
        </a>
      ) : (
        <span className="karta-autor">{imie}</span>
      )}
      {dopisek ? <span className="meta">{dopisek}</span> : null}
    </div>
  );
}
