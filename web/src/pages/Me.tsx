// B5-ME — the two "Me" surfaces, composed to the prototype's stu-me (L661-674) and gua-me (L905-918).
// CLIENT ONLY: no server change, no new read.
//
// B9-CONSUME-FAMILY — the two omissions below are REVERSED, both by S-READ-3's link reads. What B5 flagged
// as a missing endpoint was exactly that, and the endpoint now exists; the flags are resolved, not overruled.
//
// Both blocks are the same shape — h2 "Me", an identity card (avatar · name · role line · hr · label/value
// rows), then ONE second card — so the identity card is a single definition here and each persona supplies
// its own rows and second card.
//
// OMITTED, never placeholdered (verdicts, B5 STEP 1):
//   · stu-me's "My guardians" card — was NOT-SERVED (read-narrow), and B5 read it right: guardian_links_read
//     already admitted the student, but no ROUTE served it. GET /my/guardians (S-READ-3 item 1) is that route,
//     in exactly the shape B5 predicted — name + status, the AD-2 display-name elevation. The card is BUILT.
//   · gua-me's "· {n} linked children" — B5 refused to derive it from /api/enrolments because distinct
//     student_id is a DIFFERENT FACT (children WITH ENROLMENTS) and reads as quietly false for a newly-linked
//     child. That objection is now moot: GET /my/children IS the link read, so the count is the link count
//     itself. ALL statuses, not just active — the guardian is asking how many children they are linked to,
//     and a pending link is a link they are entitled to know they hold (the read serves them their own row).
//   · gua-me's "Notifications — In-app · email coming" row — DOMAIN-UNBUILT (D6): no notifications table,
//     route or client anywhere, and "email coming" is a promise about an unbuilt domain.
//   · the ceremony copy above the pairing button — instructional (Kit).
//   · a sign-out control — the shell carries it three ways (UserMenu:49, NavFooter:35, NavDrawer:46) and
//     neither block shows one here; nothing is stranded.
import { useState } from 'react';
import { App, Button, Input, Modal, Skeleton, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { initials, personName, schoolName } from '../display/names';
import { useResource } from '@/ds2';
import { mutate } from '../api/mutate';
import { LocaleSwitcher } from '../components/LocaleSwitcher';

interface EnrolRow { student_id: number; school_name_en: string | null; school_name_tc: string | null; school_name_sc: string | null }
// GET /my/guardians and GET /my/children (S-READ-3 item 1). `name` is null for every non-active link (F-1/F-2).
interface GuardianLink { guardian_id: number; name: string | null; status: string }
interface ChildLink { student_id: number; name: string | null; status: string }

/** One label/value row — the block's `.req-row` grammar: muted label left, value right. */
function MeRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, padding: '10px 0', borderTop: '1px solid var(--ka-border)' }}>
      <Typography.Text type="secondary">{label}</Typography.Text>
      <span style={{ textAlign: 'right', minWidth: 0 }}>{children}</span>
    </div>
  );
}

