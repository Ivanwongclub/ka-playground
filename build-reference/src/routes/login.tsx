import { createFileRoute, useNavigate, Link } from "@tanstack/react-router";
import { useEffect, useState, type FormEvent } from "react";
import { GraduationCap, Heart, School, Shield } from "lucide-react";
import {
  ArrowRightIcon,
  CheckIcon,
  EyeIcon,
  EyeOffIcon,
  GlobeIcon,
  LockIcon,
  MailIcon,
} from "@/components/icons";
import { toast } from "sonner";
import { Toaster } from "@/components/ui/sonner";
import { useAuth, type Role } from "@/lib/auth";
import armourLogo from "@/assets/armour-academy-logo.webp";

const LOGIN_BG =
  "https://jywgngpiuqcqgowngxuk.supabase.co/storage/v1/object/public/auth-assets/featured-sc5.jpg";

const ROLE_EMAILS: Record<Exclude<Role, "student">, string> = {
  admin: "admin@ka.test",
  school: "school@ka.test",
  teacher: "teacher@ka.test",
  parent: "parent@ka.test",
};

type ChipRole = keyof typeof ROLE_EMAILS;

export const Route = createFileRoute("/login")({
  head: () => ({
    meta: [
      { title: "Sign in · KA Playground" },
      { name: "description", content: "Sign in to KA Playground — Kings Armour Education." },
    ],
  }),
  component: LoginPage,
});

