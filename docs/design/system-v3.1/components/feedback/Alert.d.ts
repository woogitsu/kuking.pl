/**
 * Komunikat — coś się udało, nie udało, albo warto to wiedzieć przed kliknięciem.
 * @dsAdherence role="alert" tylko dla błędu, role="status" dla reszty. Nigdy kod HTTP. Nigdy żart w błędzie. Nigdy komunikat, który sam znika po dwóch sekundach.
 */
export interface AlertProps {
  odmiana?: "sukces" | "blad" | "info";
  /** Nadpisanie domyślnej ikony odmiany. */
  ikona?: string;
  className?: string;
  /** Przy błędzie: co się stało, dlaczego, co zrobić. Zdanie bez trzeciej części jest niedokończone. */
  children: React.ReactNode;
}

export declare function Alert(props: AlertProps): JSX.Element;
