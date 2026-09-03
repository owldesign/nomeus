export interface Status {
  nomeus: {
    version: string;
    home: string;
    config_path: string;
    config_exists: boolean;
    code_dir: string;
  };
  valet: {
    installed: boolean;
    version: string | null;
    tld: string;
    loopback: string | null;
    paths: string[];
    bin: string;
    trusted: boolean;
  };
  php: {
    global: string | null;
    installed: string[];
  };
  services: {
    nginx: boolean;
    dnsmasq: boolean;
    php_fpm: string[];
    mailpit: boolean;
  };
  dashboard: {
    url: string;
    linked: boolean;
  };
  instances: { name: string; type: string; port: number; running: boolean }[];
}

export type SiteType = 'parked' | 'linked' | 'proxy';

export interface Site {
  name: string;
  host: string;
  url: string;
  type: SiteType;
  path: string;
  secured: boolean;
  php: string | null; // isolated version, or null = global
  laravel: boolean;
  manifest: boolean;  // has a nomeus.yml
  nginx_conf: string | null;
}

export interface SiteDetail extends Site {
  about: Record<string, Record<string, string>> | null;
}

export type TaskStatus = 'queued' | 'running' | 'done' | 'failed';

export interface Task {
  id: string;
  label: string;
  argv: string[];
  cwd: string | null;
  status: TaskStatus;
  exit_code: number | null;
  created_at: string;
  started_at: string | null;
  finished_at: string | null;
  timeout: number;
  log?: string;
}

/** Mutations answer 202 with the task they spawned. */
export interface Enqueued {
  task: Task;
}

export interface PhpVersion {
  version: string;       // "8.3"
  patch: string | null;  // "8.3.14"
  linked: boolean;       // global
  fpm: boolean;
  sites: string[];
  ini: string;
  confd: string;
  outdated: string | null;
}

export interface PhpState {
  global: string | null;
  installed: PhpVersion[];
  installable: string[];
  min_php: string;
}

export interface ServiceStatus {
  running: boolean;
  loaded: boolean;
  pid: number | null;
  last_exit: number | null;
  crashing: boolean;
  disabled: boolean;
  installed: boolean;
}

export interface ServiceInstance {
  name: string;
  type: string;
  formula: string;
  version: string;
  port: number;
  dir: string;
  created_at: string;
  options: Record<string, unknown>;
  status: ServiceStatus;
  env: Record<string, string>;
  log?: string;
}

export interface ServiceType {
  type: string;
  label: string;
  default_port: number;
  requires_site: boolean;
  site_package: string | null;
  formulae: { formula: string; installed: boolean; version: string | null }[];
}

export interface BrewService {
  formula: string;
  label: string;
  loaded: boolean;
  pid: number | null;
  plist: string | null;
  type: string | null;
  data_dir: string | null;
  has_data: boolean;
  port: number | null;
  answering: boolean | null;
}

export interface MailStatus {
  instance: string | null;
  available: boolean;
  smtp_port: number;
  http_port: number;
  ui_url: string;
  env: Record<string, string> | null;
}

export interface MailAddress {
  Name: string;
  Address: string;
}

export interface MailSummary {
  ID: string;
  From: MailAddress | null;
  To: MailAddress[];
  Subject: string;
  Created: string;
  Tags: string[];
  Read: boolean;
  Snippet: string;
  Size: number;
  Attachments: number;
  view_url: string;
}

export interface MailMessage {
  ID: string;
  From: MailAddress | null;
  To: MailAddress[];
  Cc: MailAddress[];
  Subject: string;
  Date: string;
  Tags: string[];
  Text: string;
  HTML: string;
  Attachments: { PartID: string; FileName: string; ContentType: string; Size: number }[];
  view_url: string;
}

export interface MailPage {
  total: number;
  unread: number;
  count: number;
  start: number;
  messages: MailSummary[];
}

export interface LogSource {
  id: string;
  group: string;
  label: string;
  path: string;
  size: number;
  mtime: number;
  kind: 'laravel' | 'nginx' | 'php-fpm';
}

export interface LogRef {
  text: string;
  file: string;
  line: number;
  url: string | null;
}

export interface LogEntry {
  ts: string | null;
  env: string | null;
  level: string;
  severity: 'error' | 'warning' | 'info' | 'debug';
  message: string;
  context: string | null;
  trace: string;
  refs: LogRef[];
}

export interface LogTail {
  offset: number;
  size: number;
  truncated: boolean;
  reset: boolean;
  entries: LogEntry[];
  source: LogSource;
}

export type DumpKind = 'dump' | 'query' | 'job' | 'view' | 'request' | 'log';

export interface DumpEntry {
  id: number;
  created_at: string;
  kind: DumpKind;
  request_key: string | null;
  uri: string | null;
  method: string | null;
  command: string | null;
  file: string | null;
  line: number | null;
  url: string | null;
  text: string;
  html: string | null;
  payload: Record<string, unknown> | null;
}

export interface DumpsStatus {
  capture: boolean;
  instance: string | null;
  port: number;
  running: boolean;
  prepend: boolean;
  ini: Record<string, { ini: boolean; current: boolean }>;
  counts: Partial<Record<DumpKind, number>>;
  latest_request: string | null;
}

export interface DumpRequest {
  request_key: string;
  uri: string | null;
  method: string | null;
  command: string | null;
  first: string;
  last_id: number;
  n: number;
}

export type XdebugMode = 'off' | 'on' | 'trigger';

export interface XdebugStatus {
  versions: Record<string, { installed: boolean; so: string | null; mode: XdebugMode; tap_ini: boolean; ini_current: boolean }>;
  linked: string | null;
  port: number;
  ide_listening: boolean;
}

export interface DoctorRow {
  section: string;
  level: 'ok' | 'warn' | 'fail';
  check: string;
  detail: string;
}

export interface DoctorReport {
  rows: DoctorRow[];
  counts: { ok: number; warn: number; fail: number };
  sections: string[];
}
