=== MSH SEO ===
Contributors: technobelievesolutions
Tags: seo, schema markup, sitemap, redirects, indexnow
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

On-page SEO, schema, sitemaps and redirects that work without an account, with optional AI features from Marketing So High.

== Description ==

MSH SEO is an on-page SEO toolkit for WordPress. Everything in the first list below runs inside your site and needs no account. The features in the second list use the Marketing So High service and only work after you connect the plugin with an API key.

= Works without an account =

**SEO analysis in the editor**
* SEO score from 14 on-page checks, calculated in your browser and on your server
* Focus keyword, keyword density, readability and heading checks
* Internal and external link counts, image alt text checks

**Meta tags and social previews**
* Meta title and description editor with a live search result preview
* Open Graph and Twitter Card tags
* Facebook, LinkedIn and X preview panel

**Schema markup**
* JSON-LD structured data (Article, FAQ, HowTo and more), with a per-post schema type selector
* WooCommerce Product schema with GTIN, MPN and brand fields, including variable products

**Technical SEO**
* XML sitemaps
* 301 and 302 redirect manager with a 404 log
* Noindex controls for date archives, tags and author pages
* Breadcrumbs with JSON-LD (shortcode: [msh_seo_breadcrumbs])
* Image SEO: descriptive file names and alt text on upload
* llms.txt and llms-full.txt, and robots.txt rules for AI crawlers
* Checks under Tools > Site Health for the redirect engine and plugin conflicts

**Content tools**
* Weekly content freshness scan with a badge in the Posts list
* Answer engine (AEO) score: 11 checks for how citable a post is
* Import titles and descriptions from Yoast SEO, Rank Math and All in One SEO, without touching their data
* SEO overview page and dashboard widget

**Instant indexing (sends data to IndexNow)**
* When a post is published or updated, its URL is submitted to IndexNow so Bing, Yandex, Seznam, Naver and other participating search engines can find it quickly. This is on by default and can be switched off under MSH SEO > Settings. See External services below.

**Google Analytics tag (optional)**
* Enter your GA4 measurement id under MSH SEO > Settings and the plugin adds Google's standard tag to your pages. Nothing loads until you do. See External services below.

= Needs a Marketing So High account =

These features send data to Marketing So High and only work once you enter an API key under MSH SEO > Settings. A free plan includes 50 AI operations a month.

* AI analysis of the post you are editing, with suggested fixes
* AI-generated meta titles and descriptions
* Keyword data for your focus keyword
* Internal link suggestions drawn from your own published posts
* Automatic repair of broken links: 404s are matched to the right page and redirects are created for you
* Smart call-to-action blocks, with conversion tracking
* Sending a post to the social channels connected in your MSH account
* Publishing articles written in the MSH dashboard straight to WordPress, and refreshing older posts from it (Autopilot)

= Source code and build tools =

The editor sidebar is written in React and compiled with @wordpress/scripts. The human-readable source is included in the plugin's `src` folder and published at https://github.com/sroay/msh-seo-plugin. To rebuild `build/index.js`, run `npm install` and then `npm run build` in the plugin folder.

== Installation ==

1. Upload the `msh-seo` folder to the `/wp-content/plugins/` directory, or install the plugin from the Plugins screen.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open any post in the block editor to see the MSH SEO sidebar.
4. Optional: go to MSH SEO > Settings and enter a Marketing So High API key to turn on the AI features.

== Frequently Asked Questions ==

= Do I need a Marketing So High account? =

No. Everything listed under "Works without an account" runs without one. An account is only needed for the features listed under "Needs a Marketing So High account".

= Does the plugin send any data without an account? =

Only in two cases, both described in External services below. IndexNow submissions send the URL of a post when it is published or updated; this is on by default and can be switched off under MSH SEO > Settings. The Google Analytics tag loads only if you enter a measurement id. Nothing is sent to Marketing So High until you enter an API key.

