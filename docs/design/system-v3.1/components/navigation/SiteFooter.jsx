import React from "react";

/* Stopka. Trzyma odnośniki prawne, zdanie o serwisie i JEDYNE MIEJSCE,
   w którym przełącza się motyw.

   Przełącznik motywu jest FORMULARZEM, nie skryptem. Działa bez JavaScriptu,
   a wybór zapisuje się na koncie. Wybrany motyw ma aria-pressed="true"
   I inną wagę przycisku.

   Motyw bierze się wyłącznie z wyboru człowieka — reguły prefers-color-scheme
   w arkuszu nie ma i nie będzie (D-019). Telefon nie ma prawa przełączać
   wyglądu sam.

   Nie rób z przełącznika ikonki słońca i księżyca: dwa przyciski z napisami
   „Jasny” i „Ciemny” mówią, co się stanie. Nie chowaj przełącznika
   w ustawieniach i nigdzie indziej. Nie wkładaj do stopki nawigacji serwisu. */
const LINKI = [
  { href: "/zasady", label: "Zasady" },
  { href: "/prywatnosc", label: "Prywatność" },
  { href: "/regulamin", label: "Regulamin" },
  { href: "/pomoc", label: "Pomoc" },
];

export function SiteFooter({
  linki = LINKI,
  motyw,
  onMotyw,
  zdanie = "Prowadzimy to na własną rękę.",
  className,
}) {
  /* Sterowanie z zewnątrz, gdy strona trzyma wybór u siebie. Bez `motyw`
     i `onMotyw` stopka radzi sobie sama: przestawia `data-theme` na <html>,
     żeby podgląd motywu działał w każdym szablonie i na każdej karcie.
     W serwisie na Blade wybór i tak zapisuje POST — dlatego to jest formularz,
     a nie przycisk sterowany skryptem. */
  const [wlasny, ustawWlasny] = React.useState(null);
  const sterowana = motyw != null;
  const biezacy = sterowana ? motyw : wlasny || "jasny";

  React.useEffect(() => {
    /* Dopiero po kliknięciu. Przy montowaniu stopka nie dotyka <html>, żeby nie
       skasować motywu ustawionego przez stronę, która sama go trzyma. */
    if (sterowana || wlasny == null) return;
    document.documentElement.setAttribute("data-theme", wlasny === "ciemny" ? "dark" : "light");
  }, [wlasny, sterowana]);

  const przelacz = (nowy) => {
    if (onMotyw) onMotyw(nowy);
    if (!sterowana) ustawWlasny(nowy);
  };

  return (
    <footer className={className ? "stopka " + className : "stopka"}>
      <div className="stopka-wnetrze">
        <p className="stopka-linki">
          {linki.map((l) => (
            <a href={l.href} key={l.href}>
              {l.label}
            </a>
          ))}
        </p>
        <form
          className="rzad-przyciskow stopka-wyglad"
          action="/ustawienia/motyw"
          method="post"
          onSubmit={(e) => e.preventDefault()}
        >
          <span className="pomoc">Wygląd strony:</span>
          <button
            type="submit"
            className={biezacy === "jasny" ? "btn btn-secondary" : "btn btn-quiet"}
            name="motyw"
            value="jasny"
            aria-pressed={biezacy === "jasny" ? "true" : "false"}
            onClick={() => przelacz("jasny")}
          >
            Jasny
          </button>
          <button
            type="submit"
            className={biezacy === "ciemny" ? "btn btn-secondary" : "btn btn-quiet"}
            name="motyw"
            value="ciemny"
            aria-pressed={biezacy === "ciemny" ? "true" : "false"}
            onClick={() => przelacz("ciemny")}
          >
            Ciemny
          </button>
        </form>
        <p className="meta">{zdanie}</p>
      </div>
    </footer>
  );
}
