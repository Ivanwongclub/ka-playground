/**
 * WhyIcon — bespoke 24×24 stroke icons for the Overview > Why Join cards.
 * Same spec as the KA icon family: stroke-only, 1.8 stroke-width, rounded
 * caps/joins, currentColor. Keyword-based dispatch so programme_content
 * can either set an explicit `icon` kind or have one inferred from the title.
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

/* Ribbon-style award — laurel-medal with trailing ribbons. */
function AwardIcon(props: IconProps) {
  return (
    <Base {...props}>
      <circle cx="12" cy="9.4" r="5.4" />
      <path d="M9 13.2 L7.2 20 L12 17.6 L16.8 20 L15 13.2" />
    </Base>
  );
}

/* Three overlapping people heads — small live class. */
function UsersClusterIcon(props: IconProps) {
  return (
    <Base {...props}>
      <circle cx="8.2" cy="9" r="2.6" />
      <circle cx="15.8" cy="9" r="2.6" />
      <circle cx="12" cy="7.4" r="2.6" />
      <path d="M3.6 18.4 Q4.2 13.4 8.2 13.4 Q10 13.4 11 14.4" />
      <path d="M20.4 18.4 Q19.8 13.4 15.8 13.4 Q14 13.4 13 14.4" />
      <path d="M7.4 19 Q8.4 14.4 12 14.4 Q15.6 14.4 16.6 19" />
    </Base>
  );
}

/* Sparkle / spark — adaptive AI. */
function SparkleIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M12 3.4 L13.4 9.4 L19.4 10.8 L13.4 12.2 L12 18.2 L10.6 12.2 L4.6 10.8 L10.6 9.4 Z" />
      <path d="M18.4 16.6 L19 19 L21.4 19.6 L19 20.2 L18.4 22.6 L17.8 20.2 L15.4 19.6 L17.8 19 Z" />
    </Base>
  );
}

/* Document with a check — exam ready. */
function CheckDocIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M6.4 3.6 H14.2 L18.4 7.8 V20.4 H6.4 Z" />
      <path d="M14.2 3.6 V7.8 H18.4" />
      <path d="M8.8 13.6 L11.2 16 L15.8 11.2" />
    </Base>
  );
}

/* Trophy — competition / race. */
function TrophyIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M7.6 4.4 H16.4 V9.2 Q16.4 13.4 12 13.4 Q7.6 13.4 7.6 9.2 Z" />
      <path d="M7.6 6 H4.4 V8 Q4.4 10.2 7.6 10.6" />
      <path d="M16.4 6 H19.6 V8 Q19.6 10.2 16.4 10.6" />
      <path d="M9.4 19.6 H14.6" />
      <path d="M12 13.4 V17 Q12 19.6 9.4 19.6" />
      <path d="M12 17 Q12 19.6 14.6 19.6" />
    </Base>
  );
}

/* Checkered flag — racing / F1. */
function FlagIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M5 3.6 V20.4" />
      <path d="M5 4.6 H19.4 V13.4 H5" />
      <path d="M5 6.8 H9 V8.8 H12.6 V6.8 H16.4 V8.8 H19.4" />
      <path d="M9 8.8 V11 H12.6 M5 11 H9 V13.4 M12.6 11 H16.4 V13.4" />
    </Base>
  );
}

/* Wrench / tool — design + build. */
function ToolIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M14.4 4.4 A4.4 4.4 0 0 0 9.2 9.8 L4.6 14.4 Q3.6 15.4 4.6 16.4 L7.6 19.4 Q8.6 20.4 9.6 19.4 L14.2 14.8 A4.4 4.4 0 0 0 19.6 9.6 L17.2 12 L14.6 11.4 L14 8.8 Z" />
    </Base>
  );
}

/* Code brackets. */
function CodeIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M8.4 7.6 L3.6 12 L8.4 16.4" />
      <path d="M15.6 7.6 L20.4 12 L15.6 16.4" />
      <path d="M13.6 5.4 L10.4 18.6" />
    </Base>
  );
}

/* Brush — creative / art. */
function BrushIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M19.4 4.6 Q20.6 5.8 19.4 7 L13 13.4" />
      <path d="M10.4 12.4 L11.6 13.6" />
      <path d="M11 14 Q12.2 15.2 12.2 16.8 Q12.2 19 9.2 19 Q5.6 19 4.6 18 Q6.4 17.2 6.4 14.8 Q7.6 13.2 9.2 13.2 Q10.4 13.2 11 14 Z" />
    </Base>
  );
}