function LoginPage() {
  const { session, signIn, loading } = useAuth();
  const navigate = useNavigate();

  const [activeRole, setActiveRole] = useState<ChipRole>("admin");
  const [email, setEmail] = useState(ROLE_EMAILS.admin);
  const [password, setPassword] = useState("demo123!");
  const [showPwd, setShowPwd] = useState(false);
  const [keepSignedIn, setKeepSignedIn] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [showDemo, setShowDemo] = useState(false);

  useEffect(() => {
    if (session) {
      navigate({ to: "/dashboard" });
    }
  }, [session, navigate]);

  const pickRole = (role: ChipRole) => {
    setActiveRole(role);
    setEmail(ROLE_EMAILS[role]);
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    const { error } = await signIn(email, password);
    setSubmitting(false);
    if (error) {
      toast.error("Invalid credentials. Try one of the demo accounts above.");
      return;
    }
    toast.success("Welcome back");
    // Navigation handled by the session effect above to avoid racing
    // the onAuthStateChange listener.
  };

  const socialStub = (provider: string) => () =>
    toast(`${provider} sign-in coming soon`, { description: "Wiring OAuth in a future phase." });

  return (
    <>
      <div className="grid min-h-dvh grid-cols-1 bg-bg lg:grid-cols-[1.2fr_1fr]">
        {/* ============ LEFT: Image panel ============ */}
        <aside className="relative hidden overflow-hidden bg-[#0a0712] lg:block">
          <div
            className="absolute inset-0 animate-slow-zoom bg-cover bg-center"
            style={{ backgroundImage: `url('${LOGIN_BG}')` }}
          />
          <div className="absolute inset-0 bg-gradient-to-br from-bg/15 via-bg/50 to-bg/85" />
          <div
            className="pointer-events-none absolute inset-0 opacity-[0.04]"
            style={{
              backgroundImage:
                "url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='n'><feTurbulence baseFrequency='.9'/></filter><rect width='100%25' height='100%25' filter='url(%23n)' opacity='.5'/></svg>\")",
            }}
          />
          <div className="absolute left-[46%] top-[38%] h-3.5 w-3.5 animate-pulse-dot rounded-full bg-gold" />

          {/* Brand */}
          <div className="absolute left-9 top-8 z-10">
            <img
              src={armourLogo}
              alt="Armour Academy — Skills in Action"
              className="h-14 w-auto object-contain drop-shadow-[0_4px_16px_rgba(0,0,0,0.45)]"
            />
          </div>


          {/* Bottom content */}
          <div className="absolute inset-x-0 bottom-0 z-10 px-14 py-12">
            <h1 className="mb-4 max-w-[520px] font-heading text-[38px] font-extrabold leading-[1.1] tracking-tight text-white [text-shadow:0_2px_12px_rgba(0,0,0,0.4)]">
              Where every child<br />finds their <span className="text-gold-gradient">spark</span>.
            </h1>
            <div className="flex max-w-[480px] items-start gap-3.5 text-[14px] italic leading-[1.6] text-white/85 [text-shadow:0_1px_8px_rgba(0,0,0,0.4)]">
              <span className="-mt-2 font-serif text-5xl leading-none text-gold/60">"</span>
              <div>
                My son built a model F1 car and pitched to sponsors at 14. This programme
                changes how kids see what they're capable of.
                <div className="mt-2 not-italic text-[13px] font-semibold text-white">
                  Mrs. Yip
                  <span className="mt-0.5 block text-[11px] font-normal text-white/50">
                    Parent · Team Velocity, STEM on Car
                  </span>
                </div>
              </div>
            </div>
          </div>
        </aside>

        {/* ============ RIGHT: Form panel ============ */}
        <section className="relative flex flex-col overflow-hidden px-5 pt-[max(1rem,env(safe-area-inset-top))] pb-[max(2rem,env(safe-area-inset-bottom))] sm:px-10 lg:px-16 lg:py-12">
          <div className="pointer-events-none absolute -right-[20%] -top-[30%] h-[60%] w-[80%] rounded-full bg-[radial-gradient(circle,oklch(0.755_0.095_85/0.05),transparent_60%)] lg:bg-[radial-gradient(circle,oklch(0.755_0.095_85/0.08),transparent_60%)]" />
          <div className="pointer-events-none absolute -bottom-[20%] -left-[20%] h-[50%] w-[60%] rounded-full bg-[radial-gradient(circle,oklch(0.66_0.245_305/0.04),transparent_60%)] lg:bg-[radial-gradient(circle,oklch(0.66_0.245_305/0.06),transparent_60%)]" />

          {/* Top bar — mobile shows brand + access link, desktop shows just access link */}
          <div className="relative z-10 flex h-14 items-center justify-between gap-2">
            <Link to="/login" className="lg:hidden">
              <img
                src={armourLogo}
                alt="Armour Academy"
                className="h-9 w-auto object-contain"
              />
            </Link>
            <div className="ml-auto flex items-center gap-1.5">
              <span className="hidden text-xs text-muted-fg sm:inline">New to KA?</span>
              <a
                href="mailto:hello@kingsarmour.com"
                className="inline-flex items-center gap-1 text-xs font-semibold text-gold hover:text-gold-2"
              >
                Request access <ArrowRightIcon size={12} />
              </a>
            </div>
          </div>

          <form
            onSubmit={handleSubmit}
            className="relative z-10 mx-auto flex w-full max-w-[400px] flex-1 flex-col justify-center py-6"
          >
            <h2 className="mb-1.5 font-heading text-3xl font-extrabold tracking-tight text-fg">
              Welcome back
            </h2>
            <p className="mb-5 text-[13px] text-muted-fg">
              Sign in to continue.
            </p>

            {/* Demo role chips — collapsed on mobile, open on lg+ */}
            <div className="mb-5">
              <button
                type="button"
                onClick={() => setShowDemo((s) => !s)}
                className="flex w-full items-center justify-between text-[11px] font-semibold uppercase tracking-wider text-muted-fg hover:text-fg lg:hidden"
                aria-expanded={showDemo}
              >
                <span>Use a demo account</span>
                <span className={"transition-transform " + (showDemo ? "rotate-180" : "")}>▾</span>
              </button>
              <div className={(showDemo ? "mt-3 grid" : "hidden") + " grid-cols-2 gap-2 lg:mt-0 lg:grid"}>
                <RoleChip
                  active={activeRole === "admin"}
                  onClick={() => pickRole("admin")}
                  icon={<Shield className="h-3.5 w-3.5" />}
                  label="Admin"
                />
                <RoleChip
                  active={activeRole === "school"}
                  onClick={() => pickRole("school")}
                  icon={<School className="h-3.5 w-3.5" />}
                  label="School"
                />
                <RoleChip
                  active={activeRole === "teacher"}
                  onClick={() => pickRole("teacher")}
                  icon={<GraduationCap className="h-3.5 w-3.5" />}
                  label="Teacher"
                />
                <RoleChip
                  active={activeRole === "parent"}
                  onClick={() => pickRole("parent")}
                  icon={<Heart className="h-3.5 w-3.5" />}
                  label="Parent"
                />
              </div>
            </div>

            {/* Email */}
            <Field
              id="email"
              label="Email"
              icon={<MailIcon size={16} />}
              type="email"
              autoComplete="email"
              value={email}
              onChange={(v) => setEmail(v)}
              placeholder="you@school.edu.hk"
            />

            {/* Password */}
            <Field
              id="password"
              label="Password"
              icon={<LockIcon size={16} />}
              type={showPwd ? "text" : "password"}
              autoComplete="current-password"
              value={password}
              onChange={(v) => setPassword(v)}
              placeholder="Enter your password"
              trailing={
                <button
                  type="button"
                  onClick={() => setShowPwd((s) => !s)}
                  aria-label={showPwd ? "Hide password" : "Show password"}
                  className="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-muted-fg hover:bg-mut hover:text-fg"
                >
                  {showPwd ? <EyeOffIcon size={16} /> : <EyeIcon size={16} />}
                </button>
              }
            />

            <div className="mb-5 flex items-center justify-between">
              <label className="flex cursor-pointer select-none items-center gap-2">
                <span className="relative inline-flex h-4 w-4 items-center justify-center">
                  <input
                    type="checkbox"
                    checked={keepSignedIn}
                    onChange={(e) => setKeepSignedIn(e.target.checked)}
                    className="peer sr-only"
                  />
                  <span className="flex h-4 w-4 items-center justify-center rounded-[5px] border-[1.5px] border-white/16 bg-transparent transition-all peer-checked:border-gold peer-checked:bg-gold">
                    <CheckIcon
                      size={11}
                      strokeWidth={3}
                      className="opacity-0 transition-opacity peer-checked:opacity-100"
                      style={{ color: "#0F0B15" }}
                    />
                  </span>
                </span>
                <span className="text-xs text-muted-fg">Keep me signed in</span>
              </label>
              <a href="#" className="text-xs font-semibold text-gold hover:text-gold-2">
                Forgot password?
              </a>
            </div>

            <button
              type="submit"
              disabled={submitting}
              className="group relative flex h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-[11px] bg-gold-gradient font-body text-sm font-bold text-[#0F0B15] shadow-[0_8px_24px_rgba(201,169,98,0.25)] transition-all hover:-translate-y-px hover:shadow-[0_12px_32px_rgba(201,169,98,0.35)] disabled:opacity-70"
            >
              {submitting ? "Signing in…" : "Sign in to Playground"}
              {!submitting && <ArrowRightIcon size={16} />}
            </button>

            <div className="my-5 flex items-center gap-3 text-[11px] text-muted-fg before:h-px before:flex-1 before:bg-border before:content-[''] after:h-px after:flex-1 after:bg-border after:content-['']">
              or
            </div>

            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={socialStub("Google")}
                className="flex h-[42px] items-center justify-center gap-2 rounded-[10px] border border-border bg-card text-[13px] font-semibold text-fg transition-all hover:border-white/16 hover:bg-card-elev"
              >
                <GoogleIcon />
                Google
              </button>
              <button
                type="button"
                onClick={socialStub("Twitter")}
                className="flex h-[42px] items-center justify-center gap-2 rounded-[10px] border border-border bg-card text-[13px] font-semibold text-fg transition-all hover:border-white/16 hover:bg-card-elev"
              >
                <TwitterIcon />
                Twitter
              </button>
            </div>
          </form>

          <div className="relative z-10 mt-auto flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-3.5 text-[11px] text-muted-fg">
              <Link to="/login" className="hover:text-gold">Privacy</Link>
              <Link to="/login" className="hover:text-gold">Terms</Link>
              <Link to="/login" className="hover:text-gold">Help</Link>
            </div>
            <div className="flex items-center gap-1.5 text-[11px] text-muted-fg">
              <GlobeIcon size={12} />
              Hong Kong · <span className="font-hk">繁體中文</span>
            </div>
          </div>
        </section>
      </div>
      <Toaster />
    </>
  );
}

