# SurfaceGuard

**`deployecommerce/module-surface-guard`** — `env.php`-only kill switches for the public, unauthenticated file-upload entry points in Magento 2.

SurfaceGuard lets an operator switch off the storefront upload endpoints a store does not use. When a switch is off, that endpoint's file-upload path is denied outright and nothing reaches disk, while ordinary traffic on the same route — plain add-to-cart, plain REST cart items, address saves without a file — keeps working exactly as before.

There is no admin UI and no database configuration. Every switch lives in `app/etc/env.php`, which means it is deploy-time, version-controlled per environment, and out of reach of a compromised admin session.

## Why this exists

In August and September 2026, the StyleSmuggler campaign (CVE-2026-75650) was recorded making 835 deduplicated exploitation attempts across the Deploy fleet, with the first probe roughly twelve days ahead of public disclosure. The kill-chain staged a GIF+PHP polyglot through the guest custom-option upload path and then named that uploaded file as the source for an arbitrary-instantiation gadget. The upload half of that chain does not depend on the CVE at all — it is ordinary, intended Magento behaviour on an endpoint most of our stores never use.

The reason a polyglot gets through is that Magento's extension gate for custom-option uploads is a **blacklist**, not a whitelist. When a custom option declares no `file_extension` of its own, `magento/module-catalog/etc/config.xml` falls back to `<forbidden_extensions>php,exe</forbidden_extensions>`. So `.phtml`, `.phar`, `.pht` and `.shtml` all pass, and a file crafted to be a valid GIF *and* valid PHP satisfies the optional image check on the way through.

SurfaceGuard does not try to validate its way out of this. A tightened whitelist is still a guess about what the next bypass looks like. Instead it gives each entry point an explicit on/off, and off means refused.

## Installation

```bash
composer require deployecommerce/module-surface-guard
bin/magento module:enable DeployEcommerce_SurfaceGuard
bin/magento setup:upgrade
bin/magento setup:di:compile
```

Register the module in `app/etc/config.php` in the same commit as the code.

The module installs **inert**. With no `harden` key in `env.php`, every switch resolves to core behaviour and the module changes nothing, so it can be deployed fleet-wide before any per-site decision has been made.

## Configuration

All configuration is a single top-level `harden` key in `app/etc/env.php`:

```php
'harden' => [
    'graphql' => ['enabled' => true],          // false => POST /graphql returns 403
    'uploads' => [                             // false => that upload is denied outright
        'cart_add_file'             => true,   // checkout/cart/add custom-option files
        'guest_cart_items_file'     => true,   // REST guest-carts/{id}/items + carts/mine/items
        'customer_address_file'     => true,   // customer/address_file/upload
        'customer_custom_attr_file' => true,   // customer_custom_attributes/*_file/upload
    ],
],
```

### What each switch does

| Key | Denies | Leaves working |
| --- | --- | --- |
| `harden/uploads/cart_add_file` | Custom-option file uploads through the storefront add-to-cart controller | Ordinary add-to-cart. A urlencoded post carries no files and is never touched. |
| `harden/uploads/guest_cart_items_file` | File-type custom options on REST cart-item saves, guest and `carts/mine` | Plain REST item adds and updates. The guard keys on the option, not the route, so integrations are unaffected. |
| `harden/uploads/customer_address_file` | `customer/address_file/upload` — guest-reachable, not login-gated | Address saves that carry no file attribute. Admin-side address file uploads. |
| `harden/uploads/customer_custom_attr_file` | Storefront customer custom-attribute file uploads (Adobe Commerce) | Everything else on the customer account, and the admin-side equivalent. |
| `harden/graphql/enabled` | Every request to `POST /graphql` | Nothing on that endpoint — see the warning below. |

### The fail-safe rule

**Only a strict boolean `false` denies.** An absent key, `null`, `true`, `0`, `'0'`, `'false'`, `''` and an unreadable `env.php` all resolve to core behaviour. The module cannot take a storefront down through a typo or a missing key, and a half-written config fails open rather than closed.

Changes take effect on the next request. Deployment config is not held in the config cache, so no `cache:flush` is needed — only the usual opcache reset on deploy.

## Four things to know before you switch anything off

**`graphql.enabled = false` is blunt.** It refuses the whole endpoint. Core GraphQL cart mutations take string-only option inputs and carry no file-write sink, so there is no upload variant to deny selectively — the endpoint is the only lever. Setting this to `false` stops every headless and PWA storefront call. Use it only on sites confirmed to be Luma or Hyvä with no GraphQL consumers.

The guard runs ahead of Magento's built-in GraphQL cache, so a cached query is refused rather than served from cache. It can do nothing about a response already cached at the CDN, because Varnish or Fastly answers those before PHP is reached at all. This is a PHP-layer control, so on a site behind a CDN, purge the CDN cache after switching GraphQL off.

**Optional file options still sell.** Magento runs its file-option validation even when a customer uploads nothing against an *optional* file option, and relies on the specific exception core throws there to let the purchase continue. The backstop therefore denies only when a file is genuinely present, so switching an upload off never blocks a customer who simply left an optional upload empty.

**The custom-option backstop is layer-agnostic.** `ValidatorFile` and `ValidatorInfo` are the two points every custom-option file upload crosses before the file is moved into `pub/media`, and guarding them is what makes "off" mean genuinely neutered rather than "that one controller is blocked". The consequence is that with either `cart_add_file` or `guest_cart_items_file` off, **the admin-side custom-option file flow is denied too**. This is intentional — a store that has switched the feature off is not selling file-option products — but it is stated here so nobody debugs it as a bug later.

## Verifying a switch is live

Set the switch to `false`, then:

```bash
# Should return 403 and write nothing.
curl -s -o /dev/null -w '%{http_code}\n' \
  -F 'product=42' -F 'options[7]=@/tmp/probe.gif' \
  https://example.com/checkout/cart/add

# The denial should be the newest line here.
tail -n 5 var/log/surfaceguard.log

# And nothing should have been written.
find pub/media/custom_options -newermt '-2 minutes'
```

Every denial writes one line to `var/log/surfaceguard.log` carrying the endpoint, the switch that denied it, and the client IP. Deliberately nothing else: no filename, no request body, no header dump. A log that carries attacker-supplied content is its own liability, and the full request picture belongs in the traffic stream, not here.

A denial line for an endpoint you believed was unused is a finding. It is either a legitimate flow nobody documented, or exactly the traffic the switch was installed to stop.

## What this module does not do

- **It is not a whitelist.** It does not change Magento's extension validation, and it does not attempt to tell a safe upload from a dangerous one. It only answers whether a given endpoint is switched on.
- **It is not incident response.** It reduces future attack surface. It does not find, quarantine or remove files already written to `pub/media`, and it will not tell you whether a store was hit.
- **It is not a substitute for the hotfix.** Patching and SurfaceGuard address different halves of the problem. A store that was compromised still needs the IOC sweep and key rotation regardless of what is switched off here.
- **It does not detect or alert.** Detection lives in the fleet traffic pipeline, which runs the IOC sweep across every site at once.

## Development

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
  vendor/deployecommerce/module-surface-guard/Test/Unit
```

Do not run the suite while `bin/magento setup:di:compile` is running against the same checkout — the compile clears `generated/code` and any suite mocking factories or proxies fails en masse for reasons that have nothing to do with this module.
