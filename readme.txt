=== TLDers Domain Prices ===
Contributors: tlders
Tags: domain, domain prices, affiliate, price comparison, widget
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Domain price comparison with your own registrar affiliate links. Tables, search box, block and widget. Prices from 145+ registrars.

== Description ==

**Turn your site into a domain price comparison and earn the affiliate commission on every sale.**

TLDers Domain Prices shows live register, renew and transfer prices from 145+ registrars and 1,100+ extensions, from [TLDers](https://www.tlders.com), refreshed daily. Paste your own affiliate link for each registrar you've joined, and every "Buy" button for that registrar uses your link.

It suits hosting and web design blogs, domain investors, "best domain registrar" reviews, and any site that helps people start a website.

= What you can add =

* **Domain search box:** visitors type `mybrand.com` and see where it's cheapest to register, renew and transfer.
* **Price table** for one extension, e.g. every registrar's price for .io.
* **Cheapest price list:** the cheapest registrar for each extension you choose.
* **Inline price** inside your text, e.g. "$9.58 at Namecheap", linked.

= 6 skins =

* **Theme** (default) blends into your site's own fonts and colours
* **Aurora** (indigo to pink gradients), **Midnight** (dark with neon accents), **Fresh** (mint and teal), **Sunset** (orange to pink) and **Minimal** (clean and blue)

Pick one in Settings, or set a different skin per block, widget or shortcode (`skin="midnight"`). Every skin shows registrar avatars, a "Best price" badge, how much the cheapest registrar saves against the average, price bars, and a "Promo first year" warning when a cheap first year renews much higher.

Add them with the **Domain Prices block** (posts, pages and block-theme widget areas, with a live preview), the **sidebar widget** (classic themes), or shortcodes.

= Interactive, not static =

* **Instant sorting:** switch between Register, Renew and Transfer prices and the list reorders on the spot (animated).
* **List or grid:** visitors pick the layout they like; it's remembered in their browser.
* **Filter as you type** and **Show all** for long lists.
* **Live search:** prices appear under the search box as the visitor types, without reloading the page.

Everything still works with JavaScript turned off.

= Built for affiliates =

* **Your link per registrar**, with `{domain}`, `{sld}` and `{tld}` placeholders, so visitors land on the registrar's search for the exact domain they want.
* **Your currency:** show prices in INR, EUR, GBP or any currency at your own rate.
* **Disclosure included:** an editable affiliate disclosure appears under price tables.
* **Correct link attributes:** every Buy link gets `rel="sponsored nofollow noopener"`.
* **Sidebar search to a results page:** a compact search box in the sidebar sends visitors to a full-width results page.

= Fast and light =

* Prices are cached on your server and refreshed in the background twice a day, so your pages never wait on an outside service.
* If TLDers can't be reached, the last known prices keep showing.
* No tracking scripts and no external CSS or JavaScript. Tables use your theme's fonts and turn into compact cards on phones and in sidebars.

= Shortcodes =

* `[tlders_search]`: a search box with a results table
* `[tlders_table tld="io"]`: the registrar comparison for one extension
* `[tlders_cheapest tlds="com,net,io"]`: the cheapest registrar for each extension
* `[tlders_price tld="com"]`: the cheapest price as an inline link

= Free and paid API keys =

The plugin needs a TLDers API key ([get one at tlders.com/developers](https://www.tlders.com/developers)). A **free key** covers a few extensions you choose (about 3, within its 100 requests a month). A **paid key** covers every extension and refreshes all prices in one request.

= Registrars without your affiliate link =

By default, registrars you haven't added a link for are still shown and link through tlders.com/go, where TLDers may earn a commission. To show only registrars you have a link for, choose "Hide them" in the settings.

= Mobile app =

You can optionally serve the TLDers white-label Android/iOS app from your site. The app only receives prices and your buy links; your API key stays on your server.

= Open source =

Development happens on [GitHub](https://github.com/TLDers/tlders-domain-prices-wordpress). Bug reports and pull requests are welcome.

== External services ==

This plugin needs the TLDers API (https://www.tlders.com) for domain prices. Without it, the plugin shows nothing.

* **What is sent, and when:** your API key and the extension being looked up (e.g. "com"), when a page with a shortcode needs prices that aren't cached, during the twice-daily background refresh, and when the settings screen loads the registrar list. Your site's URL is included in the User-Agent header. Nothing about your visitors is sent.
* **Buy links:** when a visitor clicks a Buy button for a registrar you haven't set a link for (and you chose to show such registrars), their browser goes to tlders.com/go/..., which records the click (with a hashed IP address) and redirects them to the registrar.

TLDers [Terms of Service](https://www.tlders.com/terms) · [Privacy Policy](https://www.tlders.com/privacy)

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → TLDers Domain Prices** and paste your TLDers API key.
3. Under **Your affiliate links**, paste the affiliate link for each registrar you're signed up with. The greyed-out example in each box is that registrar's search page; most programs give you a link you can point at it.
4. Add the **Domain Prices** block to a page, add the **TLDers Domain Prices** widget to a sidebar, or use a shortcode such as `[tlders_search]`.

== Frequently Asked Questions ==

= How do I build my affiliate link? =

Take the link your affiliate program gives you and put `{domain}` where the domain goes. Example for a program that takes a destination URL:
`https://partner.example.net/c/123?u=https%3A%2F%2Fporkbun.com%2Fcheckout%2Fsearch%3Fq%3D{domain}`

If your program only gives a fixed link, paste it as-is: visitors land on the registrar's home page instead of their search.

= Why does the search say an extension isn't available? =

On a free API key only the extensions in "TLDs on a free key" can be looked up, so random searches can't use up your monthly quota. A paid key covers every extension.

= Do I need a disclosure? =

Most affiliate programs, and advertising laws in many countries, require you to say that you earn from links. The plugin shows an editable disclosure under price tables by default.

= How do I put a search box in the sidebar? =

Create a page with a Domain Prices block set to "Domain search box" (or the `[tlders_search]` shortcode). Then add the widget or block to your sidebar, choose "Domain search box", and pick that page as the results page. Visitors search from the sidebar and see results at full width.

= Is the plugin free? =

Yes. The plugin is free and GPL-licensed. It uses the TLDers API, which has a free plan (a few extensions) and paid plans (every extension).

= Will it slow down my site? =

No. Prices are stored on your server and refreshed in the background twice a day. Pages read the stored copy, and the CSS file loads only on pages that show prices.

= Which registrars are included? =

Over 145, including Namecheap, Porkbun, Spaceship, Dynadot, Name.com, NameSilo, Gandi, OVHcloud and BigRock. The settings screen lists them all, each with a box for your affiliate link.

= Can I change the colours? =

Pick one of the six skins in Settings. To fine-tune, override the `--tlders-accent` CSS variable (and the others in assets/tlders.css) in your theme.

== Screenshots ==

1. A domain search in the Aurora skin: best price, average and savings, then every registrar with price bars, renewal warnings and Buy buttons that use your affiliate links.
2. Extension cards with the cheapest registrar and savings, plus a search box and compact price list in a narrow side column.
3. On phones the cards stay readable and the Buy button stays in view (Sunset skin).
4. Settings: pick one of six skins, set your currency, and paste your affiliate link for each registrar you've joined.
5. Mix skins on one page: an Aurora search and extension cards above a Midnight price table.

== Changelog ==

= 1.0.0 =
* First release: Domain Prices block, sidebar widget, four shortcodes, six skins, live search, instant sorting, list/grid views, filtering, per-registrar affiliate links, currency conversion, savings and renewal insights, affiliate disclosure, background refresh and an optional mobile app API.

== Upgrade Notice ==

= 1.0.0 =
First release.
