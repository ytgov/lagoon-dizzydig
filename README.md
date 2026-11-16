# lagoon-dizzydig: Yukon Government - HPW - Digital Service Delivery Guide

## Introduction

The Digital Service Delivery Guide (a.k.a., DSDG or "DizzyDig") is a collection of
best-practice guidance and processes for designing and delivering government services
that are simple and fast and strive to improve the user experience.

This project was generated from
[lagoon-boutique-template](https://github.com/ytgov/lagoon-boutique-template).

## How to Contribute

See [CONTRIBUTING.md](CONTRIBUTING.md)

## Licence

Unless otherwise noted, the source code of this project is distributed under the
[MIT License](LICENSE).

## Requirements

- Install [ddev](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/) v1.24+.

## Installation

### Install the new site in a local development environment

1. Launch the development environment: `ddev start`
2. Visit the new site's Drupal installation page at `https://lagoon-dizzydig.ddev.site`.
3. Install the site with the following options:
   - Choose language: English
   - Installation profile: Minimal
   - Configure site: as desired but site settings will be overridden shortly.
     The site email address should be `eservices@yukon.ca` unless advised otherwise by eServices.
4. Override the system's site UUID from the value in `config/sync/system.site.yml`:
   `ddev drush cset system.site uuid <uuid value from system.site.yml>`
5. Import the template's default configuration: `ddev drush cim -y`
6. Genuflect to the Drupal God: `ddev drush cr`
7. Add the user created in step #3 to the Administrator role.
8. Verify there any errors shown on the Drupal
   [Status report](https://lagoon-quitpath-ca.ddev.site/admin/reports/status)
   are within reason.

## Yukon SSO (OpenID Connect)

Staff sign in with Yukon SSO, the OpenID Connect client `generic` (redirect URL
`/openid-connect/generic`). Its credentials and endpoints differ for each
environment (DEV, UAT, PROD), so `config/sync` holds the placeholder
`SEELOCALSETTINGS` and `settings.php` reads the real values from these
environment variables:

| Variable | Client setting |
|---|---|
| `OIDC_CLIENT_ID` | Client ID |
| `OIDC_CLIENT_SECRET` | Client secret |
| `OIDC_AUTHORIZATION_ENDPOINT` | Authorization endpoint |
| `OIDC_TOKEN_ENDPOINT` | Token endpoint |
| `OIDC_USERINFO_ENDPOINT` | UserInfo endpoint |

- On Lagoon, set them as runtime environment variables for each environment.
- On other servers, copy `web/sites/default/example.settings.local.php` to
  `web/sites/default/settings.local.php` (git ignores it) and fill in the
  values there.

Without all five, the client is turned off and the password login form shows.
With them, the login form shows only the Yukon SSO button; add `?showcore` to
`/user/login` for the password form.

Never enter real values in the client's settings form: they would be saved to
config and exported to git. The canary fails if `config/sync` holds anything
other than the placeholder.
