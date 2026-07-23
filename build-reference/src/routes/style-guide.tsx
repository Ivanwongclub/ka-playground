import { createFileRoute, Link } from "@tanstack/react-router";
import { useState } from "react";
import {
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  Info,
  Mail,
  Sparkles,
  Star,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Switch } from "@/components/ui/switch";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
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
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { Toaster } from "@/components/ui/sonner";

import { BrandMark } from "@/components/shared/BrandMark";
import { CategoryBadge } from "@/components/shared/CategoryBadge";
import { KPITile } from "@/components/shared/KPITile";
import { ProgrammeCard } from "@/components/shared/ProgrammeCard";
import { StatRing } from "@/components/shared/StatRing";
import { SectionHeader } from "@/components/shared/SectionHeader";
import { Toggle } from "@/components/shared/Toggle";
import { Eyebrow } from "@/components/shared/Eyebrow";
import { AvatarStack } from "@/components/shared/AvatarStack";
import { EmptyState } from "@/components/shared/EmptyState";
import { ThemeToggle } from "@/components/shared/ThemeToggle";
import { PasswordInput } from "@/components/shared/PasswordInput";

export const Route = createFileRoute("/style-guide")({
  head: () => ({
    meta: [
      { title: "Style guide — KA Playground" },
      { name: "description", content: "Design system primitives for the KA Playground platform." },
    ],
  }),
  component: StyleGuide,
});

const swatches = [
  { name: "bg", token: "--bg", desc: "Page background" },
  { name: "card", token: "--card", desc: "Card surface" },
  { name: "card-elev", token: "--card-elev", desc: "Elevated surface" },
  { name: "fg", token: "--fg", desc: "Primary text" },
  { name: "muted-fg", token: "--muted-fg", desc: "Secondary text" },
  { name: "border", token: "--border", desc: "Hairlines" },
];

const brand = [
  { name: "gold", token: "--gold" },
  { name: "gold-2", token: "--gold-2" },
  { name: "gold-soft", token: "--gold-soft" },
];

const categoryColors = [
  { name: "indigo", token: "--cat-indigo", label: "Language" },
  { name: "purple", token: "--cat-purple", label: "STEM" },
  { name: "cyan", token: "--cat-cyan", label: "STEM on Car" },
  { name: "pink", token: "--cat-pink", label: "Arts" },
  { name: "orange", token: "--cat-orange", label: "Maths" },
];

const semantic = [
  { name: "success", token: "--success" },
  { name: "warning", token: "--warning" },
  { name: "danger", token: "--danger" },
];

function Swatch({ name, token, desc }: { name: string; token: string; desc?: string }) {
  return (
    <div className="rounded-lg border border-border bg-card p-3">
      <div
        className="h-16 w-full rounded-md border border-border"
        style={{ background: `var(${token})` }}
      />
      <div className="mt-2 font-heading text-sm font-semibold text-fg">{name}</div>
      <div className="text-xs text-muted-fg">{token}</div>
      {desc && <div className="mt-1 text-xs text-muted-fg/80">{desc}</div>}
    </div>
  );
}

function Block({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <section className="space-y-4">
      <SectionHeader title={title} subtitle={description} />
      <div className="rounded-xl border border-border bg-card/50 p-6">{children}</div>
    </section>
  );
}

