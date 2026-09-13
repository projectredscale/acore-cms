# Store Header Gating: Requirements

## Summary

Pre-launch the site must not advertise the store. A visitor sees a login control
in the header and nothing else store-related: no shop link, no cart button. A
logged-in account keeps the full header (shop link and cart button), so the store
works for account holders once balance products exist. The store itself already
forces login, so the header change is presentation only.

## Verified facts (2026-09-13, live local site)

| Fact | Value |
|---|---|
| Site | `http://localhost`, WordPress 7.1, WooCommerce 11.0.1, multisite blog 1 |
| Theme | `twentytwentyfive` 1.5, stock, no child theme |
| Containers | `acore-cms-php-1` (PHP-FPM), `acore-cms-web.local-1` (nginx), `acore-cms-wp-db-1` (MySQL) |
| Repo surface | only `src/acore-wp-plugin/` is bind-mounted into the container; the WordPress tree lives in the docker volume `wordpress-src` |
| Header storage | WordPress template parts in the DB (`wp_template_part` posts), not files |
| Shop products | none, the shop ships empty |
| myCred | installed but inactive; the coin balance now lives in `acore_auth` |

### How the header resolves

- The theme templates (`wp-content/themes/twentytwentyfive/templates/`: `404`,
  `archive`, `home`, `index`, `page-no-title`, `page`, `search`, `single`) and
  the DB templates (36 `home`, 38 `404`, 74 `page-no-title`) all reference
  `wp:template-part {"slug":"header","theme":"twentytwentyfive"}`.
- A DB template part owning the slug `header` for the active theme overrides the
  theme file, so whichever part has that slug is the site header. No template
  needs editing to swap headers.
- The header navigation used to be `wp:page-list`, which lists every published
  page: About, Cart, Checkout, Home, How to connect, My account, Progression,
  Shop. `page-list` cannot exclude pages, so dropping Shop requires explicit
  links.
- WooCommerce injects `woocommerce/customer-account` and `woocommerce/mini-cart`
  into `core/navigation` as hooked blocks. A navigation block opts out with
  `metadata.ignoredHookedBlocks`.

### Store access and registration

- `src/acore-wp-plugin/src/Hooks/WooCommerce/StoreLogin.php` redirects guests from
  shop, cart, checkout, my-account, product and order pages to `wp-login.php`.
  Verified: 302 on all of them.
- Registration is already disabled: network option `registration` is `none` and
  WooCommerce `woocommerce_enable_myaccount_registration` is `no`.

## Requirements

| # | Requirement |
|---|---|
| R1 | A guest sees a login control in the header that leads to the account page. The WooCommerce customer-account block in `text_only` mode renders "Login" for guests and "My Account" when logged in, so one block covers both states. |
| R2 | A guest sees no shop link and no cart button in the header. |
| R3 | A logged-in user sees the shop link and the cart button. |
| R4 | The shop stays empty until balance products (SKUs crediting `account_coins`) are added. This document stocks nothing. |
| R5 | Visitor registration stays disabled. |

Acceptance criteria:

- AC1 A guest page source contains no `/shop/` link inside the header.
- AC2 A guest page source contains no `wc-block-mini-cart__button`.
- AC3 The guest header links to `/my-account/` with the label "Login".
- AC4 The header rendered for a logged-in user contains the Shop link and the cart button.
- AC5 `/shop/`, `/cart/`, `/checkout/` and `/my-account/` still redirect guests to `wp-login.php`.

## Implemented state

Part 270 was created in the Site Editor as a duplicate of the Twenty Twenty-Five
header pattern (`metadata.patternName` `twentytwentyfive/header` with the
`core/simple-header` group inside) and nothing referenced it until the swap below.
It is a template part, not a reusable block, so it is the header itself.

1. Template part 270 (`Redscale Header`) now holds:
   - explicit `core/navigation-link` items: Home, About, Progression,
     How to connect, Shop, with relative URLs;
   - `metadata.ignoredHookedBlocks` for both WooCommerce hooked blocks on the
     navigation;
   - an explicit `woocommerce/customer-account` block with
     `displayStyle: text_only`;
   - an explicit `woocommerce/mini-cart` block;
   - the navigation, account block and cart wrapped in a right-aligned flex group
     so the logo stays left.
