import React from "react";
import { Icon } from "../brand/Icon.jsx";

/* Plakietka autozapisu. Mówi, że szkic jest bezpieczny, żeby nikt nie bał się
   zamknąć strony w połowie długiego formularza.

   aria-live="polite", NIGDY assertive: człowiek właśnie pisze, więc czytnik ma
   dokończyć zdanie i dopiero potem powiedzieć „Szkic zapisany”.

   Element z aria-live musi być w drzewie ZANIM zmieni się jego treść —
   wstawiony razem z tekstem nie zostanie ogłoszony.

   Kropka na końcu jest częścią tekstu i zostaje. Bez wykrzyknika. */
export function AutosaveBadge({ tekst = "Szkic zapisany.", className }) {
  return (
    <p className={className ? "autosave-badge " + className : "autosave-badge"} aria-live="polite">
      <Icon name="ptaszek" className="alert-ikona" />
      {tekst}
    </p>
  );
}
