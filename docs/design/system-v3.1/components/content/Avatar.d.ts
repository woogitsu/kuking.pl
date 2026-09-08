/**
 * Awatar — czyja jest ta karta, ten komentarz, to powiadomienie.
 * @dsAdherence Nigdy poniżej 40 px. Nigdy alt="awatar" i nigdy brak alt. Wariant z literą jest aria-hidden. Awatar nie jest jedynym odnośnikiem do profilu.
 */
export interface AvatarProps {
  /** Adres zdjęcia. Bez niego rysuje się pierwsza litera imienia. */
  src?: string;
  /** Imię — źródło litery i domyślnego alt. */
  imie?: string;
  /** sm 40 px (wiersz, komentarz) · md 48 px (karta) · lg 80 px (główka profilu). */
  rozmiar?: "sm" | "md" | "lg";
  /** Nadpisanie alt. Pusty ciąg, gdy imię stoi obok w tekście. Nigdy „awatar”. */
  alt?: string;
  className?: string;
}

export declare function Avatar(props: AvatarProps): JSX.Element;
