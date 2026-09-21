# Security policy

## Reporting a vulnerability

Please do not publish sensitive details in a public issue. Contact the maintainer privately with:

- the affected URL, file, or configuration;
- a minimal reproduction or proof of impact;
- the PHP version and deployment mode;
- any suggested mitigation.

Until a private contact address is published, open an issue with the title `Security report requested` and avoid including credentials, personal data, or an exploit payload.

## Deployment reminders

- Keep `config.php` and `data/*.sqlite*` outside version control.
- Replace the management password hash before exposing the site.
- Keep `trust_proxy` disabled unless every request passes through a trusted reverse proxy.
- Point the web root at `public/`.
