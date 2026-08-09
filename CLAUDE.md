# WooCommerce Invoice Payment Method

A WooCommerce extension that adds one payment method: **Pay by invoice**. The customer completes
the checkout without paying, the goods go out, the invoice follows, and somebody settles it later.
What makes it a product rather than a copy of the bank transfer gateway is *who it is offered to* —
an organization the shop has agreed terms with, up to a credit limit it set — which is why this
plugin is built on top of **WooCommerce Organization Accounts Pro** rather than beside it.

- Text domain / slug: `woo-invoice-payment-method`
- PHP namespace: `WooInvoicePayment\` (PSR-4 → `includes/`)
- Global prefix: `woip` / `woo_invoice_payment` — constants, hooks, options, meta keys and the
  table name. WPCS rejects prefixes shorter than four characters, so `wip` is not usable.
- Gateway ID: `woip_invoice`, which is also where WooCommerce gets
  `woocommerce_woip_invoice_settings` and half a dozen hook names from.

## Minimum requirements

The plugin deliberately targets the current release of everything. There is no
backwards-compatibility budget: do not add shims, polyfills or version branches for older
WordPress, WooCommerce or PHP.

| Requirement | Floor | Enforced by |
|---|---|---|
| WordPress | 7.0 (current latest) | `Requires at least` header; WordPress blocks activation below it |
| PHP | 8.2 | `Requires PHP` header, `composer.json`, PHPCompatibilityWP `testVersion` |
| WooCommerce | 11.0 (current latest) | `Requires Plugins` header plus a runtime `version_compare` check |
| Organization Accounts Pro | 0.3.0 | `Requires Plugins` header plus a runtime state check |
| HPOS, Woodmart | inherited | Organization Accounts Pro refuses to boot without them |

**The dependency is the interesting one, and it is checked in three ways rather than one.**

- **`Requires Plugins: woocommerce, woo-organization-accounts-pro`** is what stops WordPress
  activating this plugin without its dependency, and stops the dependency being deactivated
  underneath it. It says nothing about *version*, and nothing at all about whether the dependency
  actually ran.
- **`WOIP_MIN_WOAP_VERSION`** is the version floor, checked against `WOAP_VERSION`.
- **`did_action( 'woo_org_accounts_loaded' )`** is the one that matters. Organization Accounts Pro
  defines its constants at the top of its main file and *then* decides whether to initialise, so
  `defined( 'WOAP_VERSION' )` is true on a site where it refused to run for want of HPOS or its
  theme. Asking whether it fired its own loaded hook is the only question with a useful answer.

`organization_plugin_state()` returns `missing`, `outdated`, `inert` or `ready`, and the admin
notice says which — because the fix is different for each: install it, update it, or go and read
the notice it is already showing about its own requirements. "A dependency is missing" would send
somebody looking for a plugin they have had installed all along.

**There is deliberately no HPOS gate and no theme gate here**, though this plugin needs both. The
dependency already refuses to boot without them, so the state check above is the same answer
arrived at once. Restating them would give a site two notices about one problem and two places to
update when a floor moves.

### Booting late is load-bearing

`bootstrap()` runs on `plugins_loaded` at priority `WOIP_BOOT_PRIORITY` (20), not at the default.
WordPress includes active plugins in directory order, so `woo-invoice-payment-method` is included
*before* `woo-organization-accounts-pro` and its `plugins_loaded` callback is registered first — at
the same priority it would therefore also run first, with `woo_org_accounts_loaded` not yet fired
and every gate above reading the wrong answer. `PluginTest::testItBootsAfterTheDefaultPriority` is
that constraint written down.

## Environment

Development runs against the same **Local by WP Engine** site as the dependency, *TestShop*, which
this directory is already symlinked into:

| | |
|---|---|
| Site root | `~/Local Sites/testshop/app/public` |
| URL | http://testshop.local |
| Stack | WordPress 7.0.x, WooCommerce 11.0.0, PHP 8.2.29, MySQL 8.4.0 |
| Plugin path in site | `wp-content/plugins/woo-invoice-payment-method` → symlink to this repo |
| Dependency | `wp-content/plugins/woo-organization-accounts-pro` → its own checkout |

**A bare `wp` command does not work.** wp-config.php sets `DB_HOST` to `localhost` and Local serves
MySQL over a socket the Homebrew PHP cannot see. Always use the wrapper, which resolves the site,
the socket and Local's own PHP build from Local's `sites.json`:

```bash
./bin/wp plugin list
```

**Never create a symlink inside this checkout.** One pointing at the checkout itself appeared here
once and made `wp i18n make-pot` recurse until the POT was two megabytes of duplicated file
references. `bin/build-zip.sh` would have copied it into the release.

## Commands

```bash
composer lint           # phpcs against the WooCommerce standard
composer lint:fix       # phpcbf — fix what can be fixed automatically
composer test           # PHPUnit against the WordPress test library
composer test:coverage  # …with a coverage report in coverage-html/
composer i18n           # re-extract the POT and recompile the translations
composer i18n:check     # is the POT still in step with the source?
composer check          # everything CI checks: validate, version, i18n, lint, test
composer build          # build dist/woo-invoice-payment-method-<version>.zip
./bin/wp <args>         # wp-cli against the Local site
```

`bin/install-wp-tests.sh` provisions the `woo_invoice_tests` database once, before the first
`composer test`. It refuses to run unless Organization Accounts Pro is installed in the same site,
because the suite loads it — there is no stub, and a stub would be a second answer to every
question this plugin asks it.

## Coding standards

`phpcs.xml.dist` (ruleset `WooCommerce-Core`) is the authority — when this document and the sniffs
disagree, the sniffs win. A PostToolUse hook runs `phpcbf` then `phpcs` on every PHP file written or
edited in this repo and blocks on remaining violations. If the hook reports that phpcbf reformatted
a file, re-read it before editing again.

- Tabs for indentation, not spaces.
- Yoda conditions: `if ( 'value' === $variable )`.
- Spaces inside parentheses: `function foo( $bar )`, `if ( $baz )`, `array( 'key' => 'value' )`.
- `snake_case` for functions and variables, `PascalCase` for classes, `UPPER_SNAKE_CASE` for constants.
- Every file, class, method and property carries a docblock. Inline comments are full sentences
  ending in a period.
- Every user-facing string is translated with the `woo-invoice-payment-method` text domain, and
  uses `printf`-style placeholders rather than concatenation.
- Class files follow PSR-4 (`includes/Gateway/InvoiceGateway.php`), not WordPress's `class-*.php`
  convention — the ruleset already excludes `includes/` from `WordPress.Files.FileName`.

Three relaxations, each scoped and each with its reason written into `phpcs.xml.dist`: the hook
prefix and hook-comment rules inside `includes/Gateway/`, because a gateway's hooks are
WooCommerce's by design (`woocommerce_update_options_payment_gateways_*` and friends are built from
the gateway ID); and nonce verification inside `tests/`, because a test constructs the request
rather than receiving one — `AdminTest` is where the handler is asserted to check it.

## Security — not negotiable

- **Sanitise on input**: `sanitize_text_field()`, `absint()`, `wc_format_decimal()`, `wp_unslash()`
  on every `$_POST` / `$_GET` value. Never read `$_REQUEST` outside a nonce check. The checkout
  collects nothing from the customer — the method states terms and takes the order — so every input
  the plugin has is on the admin screen.
- **Escape on output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` — at the point of
  output, not at assignment. That includes exception messages, which WooCommerce prints.