/** The identity card both blocks open with: avatar · name · role line · then the caller's rows. */
function IdentityCard({ name, roleLine, children }: { name: string; roleLine: string; children: React.ReactNode }) {
  return (
    <div style={{ background: 'var(--ka-card)', borderRadius: 'var(--ka-r-md)', padding: '18px 20px' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
        <span style={{ width: 44, height: 44, borderRadius: '50%', background: 'var(--ka-muted)', display: 'grid', placeItems: 'center', fontSize: 16, fontWeight: 600, color: 'var(--ka-muted-fg)', flex: 'none' }}>
          {initials(name)}
        </span>
        <div>
          <Typography.Title level={3} style={{ fontSize: 17, margin: 0 }}>{name}</Typography.Title>
          <Typography.Text type="secondary">{roleLine}</Typography.Text>
        </div>
      </div>
      {children}
    </div>
  );
}

/**
 * B9 item 2 — stu-me's "My guardians" card (block L670-673), one `.roster-row` per link.
 *
 * NAMELESS WHEN PENDING, and that is the model working. GET /my/guardians resolves the display name for
 * ACTIVE links only (F-2, mirroring F-1): the two-stage ceremony exists so the academy vets a relationship
 * before either party is treated as connected, and handing the student the name of a not-yet-approved
 * would-be guardian would give out the ceremony's prize early. So a pending row carries its STATE and no
 * identity — the student learns a link is in progress without learning who claims it.
 *
 * The block's sub-line reads "Guardian · verified". "verified" is NOT a served fact — no field on this read
 * or any other backs it — so the row carries the role word alone rather than asserting a verification the
 * platform never performed. Kit: the chip takes no dot.
 */
function GuardianRosterRow({ link }: { link: GuardianLink }) {
  const { t } = useTranslation();
  const active = link.status === 'active';
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 0', borderTop: '1px solid var(--ka-border)' }}>
      <span style={{ width: 34, height: 34, borderRadius: '50%', background: 'var(--ka-muted)', display: 'grid', placeItems: 'center', fontSize: 13, fontWeight: 600, color: 'var(--ka-muted-fg)', flex: 'none' }}>
        {active ? initials(personName(link.name)) : '·'}
      </span>
      <div style={{ flex: 1, minWidth: 0 }}>
        {/* ACTIVE → the name, with the role beneath it. PENDING → the role alone: there is no name to show,
            and a placeholder dash in the name slot would read as a broken record rather than a pending one. */}
        {active
          ? (
            <>
              <div style={{ fontWeight: 600 }}>{personName(link.name)}</div>
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>{t('me.roleGuardian')}</Typography.Text>
            </>
          )
          : <div style={{ fontWeight: 600 }}>{t('me.roleGuardian')}</div>}
      </div>
      <span className={`ant-tag ka-pill ka-pill--${active ? 'ok' : 'pend'}`} style={{ flex: 'none' }}>
        {t(`me.link.${link.status}`)}
      </span>
    </div>
  );
}

function MeTitle() {
  const { t } = useTranslation();
  return <Typography.Title level={2} style={{ fontSize: 23, marginBottom: 16 }}>{t('me.title')}</Typography.Title>;
}

// ── STUDENT: identity + School + Language. The "My guardians" card is omitted (see header). ──────────
export function StudentMe() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { identity } = useIdentity();
  // TWO reads now: /api/enrolments for the school chip, and the link read behind the guardians card. The
  // identity itself still rides the shell's /api/me.
  const enr = useResource<{ data: EnrolRow[] }>('/api/enrolments');
  const links = useResource<{ data: GuardianLink[] }>('/api/my/guardians');
  if (!identity) return <Skeleton active paragraph={{ rows: 3 }} />;

  const mine = (enr.data?.data ?? []).find((e) => e.student_id === identity.id) ?? null;
  const school = mine ? schoolName(mine, locale) : null;

  return (
    <div data-density="product" style={{ maxWidth: 900 }}>
      <MeTitle />
      <IdentityCard name={personName(identity.name)} roleLine={t('me.roleStudent')}>
        {/* School — a FACT, not a destination: neutral pill, no dot, rendered with the pill classes because
            StatusTag's contract is a closed enum → i18n label and a school name is free text (B3). */}
        {school && <MeRow label={t('me.school')}><span className="ant-tag ka-pill ka-pill--neutral">{school}</span></MeRow>}
        {/* The one row that is a CONTROL rather than a value: the block puts language here, and C3 put the
            switcher in the user menu. Same component in both places — LocaleSwitcher is the one definition
            (extracted at S-UX1 so public pages could switch too). */}
        <MeRow label={t('me.language')}><LocaleSwitcher /></MeRow>
      </IdentityCard>

      {/* B9 item 2 — the block's second card. Rendered only when the student HAS links: a student with none
          is a real and unremarkable state (a school-roll student with no guardian on the platform yet), and
          an empty "My guardians" card would invite them to wonder what went wrong. */}
      {(links.data?.data ?? []).length > 0 && (
        <div style={{ background: 'var(--ka-card)', borderRadius: 'var(--ka-r-md)', padding: '18px 20px', marginTop: 'var(--ka-zone-gap)' }}>
          <Typography.Title level={3} style={{ fontSize: 15.5, marginTop: 0, marginBottom: 4 }}>{t('me.myGuardians')}</Typography.Title>
          {(links.data?.data ?? []).map((l) => <GuardianRosterRow key={l.guardian_id} link={l} />)}
        </div>
      )}
    </div>
  );
}

