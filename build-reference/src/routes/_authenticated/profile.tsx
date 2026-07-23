import { createFileRoute } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
  UserRound,
  Mail,
  MapPin,
  Languages,
  Bell,
  Moon,
  Camera,
  LogOut,
  ShieldAlert,
  Share2,
  CheckCircle2,
  KeyRound,
} from "lucide-react";
import { useAuth, type Role } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/_authenticated/profile")({
  head: () => ({ meta: [{ title: "My Profile — KA Playground" }] }),
  component: ProfilePage,
});

const ROLE_LABEL: Record<Role, string> = {
  admin: "Administrator",
  school: "School Account",
  teacher: "Teacher",
  parent: "Parent",
  student: "Student",
};

const ROLE_TONE: Record<Role, string> = {
  admin: "bg-gold-soft text-gold",
  school: "bg-purple/15 text-purple",
  teacher: "bg-sky-500/15 text-sky-400",
  parent: "bg-success/15 text-success",
  student: "bg-pink/15 text-pink",
};

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join("");
}

function ProfilePage() {
  const { user, signOut } = useAuth();

  // Form state — local mirror, mock save
  const [fullName, setFullName] = useState("");
  const [fullNameZh, setFullNameZh] = useState("");
  const [region, setRegion] = useState("");
  const [language, setLanguage] = useState("en");
  const [bio, setBio] = useState(
    "Curious learner. Loves robotics, problem solving, and weekend visits to the science museum.",
  );
  const [dirty, setDirty] = useState(false);

  // Preferences
  const [emailNotif, setEmailNotif] = useState(true);
  const [pushNotif, setPushNotif] = useState(false);
  const [weeklyDigest, setWeeklyDigest] = useState(true);
  const [darkMode, setDarkMode] = useState(true);
  const [publicProfile, setPublicProfile] = useState(false);

  // Dialogs
  const [shareOpen, setShareOpen] = useState(false);
  const [pwOpen, setPwOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  useEffect(() => {
    if (!user) return;
    setFullName(user.full_name ?? "");
    setFullNameZh(user.full_name_zh ?? "");
    setRegion(user.region ?? "");
    setLanguage(user.language ?? "en");
    setDirty(false);
  }, [user]);

  if (!user) return null;

  const publicUrl =
    typeof window !== "undefined"
      ? `${window.location.origin}/u/${user.id.slice(0, 8)}`
      : `/u/${user.id.slice(0, 8)}`;

  return (
    <div className="w-full">
      {/* Page header */}
      <div className="flex items-end justify-between gap-4 px-10 pt-8 pb-5">
        <div>
          <h1
            className="text-fg tracking-tight"
            style={{ fontFamily: "var(--font-heading)", fontWeight: 800, fontSize: 28 }}
          >
            My Profile
          </h1>
          <p className="mt-1 text-sm text-muted-fg">
            Manage your personal information, preferences, and account settings.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            onClick={() => setShareOpen(true)}
            className="text-sm"
          >
            <Share2 className="h-4 w-4 mr-1.5" /> Share profile
          </Button>
          <Button
            onClick={() => {
              toast.success("Profile saved");
              setDirty(false);
            }}
            disabled={!dirty}
            className="bg-gold text-black hover:bg-gold/90 font-semibold"
          >
            {dirty ? "Save changes" : "Saved"}
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-12 gap-5 px-10 pb-12">
        {/* Left rail — identity card */}
        <aside className="col-span-12 lg:col-span-4 flex flex-col gap-4">
          <section className="rounded-[14px] border border-border bg-card p-6 flex flex-col items-center text-center">
            <div className="relative">
              <div
                className="grid place-items-center rounded-full text-black"
                style={{
                  width: 112,
                  height: 112,
                  background:
                    "linear-gradient(135deg, var(--gold), var(--gold-2))",
                  fontSize: 36,
                  fontWeight: 800,
                  fontFamily: "var(--font-heading)",
                }}
              >
                {initials(fullName || user.email)}
              </div>
              <button
                type="button"
                onClick={() => toast.info("Photo upload coming soon")}
                className="absolute bottom-1 right-1 grid h-9 w-9 place-items-center rounded-full border-2 border-card bg-card-elev text-fg shadow-md transition hover:bg-mut"
                aria-label="Change photo"
              >
                <Camera className="h-4 w-4" />
              </button>
            </div>

            <h2
              className="mt-4 text-fg"
              style={{ fontFamily: "var(--font-heading)", fontWeight: 700, fontSize: 18 }}
            >
              {fullName || "Unnamed"}
            </h2>
            {fullNameZh && (
              <div className="text-xs text-muted-fg">{fullNameZh}</div>
            )}

            <span
              className={cn(
                "mt-3 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold",
                ROLE_TONE[user.role],
              )}
            >
              <UserRound className="h-3 w-3" />
              {ROLE_LABEL[user.role]}
            </span>

            <div className="mt-5 w-full border-t border-border pt-4 flex flex-col gap-2 text-left">
              <Row icon={<Mail className="h-3.5 w-3.5" />} label={user.email} />
              <Row icon={<MapPin className="h-3.5 w-3.5" />} label={region || "Region not set"} />
              <Row
                icon={<Languages className="h-3.5 w-3.5" />}
                label={
                  language === "en"
                    ? "English"
                    : language === "zh-HK"
                      ? "繁體中文 (HK)"
                      : "Bilingual"
                }
              />
            </div>
          </section>

          {/* Quick stats — mock */}
          <section className="rounded-[14px] border border-border bg-card p-5">
            <div className="text-[11px] uppercase tracking-wider text-muted-fg font-semibold">
              Activity at a glance
            </div>
            <div className="mt-3 grid grid-cols-3 gap-3">
              <Stat value="12" label="Enrolments" />
              <Stat value="84%" label="Avg progress" />
              <Stat value="3" label="Certificates" />
            </div>
          </section>
        </aside>

        {/* Right column — editable sections */}
        <main className="col-span-12 lg:col-span-8 flex flex-col gap-5">
          <Card title="Personal information" desc="This is how others will see you across the platform.">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Field label="Full name (English)">
                <Input
                  value={fullName}
                  onChange={(e) => {
                    setFullName(e.target.value);
                    setDirty(true);
                  }}
                />
              </Field>
              <Field label="Full name (中文)">
                <Input
                  value={fullNameZh}
                  onChange={(e) => {
                    setFullNameZh(e.target.value);
                    setDirty(true);
                  }}
                  placeholder="Optional"
                />
              </Field>
              <Field label="Email">
                <Input value={user.email} disabled />
              </Field>
              <Field label="Region">
                <Select
                  value={region || "HK"}
                  onValueChange={(v) => {
                    setRegion(v);
                    setDirty(true);
                  }}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select region" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="HK">Hong Kong</SelectItem>
                    <SelectItem value="UK">United Kingdom</SelectItem>
                    <SelectItem value="SG">Singapore</SelectItem>
                    <SelectItem value="AU">Australia</SelectItem>
                  </SelectContent>
                </Select>
              </Field>
              <Field label="Preferred language" className="md:col-span-2">
                <Select
                  value={language}
                  onValueChange={(v) => {
                    setLanguage(v);
                    setDirty(true);
                  }}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="en">English</SelectItem>
                    <SelectItem value="zh-HK">繁體中文 (HK)</SelectItem>
                    <SelectItem value="bi">Bilingual (EN + 中)</SelectItem>
                  </SelectContent>
                </Select>
              </Field>
              <Field label="About me" className="md:col-span-2">
                <Textarea
                  rows={3}
                  value={bio}
                  onChange={(e) => {
                    setBio(e.target.value);
                    setDirty(true);
                  }}
                  placeholder="A short bio — interests, goals, anything else worth sharing."
                />
                <div className="mt-1 text-[11px] text-muted-fg text-right">
                  {bio.length}/280
                </div>
              </Field>
            </div>
          </Card>

          <Card title="Preferences" desc="Control how and when we get in touch.">
            <Pref
              icon={<Bell className="h-4 w-4" />}
              title="Email notifications"
              desc="Programme updates, enrolment confirmations, milestones."
              checked={emailNotif}
              onChange={setEmailNotif}
            />
            <Pref
              icon={<Bell className="h-4 w-4" />}
              title="Push notifications"
              desc="Browser push for urgent messages and reminders."
              checked={pushNotif}
              onChange={setPushNotif}
            />
            <Pref
              icon={<Mail className="h-4 w-4" />}
              title="Weekly digest"
              desc="Every Monday morning — what's new and what's coming up."
              checked={weeklyDigest}
              onChange={setWeeklyDigest}
            />
            <Pref
              icon={<Moon className="h-4 w-4" />}
              title="Dark mode"
              desc="Use the aubergine dark theme by default."
              checked={darkMode}
              onChange={setDarkMode}
            />
            <Pref
              icon={<Share2 className="h-4 w-4" />}
              title="Public profile"
              desc="Anyone with the link can view a read-only version of this profile."
              checked={publicProfile}
              onChange={setPublicProfile}
              last
            />
          </Card>

          <Card title="Security" desc="Keep your account safe.">
            <div className="flex items-center justify-between rounded-[10px] border border-border p-4">
              <div className="flex items-start gap-3">
                <KeyRound className="h-4 w-4 mt-0.5 text-muted-fg" />
                <div>
                  <div className="text-sm font-semibold text-fg">Password</div>
                  <div className="text-xs text-muted-fg mt-0.5">
                    Last changed 3 months ago.
                  </div>
                </div>
              </div>
              <Button variant="ghost" onClick={() => setPwOpen(true)}>
                Change password
              </Button>
            </div>

            <div className="mt-3 flex items-center justify-between rounded-[10px] border border-border p-4">
              <div className="flex items-start gap-3">
                <CheckCircle2 className="h-4 w-4 mt-0.5 text-success" />
                <div>
                  <div className="text-sm font-semibold text-fg">
                    Two-factor authentication
                  </div>
                  <div className="text-xs text-muted-fg mt-0.5">
                    Enabled · Authenticator app
                  </div>
                </div>
              </div>
              <Button
                variant="ghost"
                onClick={() => toast.info("2FA management coming soon")}
              >
                Manage
              </Button>
            </div>
          </Card>

          <Card title="Danger zone" desc="Irreversible actions — proceed carefully." tone="danger">
            <div className="flex items-center justify-between rounded-[10px] border border-border p-4">
              <div className="flex items-start gap-3">
                <LogOut className="h-4 w-4 mt-0.5 text-muted-fg" />
                <div>
                  <div className="text-sm font-semibold text-fg">Sign out</div>
                  <div className="text-xs text-muted-fg mt-0.5">
                    Sign out of this browser session.
                  </div>
                </div>
              </div>
              <Button variant="ghost" onClick={() => signOut()}>
                Sign out
              </Button>
            </div>

            <div className="mt-3 flex items-center justify-between rounded-[10px] border border-danger/40 bg-danger/5 p-4">
              <div className="flex items-start gap-3">
                <ShieldAlert className="h-4 w-4 mt-0.5 text-danger" />
                <div>
                  <div className="text-sm font-semibold text-fg">Delete account</div>
                  <div className="text-xs text-muted-fg mt-0.5">
                    Permanently delete your account and all associated data.
                  </div>
                </div>
              </div>
              <Button
                variant="ghost"
                onClick={() => setDeleteOpen(true)}
                className="text-danger hover:bg-danger/10 hover:text-danger"
              >
                Delete…
              </Button>
            </div>
          </Card>
        </main>
      </div>

      {/* Share dialog */}
      <Dialog open={shareOpen} onOpenChange={setShareOpen}>
        <DialogContent className="sm:max-w-[460px]">
          <DialogHeader>
            <DialogTitle>Share your profile</DialogTitle>
            <DialogDescription>
              Anyone with this link can view a read-only public version of your profile.
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-center gap-2">
            <Input value={publicUrl} readOnly className="font-mono text-xs" />
            <Button
              onClick={() => {
                if (typeof navigator !== "undefined" && navigator.clipboard) {
                  navigator.clipboard.writeText(publicUrl);
                }
                toast.success("Link copied");
              }}
              className="bg-gold text-black hover:bg-gold/90 font-semibold shrink-0"
            >
              Copy
            </Button>
          </div>
          <div className="flex items-center justify-center gap-2 pt-2">
            {["WhatsApp", "Email", "X", "LinkedIn"].map((c) => (
              <button
                key={c}
                type="button"
                onClick={() => toast.info(`Share via ${c} — coming soon`)}
                className="rounded-full border border-border px-3 py-1.5 text-xs font-semibold text-muted-fg transition hover:bg-mut hover:text-fg"
              >
                {c}
              </button>
            ))}
          </div>
        </DialogContent>
      </Dialog>

      {/* Password dialog */}
      <Dialog open={pwOpen} onOpenChange={setPwOpen}>
        <DialogContent className="sm:max-w-[420px]">
          <DialogHeader>
            <DialogTitle>Change password</DialogTitle>
            <DialogDescription>
              Enter your current password and choose a new one.
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-3">
            <Input type="password" placeholder="Current password" />
            <Input type="password" placeholder="New password" />
            <Input type="password" placeholder="Confirm new password" />
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setPwOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => {
                setPwOpen(false);
                toast.success("Password updated");
              }}
              className="bg-gold text-black hover:bg-gold/90 font-semibold"
            >
              Update password
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete confirm */}
      <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
        <DialogContent className="sm:max-w-[440px]">
          <DialogHeader>
            <DialogTitle className="text-danger">Delete your account?</DialogTitle>
            <DialogDescription>
              This permanently removes your profile, enrolments, and all linked records.
              This cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setDeleteOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => {
                setDeleteOpen(false);
                toast.error("Account deletion stub — not wired to backend");
              }}
              className="bg-danger text-white hover:bg-danger/90"
            >
              Yes, delete account
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/* ----- helpers ----- */