- **Authorise every state change**: `check_admin_referer()` *and* `current_user_can()`. A nonce
  alone is not authorisation.
- **`process_payment()` is the enforcement point, and it is the only one.** `validate_fields()`
  exists so the customer hears about a problem beside the field they can fix — the block checkout
  never calls it. Anything enforced only there is not enforced at all for most shops.
- **Nothing the browser sends is trusted.** The block integration hands the browser the terms and
  the credit remaining; both are re-derived on the server when the order is placed.
- **Prepare every query**: `$wpdb->prepare()` for anything with a variable in it. All SQL lives in
  `includes/Data/TermsRepository.php` and `includes/Install.php`; nothing else in the plugin
  contains a query.
- **Never commit a real credential**, including as a test fixture.

## Architecture

```
Organization (owned by the dependency)
     └── Invoice terms (this plugin's one table)
            ├── status              may it pay by invoice at all
            ├── payment_terms_days  how long it has
            └── credit_limit        how much it may owe at once
                     ↓
              Pay by invoice gateway ── classic checkout
                     │              └── block checkout (Store API)
                     └── Order: terms and due date, snapshotted
```

### Two questions, asked in this order

`Eligibility` is the whole of "is the method offered", and it asks two things:

1. **May this person buy at all?** That is not this plugin's question — it is
   `Context::can_purchase()` from the dependency, and the dependency already refuses the whole
   checkout when the answer is no. Asking it here anyway is what stops the invoice method being the
   *last one standing* on a checkout that should not be happening: a suspended organization gets no
   payment method rather than the one that lets it take goods away without paying.
