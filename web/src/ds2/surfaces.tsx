// DS2 v2 surface primitives (R0-B1) — DS2-V2-SPEC §2/§3. ZERO adoption in this card; only the dev gallery
// imports them. i18n-native (all text arrives as already-translated ReactNode; no hardcoded user-facing
// string), styled ONLY through --ka-* tokens (no literal colours). Interactive AntD (Button) is USED, never
// re-implemented. A `to` cta renders a react-router <Link> (semantic <a>, native keyboard/focus); an
// `onClick` cta renders a role=button — see DS2-V2-SPEC A2 / R0-B1 FLAG B.
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from 'antd';
import { Link } from 'react-router-dom';
import { asset } from '../assets';
import { LocaleSwitcher } from '../components/LocaleSwitcher';
import { Seal } from './atoms';
import { SubPanel } from './structure';
import type { UrgencyLevel } from '../display/urgency';
import './surfaces.css';

// ── shared: a single-action CTA (a datum, not a slot) ────────────────────────────────────────────────
// `to` (navigate) XOR `onClick` (handler) — the spec's discriminated union for every one-action primitive.
export type Ds2Cta = { label: ReactNode; to: string } | { label: ReactNode; onClick: () => void };

function CtaButton({ cta, primary = false, block = false, size = 'small' }: { cta: Ds2Cta; primary?: boolean; block?: boolean; size?: 'small' | 'middle' }) {
  const btn = (
    <Button
      type={primary ? 'primary' : 'default'}
      size={size}
      block={block}
      className={primary ? 'ka-cta' : undefined}
      onClick={'onClick' in cta ? cta.onClick : undefined}
    >
      {cta.label}
    </Button>
  );
  return 'to' in cta ? <Link to={cta.to}>{btn}</Link> : btn;
}

// ── PageCard / AuthCard (§2.1) — the standalone top-level card SubPanel is not ────────────────────────
export type PageCardWidth = 'auth' | 'form' | 'wide' | number;

/** A solid var(--ka-card) card that sits directly on the page background (NOT a zone — that is SubPanel).
 *  Width-constrained; optionally centred in a full-height column; elevated by default. */
export function PageCard({
  width = 'form',
  children,
  center = true,
  elevated = true,
}: {
  width?: PageCardWidth;
  children: ReactNode;
  center?: boolean;
  elevated?: boolean;
}) {
  const w = typeof width === 'number' ? `min(${width}px, 100%)` : `var(--ka-w-${width})`;
  const card = (
    <div className={`ds2-pagecard${elevated ? ' ds2-pagecard--elevated' : ''}`} style={{ width: w }}>
      {children}
    </div>
  );
  return center ? <div className="ds2-pagecard-center">{card}</div> : card;
}

/** The auth/public preset — a PageCard with the brand logo + optional LocaleSwitcher header. `logoAlt` is
 *  an <img alt> so it is a string (R0-B1 FLAG A). AuthCard owns the brand mark + switcher by design. */
export function AuthCard({
  logoAlt,
  localeSwitcher = true,
  width = 'form',
  children,
}: {
  logoAlt: string;
  localeSwitcher?: boolean;
  width?: PageCardWidth;
  children: ReactNode;
}) {
  return (
    <PageCard width={width} center elevated>
      <div className="ds2-authcard__head">
        {localeSwitcher && <div className="ds2-authcard__switch"><LocaleSwitcher /></div>}
        <img className="ds2-authcard__logo" src={asset('brand/armour-academy-logo.webp')} alt={logoAlt} />
      </div>
      {children}
    </PageCard>
  );
}

// ── HeroBanner (§2.2) — imagery + scrim, with the full loading/fallback behaviour ────────────────────
type HeroStatus = 'loading' | 'ok' | 'error';

/** An imagery band with the §11.2 duotone scrim. Height is reserved before load (no layout shift); the
 *  base shimmers while loading and falls back (given `fallback`, else a flat gradient) on image error;
 *  foreground contrast is guaranteed by the SCRIM, not the image. */