function Row({ icon, label }: { icon: React.ReactNode; label: string }) {
  return (
    <div className="flex items-center gap-2 text-xs text-muted-fg">
      <span className="text-muted-fg">{icon}</span>
      <span className="truncate">{label}</span>
    </div>
  );
}

function Stat({ value, label }: { value: string; label: string }) {
  return (
    <div className="flex flex-col items-center rounded-[10px] bg-mut/40 py-3">
      <div
        className="text-fg"
        style={{ fontFamily: "var(--font-heading)", fontWeight: 800, fontSize: 18 }}
      >
        {value}
      </div>
      <div className="text-[10px] uppercase tracking-wider text-muted-fg mt-0.5">
        {label}
      </div>
    </div>
  );
}

function Card({
  title,
  desc,
  children,
  tone = "default",
}: {
  title: string;
  desc?: string;
  children: React.ReactNode;
  tone?: "default" | "danger";
}) {
  return (
    <section
      className={cn(
        "rounded-[14px] border bg-card p-6",
        tone === "danger" ? "border-danger/30" : "border-border",
      )}
    >
      <header className="mb-4">
        <h3
          className={cn(
            "text-fg",
            tone === "danger" && "text-danger",
          )}
          style={{ fontFamily: "var(--font-heading)", fontWeight: 700, fontSize: 15 }}
        >
          {title}
        </h3>
        {desc && <p className="mt-0.5 text-xs text-muted-fg">{desc}</p>}
      </header>
      {children}
    </section>
  );
}

function Field({
  label,
  children,
  className,
}: {
  label: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex flex-col gap-1.5", className)}>
      <label className="text-[11px] uppercase tracking-wider text-muted-fg font-semibold">
        {label}
      </label>
      {children}
    </div>
  );
}

function Pref({
  icon,
  title,
  desc,
  checked,
  onChange,
  last,
}: {
  icon: React.ReactNode;
  title: string;
  desc: string;
  checked: boolean;
  onChange: (v: boolean) => void;
  last?: boolean;
}) {
  return (
    <div
      className={cn(
        "flex items-center justify-between gap-4 py-3",
        !last && "border-b border-border",
      )}
    >
      <div className="flex items-start gap-3 min-w-0">
        <span className="grid h-8 w-8 shrink-0 place-items-center rounded-[8px] bg-mut text-muted-fg">
          {icon}
        </span>
        <div className="min-w-0">
          <div className="text-sm font-semibold text-fg">{title}</div>
          <div className="text-xs text-muted-fg mt-0.5">{desc}</div>
        </div>
      </div>
      <Switch checked={checked} onCheckedChange={onChange} />
    </div>
  );
}
