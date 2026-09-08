import React from "react";

/* Stronicowanie „Pokaż więcej”. Dokłada następną porcję listy i zostawia
   człowieka w tym samym miejscu strony.

   Licznik MÓWI, ILE ZOSTAŁO. Lista bez widocznego końca jest u tej grupy
   odbiorców powodem, żeby przestać przewijać. Nigdy przewijanie bez końca —
   nieskończona lista nie ma stopki, a w stopce są zasady i przełącznik motywu. */
export function ShowMore({ pokazano, wszystkich, rzeczownik = "wpisów", action, nastepnaStrona, koniec = false, onPokazWiecej }) {
  return (
    <div className="srodek stos">
      <p className="pomoc" aria-live="polite">
        {koniec
          ? "To wszystko, co tu jest — " + wszystkich + " " + rzeczownik + "."
          : "Pokazujemy " + pokazano + " z " + wszystkich + " " + rzeczownik + "."}
      </p>
      {koniec ? null : (
        <form action={action} method="get" onSubmit={onPokazWiecej}>
          {nastepnaStrona ? <input type="hidden" name="strona" value={nastepnaStrona} /> : null}
          <button type="submit" className="btn btn-secondary btn-duzy">
            Pokaż więcej
          </button>
        </form>
      )}
    </div>
  );
}
