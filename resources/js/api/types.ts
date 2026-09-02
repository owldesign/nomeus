export interface Status {
  devkit: {
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
  formulae: { formula: string; installed: boolean; version: string | null }[];
}
