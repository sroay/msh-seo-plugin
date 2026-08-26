=== MSH SEO - AI-Powered SEO Tools ===
Contributors: marketingsohigh
Tags: seo, ai seo, schema markup, sitemap, content optimization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free SEO tools for WordPress with optional AI-powered features via Marketing So High.

== Description ==

MSH SEO provides a complete on-page SEO toolkit for WordPress, with both free local analysis and optional AI-powered features through the Marketing So High platform.

= Free Features (no account required) =

**SEO Analysis & Scoring**
* Real-time SEO score with 14 on-page checks
* Focus keyword tracking and optimization
* Keyword density, readability, and heading hierarchy analysis
* Internal/external link counting and image alt text checking

**Meta Tags & SERP Preview**
* Meta title and description editor with character counters
* Live SERP preview showing how your page appears in Google
* Open Graph and Twitter Card meta tags for social sharing
* Social preview panel (Facebook/LinkedIn + Twitter/X mockups)

**Schema Markup**
* Automatic JSON-LD structured data (Article, FAQ, HowTo)
* Per-post schema type selector with 14 options
* WooCommerce Product schema with GTIN, MPN, and Brand fields
* Variable product support with hasVariant schema

**Technical SEO**
* XML sitemap with automatic search engine pinging
* 301/302 redirect manager with 404 logging
* Noindex controls for archives, tags, and author pages
* Breadcrumb navigation with JSON-LD (shortcode: [msh_breadcrumbs])
* Image SEO: auto-rename uploads, auto-set alt text from context

**Instant Indexing**
* IndexNow integration (Bing, Yandex, Naver, Seznam)
* Automatic URL submission on post publish/update
* Submission log with status tracking

**Content Freshness**
* Weekly content freshness scanner
* Freshness scores based on age, content quality, and statistics
* Admin bar notice for stale content

**AI Crawler Management**
* llms.txt and llms-full.txt for AI engine discoverability
* Robots.txt enhancements for AI crawlers (GPTBot, ClaudeBot, etc.)

**Answer Engine Optimization (AEO)**
* 11-point citability score for AI search engines
* FAQ, definition, statistics, and E-E-A-T signal checks

**SEO Import**
* Non-destructive import from Yoast SEO, Rank Math, and AIOSEO
* Preview before importing, never overwrites existing MSH data

**Analytics Dashboard**
* SEO health overview with score distribution chart
* Missing meta description and keyword tracking
* IndexNow submission history
* Quick action links to optimize worst posts

**WooCommerce SEO**
* Product-specific SEO scoring (14 checks, 100 points)
* Custom product fields: GTIN, MPN, Brand
* Product schema with offers, ratings, and reviews
* Variable product support with per-variation schema

= AI-Powered Features (requires free MSH account) =

* AI content analysis with specific fix suggestions
* AI-generated meta titles and descriptions (multiple options)
* Keyword research data (volume, difficulty, CPC, intent)
* SERP competitor analysis
* One-click content distribution to 22 platforms
* Full article generation with SEO optimization
* Site intelligence scanning with AI strategy recommendations

= External Service =