export function HeroBanner({
  image,
  height = 'band',
  scrim = 'duotone',
  children,
  fallback,
}: {
  image: { src: string; alt: string };  // alt feeds <img alt> → string (see FLAG A)
  // 'band' = a PAGE hero · 'card' = a card's image band (the prototype's 96px .prog-band) · 'tall' =
  // the marketing hero. Three roles, three heights — 'card' exists because shrinking 'band' would have
  // shrunk every page hero with it (B1R rider 1).
  height?: 'band' | 'card' | 'tall';
  scrim?: 'duotone' | 'bottom' | 'none';
  children: ReactNode;
  fallback?: ReactNode;
}) {
  const [status, setStatus] = useState<HeroStatus>('loading');
  return (
    <div className={`ds2-hero ds2-hero--${height} ds2-hero--${status}`}>
      {status !== 'error' && (
        <img
          className="ds2-hero__img"
          src={image.src}
          alt={image.alt}
          loading="lazy"
          decoding="async"
          onLoad={() => setStatus('ok')}
          onError={() => setStatus('error')}
        />
      )}
      {status === 'error' && fallback != null && <div className="ds2-hero__fallback">{fallback}</div>}
      {scrim !== 'none' && <div className={`ds2-hero__scrim ds2-hero__scrim--${scrim}`} aria-hidden />}
      <div className="ds2-hero__content">{children}</div>
    </div>
  );
}

// ── UrgencyChip (§3) — one treatment for expiring/overdue/SLA ─────────────────────────────────────────
const CLOCK = (
  <svg viewBox="0 0 24 24" className="ds2-uchip__i" aria-hidden><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
);
const ALERT = (
  <svg viewBox="0 0 24 24" className="ds2-uchip__i" aria-hidden><path d="M12 3l9 16H3z" /><path d="M12 10v4M12 17h.01" /></svg>
);

/** A deadline chip coloured by level. `none` renders nothing. `label` is the already-translated countdown
 *  ("Due in 2 days" / "Overdue by 3 days"). Colour never bleeds from the gold accent (spec R-U2). */
export function UrgencyChip({ level, label }: { level: UrgencyLevel; label: ReactNode }) {
  if (level === 'none') return null;
  return (
    <span className={`ds2-uchip ds2-uchip--${level}`}>
      {level === 'overdue' ? ALERT : CLOCK}
      {label}
    </span>
  );
}

// ── TaskCard (§2.3) — the action-first persona-home unit ──────────────────────────────────────────────
/** Icon + title + one context line + optional urgency chip + exactly ONE cta. A SubPanel-backed zone (the
 *  home page is the panel). No prose body; never more than one cta. */
export function TaskCard({
  icon,
  title,
  context,
  urgency = 'none',
  urgencyLabel,
  cta,
  seal = false,
}: {
  icon: ReactNode;
  title: ReactNode;
  context?: ReactNode;
  urgency?: UrgencyLevel;
  urgencyLabel?: ReactNode;
  cta: Ds2Cta;
  seal?: boolean;
}) {
  return (
    <SubPanel tone="neutral">
      <div className="ds2-taskcard">
        <div className="ds2-taskcard__head">
          <span className="ds2-taskcard__icon" aria-hidden>{seal ? <Seal size={18} /> : icon}</span>
          <div className="ds2-taskcard__title">{title}</div>
          {urgency !== 'none' && urgencyLabel != null && <UrgencyChip level={urgency} label={urgencyLabel} />}
        </div>
        {context != null && <div className="ds2-taskcard__ctx">{context}</div>}
        {/* AL-5 (S-UX-AUDIT-1): the TaskCard CTA is a middle-weight primary button — one place, every
            TaskCard. EmptyState's CtaButton keeps the default small (its call site is unchanged). */}
        <div className="ds2-taskcard__cta"><CtaButton cta={cta} primary size="middle" /></div>
      </div>
    </SubPanel>
  );
}

// ── EmptyState (§2.4) — the one designed zero surface ─────────────────────────────────────────────────
const EMPTY_GLYPH = (
  <svg viewBox="0 0 24 24" className="ds2-empty__glyph" aria-hidden>
    <rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M8 15h4" />
  </svg>
);

/** icon + message + optional detail + optional single cta. `inline` for a table/section empty, `page` for a
 *  full-route empty. Not an error surface (empty ≠ failure). */
export function EmptyState({
  icon,
  message,
  detail,
  cta,
  size = 'inline',
}: {
  icon?: ReactNode;
  message: ReactNode;
  detail?: ReactNode;
  cta?: Ds2Cta;
  size?: 'inline' | 'page';
}) {
  return (
    <div className={`ds2-empty ds2-empty--${size}`}>
      <span className="ds2-empty__icon" aria-hidden>{icon ?? EMPTY_GLYPH}</span>
      <div className="ds2-empty__msg">{message}</div>
      {detail != null && <div className="ds2-empty__detail">{detail}</div>}
      {cta && <div className="ds2-empty__cta"><CtaButton cta={cta} primary /></div>}
    </div>
  );
}