= Is there a free plan? =

Yes. The free Marketing So High plan includes 50 AI operations a month. When they are used up the AI features pause until the next month; nothing else stops working.

= Does this conflict with other SEO plugins? =

If Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework or Slim SEO is active, MSH SEO detects it and stops printing meta tags, schema and sitemaps, so pages never get duplicates. A notice tells you when this happens. Redirects, the 404 log, Site Health checks and the AI features keep working.

To switch over completely, use MSH SEO > Import SEO to copy your titles and descriptions from Yoast SEO or Rank Math, then deactivate the old plugin.

= How do I check whether the plugin is working? =

Go to Tools > Site Health. MSH SEO adds checks for its connection, its redirect engine and plugin conflicts. The Info tab has an MSH SEO section to paste into a support request.

= The plugin's scheduled tasks do not seem to run =

WordPress runs scheduled tasks when someone visits the site, so a site with very little traffic can go a long time without them. Your host may offer a real system cron, which is more reliable.

= How do I remove it completely? =

Deactivate and delete the plugin. To also remove its data, drop the database tables ending in msh_seo_redirects and msh_seo_404_log, and delete the options whose names begin with msh_seo_.

= What is IndexNow? =

IndexNow is an open protocol that lets a site tell search engines a page has been added or changed, instead of waiting for them to crawl it. It is supported by Bing, Yandex, Seznam, Naver and others.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active, MSH SEO adds Product schema, GTIN, MPN and brand fields, and product-specific SEO checks.

= What is AEO? =

Answer Engine Optimization is about making content easy for AI search tools such as Google AI Overviews, ChatGPT and Perplexity to cite. MSH SEO scores posts on 11 checks for this.

== Screenshots ==

1. Block editor sidebar with SEO score, checks and social preview
2. Meta title and description editor with a live search result preview
3. Schema type selector
4. SEO overview page
5. Redirect manager with the 404 log
6. Dashboard widget

== External services ==

MSH SEO connects to the services below. Each entry says what is sent, when, and whether you can turn it off. The plugin also makes requests to your own site, for example to confirm the IndexNow key file is reachable; those stay between your server and your site.

**1. IndexNow (api.indexnow.org)**

Used to tell search engines about new and updated posts. When a post is published or updated, the plugin sends your site's hostname, the post's URL, your sitemap URL, and a key the plugin generates for your site. The key is also served at yoursite.com/KEY.txt so the search engines can confirm the submission came from your site. IndexNow shares each submission with the participating search engines, including Bing, Yandex, Seznam and Naver. No account is needed. This is on by default and can be switched off under MSH SEO > Settings > IndexNow.

Terms of Service: https://www.indexnow.org/terms
Microsoft Privacy Statement: https://privacy.microsoft.com/privacystatement
Yandex Privacy Policy: https://yandex.com/legal/confidential/
Seznam Privacy Policy: https://o-seznam.cz/pravni-informace/ochrana-udaju/
Naver Privacy Policy: https://www.naver.com/policy/privacy

**2. Google Analytics (www.googletagmanager.com)**

Off until you enter a GA4 measurement id under MSH SEO > Settings > Google Analytics (a connected Marketing So High dashboard can fill it in for you). Once an id is set, every front-end page loads Google's gtag.js and your visitors' page views are reported to your own Google Analytics property. Because this sends visitor data to Google under your account, your privacy policy should mention it and any consent tool you use should cover it. Clear the field to stop the tag loading.

Terms of Service: https://marketingplatform.google.com/about/analytics/terms/us/
Privacy Policy: https://policies.google.com/privacy

**3. Google Indexing API (oauth2.googleapis.com, indexing.googleapis.com)**

Off unless you paste your own Google Cloud service-account key under MSH SEO > Settings > Google Indexing API. When a post is published or updated, the plugin sends the post's URL to Google, authenticated with your key.