2. Slug swap: part 270 owns `header` (it is the live header), part 57 was renamed
   to `header-original` and retitled `Header (twenty twenty-five)`. Its content is
   untouched, which doubles as the pre-change copy.
3. New filter `src/acore-wp-plugin/src/Hooks/WooCommerce/StoreHeader.php` plus one
   `require_once` line in `WooCommerce.php`. On `render_block` it returns an empty
   string for guests when the block is `woocommerce/mini-cart` or a
   `core/navigation-link` whose path is the shop page path. Logged-in users get
   the untouched markup.

Guest header, verified with `curl http://localhost/`:

```text
Project Redscale -> http://localhost
Home -> /            About -> /about/            Progression -> /progression/
How to connect -> /how-to-connect/               Login -> http://localhost/my-account/
```

Header in the same markup rendered for a logged-in user (via
`wp eval-file` with `wp_set_current_user(1)`):

```text
... Shop -> /shop/   My Account -> http://localhost/my-account/   [cart button present]
```

## Database changes

The header is database content, so the repo alone does not reproduce the live
site. Three rows in the WordPress database of `acore-cms-wp-db-1` were written on
2026-09-13:

| Row | Change |
|---|---|
| `wp_template_part` 270 (Redscale Header) | `post_content` replaced with the markup in `header.html`; `post_name` `redscale-header` to `header` |
| `wp_template_part` 57 (Header (twenty twenty-five)) | `post_name` `header` to `header-original`, `post_title` `Header` to `Header (twenty twenty-five)`. `post_content` untouched, byte-identical to before |
| `wp_template` 36, 38, 74 | no net change: they briefly pointed at `redscale-header` while the header was being swapped, then went back to `{"slug":"header"}` and are byte-identical to before |

No other rows were written: no options, products, users or menu entries.

`wp_navigation` post 9 (`Navigation`, the old `wp:page-list`) is left in place and
is no longer referenced by the header.

Because this is database content and `npm run docker:db:export` output is
gitignored (`.gitignore` excludes `data/sql`), `header.html` in this directory is
the record of the markup. To get it back from a restorable dump instead, the dump
has to be taken separately with `npm run docker:db:export`.

## Verification

```bash
# Guest: expect no "Shop" link, no cart button, and a Login link
curl -s http://localhost/ | grep -c '/shop/'
curl -s http://localhost/ | grep -c 'wc-block-mini-cart__button'

# Store pages still bounce guests
for u in /shop/ /cart/ /checkout/ /my-account/; do
  curl -s -o /dev/null -w "$u %{http_code} %{redirect_url}\n" "http://localhost$u"
done

# Both user states in one pass
docker cp /tmp/header-check.php acore-cms-php-1:/tmp/header-check.php
docker exec acore-cms-php-1 wp eval-file /tmp/header-check.php --allow-root
```

## Revert

1. Give the slug back to the original header:
   `docker exec acore-cms-php-1 wp post update 57 --post_name=header --post_title='Header' --allow-root`
   `docker exec acore-cms-php-1 wp post update 270 --post_name=redscale-header --allow-root`
2. Remove `require_once __DIR__ . "/StoreHeader.php";` from `WooCommerce.php` and
   delete `src/acore-wp-plugin/src/Hooks/WooCommerce/StoreHeader.php`.

Deleting only the hook restores the old header for guests while keeping the new
markup, because the Shop link and cart button are rendered again.

## Appendix: live header markup

`header.html` in this directory is the exact `post_content` of template part 270
(2700 bytes, no trailing newline), so the markup is written down in one place.
Apply it with:

```bash
docker cp header.html acore-cms-php-1:/tmp/header.html
docker exec acore-cms-php-1 wp post update 270 /tmp/header.html --allow-root
```

Pass the content as a file, not as `--post_content`, otherwise WordPress strips
the slashes in the block attributes.

## Open items

1. `POST /wp-login.php` does not answer within 25s from this machine, while
   `wp_signon()` inside the container returns in 0.3s. Pre-existing, unrelated to
   this change, but the header login button lands on that page, so it needs a look
   before the site is public.
2. `.agents/skills/acore-cms/SKILL.md` and `acore-cms/AGENTS.md` still describe
   the retired myCred coin economy and the removed `src/redscale-theme/` theme.
   They need a refresh once this store work settles.
3. Later steps, not covered here: balance products (SKUs crediting `account_coins`),
   showing the coin balance on the account page, and re-enabling the shop link for
   everyone.