/* -------- helpers -------- */

function RoleChip({
  active,
  onClick,
  icon,
  label,
}: {
  active: boolean;
  onClick: () => void;
  icon: React.ReactNode;
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        "flex items-center gap-2 rounded-[10px] border px-3 py-2.5 text-left transition-all " +
        (active
          ? "border-gold bg-gold-soft text-gold"
          : "border-border bg-mut text-fg hover:border-white/16 hover:bg-card-elev")
      }
    >
      <span className={active ? "text-gold" : "text-muted-fg"}>{icon}</span>
      <span className="text-xs font-semibold">{label}</span>
    </button>
  );
}

function Field({
  id,
  label,
  icon,
  type,
  value,
  onChange,
  placeholder,
  autoComplete,
  trailing,
}: {
  id: string;
  label: string;
  icon: React.ReactNode;
  type: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  autoComplete?: string;
  trailing?: React.ReactNode;
}) {
  return (
    <div className="relative mb-3.5">
      <label
        htmlFor={id}
        className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wider text-muted-fg"
      >
        {label}
      </label>
      <div className="group relative">
        <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-fg transition-colors group-focus-within:text-gold">
          {icon}
        </span>
        <input
          id={id}
          type={type}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          autoComplete={autoComplete}
          className="h-[46px] w-full rounded-[10px] border border-border bg-card pl-10 pr-12 font-sans text-sm text-fg outline-none transition-all placeholder:text-white/30 hover:border-white/16 focus:border-gold focus:bg-card-elev focus:shadow-[0_0_0_3px_rgba(201,169,98,0.12)]"
        />
        {trailing}
      </div>
    </div>
  );
}


