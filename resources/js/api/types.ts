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