function StyleGuide() {
  const [toggleOn, setToggleOn] = useState(true);
  const [switchOn, setSwitchOn] = useState(true);
  const [checkboxOn, setCheckboxOn] = useState(true);

  return (
    <TooltipProvider>
      <div className="min-h-screen bg-bg text-fg">
        <header className="sticky top-0 z-30 flex items-center justify-between border-b border-border bg-bg/80 px-page-x py-3 backdrop-blur-md">
          <Link to="/" className="flex items-center gap-3">
            <BrandMark size="md" />
            <div>
              <div className="font-heading text-sm font-bold leading-tight">KA Playground</div>
              <div className="text-xs text-muted-fg">Style guide · Phase 0</div>
            </div>
          </Link>
          <ThemeToggle />
        </header>

        <main className="mx-auto max-w-7xl space-y-12 px-page-x py-10">
          <div className="rounded-2xl border border-border bg-card p-8 shadow-card">
            <Eyebrow icon={<Star className="h-3.5 w-3.5" />}>Kings Armour Education</Eyebrow>
            <h1 className="mt-3 font-heading text-4xl font-extrabold leading-tight text-fg">
              The KA Playground <span className="text-gold-gradient">design system</span>
            </h1>
            <p className="mt-3 max-w-2xl text-muted-fg">
              Every primitive used across the platform. Flip themes at the top right to
              confirm both look right before we build features on top.
            </p>
          </div>

          <Block title="Colours" description="Semantic, brand, category, and feedback tokens.">
            <div className="space-y-6">
              <div>
                <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-fg">Surfaces & text</div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                  {swatches.map((s) => <Swatch key={s.name} {...s} />)}
                </div>
              </div>
              <div>
                <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-fg">Brand (gold)</div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  {brand.map((s) => <Swatch key={s.name} {...s} />)}
                </div>
              </div>
              <div>
                <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-fg">Category accents</div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                  {categoryColors.map((s) => <Swatch key={s.name} {...s} desc={s.label} />)}
                </div>
              </div>
              <div>
                <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-fg">Semantic</div>
                <div className="grid grid-cols-3 gap-3">
                  {semantic.map((s) => <Swatch key={s.name} {...s} />)}
                </div>
              </div>
            </div>
          </Block>

          <Block title="Typography" description="Montserrat for display, DM Sans for body, Noto Sans HK for 繁體中文.">
            <div className="space-y-4">
              <div>
                <div className="text-xs text-muted-fg">Display / H1 · Montserrat 800</div>
                <h1 className="font-heading text-5xl font-extrabold">Where every child finds their spark</h1>
              </div>
              <div>
                <div className="text-xs text-muted-fg">H2 · Montserrat 700</div>
                <h2 className="font-heading text-3xl font-bold">Explore by area</h2>
              </div>
              <div>
                <div className="text-xs text-muted-fg">H3 · Montserrat 600</div>
                <h3 className="font-heading text-xl font-semibold">STEM on Car · Cohort 5</h3>
              </div>
              <div>
                <div className="text-xs text-muted-fg">Body · DM Sans 400</div>
                <p className="max-w-2xl text-base">
                  My son built a model F1 car and pitched to sponsors at 14. This programme
                  changes how kids see what they're capable of.
                </p>
              </div>
              <div>
                <div className="text-xs text-muted-fg">Muted · DM Sans 400</div>
                <p className="max-w-2xl text-sm text-muted-fg">Secondary copy and helper text.</p>
              </div>
              <div>
                <div className="text-xs text-muted-fg">繁體中文 · Noto Sans HK</div>
                <p className="font-hk text-lg">每個孩子都有屬於自己的火花。</p>
              </div>
            </div>
          </Block>

          <Block title="Buttons" description="Primary uses the gold gradient.">
            <div className="flex flex-wrap items-center gap-3">
              <Button className="bg-gold-gradient text-bg hover:opacity-90">Primary</Button>
              <Button variant="outline">Secondary</Button>
              <Button variant="ghost">Ghost</Button>
              <Button variant="destructive">Destructive</Button>
              <Button size="sm" className="bg-gold-gradient text-bg hover:opacity-90">Small</Button>
              <Button size="lg" className="gap-2 bg-gold-gradient text-bg hover:opacity-90">
                With icon <ArrowRight className="h-4 w-4" />
              </Button>
              <Button disabled className="bg-gold-gradient text-bg">Disabled</Button>
            </div>
          </Block>

          <Block title="Inputs">
            <div className="grid gap-6 md:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="email">Email</Label>
                <Input id="email" type="email" placeholder="parent@ka.test" />
              </div>
              <div className="space-y-2">
                <Label htmlFor="pwd">Password</Label>
                <PasswordInput id="pwd" placeholder="••••••••" defaultValue="hunter2demo" />
              </div>
              <div className="space-y-2 md:col-span-2">
                <Label htmlFor="notes">Notes</Label>
                <Textarea id="notes" placeholder="Tommy did great in this week's session…" />
              </div>
              <div className="space-y-2">
                <Label>Programme</Label>
                <Select>
                  <SelectTrigger><SelectValue placeholder="Pick a programme" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="sc1">Cambridge English Online</SelectItem>
                    <SelectItem value="sc5">STEM on Car · Cohort 5</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="flex flex-wrap items-center gap-6">
                <label className="flex items-center gap-2 text-sm">
                  <Checkbox checked={checkboxOn} onCheckedChange={(v) => setCheckboxOn(Boolean(v))} />
                  Keep me signed in
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <Switch checked={switchOn} onCheckedChange={setSwitchOn} />
                  Notifications
                </label>
                <Toggle checked={toggleOn} onChange={setToggleOn} label="KA Toggle" />
              </div>
            </div>
          </Block>

          <Block title="Input states" description="Default, focus, error, disabled, and helper text. Tab into each field to see the focus ring.">
            <div className="grid gap-6 md:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="st-default">Default</Label>
                <Input id="st-default" placeholder="parent@ka.test" />
                <p className="text-xs text-muted-fg">Helper text — use to explain format or context.</p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="st-focus">Focus (autofocused)</Label>
                <Input id="st-focus" placeholder="Tab here to see the ring" className="ring-1 ring-gold" />
                <p className="text-xs text-muted-fg">Gold ring matches the brand focus state.</p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="st-error" className="text-danger">Error</Label>
                <Input
                  id="st-error"
                  defaultValue="not-an-email"
                  aria-invalid
                  className="border-danger focus-visible:ring-danger"
                />
                <p className="text-xs text-danger">Please enter a valid email address.</p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="st-disabled">Disabled</Label>
                <Input id="st-disabled" disabled defaultValue="Locked while syncing…" />
                <p className="text-xs text-muted-fg">Disabled inputs are non-interactive and dimmed.</p>
              </div>
              <div className="space-y-2 md:col-span-2">
                <Label htmlFor="st-pwd">Password with eye toggle</Label>
                <PasswordInput id="st-pwd" defaultValue="hunter2demo" />
                <p className="text-xs text-muted-fg">Click the eye to reveal — used on login & reset.</p>
              </div>
              <div className="space-y-2 md:col-span-2">
                <Label htmlFor="st-textarea-err" className="text-danger">Textarea — error</Label>
                <Textarea
                  id="st-textarea-err"
                  defaultValue=""
                  aria-invalid
                  className="border-danger focus-visible:ring-danger"
                  placeholder="Add a note about this session…"
                />
                <p className="text-xs text-danger">Note is required before saving.</p>
              </div>
            </div>
          </Block>



          <Block title="Cards & data display">
            <div className="space-y-6">
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <KPITile label="Total Students" value={135} delta="+12 this month" deltaTone="up" fillPercent={68} />
                <KPITile label="Active Enrolments" value={87} delta="+5 this week" deltaTone="up" fillPercent={54} fillColor="bg-cyan" />
                <KPITile label="Programmes Live" value={5} delta="No change" deltaTone="flat" fillPercent={100} fillColor="bg-purple" />
                <KPITile label="Completion Rate" value="94%" delta="-1% vs last" deltaTone="down" fillPercent={94} fillColor="bg-success" />
              </div>

              <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <ProgrammeCard
                  title="STEM on Car · Cohort 5"
                  category="STEM on Car"
                  description="Design, build, and pitch a model F1 car alongside real engineers and sponsors."
                  status="Open"
                  period="Mar–Jun 2026"
                  enrolled={24}
                  capacity={28}
                  ageRange="12–16"
                  featured
                  brandColor="#06B6D4"
                />
                <ProgrammeCard
                  title="Cambridge English Online"
                  category="Language"
                  description="Cambridge curriculum delivered live by certified tutors, twice a week."
                  status="Registering"
                  period="Apr–Sep 2026"
                  enrolled={42}
                  capacity={60}
                  ageRange="8–14"
                  brandColor="#6366F1"
                />
                <ProgrammeCard
                  title="Junior Maths Olympiad"
                  category="Maths"
                  description="Competitive problem-solving for ambitious young mathematicians."
                  status="Coming Soon"
                  period="Sep 2026"
                  enrolled={0}
                  capacity={20}
                  ageRange="10–13"
                  brandColor="#F97316"
                />
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <Card>
                  <CardHeader>
                    <CardTitle>Standard card</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-sm text-muted-fg">Re-themed shadcn card using KA tokens.</p>
                  </CardContent>
                </Card>
                <Card>
                  <CardContent className="flex items-center justify-between gap-6 p-6">
                    <div>
                      <div className="text-xs font-semibold uppercase tracking-wider text-muted-fg">Team Velocity</div>
                      <div className="font-heading text-xl font-bold">4 students enrolled</div>
                    </div>
                    <StatRing percent={72} label="avg progress" />
                  </CardContent>
                </Card>
              </div>
            </div>
          </Block>

          <Block title="Badges">
            <div className="flex flex-wrap items-center gap-2">
              <CategoryBadge category="Language" />
              <CategoryBadge category="STEM" />
              <CategoryBadge category="STEM on Car" />
              <CategoryBadge category="Arts" />
              <CategoryBadge category="Maths" />
              <Badge className="bg-success/15 text-success hover:bg-success/15">Open</Badge>
              <Badge className="bg-gold-soft text-gold hover:bg-gold-soft">Registering</Badge>
              <Badge className="bg-cyan/15 text-cyan hover:bg-cyan/15">Coming Soon</Badge>
              <Badge variant="outline">Closed</Badge>
              <Eyebrow icon={<Sparkles className="h-3 w-3" />}>Featured</Eyebrow>
            </div>
          </Block>

          <Block title="Avatars & rings">
            <div className="flex flex-wrap items-center gap-8">
              <AvatarStack names={["Tommy Chan", "Emily Wong", "Lucas Liu", "Sophie Lam", "Ryan Ng"]} />
              <StatRing percent={42} color="var(--cat-cyan)" label="STEM on Car" />
              <StatRing percent={88} color="var(--cat-indigo)" label="Cambridge" size={72} />
              <StatRing percent={66} color="var(--gold)" label="Overall" />
            </div>
          </Block>

          <Block title="Feedback" description="Toasts, dialogs, tooltips, empty state.">
            <div className="space-y-6">
              <div className="flex flex-wrap gap-3">
                <Button variant="outline" onClick={() => toast.success("Saved", { description: "Hero copy updated" })}>
                  <CheckCircle2 className="mr-2 h-4 w-4 text-success" /> Success toast
                </Button>
                <Button variant="outline" onClick={() => toast.error("Couldn't save", { description: "Check your connection" })}>
                  <XCircle className="mr-2 h-4 w-4 text-danger" /> Error toast
                </Button>
                <Button variant="outline" onClick={() => toast("Heads up", { description: "Demo only — no DB yet", icon: <Info className="h-4 w-4" /> })}>
                  <Info className="mr-2 h-4 w-4" /> Info toast
                </Button>

                <Dialog>
                  <DialogTrigger asChild>
                    <Button variant="outline">Open dialog</Button>
                  </DialogTrigger>
                  <DialogContent>
                    <DialogHeader>
                      <DialogTitle>Enrol a student</DialogTitle>
                      <DialogDescription>
                        Pick a student and confirm the programme. (Demo dialog — no submit yet.)
                      </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                      <Label>Student</Label>
                      <Input placeholder="Tommy Chan" />
                    </div>
                  </DialogContent>
                </Dialog>

                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button variant="outline">
                      <AlertTriangle className="mr-2 h-4 w-4 text-warning" /> Hover for tooltip
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    <p>Spots are filling up fast.</p>
                  </TooltipContent>
                </Tooltip>
              </div>

              <EmptyState
                title="No students yet"
                description="Invite a parent or school to see students appear here."
                icon={<Mail className="h-6 w-6" />}
                action={<Button className="bg-gold-gradient text-bg hover:opacity-90">Invite someone</Button>}
              />
            </div>
          </Block>

          <Block title="Navigation primitives" description="Sidebar item, tab pills, breadcrumb.">
            <div className="grid gap-6 lg:grid-cols-2">
              <div className="space-y-2 rounded-lg border border-border bg-card p-4">
                <div className="text-xs font-semibold uppercase tracking-wider text-muted-fg">Sidebar</div>
                <button className="flex w-full items-center gap-3 rounded-md border-l-2 border-gold bg-gold-soft px-3 py-2 text-sm font-semibold text-gold">
                  <Star className="h-4 w-4" /> Playground
                </button>
                <button className="flex w-full items-center gap-3 rounded-md border-l-2 border-transparent px-3 py-2 text-sm text-muted-fg hover:bg-mut hover:text-fg">
                  <Sparkles className="h-4 w-4" /> Dashboard
                </button>
                <button className="flex w-full items-center gap-3 rounded-md border-l-2 border-transparent px-3 py-2 text-sm text-muted-fg hover:bg-mut hover:text-fg">
                  <Mail className="h-4 w-4" /> Students
                </button>
              </div>
              <div className="space-y-4 rounded-lg border border-border bg-card p-4">
                <div className="text-xs font-semibold uppercase tracking-wider text-muted-fg">Filter pills</div>
                <div className="flex flex-wrap gap-2">
                  {["All", "Language", "STEM", "Arts", "Maths"].map((p, i) => (
                    <button
                      key={p}
                      className={
                        i === 0
                          ? "rounded-full bg-gold-soft px-3 py-1.5 text-xs font-semibold text-gold"
                          : "rounded-full border border-border px-3 py-1.5 text-xs text-muted-fg hover:bg-mut hover:text-fg"
                      }
                    >
                      {p}
                    </button>
                  ))}
                </div>
                <div className="text-xs font-semibold uppercase tracking-wider text-muted-fg">Breadcrumb</div>
                <nav className="flex items-center gap-2 text-sm text-muted-fg">
                  <Link to="/" className="hover:text-fg">Home</Link>
                  <span>/</span>
                  <Link to="/style-guide" className="hover:text-fg">Style guide</Link>
                  <span>/</span>
                  <span className="text-fg">Navigation</span>
                </nav>
              </div>
            </div>
          </Block>

          <footer className="border-t border-border pt-6 text-xs text-muted-fg">
            KA Playground · Phase 0 design system · TanStack Start + Tailwind v4
          </footer>
        </main>

        <Toaster />
      </div>
    </TooltipProvider>
  );
}
