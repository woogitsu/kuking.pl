/**
 * Zapis nazwy KuKing.pl — znak plus napis. Belka górna i stopka.
 * @dsAdherence Kolor marki ma tylko „.pl” i garnek. Nie podświetlaj „King”.
 */
export interface WordmarkProps {
  /** Adres, pod który prowadzi znak. Domyślnie strona główna. */
  href?: string;
  /** Znak (garnek) przed napisem. Bez znaku tylko tam, gdzie garnek już stoi obok. */
  zeZnakiem?: boolean;
  className?: string;
}

export declare function Wordmark(props: WordmarkProps): JSX.Element;
