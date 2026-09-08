import React from "react";
import { Icon } from "../brand/Icon.jsx";

const ODMIANY = {
  sukces: { klasa: "alert-sukces", ikona: "ptaszek", rola: "status" },
  blad: { klasa: "alert-blad", ikona: "ostrzezenie", rola: "alert" },
  info: { klasa: "alert-info", ikona: "ostrzezenie", rola: "status" },
};

/* Komunikat. Mówi, że coś się udało, nie udało albo że warto coś wiedzieć,
   zanim się kliknie dalej.

   role="alert" dla błędu (przerywa czytnik, bo człowiek musi to usłyszeć teraz)
   i role="status" dla sukcesu oraz informacji (nie przerywa). Pomyłka w tę
   drugą stronę jest hałasem, w pierwszą — przemilczeniem.

   Komunikat błędu trzyma schemat: co się stało → dlaczego → co zrobić.
   Komunikat stoi NAD treścią, której dotyczy, a nie pod nią. */
export function Alert({ odmiana = "info", ikona, className, children }) {
  const o = ODMIANY[odmiana] || ODMIANY.info;
  return (
    <div className={["alert", o.klasa, className || ""].filter(Boolean).join(" ")} role={o.rola}>
      <Icon name={ikona || o.ikona} className="alert-ikona" />
      <span>{children}</span>
    </div>
  );
}
