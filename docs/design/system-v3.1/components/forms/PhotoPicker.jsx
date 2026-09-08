import React from "react";
import { Icon } from "../brand/Icon.jsx";

/* Wybór zdjęcia. Dodaje zdjęcie z telefonu albo z komputera, PO POLSKU.

   Natywny input type="file" rysuje „Choose File / No file chosen” po angielsku
   i żaden atrybut tego nie zmienia (D-107). Rozwiązanie: input schowany dla oka
   (nie dla czytnika), a widoczny jest label for, który go wyzwala. Działa bez
   JavaScriptu.

   KOLEJNOŚĆ W KODZIE JEST WYMUSZONA: input musi stać BEZPOŚREDNIO PRZED
   etykietą, bo reguła fokusu to
   .wybor-zdjecia-input:focus-visible + .wybor-zdjecia. */
export function PhotoPicker({
  id = "zdjecie",
  tytul = "Dodaj zdjęcie",
  opis = "Na telefonie kliknij tutaj, a potem wybierz „Galeria” albo „Zrób zdjęcie”.",
  accept = "image/jpeg,image/png,image/webp",
  blad,
  className,
  ...reszta
}) {
  return (
    <div className={className}>
      <input
        className="wybor-zdjecia-input"
        type="file"
        id={id}
        name={reszta.name || id}
        accept={accept}
        aria-describedby={blad ? id + "-blad" : undefined}
        {...reszta}
      />
      <label className="wybor-zdjecia" htmlFor={id}>
        <Icon name="aparat" className="wybor-zdjecia-ikona" />
        <span className="wybor-zdjecia-tytul">{tytul}</span>
        <span className="wybor-zdjecia-opis">{opis}</span>
      </label>
      {blad ? (
        <span className="field-error" id={id + "-blad"}>
          <Icon name="ostrzezenie" className="alert-ikona" />
          {blad}
        </span>
      ) : null}
    </div>
  );
}
