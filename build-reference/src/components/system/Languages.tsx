import { useState } from "react";
import { Toggle } from "@/components/shared/Toggle";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

export function SystemLanguages() {
  const [en, setEn] = useState(true);
  const [zhTw, setZhTw] = useState(true);
  const [zhCn, setZhCn] = useState(false);
  const [defaultLang, setDefaultLang] = useState("zh-TW");

  return (
    <div className="flex flex-col gap-6 pb-8">
      <header>
        <h2 className="font-heading text-2xl font-bold text-fg">Languages</h2>
        <p className="mt-1 text-sm text-muted-fg">Which languages your workspace supports.</p>
      </header>

      <div className="rounded-[14px] border border-border bg-card p-5">
        <h3 className="font-heading text-[15px] font-bold text-fg">Available languages</h3>
        <div className="mt-3 flex flex-col">
          <Row label="English" sub="en-GB" checked={en} onChange={setEn} />
          <Row label="Traditional Chinese · 繁體中文" sub="zh-HK" checked={zhTw} onChange={setZhTw} />
          <Row label="Simplified Chinese · 简体中文" sub="zh-CN" checked={zhCn} onChange={setZhCn} />
        </div>
      </div>

      <div className="rounded-[14px] border border-border bg-card p-5">
        <h3 className="font-heading text-[15px] font-bold text-fg">Default for new users</h3>
        <p className="mt-0.5 text-[12px] text-muted-fg">Applied when a parent or student first signs in.</p>
        <div className="mt-3">
          <Select value={defaultLang} onValueChange={setDefaultLang}>
            <SelectTrigger className="w-[260px]"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="en">English</SelectItem>
              <SelectItem value="zh-TW">Traditional Chinese · 繁體中文</SelectItem>
              <SelectItem value="zh-CN">Simplified Chinese · 简体中文</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>
    </div>
  );
}

function Row({ label, sub, checked, onChange }: { label: string; sub: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <div className="flex items-start justify-between gap-4 border-t border-border first:border-t-0 py-3">
      <div>
        <div className="text-[13px] font-semibold text-fg">{label}</div>
        <div className="mt-0.5 text-[11px] uppercase tracking-wider text-muted-fg">{sub}</div>
      </div>
      <Toggle checked={checked} onChange={onChange} />
    </div>
  );
}
