/**
 * „Skąd ten przepis” — najczęściej czytana część przepisu i jedyne miejsce na
 * ekranie przepisu z ciepłym tłem marki.
 * @dsAdherence Nie rób z tego cytatu ozdobnego. To treść, nie ramka na dekorację.
 */
export interface RecipeOriginProps {
  /** Domyślnie „Skąd ten przepis”. */
  tytul?: React.ReactNode;
  /** Odpowiedź na pytanie „po kim ten przepis”: „Po babci Halinie, z Rzeszowszczyzny.” */
  poKim?: React.ReactNode;
  className?: string;
  /** Historia przepisu — kiedy się go gotuje, co się z nim wiąże. */
  children?: React.ReactNode;
}

export declare function RecipeOrigin(props: RecipeOriginProps): JSX.Element;
