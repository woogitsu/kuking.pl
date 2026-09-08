import React from "react";

/* Zestaw ikon Kuking, przepisany z podglad/ikony-sprite.html paczki źródłowej.
   Kreska 2 px, zaokrąglone końce, currentColor — ikona bierze kolor po tekście,
   więc w trybie ciemnym nie trzeba niczego przełączać.

   Geometria jest tu wklejona wprost, a nie ładowana przez use href="…svg#id":
   zewnętrzny sprite nie działa przez granicę dokumentu ani przy otwarciu strony
   z dysku. Plik sprite'u leży w assets/icons/kuking-ikony.svg dla widoków Blade. */

const P = (d) => ["path", d];
const C = (cx, cy, r, filled) => ["circle", cx, cy, r, filled];

const GLYPHS = {
  dom: [P("M3 10.5 12 3l9 7.5"), P("M5.5 9.5V20h13V9.5"), P("M9.5 20v-5.5h5V20")],
  lupa: [C(11, 11, 6.5), P("m16 16 4.5 4.5")],
  plus: [P("M12 5v14M5 12h14")],
  zeszyt: [
    P("M6 3.5h12a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1Z"),
    P("M8.5 3.5v17"), P("M12 8h4M12 12h4"),
  ],
  osoba: [C(12, 8.5, 3.75), P("M4.5 20c0-3.6 3.4-5.5 7.5-5.5s7.5 1.9 7.5 5.5")],
  dzwonek: [P("M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15Z"), P("M10 20a2 2 0 0 0 4 0")],
  zegar: [C(12, 12, 8.5), P("M12 7.5V12l3 2")],
  porcje: [
    C(9, 9, 3), P("M3 19c0-3 2.7-4.5 6-4.5s6 1.5 6 4.5"),
    P("M16 6.5a3 3 0 0 1 0 5.5"), P("M17.5 14.8c2 .6 3.5 1.9 3.5 4.2"),
  ],
  czapka: [
    P("M7 16.5c-2.5-.4-4-2.2-4-4.4C3 9.3 5 7.5 7.4 7.6 8 5.5 9.8 4.2 12 4.2s4 1.3 4.6 3.4C19 7.5 21 9.3 21 12.1c0 2.2-1.5 4-4 4.4"),
    P("M7 16.5h10v3H7z"),
  ],
  zakladka: [P("M7 4h10v16l-5-3.5L7 20V4Z")],
  komentarz: [P("M4 6.5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H10l-4.5 3.5V16.5H6a2 2 0 0 1-2-2Z")],
  garnek: [
    P("M4.5 8.5h15V14c0 3.6-2.4 6-7.5 6s-7.5-2.4-7.5-6Z"),
    P("M4.5 10.5C2.8 10.5 2 11.4 2 12.7c0 1.4 1 2.3 2.5 2.3"),
    P("M19.5 10.5c1.7 0 2.5.9 2.5 2.2 0 1.4-1 2.3-2.5 2.3"),
    P("M8 5.5c0-1 .8-1.5 1.5-1.5M12 4.5c0-1 .8-1.5 1.5-1.5"),
  ],
  aparat: [P("M3.5 8.5h3.2l1.4-2.4h7.8l1.4 2.4h3.2v10.5H3.5Z"), C(12, 13.5, 3.4)],
  ostrzezenie: [P("M12 4 2.8 20h18.4Z"), P("M12 10v4.5"), C(12, 17.3, 1.1, true)],
  ptaszek: [P("m4.5 12.5 5 5 10-11")],
  strzalka: [P("M4.5 12h15"), P("m13.5 6 6 6-6 6")],
  ustawienia: [
    C(12, 12, 3.2),
    P("M12 2.8v2.4M12 18.8v2.4M4.5 12H2.1M21.9 12h-2.4M6.2 6.2 4.5 4.5M19.5 19.5l-1.7-1.7M17.8 6.2l1.7-1.7M4.5 19.5l1.7-1.7"),
  ],
};

const GRUBSZE = { plus: 2.5, ptaszek: 2.5 };

/* Znak marki: garnek w koronie z WYCIĘTYM uśmiechem (maska), nie malowanym na
   biało. Malowany uśmiech na przycisku w kolorze marki rysuje kreskę w kolorze,
   którego tam nie ma — 06-marka/ZNAK.md sekcja 6. */
function Znak({ className, style, label }) {
  const id = "kuking-wyciecie-" + React.useId().replace(/:/g, "");
  return (
    <svg
      className={className}
      style={style}
      viewBox="0 0 64 64"
      focusable="false"
      role={label ? "img" : undefined}
      aria-label={label || undefined}
      aria-hidden={label ? undefined : "true"}
    >
      <mask id={id} maskUnits="userSpaceOnUse" x="0" y="0" width="64" height="64">
        <rect x="0" y="0" width="64" height="64" fill="#FFFFFF"></rect>
        <path d="M24 43 C28 47 36 47 40 41" fill="none" stroke="#000000" strokeWidth="3.5" strokeLinecap="round"></path>
        <path d="M18 28 C27 30 37 30 46 28" fill="none" stroke="#000000" strokeWidth="3" strokeLinecap="round"></path>
      </mask>
      <g mask={"url(#" + id + ")"} fill="currentColor" stroke="currentColor">
        <path d="M18 22 L16 15 L24 19 L32 11 L40 19 L48 15 L46 22 C39 25 25 25 18 22 Z" strokeWidth="2" strokeLinejoin="round"></path>
        <circle cx="16" cy="14" r="3.5" stroke="none"></circle>
        <circle cx="32" cy="9" r="3.5" stroke="none"></circle>
        <circle cx="48" cy="14" r="3.5" stroke="none"></circle>
        <path d="M17 29 H47 V38 C47 49 41 54 32 54 C23 54 17 49 17 38 Z" stroke="none"></path>
        <path d="M17 32 C12 29 9 31 9 36 C9 42 13 44 18 42" fill="none" strokeWidth="5" strokeLinecap="round"></path>
        <path d="M47 32 C52 29 55 31 55 36 C55 42 51 44 46 42" fill="none" strokeWidth="5" strokeLinecap="round"></path>
      </g>
    </svg>
  );
}

export function Icon({ name, className, style, label }) {
  if (name === "znak") return <Znak className={className} style={style} label={label} />;
  const glyph = GLYPHS[name];
  if (!glyph) return null;
  const wymiar = className ? undefined : { width: "1.5rem", height: "1.5rem" };
  return (
    <svg
      className={className}
      style={{ ...wymiar, ...style }}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={GRUBSZE[name] || 2}
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
      role={label ? "img" : undefined}
      aria-label={label || undefined}
      aria-hidden={label ? undefined : "true"}
    >
      {glyph.map((el, i) =>
        el[0] === "path" ? (
          <path key={i} d={el[1]} />
        ) : (
          <circle
            key={i}
            cx={el[1]}
            cy={el[2]}
            r={el[3]}
            fill={el[4] ? "currentColor" : undefined}
            stroke={el[4] ? "none" : undefined}
          />
        )
      )}
    </svg>
  );
}

export const NAZWY_IKON = Object.keys(GLYPHS).concat("znak");
