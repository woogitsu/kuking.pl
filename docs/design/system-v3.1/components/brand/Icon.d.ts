export type NazwaIkony =
  | "dom" | "lupa" | "plus" | "zeszyt" | "osoba" | "dzwonek" | "zegar"
  | "porcje" | "czapka" | "zakladka" | "komentarz" | "garnek" | "aparat"
  | "ostrzezenie" | "ptaszek" | "strzalka" | "ustawienia" | "znak";

/**
 * Ikona z zestawu Kuking. Zawsze obok tekstu, nigdy zamiast tekstu.
 * @dsAdherence Nie stawiaj ikony jako jedynej treści przycisku ani pozycji nawigacji.
 */
export interface IconProps {
  /** Nazwa ikony z zestawu Kuking. */
  name: NazwaIkony;
  /** Klasa rozmiaru z arkusza: side-nav-ikona, bottom-nav-ikona, alert-ikona, dana-przepisu-ikona, wordmark-znak, empty-state-znak. Bez klasy ikona ma 24 px. */
  className?: string;
  style?: React.CSSProperties;
  /**
   * Etykieta dla czytnika ekranu. Podaj TYLKO wtedy, gdy ikona stoi sama i jest
   * jedynym nośnikiem znaczenia (favicon, znak wodny). Ikona obok tekstu jest
   * ozdobą i zostaje aria-hidden — reguła „nigdy” nr 1.
   */
  label?: string;
}

export declare function Icon(props: IconProps): JSX.Element | null;
export declare const NAZWY_IKON: string[];
