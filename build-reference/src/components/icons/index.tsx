/**
 * KA Icon family — bespoke 24×24 stroke icons.
 * Spec: stroke-only, 1.8 stroke-width, rounded caps/joins, currentColor.
 * Personality: slightly geometric, slightly hand-drawn (Phosphor/Iconoir feel),
 * with gentle curves where most icon sets use sharp angles.
 */
import type { SVGProps } from "react";

type IconProps = SVGProps<SVGSVGElement> & { size?: number | string };

function Base({ size = 24, children, ...rest }: IconProps & { children: React.ReactNode }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...rest}
    >
      {children}
    </svg>
  );
}

/* Envelope with a small gold seal-dot on the flap. */
export function MailIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M3.5 6.5 Q3.5 5.2 4.8 5.2 H19.2 Q20.5 5.2 20.5 6.5 V17.5 Q20.5 18.8 19.2 18.8 H4.8 Q3.5 18.8 3.5 17.5 Z" />
      <path d="M3.9 6.8 L12 13 L20.1 6.8" />
      {/* gold seal dot — own colour, not currentColor */}
      <circle cx="12" cy="13" r="0.95" fill="var(--gold, #c9a962)" stroke="none" />
    </Base>
  );
}

/* Rounded-shackle padlock with a tiny gold keyhole accent. */
export function LockIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M4.6 11.6 Q4.6 10.4 5.8 10.4 H18.2 Q19.4 10.4 19.4 11.6 V19.2 Q19.4 20.4 18.2 20.4 H5.8 Q4.6 20.4 4.6 19.2 Z" />
      <path d="M7.6 10.4 V7.6 Q7.6 3.6 12 3.6 Q16.4 3.6 16.4 7.6 V10.4" />
      {/* keyhole — gold accent */}
      <circle cx="12" cy="14.6" r="1.15" fill="var(--gold, #c9a962)" stroke="none" />
      <path d="M12 15.4 V17.2" stroke="var(--gold, #c9a962)" />
    </Base>
  );
}

/* Almond eye with a soft pupil. */
export function EyeIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M2.4 12 Q6.8 5.2 12 5.2 Q17.2 5.2 21.6 12 Q17.2 18.8 12 18.8 Q6.8 18.8 2.4 12 Z" />
      <circle cx="12" cy="12" r="2.8" />
    </Base>
  );
}

export function EyeOffIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M4.2 4.6 L19.8 19.4" />
      <path d="M9.2 5.6 Q10.5 5.2 12 5.2 Q17.2 5.2 21.6 12 Q20.3 14 18.7 15.4" />
      <path d="M15.4 17.6 Q13.8 18.8 12 18.8 Q6.8 18.8 2.4 12 Q4 9.6 5.9 8" />
      <path d="M9.7 9.9 A2.8 2.8 0 0 0 14 14.2" />
    </Base>
  );
}

export function CheckIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M4.8 12.4 L9.6 17 L19.4 7.2" />
    </Base>
  );
}

export function ArrowRightIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M4.4 12 H19.2" />
      <path d="M13.6 5.8 L19.8 12 L13.6 18.2" />
    </Base>
  );
}

export function ArrowLeftIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M19.6 12 H4.8" />
      <path d="M10.4 5.8 L4.2 12 L10.4 18.2" />
    </Base>
  );
}

/* 5-point star with rounded tips and slightly varied arm lengths
   so it reads alive, not stamped. */
export function StarIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M12 3.1 L14.15 8.55 L19.85 8.95 L15.45 12.7 L16.95 18.55 L12 15.35 L7.05 18.7 L8.55 12.85 L4.15 8.85 L9.95 8.45 Z" />
    </Base>
  );
}

export function SearchIcon(props: IconProps) {
  return (
    <Base {...props}>
      <circle cx="10.6" cy="10.6" r="6.4" />
      <path d="M15.4 15.4 L20.2 20.2" />
    </Base>
  );
}

export function BellIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M6 16.2 Q5.4 16.2 5.4 15.6 Q5.4 15.2 5.7 14.9 L7 13.6 V10.4 Q7 6.4 12 6.4 Q17 6.4 17 10.4 V13.6 L18.3 14.9 Q18.6 15.2 18.6 15.6 Q18.6 16.2 18 16.2 Z" />
      <path d="M10.2 19 Q10.8 20 12 20 Q13.2 20 13.8 19" />
      <path d="M12 4.4 V6.4" />
    </Base>
  );
}

export function SunIcon(props: IconProps) {
  return (
    <Base {...props}>
      <circle cx="12" cy="12" r="3.6" />
      <path d="M12 3.2 V5.2" />
      <path d="M12 18.8 V20.8" />
      <path d="M3.2 12 H5.2" />
      <path d="M18.8 12 H20.8" />
      <path d="M5.8 5.8 L7.2 7.2" />
      <path d="M16.8 16.8 L18.2 18.2" />
      <path d="M5.8 18.2 L7.2 16.8" />
      <path d="M16.8 7.2 L18.2 5.8" />
    </Base>
  );
}

export function MoonIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M20 14.4 Q18.6 15.2 17 15.2 Q12 15.2 12 10.2 Q12 6.8 14.6 5.2 Q9.2 4.6 6 8.6 Q3.2 12 5 16.4 Q7 21 12 21 Q17.2 21 20 14.4 Z" />
    </Base>
  );
}

export function ChevronDownIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M5.6 9 L12 15.4 L18.4 9" />
    </Base>
  );
}

export function ChatIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M4.2 6.2 Q4.2 4.8 5.6 4.8 H18.4 Q19.8 4.8 19.8 6.2 V14.6 Q19.8 16 18.4 16 H9.6 L5.6 19.4 V16 Q4.2 16 4.2 14.6 Z" />
    </Base>
  );
}

export function GlobeIcon(props: IconProps) {
  return (
    <Base {...props}>
      <circle cx="12" cy="12" r="8.4" />
      <path d="M3.6 12 H20.4" />
      <path d="M12 3.6 Q7.2 7.6 7.2 12 Q7.2 16.4 12 20.4" />
      <path d="M12 3.6 Q16.8 7.6 16.8 12 Q16.8 16.4 12 20.4" />
    </Base>
  );
}