function GoogleIcon() {
  return (
    <svg viewBox="0 0 48 48" className="h-4 w-4">
      <path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.1 8 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.7-.4-3.9z"/>
      <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.8 1.1 8 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
      <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2L31.2 33.6c-2.1 1.5-4.6 2.4-7.2 2.4-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
      <path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2c-.4.4 6.6-4.8 6.6-14.8 0-1.3-.1-2.7-.4-3.9z"/>
    </svg>
  );
}

function TwitterIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="currentColor">
      <path d="M22.46 6c-.77.35-1.6.58-2.46.69.88-.53 1.56-1.37 1.88-2.38-.83.5-1.75.85-2.72 1.05C18.37 4.5 17.26 4 16 4c-2.35 0-4.27 1.92-4.27 4.29 0 .34.04.67.11.98C8.28 9.09 5.11 7.38 3 4.79c-.37.63-.58 1.37-.58 2.15 0 1.49.75 2.81 1.91 3.56-.71 0-1.37-.2-1.95-.5v.03c0 2.08 1.48 3.82 3.44 4.21a4.22 4.22 0 01-1.93.07 4.28 4.28 0 004 2.98 8.521 8.521 0 01-5.33 1.84c-.34 0-.68-.02-1.02-.06C3.44 20.29 5.7 21 8.12 21 16 21 20.33 14.46 20.33 8.79c0-.19 0-.37-.01-.56.84-.6 1.56-1.36 2.14-2.23z" />
    </svg>
  );
}
