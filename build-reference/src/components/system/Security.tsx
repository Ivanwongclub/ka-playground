import { useState } from "react";
import { ShieldCheck, Lock } from "lucide-react";
import { Toggle } from "@/components/shared/Toggle";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

export function SystemSecurity() {
  const [twoFa, setTwoFa] = useState(true);
  const [sso, setSso] = useState(false);
  const [session, setSession] = useState("4h");
  const [encrypt] = useState(true);
  const [retention, setRetention] = useState("12");

  return (
    <div className="flex flex-col gap-6 pb-8">
      <header>
        <h2 className="font-heading text-2xl font-bold text-fg">Security</h2>
        <p className="mt-1 text-sm text-muted-fg">Workspace-wide access controls and data protection.</p>
      </header>

      <div className="rounded-[14px] border border-border bg-card p-5">
        <h3 className="font-heading text-[15px] font-bold text-fg">Access controls</h3>
        <div className="mt-3 flex flex-col">
          <Row>
            <Info label="Require 2FA for admins" desc="Force second-factor verification for any admin sign-in." />
            <Toggle checked={twoFa} onChange={setTwoFa} />
          </Row>
          <Row>
            <Info label="Single sign-on enforcement" desc="Require workspace SSO for all team members." />
            <Toggle checked={sso} onChange={setSso} />
          </Row>
          <Row>
            <Info label="Session timeout" desc="Automatically sign users out after inactivity." />
            <Select value={session} onValueChange={setSession}>
              <SelectTrigger className="w-[140px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="30m">30 minutes</SelectItem>
                <SelectItem value="1h">1 hour</SelectItem>
                <SelectItem value="4h">4 hours</SelectItem>
                <SelectItem value="never">Never</SelectItem>
              </SelectContent>
            </Select>
          </Row>
        </div>
      </div>

      <div className="rounded-[14px] border border-border bg-card p-5">
        <h3 className="font-heading text-[15px] font-bold text-fg">Data & privacy</h3>
        <div className="mt-3 flex flex-col">
          <Row>
            <Info
              label="Store student data in Hong Kong region"
              desc="All data stays in HK datacenters to comply with local rules."
              badge={
                <span className="inline-flex items-center gap-1 rounded-full bg-cyan/15 px-2 py-0.5 text-[10px] font-semibold text-cyan">
                  <Lock size={10} /> PDPO
                </span>
              }
            />
            <div className="inline-flex items-center gap-2 text-[12px] font-semibold text-success">
              <ShieldCheck size={14} /> Always on
            </div>
          </Row>
          <Row>
            <Info label="Encrypt all stored data" desc="AES-256 at rest, TLS 1.3 in transit." />
            <div className="inline-flex items-center gap-2 text-[12px] font-semibold text-success">
              <ShieldCheck size={14} /> On
            </div>
          </Row>
          <Row>
            <Info label="Retain activity logs" desc="How long audit entries are kept for compliance." />
            <Select value={retention} onValueChange={setRetention}>
              <SelectTrigger className="w-[160px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="6">6 months</SelectItem>
                <SelectItem value="12">12 months</SelectItem>
                <SelectItem value="24">24 months</SelectItem>
                <SelectItem value="36">36 months</SelectItem>
              </SelectContent>
            </Select>
          </Row>
        </div>
        <input type="hidden" value={String(encrypt)} readOnly />
      </div>
    </div>
  );
}

function Row({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-t border-border first:border-t-0 py-3">
      {children}
    </div>
  );
}

function Info({ label, desc, badge }: { label: string; desc: string; badge?: React.ReactNode }) {
  return (
    <div className="min-w-0">
      <div className="flex items-center gap-2">
        <span className="text-[13px] font-semibold text-fg">{label}</span>
        {badge}
      </div>
      <div className="mt-0.5 text-[12px] text-muted-fg">{desc}</div>
    </div>
  );
}
