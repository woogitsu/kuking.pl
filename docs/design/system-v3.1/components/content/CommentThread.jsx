import React from "react";
import { Avatar } from "./Avatar.jsx";
import { RowList } from "./NotificationRow.jsx";

/* Wątek komentarzy pod przepisem albo daniem: pytania o zamienniki, odpowiedzi
   autora, uwagi.

   JEDEN POZIOM ZAGNIEŻDŻENIA, nie więcej. Odpowiedź jest oznaczona SŁOWAMI
   („w odpowiedzi do Haliny”), a wcięcie z kreską jest dodatkiem dla oka, nie
   zamiennikiem: samo wcięcie przy 320 px jest za małe, żeby je zauważyć, a przy
   trzech poziomach zjada połowę szerokości.

   Kolejność jest chronologiczna — nigdy „po popularności”. */
export function CommentThread({ komentarze = [], pusty = "Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo pierwszy." }) {
  if (!komentarze.length) return <p className="pomoc">{pusty}</p>;
  return (
    <RowList>
      {komentarze.map((k) => (
        <li className={k.wOdpowiedziDo ? "wiersz watek-odpowiedzi" : "wiersz"} key={k.id}>
          <Avatar src={k.avatarSrc} imie={k.autor} rozmiar="sm" />
          <div className="wiersz-tresc">
            <a className="karta-autor" href={k.autorHref}>
              {k.autor}
            </a>
            {k.wOdpowiedziDo ? <span className="meta">w odpowiedzi do {k.wOdpowiedziDo}</span> : null}
            <p>{k.tresc}</p>
            <div className="karta-meta">
              <span>{k.czas}</span>
              {k.odpowiedzHref ? (
                <>
                  <span className="karta-meta-kropka" aria-hidden="true">
                    ·
                  </span>
                  <a href={k.odpowiedzHref}>Odpowiedz</a>
                </>
              ) : null}
            </div>
          </div>
        </li>
      ))}
    </RowList>
  );
}
