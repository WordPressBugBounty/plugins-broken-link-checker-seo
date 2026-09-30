=== Broken Link Checker by AIOSEO – Find & Fix Broken Internal, External & Video Links ===
Contributors: aioseo, smub, benjaminprojas
Tags: broken link checker, broken links, link checker, dead links, 404
Tested up to: 7.1
Requires at least: 5.7
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.txt

Broken link checker that finds and fixes broken links, broken images, and dead video links to protect your site's SEO.

== Description ==

Broken links hurt your SEO and send visitors to dead 404 pages. Broken Link Checker by AIOSEO scans your whole WordPress site for broken links and shows you exactly which ones to fix. It checks internal links, external links, broken images, and video links, then lets you fix or remove them in a few clicks without editing each post by hand.

This free plugin connects to your AIOSEO account and scans up to 250 internal links every month at no cost, with credits that renew each month. Upgrade to a paid subscription to scan more internal and external links, monitor more frequently, and cover larger sites. [See Broken Link Checker pricing and upgrade here.](https://aioseo.com/pricing-broken-link-checker/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin)

= 🔗 Find Broken Internal and External Links =

Broken Link Checker monitors every internal link and external link on your WordPress website and reports which links work and which are broken. It crawls your content on a schedule and records the status of each link: a working 200 response, a 301 or 302 redirect, or a broken 404. You get a clear list of broken links to fix, so dead links never sit on your pages unnoticed.

= 🎥 Check Broken Video Links on YouTube, Vimeo, and More =

Video links break in a way that a normal link check misses. A YouTube or Vimeo page can still load and return a working status long after the video was deleted, set to private, or removed by the uploader. Broken Link Checker auto-detects video links and verifies that the video behind each link still exists. It checks the video itself across YouTube, Vimeo, and more than ten other platforms, then flags the ones that are gone so you can swap in a working video. It covers both the videos you link to and the ones you embed, including the players a page builder or a pasted embed code writes as an iframe, so a video that was taken down does not leave a dead player sitting on your page. This keeps tutorials, reviews, and any post built around an embedded or linked video from quietly going stale.

= 🖼️ Detect Broken Images =

Broken Link Checker also finds broken images. If an image file was moved, renamed, or deleted, the plugin reports the broken image URL next to your broken links so you can replace it before visitors land on a missing graphic. It covers the images in your content, the ones a block keeps in its own settings, and the ones a block or a page builder section uses as a background. It also reports audio files that no longer load.

= 🔒 Catch the Links That Look Fine But Are Not =

A link check that only reads the status code misses the links that answer perfectly and still fail your visitors. Broken Link Checker reports a destination whose SSL certificate has expired or does not match the site, because a browser stops your visitor with a full-page warning no matter what the server answered.

It also tells you why every other broken link failed, instead of only that it did: blocked, timed out, unreachable, an address that cannot exist, or a page that is there and asks to be signed in to. That last one matters, because a link behind a login is not broken at all - and a report that calls it broken sends you looking for a fault in an address that is fine. The reason appears in the report, on the frontend highlighter, and in the CSV export.

= 🧭 Find Links Everywhere, Not Just in Post Content =

Plenty of links never appear in the body of a post, and a checker that only reads post content misses every one of them. Broken Link Checker looks in all the other places a link hides on a WordPress site:

* **Custom fields** - including Advanced Custom Fields (ACF) link, URL, and text fields.
* **Navigation menus** - custom links in your menus, which often point at pages that were later renamed or deleted.
* **Category and tag descriptions** - links in your term descriptions.
* **Author bios and websites** - links on user profiles.
* **Post excerpts** - links in the excerpt as well as the content.
* **Synced patterns, templates, and template parts** - block content the editor stores outside your posts, including navigation blocks.
* **Embeds** - links behind embed blocks that are not videos.

Every broken link is reported with the exact place it was found, so you know whether to open the post editor, the menu editor, or a user profile to fix it. Each source can be switched on or off in the settings.

= 🧱 Supported Page Builders =

If your pages are built with a page builder, your links live in builder data rather than plain post content. Broken Link Checker reads those layouts directly, so a builder page is checked like any other content. The report tells you which link is broken and which page holds it, then sends you to that builder to change it - rewriting a layout in place is the builder's job, not ours:

* **Elementor**
* **Divi** - both Divi 4 and Divi 5 layouts.
* **WPBakery Page Builder**
* **SiteOrigin Page Builder**
* **Avada Builder** (Fusion Builder)
* **SeedProd**
* **The WordPress block editor** - including reusable and synced patterns.

= ☁️ Cloud-Based Scanning That Does Not Slow Down Your Site =

Broken Link Checker by AIOSEO runs as a cloud service connected to your AIOSEO account, so the link scanning happens on our servers instead of yours. Other broken link checker plugins crawl from your own server, which can spike resource usage, get your hosting account flagged, or cause your server IP to be blocked. With cloud-based link scanning, your site speed stays the same and your hosting provider has no reason to throttle you, even on large sites with thousands of links.

= 🎯 Granular Control Over What Gets Scanned =

You decide what Broken Link Checker monitors. Choose which post types to scan, such as pages, posts, and custom post types, and which post statuses to include, such as published, draft, or pending review. This keeps the scan focused on the content that matters and your link credits spent where they count.

= 🚫 Exclude URLs You Do Not Want Checked =

Some links should not be checked at all. Affiliate links, tracking redirects, and third-party URLs that block automated requests can show up as false broken links. Broken Link Checker lets you exclude URLs by exact address, by wildcard, by host, or by regular expression - all in a single exclusion list - so your broken link report stays accurate. Remove an exclusion later and the links it was hiding come back.

= 📊 See What to Fix First =

A long list of broken links does not tell you where to start. The dashboard ranks the fixes that clear the most broken links for the least work: one dead address used in dozens of places, a domain that has gone offline, a page carrying several at once. It also shows why your links fail and how long each has been broken, and every figure opens the report already narrowed to it.

= 🛠️ Fix Broken Links Without Leaving WordPress =

Finding broken links is only half the job. Broken Link Checker lets you fix them straight from the dashboard. Edit a link inline to point it at the correct URL, unlink it, or remove it, and the change is written back to your post automatically. You can clear out many broken links in a single sitting instead of opening each post one at a time.

Broken links are also marked in red on the frontend with the Broken Links Highlighter, so they are easy to spot, and the plugin can stop search engines from following broken links while you work through them. If you use the AIOSEO Redirects feature, you can send a broken link straight to a working URL with a redirect. When a link redirects before it breaks, the report offers the end of the redirect chain as the replacement, so you can point the link at where it actually ends up in one click.

= 📬 Broken Link Reports by Email =

You should not have to remember to check a dashboard. Broken Link Checker emails you when something changes:

* A **weekly email** listing only the links that broke since the last report, so a quiet week means a quiet inbox.
* A **monthly scorecard** with your totals - links checked, broken, redirecting, and newly broken - plus what broke that month.

Add as many recipients as you like in the settings, and every email has a one-click unsubscribe link.

= 📤 Export Your Broken Links =

Export the report to CSV with one click. The file matches whatever filters and search you have on screen, so you can hand a client a list of exactly the broken external links on their site, or work through a long list in a spreadsheet. Every row carries where the link was found and why the check failed.

= 🏢 Who Uses Broken Link Checker =

Broken Link Checker works for any WordPress site that links out or links internally:

* **Bloggers and publishers** - keep years of posts free of dead links and broken video embeds.
* **eCommerce stores** - catch broken product links, broken images, and supplier URLs.
* **Affiliate marketers** - find broken affiliate links before they cost you commissions.
* **Agencies and freelancers** - monitor client sites and fix broken links from one place.
* **Local businesses** - make sure menu, booking, and map links keep working.

= 🛡️ Built by the Team at AIOSEO =

Broken Link Checker is built by AIOSEO, the team behind the All in One SEO plugin used on more than 3 million WordPress websites. The same focus on practical, results-driven SEO goes into keeping your links healthy and your visitors out of 404 pages.

= 🔎 A Better Way to Check Broken Links Than Ahrefs, Screaming Frog & Other Tools =

If you have looked for a way to find broken links, you have probably tried tools like Ahrefs, Screaming Frog, Dr. Link Check, or Dead Link Checker. Those tools can crawl a site, but they run outside WordPress, so you copy URLs back and forth and fix every broken link by hand in the editor. Broken Link Checker by AIOSEO lives inside your WordPress dashboard. It finds broken links, broken images, and dead video links, and it fixes them in place. Because the scanning runs in the cloud, it does this without loading your server the way an on-site crawler plugin does.

= Credits =

This plugin is created by <a href="https://benjaminrojas.net/" rel="friend" title="Benjamin Rojas">Benjamin Rojas</a> and <a href="https://syedbalkhi.com/" rel="friend" title="Syed Balkhi">Syed Balkhi</a>.

= Branding Guideline =

AIOSEO&reg; is a registered trademark of Semper Plugins LLC. When writing about the WordPress SEO plugin by AIOSEO, please use the following format.

* AIOSEO (correct)
* All in One SEO (correct)
* AIO SEO (incorrect)
* All in 1 SEO (incorrect)
* AISEO (incorrect)

= What's Next =

If you like our Broken Link Checker plugin, then consider checking out our other projects:

* <a href="https://aioseo.com/" rel="friend" title="AIOSEO">AIOSEO</a> - The Best WordPress SEO plugin & toolkit to improve your SEO rankings in search results.
* <a href="https://optinmonster.com/" rel="friend" title="OptinMonster">OptinMonster</a> - Get more email subscribers with the most popular conversion optimization plugin for WordPress.
* <a href="https://wpforms.com/" rel="friend" title="WPForms">WPForms</a> - #1 drag & drop online form builder for WordPress (trusted by 5 million sites).
* <a href="https://www.monsterinsights.com/" rel="friend" title="MonsterInsights">MonsterInsights</a> - See the stats that matter and grow your business with confidence. Best Google Analytics plugin for WordPress.
* <a href="https://www.seedprod.com/" rel="friend" title="SeedProd">SeedProd</a> - Create beautiful landing pages with our powerful drag & drop landing page builder.
* <a href="https://wpmailsmtp.com">WP Mail SMTP</a> - Improve email deliverability for your contact form with the most popular SMTP plugin for WordPress.
* <a href="https://rafflepress.com/">RafflePress</a> - Best WordPress giveaway and contest plugin to grow traffic and social followers.
* <a href="https://www.smashballoon.com">Smash Balloon</a> - #1 social feeds plugin for WordPress - display social media content in WordPress without code.
* <a href="https://wpcode.com/">WPCode</a> - Must have WordPress code snippet management plugin to help you future-proof website customization (trusted by 1.5 million sites).
* <a href="https://duplicator.com/">Duplicator</a> - Popular WordPress backup and migration plugin used by over 1 million websites.
* <a href="https://www.pushengage.com/">Push Engage</a> - Connect with visitors after they leave your website with the leading web push notification plugin.
* <a href="https://trustpulse.com/">TrustPulse</a> - Add real-time social proof notifications to boost your store conversions by up to 15%.
* <a href="https://searchwp.com/">SearchWP</a> – The most advanced custom WordPress search plugin to improve WordPress search quality.
* <a href="https://affiliatewp.com/">AffiliateWP</a> – #1 affiliate management plugin for WordPress. Add a referral program to your online store.
* <a href="https://wpsimplepay.com/">WP Simple Pay</a> – #1 Stripe payments plugin for WordPress. Start accepting one-time or recurring payments without a shopping cart.
* <a href="https://easydigitaldownloads.com/">Easy Digital Downloads</a> – The best WordPress eCommerce plugin to sell digital products (eBooks, software, music, and more).
* <a href="https://www.wpcharitable.com/">WPCharitable</a> - Top-rated WordPress donation and fundraising plugin for WordPress.
* <a href="https://sugarcalendar.com/">Sugar Calendar</a> – A simple event calendar plugin for WordPress that's both easy and powerful.

Visit <a href="http://www.wpbeginner.com/" rel="friend" title="WPBeginner">WPBeginner</a> to learn from our <a href="http://www.wpbeginner.com/category/wp-tutorials/" rel="friend" title="WordPress Tutorials">WordPress Tutorials</a> and find out about the <a href="http://www.wpbeginner.com/category/plugins/" rel="friend" title="Best WordPress Plugins">best WordPress plugins</a>.

== Installation ==

1. Install Broken Link Checker by AIOSEO either through the WordPress plugin directory or by uploading the plugin files to your server.
2. Activate the plugin through the Plugins screen in WordPress.
3. Connect the plugin to a free or paid AIOSEO account when prompted. This is required because the link scanning runs as a cloud service.
4. Choose which post types and post statuses you want to scan in the settings.
5. Broken Link Checker starts scanning and reports any broken links, broken images, and dead video links in your dashboard.

== Frequently Asked Questions ==

= Does Broken Link Checker slow down my website? =

No. The link scanning runs on AIOSEO's servers rather than your own, so checking your links does not use your site's resources or affect page speed.

= Do I need an account to use Broken Link Checker? =

Yes. Because Broken Link Checker runs as a cloud service, it requires an AIOSEO account. The free account scans up to 250 internal links per month, and those credits renew every month.

= Can it check external links and video links? =

Yes. Broken Link Checker checks internal links, external links, and broken images. It also checks video links and verifies whether the video still exists on YouTube, Vimeo, and more than ten other platforms.

= How does it check broken video links? =

A video page can return a working status even after the video was deleted or made private. Broken Link Checker detects video links and checks the video itself, so it can flag videos that are gone even when the URL still loads. It does the same for embedded players, including the iframe a page builder or a pasted embed code leaves behind.

= Can I fix broken links without editing each post? =

Yes. You can edit, unlink, or remove a broken link directly from the Broken Link Checker dashboard, and the change is saved back to your content for you.

= How often does it scan for broken links? =

Broken Link Checker runs automatic scans on a schedule, and you can choose the recheck frequency in the settings. Paid subscriptions can monitor more frequently and cover more links.

= Does it work with my page builder? =

Yes. Broken Link Checker reads layouts built with Elementor, Divi (4 and 5), WPBakery Page Builder, SiteOrigin Page Builder, Avada Builder, and SeedProd, as well as the WordPress block editor. Links inside those layouts are found and reported, including the pictures a block or a section background points at. Fixing one takes you into the builder that owns the page, since the report cannot safely rewrite a layout.

= Does it only check links in my post content? =

No. It also checks custom fields (including ACF), navigation menus, category and tag descriptions, author bios and websites, post excerpts, and block content stored outside your posts such as synced patterns, templates, and navigation blocks. Each of these can be switched on or off in the settings.

= Can it email me when a link breaks? =

Yes. You can enable a weekly email that lists the links that broke since the last report, and a monthly summary of your site's overall link health. You choose who receives them in the settings.

= A link shows as broken but it opens fine for me. Why? =

The report tells you why each check failed, and some of those reasons will not stop you personally. A destination can block automated requests, answer too slowly, or ask to be signed in to, so the page opens for you while a checker is turned away. A certificate error is the opposite case: the server answers normally, but a browser stops your visitors with a warning screen. If a link really is fine and you would rather it was not checked, add it to the exclusion list.

= Where should I start if I have a lot of broken links? =

The dashboard ranks your broken links by how much a single fix clears, so you can start with the dead address that appears on dozens of pages rather than working through the list in order. Every figure on it opens the report already narrowed to what you clicked.

= What does Broken Link Checker check, in full? =

* [Broken Link Checker](https://aioseo.com/features/broken-link-checker/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin) - scan your whole WordPress site for broken links and fix them from one dashboard.
* Internal link checker - monitor every internal link between your posts and pages.
* External link checker - check outbound links to other websites for 404 errors.
* Broken video link checker - verify videos on YouTube, Vimeo, and 10+ platforms still exist.
* Broken image detector - find images that no longer load, including block and section background images.
* Broken audio detector - find audio files that no longer load.
* Broken embed detection - check the video players a page builder or a pasted embed code writes as an iframe.
* SSL certificate checks - catch a destination whose certificate a browser refuses, even when the server answers normally.
* Failure reasons - blocked, timed out, unreachable, invalid address, login required, or certificate error, shown in the report, the highlighter, and the export.
* Redirect detection - see which links return a 301 or 302 redirect.
* Cloud-based scanning - link checks run on AIOSEO servers, not your server.
* Scheduled automatic scans - links are rechecked on a schedule you set.
* Inline link editing - fix or update a broken link without opening the post editor.
* Unlink and remove - clear out dead links in one click.
* Redirect chain resolution - replace a link with the URL it actually ends up at.
* Broken Links Highlighter - broken links are marked in red on the frontend, with a panel saying why each one failed, and a toolbar switch to turn the marks off.
* AIOSEO Redirects integration - redirect a broken link to a working URL.
* Custom field scanning - find links in custom fields, including ACF.
* Navigation menu scanning - check the custom links in your menus.
* Term description and author bio scanning - check links outside your posts.
* Page builder support - Elementor, Divi 4 and 5, WPBakery, SiteOrigin, Avada, and SeedProd.
* Synced pattern and template scanning - check block content stored outside your posts.
* Found-in reporting - see every place a link appears, listed per location.
* Email reports - weekly change summaries and a monthly link health scorecard.
* CSV export - export your broken links with where each was found and why it failed, matching the filters on screen.
* Granular post type control - choose which post types and statuses to scan.
* URL and domain exclusions - skip affiliate links, trackers, and URLs you do not want checked, by wildcard, host, or regular expression.
* Link status filters - filter links by good, broken, redirect, or not yet checked.
* Media type filters - narrow the report to images, videos, or non-media links.
* Broken link count column - see broken link counts in the AIOSEO Details post column, linked to the rows behind it.
* 404 log integration - see how many of your posts link to each 404'd URL in AIOSEO Redirects.
* Site Health checks - WordPress tells you when link monitoring has stopped, with a toolbar warning when your account is not connected.
* Link health dashboard - see what to fix first, ranked by how much one fix clears.
* WordPress dashboard widget - the most useful fix, without leaving your dashboard.
* Multisite support - license the whole network once, then activate or deactivate individual sites from a single Site Activations screen.
* Free monthly link credits - scan up to 250 internal links every month for free.

== Screenshots ==

1. The broken links report: every dead link on your site, why each one failed in plain English, and where it was found.
2. The dashboard ranks your broken links by how much a single fix clears, so you start with the address that appears on the most pages.
3. Replace a dead URL from the report. The link is rewritten wherever it appears, with no need to open the editor.
4. Links are found beyond post content: in navigation menus, custom fields, term descriptions, user bios and synced patterns.
5. One dead address, every place it appears. Fix it once, or act on a single occurrence.
6. Choose which parts of your site are scanned, how often links are rechecked, and whether you get an email when something breaks.

== Changelog ==

**New in Version 1.3.1**

* New: Links are now found outside post content - in custom fields (including ACF), navigation menus, category and tag descriptions, author bios and websites, post excerpts, and the block content the editor stores outside your posts such as synced patterns, templates, template parts and navigation blocks.
* New: Page builder support - links inside Elementor, Divi (4 and 5), WPBakery Page Builder, SiteOrigin Page Builder, Avada Builder and SeedProd layouts are now found and reported, including the pictures a block or a section background points at.
* New: Broken link reports by email - a weekly email listing the links that broke since the last report, and a monthly scorecard of your site's link health, sent to the recipients you choose.
* New: The report is now organised around where each link was found, with every location for a link listed and paginated inline, and a filter to narrow the report to one source - offering only the sources that actually hold links.
* New: Filter the report by internal or external links, and by images, videos or non-media links.
* New: Export the report to CSV, matching the filters and search you have on screen.
* New: Exclude URLs by wildcard, by host, or by regular expression, in a single exclusion list.
* New: Added a scan frequency setting to control how often links are rechecked.
* New: Added per-source scanning settings, so you choose which places are monitored - navigation menus, custom fields, term descriptions, author bios and websites, synced patterns and templates.
* New: When a broken link redirects, the report offers the end of the redirect chain as the replacement.
* New: Audio files, and the images that core blocks keep in a block attribute, are now reported.
* New: Added Site Health checks that report when link monitoring has stopped, and an admin bar warning when the account is not connected.
* New: A dashboard - it opens on what to do next rather than on a list of everything that is wrong. Its main panel ranks the fewest fixes that clear the most broken links: one dead address used in many places, a whole host that has gone, a page carrying several at once, broken images and videos. Alongside it, why your links are failing, the domains costing you the most, how long each has been broken, and where on your site they live. Every figure opens the report already narrowed to it.
* New: The Broken Links Highlighter can be switched on and off from the toolbar while you are looking at a page, and each person can turn it off for themselves without changing it for anyone else.
* New: Multisite support - the network admin has its own Site Activations screen, where you connect the network's license once and then activate or deactivate the individual sites on the network from a single list.
* New: Registered the plugin's abilities with the WordPress Abilities API.
* New: A destination whose SSL certificate has expired, or does not match, is now reported as broken. These answer a link check normally, so the report used to call them working links while a browser stops your visitors with a warning screen.
* New: Videos embedded as an iframe - pasted in as HTML, or written by a page builder - are now checked, so a video that was taken down no longer leaves a dead player on the page with nothing said about it.
* New: The CSV export has a Reason column, so a row reading "Broken" beside a working status code explains itself in a spreadsheet.
* New: The dashboard says how many links are still waiting for a verdict, next to the counts they are missing from, and the monthly email names what they are waiting for rather than giving one reason for all of them.
* Updated: The highlighter now explains a broken link where you find it. Hovering a marked link shows what it points at, why the check failed, and a note that only logged-in editors can see the marks - along with where to turn them off. The panel stays open while you move the pointer onto it.
* Updated: The Broken Links Highlighter is now on by default.
* Updated: The report shows how long each link has been broken and can be sorted by it, so the breakage that has been live longest is easy to find.
* Updated: The Broken Link Checker widget on your WordPress dashboard now shows the single most useful thing to fix, rather than a chart of your link totals.
* Updated: AIOSEO's Details column now marks broken links with a broken chain icon and links the count straight to the rows behind it, for any post title.
* Updated: AIOSEO's 404 log now shows how many of your posts link to each 404'd URL.
* Updated: Rewrote the email template so it renders correctly in Outlook, Gmail, and clients that invert colours.
* Updated: The broken links report is substantially faster - counts are cached, rows no longer cost a query each, and paging no longer recounts the whole table.
* Updated: Every source is now indexed regardless of the settings, so switching a source on shows its links immediately instead of waiting for a full rescan. Switching one off no longer deletes anything.
* Updated: A synced pattern, template or navigation block is reindexed as soon as it is saved, so a link you fix there leaves the report right away.
* Updated: A synced pattern is only editable from the report by someone who can edit that particular pattern, since a pattern belongs to whoever created it.
* Updated: Removing a URL exclusion now restores the links it had removed from every source, not just from posts.
* Updated: An action a source cannot perform now explains why on hover instead of quietly disappearing.
* Updated: An image's alternative text is not something the report can rewrite, and it now says so instead of appearing to save the change.
* Updated: Replaced the link status details modal with an inline panel that states exactly what a change will affect.
* Updated: Elementor pages no longer report the same link twice, a page built with a builder is reported as a page, and a builder that is no longer active stops being reported.
* Updated: The report can now be sorted by when each link was last checked.
* Updated: Reorganised the settings - the link settings have their own section, the Advanced Settings master switch is gone, and scan sources use the same post type toggles as the rest of the plugin.
* Updated: Your link quota is now explained where it is shown, and turns red as it approaches the limit.
* Updated: Improved the account connection and activation prompts, and added a newsroom panel for plugin news.
* Updated: The report now says why a check failed rather than only that it did. A destination that blocked the check, one that did not respond in time, one that could not be reached at all, one whose address cannot exist, and a page that is there and asks to be signed in to each read differently, instead of all of them showing the same error. The last of those is the one worth calling out: a link wanting a login used to be reported as an error, which sent you looking for a fault in an address that is fine.
* Updated: No Broken Link Checker JavaScript is loaded on admin screens that do not need it. A small script used to run on every page in the dashboard to style one menu item, which is now done in CSS.
* Updated: The highlighter now marks broken images, frames and embedded players. They have no text to underline, so they take a dashed outline drawn inside their own box, and a player is outlined when one of the sources behind it is broken.
* Updated: A link whose address holds a character no browser accepts is now marked by the highlighter in Firefox and Safari as well as Chrome, and is reported rather than quietly dropped from the scan.
* Updated: The scan no longer waits out its idle timer when links fall due inside it, so a post you save, an import, or a plugin update is picked up straight away instead of up to an hour later.
* Updated: Broken Link Checker now requires PHP 7.4 or higher.
* Updated: The report no longer offers to move a post to the trash. Edit or unlink the broken link instead.
* Fixed: Dismissed links were counted and listed under the Pending filter, so the filter counts did not add up to the total.
* Fixed: A link could be counted and listed under two filter tabs at once, so the tabs added up to more than the total. A link that had ended up with two check records behind it is now repaired, and the scan no longer creates them.
* Fixed: A link edit that changed nothing reported success, closing the panel over content that still carried the old URL.
* Fixed: Searching the report treated an underscore or a percent sign in a URL as a wildcard, so a search could match links that did not contain it.
* Fixed: Relative link URLs are now kept relative when they are sanitised.
* Fixed: On some sites a script error broke the post editor, and the plugin was not the obvious cause because the error named an AIOSEO object. The script no longer depends on data a page may not have printed.
* Fixed: A recheck the service refused - an unverified license, or a month of link checks already used - advised trying again, which could never work. It now says what actually happened.
* Fixed: Two links whose addresses differ only in capitalisation were treated as one. The filter tabs added up to more than the total, and one of the pair was counted but never listed, exported, or fixable from the report.
* Fixed: The email report counted a link that redirected and then broke as both broken and a redirect, where the report on screen counts it as broken only.
* Fixed: Editing a link did nothing for an address the scan had tidied up on the way in - surrounding spaces, a backslash in place of a slash, a default port, a dot segment, a non-English path - which is the shape a broken link is most likely to have been written in.
* Fixed: Editing a link could not rewrite the address on an iframe, video or audio tag, and it dropped the part after the # from a link that had one, so two links pointing at different places on the same page were both sent to the same place.
* Fixed: Editing a link whose anchor text begins inside the surrounding HTML could damage the markup around it.
* Fixed: A post dated in the future - a scheduled post, or content an importer forward-dated - was re-read on every scan and never settled, so the scan never got as far as your menus, custom fields, term descriptions, author profiles, patterns and templates.
* Fixed: The second-opinion recheck kept picking the same few links every run, so the rest of the queue was never reached and a dead host never settled.
* Fixed: A link our service could not fetch was never rechecked from your own site, so it never resolved, was counted against your credits on every scan, and could settle as a working link.
* Fixed: A link that failed every recheck kept the status code the service had last seen, so a row could read "the destination returned a 200 error".
* Fixed: Searching the report for something that matches nothing listed every link instead of none.
* Fixed: The Activate button was live with the license key field empty, and pressing it answered "an unknown error occurred" rather than asking for a key.
* Fixed: Addresses that 1.3.0 stored in a mangled form - two links glued into one, a host with the path run into it - are re-read when you upgrade instead of staying in the report as broken.
* Fixed: An excluded post recorded by anything other than the settings screen could stop the scan with a fatal error.

**New in Version 1.3.0.1**

* Fixed: Links scan not finding any links on new installs.

**New in Version 1.3.0**

* New: Video support - Broken Link Checker now auto-detects video links and checks whether the linked video still exists, instead of just making sure that the link works. YouTube, Vimeo and 10+ platforms supported!
* New: Broken links are now scanned a second time client-side to reduce false-negatives due to WAF blocks or timeouts.
* Updated: Added support for HTML API for WP 6.6 and above to make link updates/removals more reliable.
* Updated: Added a license recheck action link to settings to refresh subscription data (useful in case of connection issues/subscription upgrades) + an indicator for the amount of sites that are active under the subscription.
* Updated: Added a mechanism to prevent duplicate actions from being scheduled.
* Updated: Link Assistant and Broken Link Checker now clean up database rows for trashed, private, or deleted posts so tables don't accumulate invisible bloat over time.
* Updated: Hardened options against unintended frontend exposure.
* Fixed: Setup Wizard sometimes not triggering due to missing cache table.
* Fixed: Relative URLs are now resolved against the post permalink before storing them to the DB.
* Fixed: Malformed URLs sent to Broken Link Checker server in rare cases.
* Fixed: Scan sometimes getting stuck due to duplicate rows in the DB.

**New in Version 1.2.10**

* Fixed: PHP error when connecting BLC when AIOSEO is not active.

**New in Version 1.2.9**

* Updated: Links that have never been checked before are now prioritized better.
* Updated: Improved scheduled action scheduling to prevent duplicate actions.
* Updated: Improved request handling with concurrency guards and caching to reduce outbound request volume.
* Updated: Added transient fallback mechanism for caching in case aioseo_blc_cache table doesn't exist.
* Updated: Various performance improvements.
* Fixed: Subsite database table cache no longer includes tables of all other subsites for multisites.
* Fixed: Rare issue where some links wouldn't be indexed due to special characters like new lines.

**New in Version 1.2.8**

* Updated: Changed wording to reflect that free subscriptions never expire, but that their quota resets each month.

**New in Version 1.2.7**

* New: Users using AIOSEO, Broken Link Checker and Link Assistant now see their broken links count in the AIOSEO Details post column.
* Updated: Compatibility with WordPress 6.9.
* Updated: Cache class now uses JSON instead of PHP serialization to prevent cache misses when charsets don't match.
* Updated: Various database performance improvements.
* Updated: Hardened database queries against SQL attacks.
* Fixed: "Good" filter now no longer shows URLs that still need to be scanned for the first time.

**New in Version 1.2.6**

* New: Added Link Distribution dashboard widget to the WordPress admin dashboard.
* New: Added reminder emails to inform users when Broken Link Checker is not connected.
* Updated: All BLC plans, including free, now support external links.
* Updated: The free BLC plan now includes 50 more links for free, for a total of 250.
* Updated: Added warning messages when indexed link count is larger than plan link quota or link quota is (almost) depleted.
* Updated: Added hook to filter URLs when they are indexed to add compatibility with WP Offload Media.
* Updated: Hardened REST API routes and added permission checks to improve security.
* Fixed: Link scan sometimes getting stuck if AIOSEO is not installed due to missing function.

**New in Version 1.2.5**

* Fixed: Link status scan sometimes getting stuck due to URL hash collisions.
* Fixed: Free plan not indicating that external links are not supported.

**New in Version 1.2.4**

* Updated: Hardening of ORDER BY/LIMIT clauses for database queries.

**New in Version 1.2.3**

* Updated: Added support for updating/removing relative URLs.

**New in Version 1.2.2**

* New: Added support for indexing and checking media URLs.
* Updated: Improved link removal process to increase rate of success.
* Fixed: Link Status row now closes after performing a table action to ensure no data leaks through to the next row.

**New in Version 1.2.1**

* New: Added a "Not Checked Yet" filter for new, unchecked links to the links table.
* New: Added an alert to the link info modal to indicate the date when the link will be rechecked.
* New: Added an admin notice to inform users when they have not connected Broken Link Checker yet.
* Fixed: Image URIs could cause catastrophical regex backtracking, freezing up the site.
* Fixed: Links with leading/trailing spaces in their URL or anchor text could not be edited or unlinked.
* Fixed: Broken links sometimes not highlighted for pages and CPTs.

**New in Version 1.2.0**

* New: Broken Links Highlighter - The new highlighter marks broken links on the frontend of your website, making it easier for you to find and fix them.
* New: AIOSEO Redirects Integration - BLC now integrates with AIOSEO Redirects so that you can easily redirect broken links to a working URL.
* Updated: Added additional inline error alerts for better user experience.
* Fixed: URLs with encoded characters could not be scanned because they were incorrectly hashed in the database.
* Fixed: URLs for media files (with the exception of images) are no longer indexed.
* Fixed: Unified URL rows now correctly respect the included post types, included post status, excluded posts and excluded domain settings.
* Fixed: When deleting a post, the confirmation modal now correctly shows up again.

**See our [changelog on aioseo.com](https://aioseo.com/changelog/broken-link-checker/?utm_source=wprepo&utm_medium=link&utm_campaign=blc) for previous releases.**

== Upgrade Notice ==

= 1.3.1 =

Broken Link Checker now finds links in custom fields, menus, term descriptions, author bios and page builders, checks embedded videos and SSL certificates, says why each link failed, emails reports, and adds a link health dashboard. Faster report, plus many fixes. Requires PHP 7.4.

= 1.3.0 =

Broken Link Checker now checks video links on YouTube, Vimeo, and 10+ platforms, rechecks broken links client-side to reduce false positives, and includes several fixes and performance improvements.