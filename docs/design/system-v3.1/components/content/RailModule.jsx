import React from "react";
import { Avatar } from "./Avatar.jsx";

/* Moduł szyny. Wypełnia trzecią kolumnę rzeczami, których w kolumnie głównej
   nie ma. Najczęściej jest to „kuKINGi na dziś”.

   SZYNA NIGDY NIE POWTARZA KOLUMNY GŁÓWNEJ — ten sam wpis dwa razy na jednym
   ekranie był problemem nr 4 dzisiejszej tablicy.

   Nie rób z tego rankingu: bez „Top”, bez liczników pozycji, bez „najlepsi
   w tym tygodniu”. Publiczne rankingi dzielą ludzi na dwie klasy i wyłączają
   publikowanie u większości.

   Szyny nie ma poniżej 1280 px — jeśli treść jest potrzebna, ma drugą kopię
   w tylko-waskie. Nigdy obie kopie widoczne naraz. */
export function RailModule({ tytul, podtytul, stopka, id, className, children }) {
  const idTytulu = id || "szyna-tytul";
  return (
    <section
      className={className ? "szyna-modul " + className : "szyna-modul"}
      aria-labelledby={idTytulu}
    >
      <h2 className="szyna-tytul" id={idTytulu}>
        {tytul}
      </h2>
      {podtytul ? <p className="szyna-podtytul">{podtytul}</p> : null}
      {children}
      {stopka ? <p className="szyna-stopka">{stopka}</p> : null}
    </section>
  );
}

/* Wiersz z osobą. Zawija się, a szyna-osoba-tekst ma bazę spacing-24: przy
   zwykłej skali przycisk stoi obok imienia, a przy powiększonym tekście schodzi
   niżej — zamiast łamać imię w środku wyrazu. „Obserwujesz” zmienia NAPIS,
   nie tylko aria-pressed i kolor. */
export function RailPerson({ imie, opis, avatarSrc, href, obserwowana = false, onObserwuj }) {
  return (
    <div className="szyna-osoba">
      <Avatar src={avatarSrc} imie={imie} rozmiar="sm" />
      <div className="szyna-osoba-tekst">
        {href ? (
          <a className="szyna-osoba-imie" href={href}>
            {imie}
          </a>
        ) : (
          <span className="szyna-osoba-imie">{imie}</span>
        )}
        <span className="szyna-osoba-opis">{opis}</span>
      </div>
      <button
        type="submit"
        className="btn btn-secondary"
        aria-pressed={obserwowana ? "true" : "false"}
        onClick={onObserwuj}
      >
        {obserwowana ? "Obserwujesz" : "Obserwuj"}
      </button>
    </div>
  );
}

/* Wiersz z daniem. Zdjęcie jest małe CELOWO: szyna wskazuje, gdzie zajrzeć,
   a nie pokazuje dania — od tego jest strumień. */
export function RailDish({ tytul, opis, zdjecie, alt = "", href }) {
  return (
    <a className="szyna-danie" href={href}>
      <img className="szyna-danie-zdjecie" src={zdjecie} alt={alt} />
      <span>
        <span className="szyna-danie-tytul">{tytul}</span>
        <span className="szyna-osoba-opis">{opis}</span>
      </span>
    </a>
  );
}
