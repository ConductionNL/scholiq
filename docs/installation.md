---
sidebar_position: 2
---

# Installation

This guide walks you through installing Scholiq on your Nextcloud instance.

## Prerequisites

Before installing Scholiq, ensure your environment meets these requirements:

| Requirement | Minimum version | Notes |
|---|---|---|
| Nextcloud | 28.0 | Server must be running |
| PHP | 8.1 | 8.2+ recommended |
| OpenRegister | latest | Required; Scholiq stores all data via OpenRegister |
| OpenConnector | latest | Required; handles BRON/ROD, UWLR, OSO, Edukoppeling adapters |
| PostgreSQL | 14 | Recommended database backend for OpenRegister |

## Install from the App Store

1. Log in to your Nextcloud as an administrator.
2. Open the **Apps** menu (top-right user menu, or navigate to `/settings/apps`).
3. Search for **Scholiq**.
4. Click **Download and enable**.
5. Wait for the installation to complete. Nextcloud will download the app and run the repair steps automatically.

## Manual installation (development)

If you are installing from source or a release archive:

```bash
# Navigate to your Nextcloud custom_apps directory
cd /var/www/html/custom_apps

# Clone or unpack Scholiq
git clone https://github.com/ConductionNL/scholiq.git scholiq

# Install PHP dependencies
cd scholiq && composer install --no-dev

# Install JavaScript dependencies and build
npm install --legacy-peer-deps && npm run build

# Enable the app
php /var/www/html/occ app:enable scholiq
```

## Initial configuration

After installation, complete the setup wizard:

1. Navigate to **Administration settings** (gear icon, then **Administration**).
2. Open the **Scholiq** section in the left sidebar.
3. The app will prompt you to configure the following registers in OpenRegister:
   - **Courses register**: stores course definitions, modules, and lessons
   - **Enrolments register**: stores learner enrolments and progress records
   - **Credentials register**: stores certificates and digital badges
   - **Compliance register**: stores compliance-training completions and audit logs
4. Click **Initialize registers** to create the default register and schema configuration.
5. Optionally configure OpenConnector source connections for:
   - DUO BRON/ROD (student registration)
   - UWLR (learning result exchange)
   - OSO (student transfer dossier)
   - SURFconext (higher-education SSO)

## First-login checklist

After the registers are initialised:

- [ ] Open Scholiq from the Nextcloud app menu
- [ ] Confirm the dashboard loads without errors
- [ ] Navigate to **Courses** and verify the register is reachable
- [ ] (Admin) Navigate to **Settings** and confirm all register connections are green
- [ ] (Higher ed) Configure your SURFconext entity ID under **Settings > Identity**

## Troubleshooting

**Scholiq shows a blank screen after install**
Run `php occ app:repair scholiq` to re-run the register initialisation step.

**"OpenRegister not found" error**
Install and enable OpenRegister before enabling Scholiq. Scholiq requires OpenRegister as a dependency.

**Dashboard shows "Connection error" on a register tile**
Check that OpenRegister is running and the register slugs match those configured in Scholiq's settings. Re-run **Initialize registers** if needed.

**Permission error on first open**
Ensure the Nextcloud `www-data` user has write access to the `custom_apps/scholiq` directory.

## Upgrading

Scholiq follows Nextcloud's standard upgrade path. When a new version is available:

1. The Nextcloud update notification will appear in **Administration > Overview**.
2. Click **Update** next to Scholiq, or run `php occ upgrade`.
3. The repair step will migrate any register schema changes automatically.

## Uninstalling

To remove Scholiq:

```bash
php /var/www/html/occ app:disable scholiq
php /var/www/html/occ app:remove scholiq
```

Note: this does not delete data stored in OpenRegister. To remove Scholiq data, delete the associated registers in OpenRegister's administration interface.