// ── GUARDIAN: identity + Language, then the pairing card. ────────────────────────────────────────────
export function GuardianMe() {
  const { t } = useTranslation();
  const { identity } = useIdentity();
  const { message } = App.useApp();
  const [open, setOpen] = useState(false);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  // B9 item 3 — the block's "Guardian · 2 linked children" (L909). The count is the LINK count, from the
  // link read itself, so it is the fact the line claims to be. ALL statuses: the guardian is being told how
  // many children they are linked to, and a pending link is one they hold and are served their own row for.
  const links = useResource<{ data: ChildLink[] }>('/api/my/children');
  if (!identity) return <Skeleton active paragraph={{ rows: 3 }} />;

  // Zero falls back to the bare role word rather than "Guardian · 0 linked children" — a freshly registered
  // guardian awaiting their first ceremony is a real state, and counting to zero at them states nothing.
  const linkCount = (links.data?.data ?? []).length;
  const roleLine = linkCount > 0 ? t('me.roleGuardianLinked', { count: linkCount }) : t('me.roleGuardian');

  // REDEEM only. The block's "Start a pairing code" names the wrong actor: POST /my/pairing-codes is
  // role:student — the STUDENT generates, the GUARDIAN redeems (POST /pairing-codes/redeem, role:guardian).
  // Model beats prototype. requestByEmail is deliberately NOT here: it is a different ceremony that creates a
  // pending link against a student located by exact email, and it wants its own verdict. FLAG.
  const redeem = async () => {
    setBusy(true);
    const r = await mutate('/api/pairing-codes/redeem', { code });
    setBusy(false);
    if (r.ok) {
      // NOT "linked": the server returns status `pending_confirmation`. Redeeming a code opens the two-stage
      // ceremony (OD-23/27) — the STUDENT confirms, then an admin approves — so claiming a completed link
      // here would tell the guardian something untrue about a child-safety relationship.
      void message.success(t('me.codeAccepted'));
      setOpen(false);
      setCode('');
      return;
    }
    // The SERVER's message is the message — PairingService says "Invalid pairing code", "This code has
    // expired", "This student already has a guardian …". Inventing client copy here would put a second,
    // drifting vocabulary in front of a child-safety ceremony.
    void message.error(r.message ?? t('mutate.failed'));
  };

  return (
    <div data-density="product" style={{ maxWidth: 900 }}>
      <MeTitle />
      <IdentityCard name={personName(identity.name)} roleLine={roleLine}>
        <MeRow label={t('me.language')}><LocaleSwitcher /></MeRow>
      </IdentityCard>

      <div style={{ background: 'var(--ka-card)', borderRadius: 'var(--ka-r-md)', padding: '18px 20px', marginTop: 'var(--ka-zone-gap)' }}>
        <Typography.Title level={3} style={{ fontSize: 15.5, marginTop: 0, marginBottom: 12 }}>{t('me.linkChild')}</Typography.Title>
        <Button onClick={() => setOpen(true)}>{t('me.enterCode')}</Button>
      </div>

      <Modal
        open={open}
        title={t('me.enterCode')}
        okText={t('me.codeSubmit')}
        onOk={() => void redeem()}
        confirmLoading={busy}
        okButtonProps={{ disabled: code.trim().length !== 6 }}
        onCancel={() => setOpen(false)}
        destroyOnClose
      >
        {/* 6 characters is the server's own rule (`size:6`), enforced here only to stop a doomed round-trip —
            the server remains the authority. */}
        <Input
          value={code}
          onChange={(e) => setCode(e.target.value.trim())}
          maxLength={6}
          aria-label={t('me.codeLabel')}
          placeholder={t('me.codeLabel')}
        />
      </Modal>
    </div>
  );
}
