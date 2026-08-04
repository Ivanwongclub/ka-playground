// DEV-ONLY DS2 component gallery — the visual reference + regression anchor for the atom kit (STEP 2).
// Dead-code-eliminated from production (DEV-gated route in main.tsx, like the Style Guide). It is the ONE
// deliberate adopter of @/ds2 in STEP 2 (allowlisted in scripts/ds2-import-guard.mjs). Its labels are
// developer-facing (component + prop-state names), so it is excluded from the i18n hardcoded-string scan.
import { useTranslation } from 'react-i18next';
import { StatusAtom, StatChip, MetaChip, StateBadge, ProgressRing, DatedBadge, Seal, StatusTag } from '@/ds2';

const S: Record<string, React.CSSProperties> = {
  page: { background: 'var(--ka-bg)', color: 'var(--ka-fg)', minHeight: '100vh', padding: 32, fontFamily: 'var(--ka-font-body)' },
  h1: { fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 28, marginBottom: 4 },
  lead: { color: 'var(--ka-fg-muted)', fontSize: 13, marginBottom: 28 },
  eyebrow: { fontFamily: 'var(--ka-font-mono)', fontSize: 11, letterSpacing: '.15em', textTransform: 'uppercase', color: 'var(--ka-gold)', marginBottom: 10 },
  card: { background: 'var(--ka-card)', border: '1px solid var(--ka-border)', borderRadius: 'var(--ka-r-lg)', padding: 20, marginBottom: 16 },
  row: { display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' },
  avatar: { width: 48, height: 48, borderRadius: '50%', background: 'linear-gradient(135deg,#2c2338,#3a2f4a)', display: 'grid', placeItems: 'center', fontFamily: 'var(--ka-font-display)', fontWeight: 700, color: 'var(--ka-gold)', border: '1px solid var(--ka-border)', position: 'relative' },
  avOk: { boxShadow: '0 0 0 2px var(--ka-gold)' },
  avWarn: { boxShadow: '0 0 0 2px var(--ka-warning)' },
  badgePos: { position: 'absolute', right: -3, bottom: -3 },
};

function Section({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={S.card}>
      <div style={S.eyebrow}>{label}</div>
      <div style={S.row}>{children}</div>
    </div>
  );
}

export function Ds2Gallery() {
  const { i18n } = useTranslation();
  const locale = i18n.language;
  return (
    <div style={S.page}>
      <div style={S.h1}>DS2 Atom Kit</div>
      <div style={S.lead}>Every atom in every state — on the --ka-* tokens, darkAlgorithm-native. DEV-only reference.</div>

      <Section label="StatusAtom · D5 hierarchy (loud status + demoted detail)">
        <StatusAtom icon={<Seal size={20} />} status="Consent complete" detail="Signed 28 Jul 2026" tone="attested" />
        <StatusAtom status="Signature needed" detail="Confirms the seat before it starts" tone="action" />
        <StatusAtom status="Draft" />
      </Section>

      <Section label="StatChip · MetaChip · DatedBadge (data-as-atoms, D7)">
        <StatChip value={2} label="programmes" />
        <StatChip value={11} label="age" />
        <MetaChip>Mon 16:00</MetaChip>
        <MetaChip>Kowloon studio</MetaChip>
        <DatedBadge label="Signed" date="2026-07-28T00:00:00Z" locale={locale} />
      </Section>

      <Section label="StateBadge (on an avatar) · Seal">
        <div style={{ ...S.avatar, ...S.avOk }}>SY<span style={S.badgePos}><StateBadge state="sealed" title="Consent sealed" /></span></div>
        <div style={{ ...S.avatar, ...S.avWarn }}>MK<span style={S.badgePos}><StateBadge state="warn" title="Action needed" /></span></div>
        <StateBadge state="ok" title="OK" />
        <StateBadge state="action" title="Action" />
        <Seal size={24} />
      </Section>

      <Section label="ProgressRing (readiness as a visual, D7)">
        <ProgressRing value={7} total={9} />
        <ProgressRing value={2} total={2} />
        <ProgressRing value={0} total={5} />
      </Section>

      <Section label="StatusTag (re-exported existing status pills)">
        <StatusTag domain="orderStatus" value="paid" />
        <StatusTag domain="paymentStatus" value="pending_confirmation" />
        <StatusTag domain="enrolmentStatus" value="active" />
        <StatusTag domain="sessionStatus" value="in_progress" />
      </Section>
    </div>
  );
}
