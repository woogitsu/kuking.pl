/**
 * Lista składników — wiersz oddzielony kreską, bez pudełka w pudełku.
 * Ekran przepisu i panel przepisu.
 * @dsAdherence Zawsze lista, nigdy akapit z myślnikami. Nie chowaj składników pod rozwijany panel. Składniki pisze się tak, jak się mówi w kuchni.
 */
export interface IngredientListProps {
  /** „3 szklanki mąki”, „mleko — ile weźmie”. Nic nie trzeba przeliczać na gramy. */
  skladniki: React.ReactNode[];
  className?: string;
}

export declare function IngredientList(props: IngredientListProps): JSX.Element;
