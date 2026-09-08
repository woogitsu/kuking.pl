import React from "react";

/* Potwierdzenie akcji destrukcyjnej bez JavaScriptu. Pierwsze kliknięcie
   rozwija pytanie, prawdziwy przycisk stoi dopiero w środku.

   details zamiast confirm() z dwóch powodów naraz: działa bez skryptu
   i JEST WIDOCZNE. Systemowe okno potwierdzenia pojawia się w miejscu, na które
   nikt nie patrzy, i u osób 50+ bywa po prostu przeoczone. */
export function ConfirmDestructive({
  etykieta,
  pytanie,
  potwierdzenie = "Tak, usuń",
  anuluj = "Zostaw",
  action,
  hrefAnuluj = "#",
  onPotwierdz,
  otwarte,
  children,
}) {
  return (
    <details className="confirm" open={otwarte}>
      <summary className="confirm-summary btn btn-danger">{etykieta}</summary>
      <div className="confirm-body">
        <p className="confirm-question">{pytanie}</p>
        {children ? (
          children
        ) : (
          <form className="rzad-przyciskow" action={action} method="post">
            <button type="submit" className="btn btn-danger" onClick={onPotwierdz}>
              {potwierdzenie}
            </button>
            <a className="btn btn-secondary" href={hrefAnuluj}>
              {anuluj}
            </a>
          </form>
        )}
      </div>
    </details>
  );
}
