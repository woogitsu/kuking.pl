import React from "react";
import { Icon } from "../brand/Icon.jsx";

const SZEROKOSCI = {
  rok: "field-input-rok",         /* 8ch  — rok, godzina */
  liczba: "field-input-liczba",   /* 10ch — porcje, minuty, ilość */
  krotkie: "field-input-krotkie", /* 22ch — nazwa składnika, „po kim ten przepis” */
  srednie: "field-input-srednie", /* 40ch — nazwa przepisu, tytuł */
  pelne: "",                      /* 100% — akapit, adres, opis */
};

/* Pole formularza. Zbiera jedną informację i mówi PRZED wpisaniem, czego
   oczekujemy.

   Etykieta stoi NAD polem, podpowiedź też. Człowiek czyta ją, zanim zacznie
   pisać, a nie po tym, jak skończy. Podpowiedź w środku pola znika po pierwszej
   literze i człowiek zostaje z pustym prostokątem — reguła „nigdy” nr 8.

   Szerokość jest dobrana do treści (D-109): prostokąt na czterdzieści znaków
   pod pytaniem „Ile porcji?” mówi człowiekowi, że oczekujemy zdania.

   Oznaczamy WYMAGANE, nie „(nieobowiązkowe)” (D-106) — i nigdy gwiazdką. */
export function Field({
  id,
  etykieta,
  typ = "text",
  szerokosc = "pelne",
  wymagane = false,
  podpowiedz,
  help,
  blad,
  dlugie = false,
  opcje,
  disabled = false,
  powodWylaczenia,
  className,
  children,
  ...reszta
}) {
  const idPodpowiedzi = podpowiedz ? id + "-pod" : null;
  const idBledu = blad ? id + "-blad" : null;
  const idHelp = help ? id + "-help" : null;
  const opisane = [idPodpowiedzi, idHelp, idBledu].filter(Boolean).join(" ") || undefined;

  const klasyPola = ["field-input", SZEROKOSCI[szerokosc] || "", dlugie ? "field-input-dlugie" : ""]
    .filter(Boolean)
    .join(" ");

  const wspolne = {
    className: klasyPola,
    id,
    name: reszta.name || id,
    required: wymagane || undefined,
    disabled: disabled || undefined,
    "aria-describedby": opisane,
    "aria-invalid": blad ? "true" : undefined,
    ...reszta,
  };

  let kontrolka;
  if (typ === "textarea") kontrolka = <textarea {...wspolne} />;
  else if (typ === "select")
    kontrolka = (
      <select {...wspolne}>
        {(opcje || []).map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    );
  else kontrolka = <input type={typ} {...wspolne} />;

  return (
    <div className={["field", blad ? "has-error" : "", className || ""].filter(Boolean).join(" ")}>
      <label className="field-etykieta" htmlFor={id}>
        {etykieta} {wymagane ? <span className="field-wymagane">wymagane</span> : null}
      </label>
      {podpowiedz ? (
        <span className="field-podpowiedz" id={idPodpowiedzi}>
          {podpowiedz}
        </span>
      ) : null}
      {children || kontrolka}
      {help ? (
        <span className="field-help" id={idHelp}>
          {help}
        </span>
      ) : null}
      {disabled && powodWylaczenia ? <span className="field-help">{powodWylaczenia}</span> : null}
      {blad ? (
        <span className="field-error" id={idBledu}>
          <Icon name="ostrzezenie" className="alert-ikona" />
          {blad}
        </span>
      ) : null}
    </div>
  );
}

/* Dwa pola w jednym wierszu. Zawija się przy 320 px i zeruje górny margines
   pól w środku. */
export function FieldRow({ className, children }) {
  return <div className={className ? "rzad-pol " + className : "rzad-pol"}>{children}</div>;
}