2. **Has this organization been given invoicing?** The stored terms answer outright when they say
   allowed or refused, and defer to the gateway's own availability setting when they say nothing.

`Credit` is separate on purpose. Eligibility is about the **customer**, so it decides whether the
method appears; the credit limit is about the **basket**, so it is enforced when the order is placed
and explained in so many words. A payment method that vanished when somebody added one more case to
their cart would read as a bug in the shop.

### Every answer has a "nothing decided" state that is not its zero

All three columns are nullable, and none of the nulls could be folded into a zero:

| Column | `null` means | `0` means |
|---|---|---|
| `status` (`default`) | follow the gateway's availability setting | — |
| `payment_terms_days` | use the gateway's default | payable on receipt |
| `credit_limit` | no limit | no credit at all |

Storing "no limit" as zero would mean the shop could not express it, and a customer whose limit was
accidentally emptied would silently be refused every future invoice. The admin form therefore asks
with a **radio that names the answer** plus a field for the number, never with a field whose
emptiness has to be read as a decision — the same pattern the dependency calls `woap-choice`, and
for the same reason. `AdminTest::testTheRadioDecidesRatherThanTheBox` is the bug it prevents.

An organization with no row at all reads as an all-default `Terms` rather than `null`, so every
caller has an object to ask; and saving a set of terms that says nothing **deletes** the row, because
a row of defaults and no row mean the same thing and keeping both would leave the screen reporting a
decision nobody made.

**The table is a side table, not three columns added to `woap_organizations`.** The dependency owns
that schema, and a plugin that can be deactivated must not leave columns behind in somebody else's
table. `organization_id` is the primary key, so "one organization has one set of terms" is a rule
the database keeps rather than one every write has to remember — the same reason
`woap_members.user_id` is UNIQUE next door.

### There is no invoice paid-flag

WooCommerce already answers "has this been paid?": it stamps `date_paid` on an order the moment the
order reaches a status in `wc_get_is_paid_statuses()`, which is what a shop marking an invoice
settled does. A `_woip_invoice_paid` meta beside that would be a second answer to one question, and
the two would disagree the first time somebody changed a status without using whichever screen
maintained our flag.

So an invoice is outstanding while its order has no `date_paid` and has not been cancelled, refunded
or failed — and the unpaid statuses are *derived* from `wc_get_order_statuses()` rather than listed,
so a shop with a custom status gets the sensible answer.

**The consequence decides how a shop should configure the gateway**, and the settings screen says so:
moving an order to Processing or Completed frees the credit it was using. A shop that ships before it
is paid and wants the limit to keep meaning something should leave new invoice orders **on hold**.

### The terms on an order are a snapshot

`Checkout\OrderMeta` writes `_woip_payment_terms_days` and `_woip_due_date` when the order is
placed, and nothing recomputes them afterwards. Shortening a customer's terms next
month must not retrospectively make last month's invoice overdue — an order is a financial record of
an agreement that was in force when it was made.

The number of days is stored *beside* the date it produced even though one can be derived from the
other, because neither derivation is safe: reading the days off the current terms is the
retrospective edit above, and recomputing the date from the order date and the current terms is the
same edit wearing arithmetic.

