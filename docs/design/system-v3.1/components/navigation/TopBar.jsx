import React from "react";
import { Wordmark } from "../brand/Wordmark.jsx";
import { Icon } from "../brand/Icon.jsx";
import { Avatar } from "../content/Avatar.jsx";

/* Belka górna. Trzyma znak serwisu, wejście do szukania i dwie akcje konta
   w tym samym miejscu na każdej podstronie.

   Belka jest w TEJ SAMEJ siatce co treść pod spodem: pole nigdy nie jest
   szersze od kolumny, którą opisuje.

   PONIŻEJ 1024 px pole szukania znika W CAŁOŚCI — znak, pole i dwie akcje
   z napisami mają razem około 600 px. Na telefonie szukanie jest pozycją
   dolnego paska. Nie zdejmujemy napisów z akcji, żeby się zmieściły: ikona bez
   podpisu jest zakazana; jeśli coś się nie mieści, ma zniknąć całe.

   Pole siedzi w topbar-szukaj-kolumna, bo topbar-szukaj jest rzędem i sam
   ułożyłby etykietę OBOK pola. */
export function TopBar({
  gosc = false,
  imie = "Basia",
  avatarSrc,
  placeholder = "Szukaj przepisu albo osoby",
  onSzukaj,
  className,
}) {
  return (
    <header className={className ? "topbar " + className : "topbar"}>
      <div className="topbar-wnetrze">
        <Wordmark href="/" />

        {gosc ? null : (
          <form className="topbar-szukaj" role="search" action="/szukaj" onSubmit={onSzukaj}>
            <span className="topbar-szukaj-kolumna">
              <label className="tylko-dla-czytnika" htmlFor="q">
                Szukaj przepisu albo osoby
              </label>
              <input className="field-input" type="search" id="q" name="q" placeholder={placeholder} />
            </span>
            <button type="submit" className="btn btn-secondary">
              <Icon name="lupa" className="side-nav-ikona" />
              Szukaj
            </button>
          </form>
        )}

        <div className="topbar-akcje">
          {gosc ? (
            <>
              <a className="btn btn-quiet" href="/logowanie">
                Zaloguj
              </a>
              <a className="btn btn-primary" href="/rejestracja">
                Zostań kuKINGiem
              </a>
            </>
          ) : (
            <>
              <a className="btn btn-quiet" href="/powiadomienia">
                <Icon name="dzwonek" className="side-nav-ikona" />
                Powiadomienia
              </a>
              <a className="btn btn-quiet" href={"/@" + imie.toLowerCase()}>
                <Avatar src={avatarSrc} imie={imie} rozmiar="sm" alt="" />
                Konto
              </a>
            </>
          )}
        </div>
      </div>
    </header>
  );
}
