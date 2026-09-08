import React from "react";
import { Icon } from "../brand/Icon.jsx";

/* Pusty stan. Mówi, co się tu pojawi i co zrobić, żeby się pojawiło.

   Tytuł NIE jest nagłówkiem strony — jest p, żeby nie zaśmiecać konspektu
   nagłówków fałszywym poziomem. Nagłówkiem jest tytuł sekcji nad nim.

   Nigdy „Brak danych” ani „Nic tu nie ma” bez ciągu dalszego: pusty stan bez
   akcji jest ślepym zaułkiem. Znak 64 px wystarczy — obrazek na pół ekranu
   spycha akcję poniżej krawędzi. */
export function EmptyState({ znak = "znak", tytul, opis, className, children }) {
  return (
    <div className={className ? "empty-state " + className : "empty-state"}>
      <Icon name={znak} className="empty-state-znak" />
      <p className="empty-state-title">{tytul}</p>
      {opis ? <p className="empty-state-opis">{opis}</p> : null}
      {children}
    </div>
  );
}
