/**
 * Stronicowanie „Pokaż więcej” — dokłada porcję listy i zostawia człowieka
 * w tym samym miejscu strony. Osiem ekranów z listami.
 * @dsAdherence Nigdy przewijanie bez końca. Nigdy numerowane strony. Licznik mówi, ile zostało.
 */
export interface ShowMoreProps {
  /** Ile pozycji jest już widocznych. */
  pokazano?: number;
  /** Ile jest wszystkich. */
  wszystkich: number;
  /** Rzeczownik w dopełniaczu mnogim: „wpisów”, „przepisów”, „powiadomień”. */
  rzeczownik?: string;
  /** Adres GET-a, który dokłada porcję. */
  action?: string;
  /** Numer następnej strony w ukrytym polu. */
  nastepnaStrona?: number;
  /** Koniec listy: przycisku nie ma, zostaje zdanie. */
  koniec?: boolean;
  onPokazWiecej?: (e: React.FormEvent) => void;
}

export declare function ShowMore(props: ShowMoreProps): JSX.Element;
