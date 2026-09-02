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
  };
  php: {
    global: string | null;
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
