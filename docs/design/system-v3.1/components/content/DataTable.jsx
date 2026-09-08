import React from "react";

/* Tabela. Jedyny kształt treści w serwisie, którego nie da się złożyć z kart:
   zestawienie danych w długim dokumencie prawnym.

   Tabela przewija się WE WŁASNYM pudełku, nigdy razem ze stroną. To drugi,
   po karuzeli zdjęć, świadomy wyjątek od zakazu przewijania w poziomie.

   Nad tabelą stoi zdanie mówiące, co w niej jest — przy 320 px widać naraz
   jedną kolumnę i to zdanie bywa jedyną orientacją.

   Nie zamieniaj tabeli na listę definicji „bo lepiej się zawija”: zestawienie
   trzech kolumn jest tabelą i czytnik ma prawo to wiedzieć. */
export function DataTable({ naglowki = [], wiersze = [], wstep, className }) {
  return (
    <>
      {wstep ? <p className="pomoc">{wstep}</p> : null}
      <div className={className ? "tabela-otoczka " + className : "tabela-otoczka"}>
        <table className="tabela">
          <thead>
            <tr>
              {naglowki.map((n, i) => (
                <th scope="col" key={i}>
                  {n}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {wiersze.map((w, i) => (
              <tr key={i}>
                {w.map((k, j) => (
                  <td key={j}>{k}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
