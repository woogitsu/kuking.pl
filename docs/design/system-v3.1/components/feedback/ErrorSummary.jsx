import React from "react";

/* Podsumowanie błędów. Zbiera na górze formularza wszystko, czego brakuje,
   i prowadzi do każdego pola jednym kliknięciem — także wtedy, gdy formularz
   ma cztery tysiące pikseli wysokości.

   Dwa nagłówki, zależnie od liczby: „Jednej rzeczy jeszcze brakuje” i „Kilku
   rzeczy jeszcze brakuje”. Liczba mnoga przy jednym błędzie jest drobnym
   kłamstwem, które ludzie zauważają.

   Skok do pola działa BEZ SKRYPTU: to zwykły odnośnik #id, a element wskazany
   adresem dostaje w warstwie base tę samą obwódkę co przy fokusie. */
export function ErrorSummary({ bledy = [], className }) {
  if (!bledy.length) return null;
  const tytul = bledy.length === 1 ? "Jednej rzeczy jeszcze brakuje" : "Kilku rzeczy jeszcze brakuje";
  return (
    <div className={className ? "error-summary " + className : "error-summary"} role="alert" tabIndex={-1}>
      <h2 className="error-summary-title">{tytul}</h2>
      <ul>
        {bledy.map((b) => (
          <li key={b.id}>
            <a href={"#" + b.id}>{b.tekst}</a>
          </li>
        ))}
      </ul>
    </div>
  );
}
