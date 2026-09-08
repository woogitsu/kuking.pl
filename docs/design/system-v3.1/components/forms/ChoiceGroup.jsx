import React from "react";

/* Grupa wyboru „Kto to widzi”. Ustawia widoczność wpisu albo przepisu jednym
   spojrzeniem — wszystkie trzy możliwości są widoczne naraz, więc nie trzeba
   niczego rozwijać, żeby dowiedzieć się, co się wybiera.

   NIGDY lista rozwijana: lista chowa dwie z trzech możliwości i wymaga
   kliknięcia, żeby dowiedzieć się, co się traci. Kit v2 rozbił się o to.

   Liczba kolumn bierze się z miejsca, które komponent NAPRAWDĘ MA
   (auto-fit, minmax(min(13rem, 100%), 1fr)), a nie z szerokości okna — D-112.

   fieldset ma min-width: 0 w arkuszu; bez tego grupa miała 919 px w oknie
   768 px. Semantyka fieldset + legend zostaje, bo dzięki niej czytnik ogłasza
   pytanie przy każdej możliwości. */
export const WIDOCZNOSC_DOMYSLNA = [
  { value: "wszyscy", label: "Wszyscy", help: "Także osoby bez konta." },
  { value: "obserwujacy", label: "Tylko obserwujący", help: "Osoby, które Cię obserwują." },
  { value: "ja", label: "Tylko ja", help: "Twój prywatny zeszyt." },
];

export function ChoiceGroup({
  name = "widocznosc",
  legenda = "Kto to widzi",
  opcje = WIDOCZNOSC_DOMYSLNA,
  wybrana,
  onZmiana,
  className,
}) {
  /* Ten sam układ co w `SiteFooter`: z `onZmiana` grupą steruje strona,
     bez niego grupa trzyma wybór u siebie, a `wybrana` jest tylko wartością
     początkową. Bez tego `checked` bez `onChange` zamrażało grupę na amen. */
  const sterowana = typeof onZmiana === "function";
  const [wlasna, ustawWlasna] = React.useState(wybrana != null ? wybrana : (opcje[0] || {}).value);
  const biezaca = sterowana ? wybrana : wlasna;

  return (
    <fieldset className={className ? "field " + className : "field"}>
      <legend className="field-etykieta">{legenda}</legend>
      <div className="choice-grid">
        {opcje.map((o) => {
          const id = name + "-" + o.value;
          return (
            <label className="choice" htmlFor={id} key={o.value}>
              <span className="choice-naglowek">
                <input
                  type="radio"
                  name={name}
                  id={id}
                  value={o.value}
                  checked={biezaca === o.value}
                  onChange={() => (sterowana ? onZmiana(o.value) : ustawWlasna(o.value))}
                />
                <span className="choice-label">{o.label}</span>
              </span>
              <span className="choice-help">{o.help}</span>
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}
