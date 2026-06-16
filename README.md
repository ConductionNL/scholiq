![Scholiq logo](img/app-store.svg)

# Scholiq

**Open-source leerlingvolgsysteem (LVS) + leeromgeving (LMS) for Nextcloud**

Scholiq is a compliance-training and learning management app for organisations and schools. It ships as a Nextcloud app built manifest-first on OpenRegister — every entity, lifecycle, notification, and dashboard widget is declared in a JSON schema register, not hand-coded in PHP services.

[![Latest release](https://img.shields.io/gitea/v/release/Conduction/scholiq?gitea_url=https%3A%2F%2Fcodeberg.org)](https://codeberg.org/Conduction/scholiq/releases)
[![License](https://img.shields.io/badge/license-EUPL--1.2-blue)](LICENSE)
[![Code quality](https://ci.codeberg.org/api/badges/Conduction/scholiq/status.svg)](https://ci.codeberg.org/repos/Conduction/scholiq)

---

## What scholiq is

Scholiq replaces the deprecated `learniq` and `edudesk` concept apps. Its **Path A — compliance-audit MVP** (Wave 2) delivers:

- **Course + Lesson management** — lifecycle-gated publishing (draft → published → archived); cmi5/xAPI/SCORM content runtime; lesson player in-app.
- **Enrolment engine** — mandatory training assignment; bulk-enrol by group or role; T-30/T-7/T-1 due-date reminders; RAG status per enrolment.
- **Certification** — Open Badges 3.0 credentials auto-issued on completion; RS256-signed; public verify URL; tiered expiry alerts; renewal auto-enrolment.
- **Compliance-audit wedge** — Regulation objects (NIS2, AVG, BIO, …); coverage-% aggregation over live Enrolment/Attestation/Credential data; officer alerts on coverage drop; attestation signing (HMAC-SHA256); audit-pack ZIP export.
- **EU AI Act gate** — every AI feature is a disabled-by-default schema object; enabling requires explicit DPO acknowledgement.

## Path A — Compliance-audit MVP

The wedge targets compliance officers at organisations subject to NIS2 / Cyberbeveiligingswet, AVG, or BIO2. The critical workflow:

1. Compliance officer creates a Regulation (e.g. `NIS2`) and links mandatory Courses.
2. HR bulk-enrols a cohort; due dates are assigned.
3. Learners complete lessons, sign attestations; credentials auto-issue.
4. Compliance officer views real-time coverage %, exports an audit pack for an external auditor.

## Quick start

### Requirements

| Dependency | Version |
|---|---|
| Nextcloud | 28 – 33 |
| PHP | 8.1+ |
| Node.js | 20+ |
| [OpenRegister](https://codeberg.org/Conduction/openregister) | latest |
| [OpenConnector](https://codeberg.org/Conduction/openconnector) | latest |

### Install

```bash
# 1. Enable the app
docker exec nextcloud php occ app:enable scholiq

# 2. Register the scholiq register (manual step — auto-bootstrap pending openregister#1487)
#    Import lib/Settings/scholiq_register.json via the OpenRegister admin UI, or:
docker exec nextcloud php occ openregister:register:import /var/www/html/custom_apps/scholiq/lib/Settings/scholiq_register.json
```

> The automatic register-bootstrap on `app:enable` is blocked by openregister#1487 (tracked in scholiq#35). Until that is resolved, the manual import step above is required after every fresh install.

### From source (development)

```bash
cd /var/www/html/custom_apps
git clone https://codeberg.org/Conduction/scholiq.git scholiq
cd scholiq
npm install && npm run build
make dev-link          # creates the scholiq -> nextcloud-scholiq symlink if needed
docker exec nextcloud php occ app:enable scholiq
```

## Documentation

| Document | Description |
|---|---|
| [Architecture](docs/ARCHITECTURE.md) | Schema model, ADR chain, PHP exception inventory |
| [User guide](docs/USER-GUIDE.md) | Learner / Manager / Compliance-Officer walkthroughs |
| [Admin guide](docs/ADMIN-GUIDE.md) | Install, register bootstrap, signing keys, troubleshooting |
| [API reference](docs/API.md) | OR endpoints, scholiq-specific endpoints, examples |
| [Specs](docs/SPECS.md) | Links to the 6 applied OpenSpec changes |

## Development

### Code quality

```bash
# PHP
composer check:strict   # PHPCS, PHPMD, Psalm, PHPStan, PHPUnit

# Frontend
npm run lint            # ESLint
npm run stylelint       # CSS linting
npm run check:manifest  # validates src/manifest.json against nc-vue schema
```

### Frontend development

```bash
npm run dev             # watch mode
npm run build           # production build
```

## Tech stack

| Layer | Technology |
|---|---|
| Frontend | Vue 2.7, `@conduction/nextcloud-vue` CnAppRoot (Tier 4) |
| Data model | `src/manifest.json` + `lib/Settings/scholiq_register.json` |
| Build | Webpack 5, `@nextcloud/webpack-vue-config` |
| Backend | PHP 8.1+, Nextcloud App Framework |
| Data | OpenRegister (PostgreSQL JSON objects) |
| Quality | PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint |

## Branches

| Branch | Purpose |
|---|---|
| `main` | Stable releases |
| `beta` | Pre-release builds |
| `development` | Active development — merge target for feature branches |

## Standards and compliance

- WCAG 2.2 AA (Dutch government requirement)
- EU AI Act Regulation 2024/1689 — high-risk feature gate via `AiFeature` schema
- Open Badges 3.0 (W3C VC-aligned) — issued credentials
- xAPI 1.0.3 / cmi5 — content runtime and LRS
- EUPL-1.2 license

## Related apps

- [OpenRegister](https://codeberg.org/Conduction/openregister) — required object-store foundation
- [OpenConnector](https://codeberg.org/Conduction/openconnector) — required for external adapters

## Support

Contact [support@conduction.nl](mailto:support@conduction.nl) for support.
For an SLA, contact [sales@conduction.nl](mailto:sales@conduction.nl).

## License

[EUPL-1.2](LICENSE) — Built by [Conduction](https://conduction.nl).
