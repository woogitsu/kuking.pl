import React from "react";
import { Avatar } from "./Avatar.jsx";
import { Badge } from "../feedback/Badge.jsx";

/* Wiersz powiadomienia. Mówi, że ktoś ugotował z Twojego przepisu, napisał
   komentarz albo zaczął Cię obserwować.

   Nieprzeczytany poznaje się po tle I PO SŁOWIE „nowe” — kolor nigdy nie jest
   jedynym nośnikiem informacji.

   Powiadomienie jest pełnym zdaniem z imieniem i rzeczą: „Halina ugotowała Twój
   rosół.”, nie „Nowa aktywność”.

   Nie dawaj powiadomieniom kształtu karty: to krótkie, jednorodne wiersze —
   karta w tym miejscu udaje treść, której nie ma. Nie wkładaj do wiersza dwóch
   akcji; cały wiersz prowadzi w jedno miejsce. */
export function NotificationRow({ imie, avatarSrc, tresc, czas, nieprzeczytane = false }) {
  return (
    <li className={nieprzeczytane ? "wiersz wiersz-nieprzeczytany" : "wiersz"}>
      <Avatar src={avatarSrc} imie={imie} rozmiar="sm" />
      <div className="wiersz-tresc">
        <p>
          {tresc} {nieprzeczytane ? <Badge waga="spokojny">nowe</Badge> : null}
        </p>
        <span className="wiersz-czas">{czas}</span>
      </div>
    </li>
  );
}

/* Lista wierszy — wspólna otoczka powiadomień i komentarzy. Jest ul, więc
   czytnik poda liczbę pozycji. */
export function RowList({ className, children }) {
  return <ul className={className ? "lista-wierszy " + className : "lista-wierszy"}>{children}</ul>;
}
