# Operations release, 2026-10-18

User requested ALL functions in the pasted three recommendation messages. Existing PHP/Plesk stack retained. Work is part of this chat, no new threads/agents. Original data preserved; illustrative menu remains demo.

Acceptance checklist (implementation and local verification; live delivery limits below):
- [x] Orders: quote/confirm, status, reservations, completion, cancellation, refund, exact totals and idempotency.
- [x] Batches linked to catalog/recipes, FEFO allocation, expiry/condition checks, live availability.
- [x] Schedules: staff/resources, weekly hours/breaks, duration, overlap protection, rescheduling, reminders.
- [x] Telegram: conversational order/booking, explicit confirmation, budget selection, handoff; preserve native voice.
- [x] Staff roles, invitation/login/revocation, server authorization and owner audit/undo.
- [x] Import add/update, SKU/name match, before/after preview, conflict-safe undo.
- [x] Campaign codes tied to orders/resources; actual attribution and expiry/quantity limits.
- [x] Lost-demand evidence and actual outcome/finance reports with periods and unknown cost handling.
- [x] Owner voice assistant: inspect/plan/approve/execute/result/error and undo.
- [x] Budget bundles and explicit what-if calculations.
- [x] Supplier price extraction text/table/photo, reviewed units, MOQ/shipping comparisons and draft request.
- [x] Voice shift handover, assigned tasks/status and next shift.
- [x] Service passports, timeline/photos and revocable private links.
- [x] QR feedback, text/voice, actionable tickets and resolution tracking.
- [x] Guided setup and readiness, live/demo data distinction.
- [x] Verify production UI/API and failure/concurrency paths; archive isolated test records.

External integrations remain dependent on credentials/permissions: WhatsApp, payment provider, Google OAuth, POS/1C. Do not present unconnected services as active. No purchase/payment or customer broadcast is authorized for assistant testing.

Release checks: 26 MariaDB scenarios, 7 HTTP check groups, concurrent independent PHP workers, live Gemini text/audio checks, local browser order flow, production login/API/AI plan apply and undo. Cron task 801 now runs every minute.

A new real incoming Telegram message was verified after the service fix on 2026-10-18: both text and audio were delivered. Consented reminder delivery has not been repeated. Mobile-device and load testing remain pilot work. External integrations listed above are not represented as connected. See DEVELOPMENT-STATUS.md for the exact verification boundary.


### 2026-10-18 — free cloud image generation

Implemented per-workspace Cloudflare Workers AI connections and a durable image queue, encrypted tokens, owner-only private image delivery, conservative per-business and per-account daily limits, idempotent submission, cancellation, and no automatic retry after uncertain outcomes. The content studio generates an image, keeps it in the workspace library and imports it into the existing post canvas. The existing minute cron processes the queue without an always-on user PC. Settings validate access without inference. Privacy disclosures describe the prompt transmission to Cloudflare.

Production verification: Cloudflare Billing showed **Workers Free / Active** and **No payment method on file**. A scoped Workers AI Read/Edit token was connected through the site's settings; no paid subscription enabled. The scheduled worker completed one FLUX.1 Schnell request and stored a 1024 × 1024 JPEG. Download and import into the post editor succeeded. A clearly marked test draft “Начните утро со вкуса” was saved; this verification did not publish it. Daily counter moved from 5 to 4 remaining. Files backed up outside the document root as `site-before-image-generation-20261018.zip` before deployment.

Checks: npm source/business checks; 26 operations integration cases; 8 social test groups; 9 service cases; 10 image cases including concurrent worker exclusion, encrypted credentials, tenant isolation, quota accounting, bad images, interrupted execution and no retries; HTTP authentication/CSRF/role/private-media checks. Image provider mocked in automated tests; real inference verified separately above. The account plan is verified manually; the restricted API token cannot detect a later switch to Workers Paid. Existing Telegram and Instagram functionality passed regression checks. TikTok remains profile connection only.
