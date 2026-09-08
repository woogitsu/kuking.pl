import React from "react";

/* Kroki kreatora. Mówi, na którym z trzech kroków formularza przepisu stoi
   człowiek i ile zostało.

   POSTĘP JEST NAPISANY SŁOWAMI: „Krok 2 z 3 — składniki”. Kropki są dodatkiem
   i mają role="presentation", bo nie niosą nic ponad to zdanie. Trzy identyczne
   kreski nie mówią nic komuś, kto nie zna tej konwencji.

   Kropki nie są klikalne: skok do kroku 3 z pominięciem 2 zostawia szkic
   w stanie, którego serwer nie umie zapisać. */
export function WizardSteps({ krok, ile = 3, nazwa, className }) {
  return (
    <div className={className ? "wizard-steps " + className : "wizard-steps"}>
      <p className="wizard-steps-current">
        Krok {krok} z {ile}
        {nazwa ? <span className="wizard-steps-nazwa"> — {nazwa}</span> : null}
      </p>
      <div className="wizard-steps-track" role="presentation">
        {Array.from({ length: ile }, (_, i) => (
          <span key={i} className="wizard-steps-dot" data-done={i < krok ? "true" : undefined} />
        ))}
      </div>
    </div>
  );
}
