import React from "react";

/* Szkielet strony zalogowanego. JEDNA ZASADA NADRZĘDNA: belka górna, siatka
   treści i stopka mają tę samą szerokość i te same kolumny — zawsze, na każdej
   podstronie.

   Trzecia kolumna istnieje ZAWSZE od 80rem, także wtedy, gdy nie ma czym jej
   wypełnić (D-102). Alternatywą jest strona, która ma inną szerokość na każdej
   podstronie: pusty margines po prawej wygląda spokojnie, a przeskakująca
   nawigacja wygląda na usterkę.

   Trzy progi, nie pięć: 320 px jedna kolumna z dolnym paskiem, 64rem dochodzi
   nawigacja boczna, 80rem dochodzi szyna. */
export function AppShell({ solo = false, naglowek, nawigacja, szyna, stopka, className, children }) {
  return (
    <div className={className ? "app-shell " + className : "app-shell"}>
      <a className="tylko-dla-czytnika" href="#tresc">
        Przejdź do treści
      </a>
      {naglowek}
      <div className={solo ? "app-body app-body-solo" : "app-body"}>
        {solo ? null : nawigacja}
        <main className="app-main" id="tresc">
          {children}
        </main>
        {solo ? null : <aside className="app-rail">{szyna}</aside>}
      </div>
      {stopka}
    </div>
  );
}

/* Sekcja treści z nagłówkiem i zdaniem opisu. */
export function Section({ tytul, opis, akcja, poziom = "h2", className, children }) {
  const Tytul = poziom;
  return (
    <section className={className ? "sekcja " + className : "sekcja"}>
      {tytul ? (
        <div className="sekcja-naglowek">
          <Tytul className="sekcja-tytul">{tytul}</Tytul>
          {akcja}
        </div>
      ) : null}
      {opis ? <p className="sekcja-opis">{opis}</p> : null}
      {children}
    </section>
  );
}