`Checkout\OrderDetails::rows()` is the one list every screen showing an invoice is built from — the
customer's order details, both formats of the order emails, and the admin order screen. Three
near-identical lists drifting apart is how a shop ends up with a due date on the email that the
customer's account page disagrees with.

### Both checkouts, one gateway

WooCommerce 11 gives a new shop the block checkout by default, so a gateway that only did the
classic one would be invisible to most shops. `Gateway\Blocks\InvoiceBlockSupport` describes the
method to the browser and `assets/js/blocks/checkout.js` renders it; the order itself still goes
through `InvoiceGateway::process_payment()`, because the Store API hands the chosen method straight
to it and copies the block's payment data into `$_POST` on the way.

- **The block script is plain JavaScript against the globals WooCommerce already enqueues** — no JSX,
  no bundler — so the plugin still builds with nothing but Composer and CI's `node --check assets/js`
  is a real test of the file that ships.
- **`BlocksTest` asserts the two halves cannot drift apart**: the same method name and the same
  availability answer as the classic gateway. The name is a string in a JavaScript file, and
  nothing but a test connects it to the constant the server uses.
- **`declare_compatibility( 'cart_checkout_blocks' )` is not a nicety here.** A gateway that has not
  declared it is hidden from the block checkout entirely.

### Settings live in one place, and it is the gateway

`WooCommerce → Settings → Payments → Pay by invoice` is the only settings screen, including the
data-retention checkbox that would normally sit on a plugin page. The defaults live in
`Gateway\Options::defaults()` and `init_form_fields()` reads them from there, because the rest of the
plugin reads the option directly — the gateway object does not exist on `plugins_loaded`, and it
certainly does not exist during an uninstall. A default typed twice would mean one thing before the
settings were first saved and another afterwards; `GatewayTest::testTheFormDefaultsAreTheOptionDefaults`
holds them together.

`WooCommerce → Invoice Payments` is not a second settings screen: it is the per-customer agreements,
which are a different kind of thing. Editing one organization is **its own screen**, reached by
`?woip_organization=<id>`, never a form folded underneath the list — the row being edited otherwise
lives only in a query argument the form does not post back, so a rejected submission returns as a
blank form and saving again writes the wrong customer's terms.

### The gateway is switched off at activation

Turning a payment method on for a shop that has not been asked which of its customers may use it
would offer every organization the right to take goods away without paying. An activation routine is
not where that decision belongs.

## Working agreement

- Run `composer lint` before calling any change done. The hook covers files you edit; the full run
  catches everything else.
