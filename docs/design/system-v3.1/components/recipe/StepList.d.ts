/**
 * Kroki przepisu, numerowane licznikiem CSS. Ekran przepisu.
 * @dsAdherence Nigdy numeru kroku w treści. Numer rysuje licznik CSS. Jeden krok to jedna czynność.
 */
export interface StepListProps {
  /** Jeden krok to jedna czynność — łatwiej to czytać przy garnku. */
  kroki: React.ReactNode[];
  className?: string;
}

export declare function StepList(props: StepListProps): JSX.Element;
