# Dox Feedback — Attribution & License

**Dox Feedback** is published by Dox Studio (https://doxstudio.com) under the
**GNU General Public License, version 2 or later (GPL-2.0-or-later)**.

## Upstream

Dox Feedback is a fork of:

> **Reviso – Client Feedback & Approvals**, licensed GPL-2.0-or-later.

The pinned-comment / review-mode / approvals core is derived from that project
and remains under GPL-2.0-or-later. We thank the upstream authors. "Reviso" is a
trademark of its respective owner and is **not** used by this plugin; Dox
Feedback is an independent, rebranded distribution and is not affiliated with or
endorsed by the upstream project.

## Original work in this distribution

The following are **original implementations** written for Dox Studio, built on
the upstream plugin's public extension hooks (not copied from any paid add-on):

- **Multi-page / whole-site reviews** — bundling several pages, or an entire
  site, into one shareable review link.
- **Email-invited reviewers with roles** — magic-link invitations and the
  Viewer / Reviewer / Approver / Lead permission model.

The upstream project's opt-in telemetry has been **removed** in this
distribution: Dox Feedback makes no outbound "phone-home" requests.

## Third-party components

- `assets/vendor/snapdom.min.js` — snapDOM, used for in-page screenshot capture.
  Distributed under its own license; see the snapDOM project for terms.

## Your rights

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 2 of the License, or (at your option) any later
version. See https://www.gnu.org/licenses/gpl-2.0.html.