Terms of Service: https://policies.google.com/terms
Privacy Policy: https://policies.google.com/privacy

**4. Marketing So High (app.marketingsohigh.com)**

Provides the features listed under "Needs a Marketing So High account". Nothing is sent until you enter an API key under MSH SEO > Settings. After that:

* Daily health report: your site URL; the plugin, WordPress and PHP versions; whether each feature is working; the number of redirect rules; the URLs in your 404 log with their hit counts, referrers and user agents; and the URLs and titles of your published posts and pages, so broken links can be matched to the right page.
* Weekly content reports: for each published post, its title, URL, slug, publish and modified dates, word count, and counts of headings, links and images; plus a summary of how many posts are fresh or stale, with the stale ones listed.
* When you use an AI feature in the editor: the post's title, content, focus keyword and URL; for internal link suggestions, also the titles and URLs of your recent published posts and pages.
* Smart call-to-action blocks: the plugin fetches their wording from Marketing So High, and forwards which block a visitor saw or clicked and on which page. No visitor identity is sent.
* Sending a post to your social channels: the post's title, excerpt, text, URL, featured image URL and the channels you picked.
* Newsletter form: shown under posts only if your Marketing So High account has a newsletter. A visitor who fills it in sends their email address and the site's hostname from their own browser to the newsletter's double opt-in endpoint, and is not subscribed until they confirm by email.
* When the dashboard publishes an article to your site, the plugin downloads the article's featured image from the address the dashboard supplies.

No user accounts, passwords or comment content are sent.

Terms of Service: https://marketingsohigh.com/terms
Privacy Policy: https://marketingsohigh.com/privacy

== Changelog ==

= 1.5.3 =
* Fixed: on a site installed in a subfolder (example.com/blog), /blog/sitemap.xml returned a 404. Sitemap addresses are now matched relative to where WordPress is installed.
* Fixed: the sitemap is also served at /wp-sitemap.xml, WordPress's own sitemap address, so links and search console submissions made before installing the plugin keep working.
* Fixed: WordPress's core sitemap was switched off even when another SEO plugin was active and MSH SEO was not serving a sitemap.

= 1.5.2 =
* Added: the daily health report now says whether search engines can read the site's IndexNow key file. On sites whose front end does not serve WordPress's files, submissions were being ignored without any warning.

= 1.5.1 =
* Fixed: a redirect MSH created automatically for a broken link could hide a post published later at the same address, sending every visitor elsewhere. Automatic redirects now apply only while the address is still a 404, and new ones are not created for addresses that already have a published post or page. Redirects you add yourself are unchanged.

= 1.5.0 =
* Changed: every option, post meta key, database table, transient, AJAX action, nonce and class now uses the msh_seo prefix, as WordPress.org requires. Existing data is moved to the new names automatically on the first page load after the update, including redirect rules, the 404 log and focus keywords.
* Changed: all CSS and JavaScript is loaded with wp_enqueue_style and wp_enqueue_script from files in the assets folder. Nothing is printed inline any more.
* Security: JSON-LD is encoded with JSON_HEX_TAG, so text containing a closing script tag cannot break out of the schema block.
* Security: SEO meta fields and the local analysis and link suggestion endpoints now check that the user may edit the specific post, not just posts in general.
* Fixed: sitemap requests no longer switch page caching off for every URL on the site.
* Removed: the Google and Bing sitemap pings. Both endpoints were retired by the search engines and did nothing; IndexNow covers the same need.
* New: settings to switch IndexNow off and to enter or remove a Google Analytics measurement id.
* New: the source for the editor sidebar ships in the src folder, with build instructions in this readme.

