import React from "react";

const ROZMIARY = { sm: "avatar-sm", md: "", lg: "avatar-lg" };

/* Awatar. Pokazuje, czyja jest ta karta, ten komentarz, to powiadomienie.

   Bez zdjęcia — pierwsza litera imienia na tle brand-tint, z aria-hidden:
   pojedyncza litera przeczytana przez czytnik jest szumem. Nigdy sylwetka
   z ikony zamiast litery — wygląda jak zdjęcie, które się nie wczytało.

   Nigdy poniżej 40 px. Twarz w kółku 24 px jest plamą.

   Awatar sam nie bywa jedynym odnośnikiem do profilu — obok zawsze stoi imię
   i ono też jest odnośnikiem. */
export function Avatar({ src, imie, rozmiar = "md", alt, className }) {
  const klasy = ["avatar", ROZMIARY[rozmiar] || "", className || ""].filter(Boolean).join(" ");
  if (src) {
    return (
      <span className={klasy}>
        <img src={src} alt={alt != null ? alt : imie ? "Zdjęcie profilowe: " + imie : ""} />
      </span>
    );
  }
  return (
    <span className={klasy} aria-hidden="true">
      {(imie || "?").trim().charAt(0).toUpperCase()}
    </span>
  );
}
