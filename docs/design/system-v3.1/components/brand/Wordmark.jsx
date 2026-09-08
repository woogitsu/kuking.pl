import React from "react";
import { Icon } from "./Icon.jsx";

/* Zapis nazwy w belce. „KuKing” jest w kolorze ATRAMENTU, kolor marki dostaje
   wyłącznie „.pl” i znak (BRAND_IDENTITY sekcja 2). Podświetlenie samego „King”
   kolorem marki jest błędem znaczeniowym: ogłasza żart, który ma być odkrywany.

   Cały napis idzie w JEDEN span, bo klasa wordmark ma gap między dziećmi
   i rozbity napis rozjeżdża się na „Ku King .pl”. */
export function Wordmark({ href = "/", zeZnakiem = true, className }) {
  const klasy = className ? "wordmark " + className : "wordmark";
  return (
    <a className={klasy} href={href}>
      {zeZnakiem ? <Icon name="znak" className="wordmark-znak" /> : null}
      <span>
        KuKing<span className="wordmark-koncowka">.pl</span>
      </span>
    </a>
  );
}