= 1.4.1 =
* Fixed: on a headless site, IndexNow was told about WordPress's permalink (example.com/my-post/) instead of the address the front end serves (example.com/blog/my-post), so search engines were handed a redirect. Before submitting, the plugin now follows redirects on the live site and sends the address that answers. A bulk re-submit learns the pattern from one post of each type rather than checking every URL. Anything uncertain keeps the permalink.
* Fixed: IndexNow answers 200 before it verifies the key, then drops the URLs if the key file cannot be fetched. The submission log recorded those as successes. The plugin now checks that the key file is reachable at the key location and marks the submission failed, with the reason, when it is not.

= 1.4.0 =
* New: an author box under every post with the founder's name, role, a one-line bio and profile links, delivered by the MSH dashboard. Readers and answer engines see who is speaking. Turn off with the msh_seo_author_box_enabled option.
* New: a newsletter signup form under every post that posts to the MSH newsletter's double-opt-in endpoint. Only appears on sites whose organisation owns a newsletter. Turn off with the msh_seo_newsletter_enabled option.
* New: Bing Webmaster Tools verification tag, delivered from the dashboard like the Google one.

= 1.3.0 =
* Improved: the schema now says who you are. Your brand's profile links (LinkedIn, X, GitHub and the like) are printed as sameAs on the Organization, on every article's publisher and, when MSH knows the founder, on the author with a job title and profile links. Answer engines use exactly these signals to decide who is speaking. Links arrive from the MSH dashboard and never overwrite a list you typed into the settings screen yourself.
* Fixed: an SEO title set by MSH was still getting " – Site Name" appended, which pushed every one past the length Google shows. A SEO title now stands on its own.

= 1.2.0 =
* New: one-click Google Analytics. When your site is connected to MSH and your Google account is linked there, MSH reads the GA4 property's measurement id and places the standard Google tag on the site for you — then checks the live page to confirm it is really there. Until now MSH could map a property to a site but nothing put the tag on the page, so a property could sit "connected" for months without recording a single visit. The id arrives by direct push, with the hourly connection check, or with the daily check-in, so a reinstall or an offline moment cannot lose it. The tag is not added to a site that already carries a Google tag of its own.

= 1.1.3 =
* Improved: the broken-link report now shows when each link was last followed, not just how many times it has ever been followed. A page that was hit heavily last month and not once since used to look exactly like one being hit today, which pushed problems you had already fixed to the top of the list.

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
* Fixed: Speakable schema now activates for the [msh_seo_answer] shortcode and block (previously only raw markup).
* Fixed: the article "about"/"keywords" schema values no longer contain HTML entities (e.g. & rendered correctly for AI engines).

= 1.0.1 =
* Status chips now recognize central Google indexing: when the site is connected to MSH, Google pings run server-side on every publish — chips read "Google via MSH" instead of a misleading "off". The plugin's own service-account field is now clearly optional (self-hosted mode only).
* Fixed: the MSH connection now self-heals — the plugin silently re-verifies when its hourly check expires, so connected features (Smart CTA, Link Mesh, AI analysis) no longer lapse between visits to the settings page.
* Plugin updates trigger an immediate connection refresh, so new capabilities appear right after upgrading.

= 1.0.0 =
* New: Internal Link Mesh — the editor suggests ranked internal links from your whole site's topic-cluster graph (via the MSH brain) and inserts them with one click.
* New: Smart CTAs — an intent-personalized call-to-action (auto-appended or the [msh_seo_cta] shortcode / block) that adapts its message to each visitor (search, returning, high-intent) and streams impression/click/conversion events back to MSH so your content engine learns what converts.
* New: Answer Engine (AEO) upgrades — Speakable schema, an "about" entity + keywords on articles, Organization sameAs profiles, and a quotable "Quick answer" block ([msh_seo_answer]) built for AI Overviews / ChatGPT citations.
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

= 1.5.0 =
Renames the plugin's stored data to the msh_seo prefix. The move happens automatically on the first page load after updating; no action is needed.

= 0.6.0 =
New Gutenberg sidebar panels: Social Preview, Schema Selector. Enhanced dashboard widget. Bug fixes.
