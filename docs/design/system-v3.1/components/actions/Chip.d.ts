/**
 * Chip zakresu — zawęża listę wyników do jednego rodzaju rzeczy.
 * Strona tagu i ekran szukania.
 * @dsAdherence Maksimum pięć chipów. Nigdy pasek przewijany w bok. Nigdy sama ikona. Chip ma 48 px.
 */
export interface ChipProps {
  /** Podany adres zamienia chip na odnośnik z aria-current. Bez adresu chip jest przyciskiem z aria-pressed. */
  href?: string;
  /** Bieżący zakres: tło brand-tint, obwódka marki, napis grubszy. */
  biezacy?: boolean;
  className?: string;
  children: React.ReactNode;
}

/** Rząd chipów. Zawija się do drugiego wiersza, nigdy nie przewija w poziomie. */
export interface ChipRowProps {
  /** Nazwa nawigacji dla czytnika ekranu. */
  etykieta?: string;
  className?: string;
  children: React.ReactNode;
}

export declare function Chip(props: ChipProps): JSX.Element;
export declare function ChipRow(props: ChipRowProps): JSX.Element;