This plugin optionally connects to the Marketing So High platform (https://marketingsohigh.com) to provide AI-powered SEO features. This connection is entirely optional and the plugin provides full local SEO analysis without it.

When connected, the following data is sent to the MSH API:

* Your site URL (for verification)
* Post title, content, and focus keyword (for AI analysis)

Data is processed according to the Marketing So High [Privacy Policy](https://marketingsohigh.com/privacy).
[Terms of Service](https://marketingsohigh.com/terms).

No data is sent unless you explicitly connect your MSH account and trigger an AI feature.

== Installation ==

1. Upload the `msh-seo` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Start editing any post to see the SEO sidebar in Gutenberg
4. (Optional) Go to MSH SEO settings and connect your Marketing So High account for AI features

== Frequently Asked Questions ==

= Do I need a Marketing So High account? =

No. Every local SEO feature works without one, and the plugin contacts nothing until you connect. An account is only needed for the two AI features.

= Is there a free plan? =

Yes. The free plan includes 50 AI operations a month. Once those are used the AI features pause until the next month; nothing else stops working.

= Does this conflict with other SEO plugins? =

No. If Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework or Slim SEO is active, MSH SEO detects it and automatically steps back from meta tags, schema and sitemaps so you never get duplicates. You will see a notice telling you this has happened.

Everything that does not overlap keeps running: redirects, the 404 log and its automatic repair, the health checks and the AI tools. That means you can run MSH SEO alongside your existing SEO plugin purely for the broken-link repair, which is the thing no other free plugin does.

If you would rather switch over completely, use MSH SEO > Import SEO to bring your titles and descriptions across from Yoast or Rank Math, then deactivate the old plugin. MSH SEO takes over the head output automatically once it is gone.

= Which features are free, and which need an account? =

Free forever, no account, nothing sent anywhere:

* Meta titles and descriptions, Open Graph and Twitter cards
* Schema markup and breadcrumbs
* XML sitemaps
* Redirects, the 404 log, and automatic repair of broken links
* Health checks under Tools > Site Health

Needs a free Marketing So High account (50 AI operations a month):

* AI-generated meta titles and descriptions
* AI page analysis

Nothing is sent to Marketing So High until you enter an API key. See the External services section below for exactly what is sent and when.

= How do I check whether the plugin is working? =

Go to Tools > Site Health. MSH SEO adds checks there for its connection, its redirect engine and any plugin conflicts. The Info tab has an MSH SEO section with everything a support request would need — please paste it if you report a problem.

= The plugin's scheduled tasks do not seem to run =

WordPress only runs scheduled tasks when someone visits the site, so a site with very little traffic can go a long time without them firing. This is a WordPress behaviour rather than a plugin fault. Your host may offer a real system cron, which is more reliable.

= How do I remove it completely? =

Deactivate and delete the plugin in the usual way. To also remove its data, drop the tables ending in msh_redirects and msh_404_log, and delete the options beginning msh_.

= What is IndexNow? =

IndexNow is a protocol supported by Bing, Yandex, Naver, and Seznam that lets you instantly notify search engines when content is published or updated. MSH SEO automatically submits URLs when you publish a post.

= Does it work with WooCommerce? =

Yes. MSH SEO adds Product schema markup, GTIN/MPN/Brand fields, and product-specific SEO scoring when WooCommerce is active.

= What is AEO? =

Answer Engine Optimization helps your content get cited by AI search engines like Google AI Overview, ChatGPT, and Perplexity. MSH SEO scores your content across 11 citability checks.

== Screenshots ==

1. Gutenberg sidebar with SEO score, checks, and social preview
2. Meta title and description editor with live SERP preview
3. Schema type selector with 14 options
4. SEO Analytics dashboard with score distribution
5. Redirect manager with 404 logging
6. WooCommerce product SEO fields
7. WordPress dashboard SEO overview widget

== External services ==

This plugin connects to three external services. Nothing is sent anywhere until you explicitly
connect the plugin by entering an API key, with the single exception of the search engine sitemap
ping described below.

**1. Marketing So High (app.marketingsohigh.com)**

The plugin's AI features are provided by Marketing So High. Data is only ever sent once you have
entered an API key under MSH SEO > Settings > Connection. Without a key the plugin runs its local
SEO features and contacts nothing.

What is sent, and when:

* Daily health report: your site URL, the plugin/WordPress/PHP versions, whether each feature is
  working, the number of redirect rules you have, and the URLs in your 404 log with their hit
  counts. Also the URLs and titles of your published posts and pages, so the service can work out
  where a broken link should point.
* Weekly content report: for each published post, its title, URL, slug, publish and modified dates,
  word count and a count of its headings, links and images.
* When you use an AI feature: the content of the post you are working on, so it can be analysed or
  a meta title and description generated for it.
* Conversion events, if you enable the call-to-action feature: which CTA was shown and clicked, and
  on which page.

No visitor personal data, comment content, user accounts, email addresses or passwords are ever
sent.

Service: https://marketingsohigh.com
Terms of Service: https://marketingsohigh.com/terms
Privacy Policy: https://marketingsohigh.com/privacy

**2. Google sitemap ping (www.google.com)**

When you publish or update a post, the plugin notifies Google that your sitemap has changed, by
requesting https://www.google.com/ping?sitemap=YOUR_SITEMAP_URL. The only data sent is your own
sitemap URL. This happens whether or not you have connected an API key, and can be turned off under
MSH SEO > Settings.

Terms of Service: https://policies.google.com/terms
Privacy Policy: https://policies.google.com/privacy

**3. Google Indexing API (oauth2.googleapis.com, indexing.googleapis.com)**

Optional, and off unless you supply your own Google service account credentials. When enabled, the
plugin asks Google to index or re-index a specific URL on your site. Only the URL is sent, together
with your own credentials to authenticate the request.

Terms of Service: https://policies.google.com/terms
Privacy Policy: https://policies.google.com/privacy

== Changelog ==

= 1.1.2 =
* Fixed: the broken-link report was counting the plugin's own daily check as visitor traffic. Each night it re-tests your dead URLs to see whether any have come back, and every one of those tests was being recorded as somebody hitting the page. Because the report is ordered by hit count, and the pages it re-tests are taken from the top of that order, a handful of URLs climbed the list on their own and stayed there. Those counts now reflect real visitors only.

= 1.1.1 =
* Improved: the broken-link report now says where each broken link was clicked from and whether it was a person or a bot. That is the difference between "someone linked to this from your own menu" and "a scanner is probing for a page you never had" — the first is worth fixing, the second is worth ignoring, and until now they looked identical.
* Privacy: referrers are trimmed to the page address only, with any query string dropped before it leaves your server, and browsers are recorded by family (Chrome, Safari) rather than the full identifying string.

= 1.1.0 =
* New: MSH SEO now detects Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework and Slim SEO, and automatically stops writing meta tags, schema and sitemaps when one of them is active. No more duplicate tags. On WooCommerce stores it also stops emitting its own Product schema, since two competing product schemas can cost a shop its price and rating snippets in search. Redirects, broken-link repair, health checks and the AI tools keep working alongside them.
* New: checks in Tools > Site Health for the connection, the redirect engine and plugin conflicts, plus an MSH SEO section in the Info tab with everything needed to diagnose a problem.
* New: the plugin now reports its own health to Marketing So High once a day, and checks what it reports rather than assuming it. It verifies the redirect table by asking the database, and fetches your sitemap and llms.txt to confirm they really answer.
* New: broken links repair themselves. Dead URLs that clearly point at one of your published posts get a permanent redirect automatically. Every rule it writes is tagged, so they can all be undone as a set, and a rule you wrote by hand is never touched or overwritten.
* New: checks that pages you have published can actually be opened by a visitor. A page can be live in WordPress and still return "not found" to the public after a theme, permalink or hosting change, and nothing else notices.
* Fixed: redirects now work on installs in a subfolder (example.com/blog). A rule written as /my-page could never match a request for /blog/my-page, so those rules never fired.
* Fixed: redirects are now applied before WordPress guesses a destination for an unknown URL. Your explicit rule previously lost to that guess, which also cost every visitor an extra hop.
* Fixed: the redirect and 404 tables now repair themselves whenever the plugin updates, not only when it is reactivated.

= 1.0.5 =
* New: 'Your Growth' section on the Analytics page — real Google Search traffic over time (impressions + clicks with 28-day trend), the keywords now ranking for you (with position), and how much content the engine has published. The clearest view yet of how MSH is growing your site.

= 1.0.4 =
* Fixed: the "Smart CTA" toggle always displayed as unchecked and wouldn't stay checked after saving. Its checkbox now reflects the true saved state (the CTA itself was active by default the whole time; only the toggle's display was wrong). Verified live.

= 1.0.3 =
* Fixed: on headless installs (public site on a different domain than WordPress) every editor AI panel — SEO score, keyword data, meta generation, Internal Link Mesh — failed with "Could not get a valid response from the server". The editor's REST calls were sent to the public front-end domain instead of the WordPress host; they now always target the WordPress host. Found and verified live on a headless production site.

= 1.0.2 =
* Fixed: saving any setting no longer silently erases a stored Google service-account key (leave the field blank to keep it; type CLEAR to remove it).
* Fixed: publishing is no longer slowed by indexing — IndexNow and Google pings now run out-of-band after publish instead of inside the save request.
* Fixed: the connection check never runs during visitor page loads (admin-only, with a stampede lock) — the Smart CTA uses its own cached config.
* Fixed: Internal Link Mesh no longer wraps a phrase that already sits inside another link (no more nested links).
* Fixed: Speakable schema now activates for the [msh_answer] shortcode and block (previously only raw markup).
* Fixed: the article "about"/"keywords" schema values no longer contain HTML entities (e.g. & rendered correctly for AI engines).

= 1.0.1 =
* Status chips now recognize central Google indexing: when the site is connected to MSH, Google pings run server-side on every publish — chips read "Google via MSH" instead of a misleading "off". The plugin's own service-account field is now clearly optional (self-hosted mode only).
* Fixed: the MSH connection now self-heals — the plugin silently re-verifies when its hourly check expires, so connected features (Smart CTA, Link Mesh, AI analysis) no longer lapse between visits to the settings page.
* Plugin updates trigger an immediate connection refresh, so new capabilities appear right after upgrading.

= 1.0.0 =
* New: Internal Link Mesh — the editor suggests ranked internal links from your whole site's topic-cluster graph (via the MSH brain) and inserts them with one click.
* New: Smart CTAs — an intent-personalized call-to-action (auto-appended or the [msh_cta] shortcode / block) that adapts its message to each visitor (search, returning, high-intent) and streams impression/click/conversion events back to MSH so your content engine learns what converts.
* New: Answer Engine (AEO) upgrades — Speakable schema, an "about" entity + keywords on articles, Organization sameAs profiles, and a quotable "Quick answer" block ([msh_answer]) built for AI Overviews / ChatGPT citations.
* New: AI Visibility — see whether AI answers actually cite your site, and which competitors they cite instead.
* New: Google Indexing API support (service-account JSON) + bulk re-submit of all URLs, alongside the existing IndexNow instant indexing.
* Redesigned admin console: a modern, branded dashboard with feature status, AI-visibility, and indexing at a glance.
* World-class Analytics command center: SEO score trend chart (daily history), AI-visibility donut with the competitors AI cites instead, Smart-CTA conversion funnel (seen → clicked → converted with CTR/CVR and intent split), indexing pulse with one-click re-submit, a run-now freshness scan button, and real measured statuses replacing static labels.

= 0.9.3 =
* One-click Google Search Console onboarding support
* New: stores a Google site verification token and outputs the <meta name="google-site-verification"> tag in <head> (site-wide, independent of the meta module toggle)
* New REST endpoint msh-seo/v1/site-verification (application-password authenticated) so the MSH dashboard can push/clear the token
* The token also syncs automatically via the hourly MSH connection check

= 0.6.0 =
* Added Social Preview panel (Facebook/LinkedIn + Twitter/X mockups)
* Added Schema Type selector per-post (14 types)
* Added AI feature upsell modal for non-connected users
* Enhanced dashboard widget with content freshness and full SEO dashboard link
* Fixed IndexNow data display in analytics page
* Fixed CSS enqueue for builds without stylesheet
* Bumped version across all files

= 0.5.0 =
* Added WooCommerce SEO module (Product schema, GTIN, MPN, Brand)
* Added content freshness scanner with weekly cron
* Added AI Crawler management (llms.txt, robots.txt for AI bots)
* Added Answer Engine Optimization (AEO) scoring
* Added page type detection for marketing pages
* Added SEO Analytics admin page with score distribution charts
* Added Image SEO automation (auto-rename, auto-alt-text)
* Added breadcrumbs with shortcode and JSON-LD

= 0.4.0 =
* Added IndexNow instant indexing
* Added content distribution meta box
* Added SEO data import from Yoast, Rank Math, AIOSEO
* Added dashboard widget with marketing overview

= 0.3.0 =
* Added XML sitemap with search engine pinging
* Added 301/302 redirect manager with 404 logging
* Added noindex controls

= 0.2.0 =
* Added Gutenberg sidebar with React components
* Added focus keyword panel with data lookup
* Added connection status indicator

= 0.1.0 =
* Initial release
* Local SEO scoring engine with 14 checks
* Meta title and description editor
* SERP preview
* Open Graph and Twitter Card output
* JSON-LD schema markup (Article, FAQ, HowTo)

== Upgrade Notice ==

= 0.6.0 =
New Gutenberg sidebar panels: Social Preview, Schema Selector. Enhanced dashboard widget. Bug fixes.
