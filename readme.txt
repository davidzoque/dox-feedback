=== Dox Feedback ===
Contributors: doxstudio
Tags: client feedback, website feedback, approvals, bricks, elementor
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Client & website feedback for WordPress: pin comments, collect approvals & sign-off, share no-login review links — single page, several pages or a whole site, with email-invited reviewers and roles. Bricks, Elementor & Gutenberg.

== Description ==

**Dox Feedback** turns WordPress into a client-collaboration tool. Reviewers (clients, teammates, stakeholders) leave pinned feedback directly on your live pages — no logins, no email chains, no PDF round-trips. It is native to the **Bricks** and **Elementor** builders and the **Gutenberg** block editor, and works on any WordPress theme or site.

Everything is included in a single plugin — no separate add-on and no licence key.

= Features =

* **Pinned comments** on any element — desktop, tablet, or mobile
* **Threaded replies** and one-tap emoji reactions
* **Status tracking** — open, in progress, resolved — with filters
* **Review links** — share one page, a selection of pages, or your whole site under a single no-login link
* **Email-invited reviewers with roles** — Viewer / Reviewer / Approver / Lead, each authenticated by a private magic link
* **Client approvals** — reviewers mark a page approved and you get a timestamped sign-off record
* **Works in maintenance & coming-soon mode** for invited reviewers, while the site stays private to the public
* **Email notifications** when someone comments, replies, or approves — with optional burst-coalescing
* **Builder-native UI** — the overlay adopts the look of Bricks, Elementor or the block editor
* **Element-anchored pins** that survive page edits (DOM, content and position fallbacks)

= Privacy & data =

Dox Feedback stores all comment data **on your own WordPress site** (`wp_dxf_*` tables) and makes **no outbound "phone-home" requests** of any kind.

== Installation ==

1. Upload the `dox-feedback` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload Plugin.
2. Activate **Dox Feedback**.
3. Open any page in Bricks, Elementor or the block editor and the comment overlay appears — or use the **Dox Feedback** button in the front-end admin bar to start a review for the current page or your whole site.

== Frequently Asked Questions ==

= Do my reviewers need a WordPress account? =

No. Share a review link and reviewers comment as themselves — Dox Feedback captures their name (and optional email) when they leave their first comment. For email-restricted reviews each invitee gets a private magic link tied to their role.

= Which page builders are supported? =

Dox Feedback is native to the Bricks and Elementor builders and the Gutenberg block editor, and it works on any WordPress theme. Pins anchor to each builder's elements, with content and position fallbacks so they survive page edits.

= Can I review a whole site under one link? =

Yes. A review can cover a single page, a selection of pages, or the entire site (optionally including pages published later) — all behind one shareable link, with a per-page checklist and status for the reviewer.

= Where is my data stored? =

In your own WordPress database (`wp_dxf_*` tables). Nothing is transmitted off-site.

== Changelog ==

= 1.0.3 =
* Security: guest reviewer actions are now scoped to the reviewer's own review. Editing, deleting, resolving, reacting to, repositioning, attaching a screenshot to, or replying to a comment can no longer reach another review's comments on a page that two reviews happen to share.
* Security: page sign-off is now recorded only against the review the reviewer is working in, so one client's approval can no longer appear on another client's review of a shared page.

= 1.0.2 =
* Security: review management (close, reopen, delete, publish) now verifies ownership server-side — only the review's creator, or an editor who can manage others' content, can perform these actions.

= 1.0.1 =
* Branding: refreshed the wp-admin screens and the reviewer overlay to the Dox Studio look (white surfaces, orange accent, logo header) and replaced the leftover admin-bar mark.
* The reviewer surface (comment pins, sidebar buttons, focus states) now uses the Dox Studio orange accent.
* New comments now appear in the reviewer sidebar instantly (optimistic insert) instead of only after the save completes.
* Fix: review links could return a 404 when WordPress had not flushed its rewrite rules (e.g. after a folder rename or permalink change). The rules now self-heal on load.
* Fix: the "View in builder" link now opens the editor that actually built the page — Elementor, Bricks, or the block editor — instead of always assuming Bricks.
* Notification emails now use the site's own logo and name (Appearance → Customize → Site Identity) instead of the plugin's.
* Presented as a single, all-inclusive plugin: removed the upgrade/upsell screens and the docs/guide links.

= 1.0.0 =
* First Dox Studio release. Single-plugin build: pinned comments, threaded replies, emoji reactions, status tracking, client approvals/sign-off, and email notifications.
* Review links for a single page, a selection of pages, or an entire site (with optional auto-inclusion of future pages), behind one no-login link with a per-page reviewer checklist.
* Email-invited reviewers with roles — Viewer / Reviewer / Approver / Lead — authenticated by single-use magic links, with per-reviewer revoke. Role gating is enforced server-side.
* Native Bricks, Elementor and Gutenberg support; works on any theme.
* No telemetry and no external requests.

== Credits ==

Dox Feedback is a fork of "Reviso – Client Feedback & Approvals" (GPL-2.0-or-later). Multi-page / whole-site reviews and email-invited reviewers with roles are original Dox Studio implementations built on the upstream extension hooks. See NOTICE.md for full attribution.