/* Function / calculator — maths. */
function CalcIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M5 4.6 H19 V19.4 H5 Z" />
      <path d="M5 9.2 H19" />
      <path d="M8 13 L10 15 M10 13 L8 15" />
      <path d="M13.4 13.4 H16.4 M13.4 16 H16.4" />
      <path d="M8.4 7 H15.6" />
    </Base>
  );
}

const KEYWORD_MAP: Array<[RegExp, string]> = [
  [/(racing|f1|race|competition|compete|grand prix)/i, "trophy"],
  [/(checker|flag|track)/i, "flag"],
  [/(certif|accredit|approved|endorsed|partner|official)/i, "award"],
  [/(small|live class|team|cohort|group|of 3|of 6|of 8)/i, "users"],
  [/(ai|adaptive|personali[sz]ed|smart|intelligent)/i, "sparkle"],
  [/(exam|ready|tested|mock|assessment|yle|ket|pet)/i, "check-doc"],
  [/(design|build|cad|engineer|prototype|maker|fusion)/i, "tool"],
  [/(code|coding|software|programming|developer|tech)/i, "code"],
  [/(art|creative|paint|draw|studio|portfolio|brush)/i, "brush"],
  [/(math|logic|number|calcul|equation|function)/i, "calc"],
  [/(discipline|skills|roles|all-round|multi)/i, "sparkle"],
];

export function whyIconKind(item: { icon?: string; t?: string }): string {
  const explicit = item.icon?.toLowerCase();
  if (explicit) {
    if (/(award|certif|ribbon|accredit)/.test(explicit)) return "award";
    if (/(live|small|class|users|team|cluster)/.test(explicit)) return "users";
    if (/(ai|adaptive|spark|brain|personali)/.test(explicit)) return "sparkle";
    if (/(exam|ready|test|check|doc)/.test(explicit)) return "check-doc";
    if (/(racing|trophy|compet)/.test(explicit)) return "trophy";
    if (/(flag|checker)/.test(explicit)) return "flag";
    if (/(design|build|tool|wrench|cad)/.test(explicit)) return "tool";
    if (/(code|tech|bracket)/.test(explicit)) return "code";
    if (/(art|brush|creative)/.test(explicit)) return "brush";
    if (/(math|calc|function|logic)/.test(explicit)) return "calc";
  }
  const t = item.t ?? "";
  for (const [re, kind] of KEYWORD_MAP) if (re.test(t)) return kind;
  return "sparkle";
}

export function WhyIcon({ kind, ...rest }: IconProps & { kind: string }) {
  switch (kind) {
    case "award": return <AwardIcon {...rest} />;
    case "users": return <UsersClusterIcon {...rest} />;
    case "sparkle": return <SparkleIcon {...rest} />;
    case "check-doc": return <CheckDocIcon {...rest} />;
    case "trophy": return <TrophyIcon {...rest} />;
    case "flag": return <FlagIcon {...rest} />;
    case "tool": return <ToolIcon {...rest} />;
    case "code": return <CodeIcon {...rest} />;
    case "brush": return <BrushIcon {...rest} />;
    case "calc": return <CalcIcon {...rest} />;
    default: return <SparkleIcon {...rest} />;
  }
}

/* Highlight tile icons — distinct from the why icons. */
export function FormatIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M3.6 5.6 H20.4 V16.4 H3.6 Z" />
      <path d="M8.4 19.4 H15.6" />
      <path d="M12 16.4 V19.4" />
      <circle cx="12" cy="11" r="1.6" />
    </Base>
  );
}

export function ClassSizeIcon(props: IconProps) {
  return <UsersClusterIcon {...props} />;
}

export function CertificationIcon(props: IconProps) {
  return <AwardIcon {...props} />;
}

export function CalendarIcon(props: IconProps) {
  return (
    <Base {...props}>
      <path d="M4.4 6.4 H19.6 V19.6 H4.4 Z" />
      <path d="M4.4 10 H19.6" />
      <path d="M8.4 4 V7.6" />
      <path d="M15.6 4 V7.6" />
    </Base>
  );
}