- Add or update a test with each behaviour change — `composer test`.
- Verify against the real site (`./bin/wp`, http://testshop.local) rather than reasoning about what
  WooCommerce would do.
- Bump the version in both the `woo-invoice-payment-method.php` header and the `WOIP_VERSION`
  constant together — `bin/check-version.sh` fails the build when they drift apart.
- Bump `WOIP_DB_VERSION` whenever `Install::schema()` changes, or existing sites keep the old table.
- When the dependency's floor moves, update the `Requires Plugins` header and
  `WOIP_MIN_WOAP_VERSION` together.

## Languages — English and German

The plugin ships English (the source strings) and German, and German is a requirement rather than a
courtesy: `tests/I18nTest.php` fails the build on a string that is in the catalogue but untranslated.
WordPress falls back to English silently, one label at a time, so nothing else would notice.

- **Two German catalogues, not one.** WordPress treats `de_DE` (informal, *du* — the register
  WordPress core itself uses) and `de_DE_formal` (*Sie*) as unrelated locales, so both are
  maintained. Only the strings that address the reader differ between them.
- **Every other German locale is mapped**, by `Plugin::map_german_locale()` on
  `load_textdomain_mofile`. `de_AT`, `de_CH` and `de_CH_informal` have no catalogue of their own and
  WordPress does not fall back between German locales, so without this an Austrian shop reads an
  English checkout.
- **`.mo` and `.l10n.php` are both committed and both shipped.** WordPress 6.5 and newer load the
  PHP file in preference to the `.mo`. The `.po` sources are the input and are left out of the build.
- **Regenerate with `composer i18n`** after adding or changing any translatable string. A fuzzy entry
  counts as untranslated and fails the tests.
- **`composer i18n:check`** asserts the POT still lists every string in the source. It compares the
  *set of strings*, not the files, so moving a function does not fail the build.
- Strings for the block checkout script are registered with `wp_set_script_translations()`; without
  it the block checkout would be the one screen in the plugin that is always English.

## Continuous integration and releases

`.github/workflows/ci.yml` runs on every push to `main` and every pull request: coding standards
(`composer validate --strict`, `bin/check-version.sh`, `composer lint`, `node --check` over
`assets/js`), translations, `composer audit --locked`, the full suite on PHP 8.2, 8.3 and 8.4, a
coverage run under pcov, and `bin/build-zip.sh` so every pull request proves the release artefact
still builds.

**CI cannot use `bin/install-wp-tests.sh`** — it resolves everything from Local's `sites.json`.
`bin/install-wp-tests-ci.sh` is the runner's equivalent, and it has one job the dependency-free
plugins do not: **it installs Organization Accounts Pro**. That plugin is not on WordPress.org, so
the runner fetches its release zip the same way an installed copy discovers an update — through the
`update.json` published beside it at `/releases/latest/download/update.json`, which names the
package. Pin a version with `WOAP_VERSION` if a release ever needs testing against an older one.

It also **symlinks this checkout into `wp-content/plugins/woo-invoice-payment-method`, and that link
is load-bearing.** `tests/bootstrap.php` loads both plugins from `WP_PLUGIN_DIR` and calls
`wp_register_plugin_realpath()` first, which is how WordPress itself loads a symlinked plugin and the
only way `plugin_basename()` shortens to the plugin slug. Everything keyed on that slug behaves
differently when it does not: `FeaturesUtil::declare_compatibility()` records support under an
absolute path, and `load_plugin_textdomain()` registers a languages directory that does not exist.

Releases are cut by pushing a `v*` tag (`.github/workflows/release.yml`): CI runs against the tagged
commit first, `bin/check-version.sh <tag>` asserts the tag and the two version declarations agree,
and `bin/build-zip.sh` produces the zip, its `.sha256` and `dist/update.json`, which `gh release
create` publishes. A tag containing a hyphen (`v0.2.0-rc.1`) is published as a pre-release.

## Updates from GitHub

The plugin is not in the WordPress.org directory, so nothing would otherwise tell an installed copy
that a newer version exists. `Updates\Updater` closes that gap through the **`Update URI` header**
and core's **`update_plugins_{$hostname}` filter** — the mechanism WordPress added in 5.8 for exactly
this — rather than a bundled update-checker library. It works the same way as the dependency's, and
the same three things are worth remembering:

- **An answer is returned even when the installed version is current**, which is what makes the
  auto-update toggle appear: the plugins screen decides a plugin supports updates by finding it in
  `response` *or* `no_update`.
- **The metadata comes from `update.json` published beside the zip**, not from `api.github.com`,
  which is rate limited to 60 requests an hour per IP. **A release published without `update.json`
  is invisible to every installed copy** — and to CI's dependency installer, which reads the same
  file.
- **The updater is registered before the requirement gates**, in the main file rather than in
  `Plugin::init()`. An update is often exactly what fixes a plugin sitting inert behind one.

## Dependency versions — do not "upgrade" these

Two pins look outdated and are not.

- **PHP_CodeSniffer stays on 3.x.** 4.0 is released, but `wp-coding-standards/wpcs` 3.4.1 requires
  `^3.13.5`. Requiring `woocommerce/woocommerce-sniffs ^2.0` resolves the whole standards stack
  correctly; do not pin PHPCS directly.
- **PHPUnit stays on 9.x.** WordPress's own test library calls
  `PHPUnit\Util\Test::parseTestMethodAnnotations()` and `$this->getName( false )`, both removed in
  PHPUnit 10. This is a WordPress constraint, not a polyfills one.
- `config.platform.php` is pinned to `8.2.29` so Composer resolves for the site's runtime rather
  than the host's PHP 8.5.
