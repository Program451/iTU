# Video lessons and Instagram / TikTok publishing

The shared sign-in routes administrators to the admin dashboard. In **Видеоуроки · API**, an administrator can upload MP4/WebM, add Russian and optional Kazakh text, choose a service category and order, preview, save a draft, or publish. Published lessons are immutable; use a copy or withdraw before editing. The normal workspace has a searchable library, private video streaming with byte ranges, and per-user completion. Lessons are independent of welcome and update popups. No sample videos are shipped to production.

## Publishing

Content Studio → **Публикация / время** opens the saved image and caption, destination checkboxes, and an optional Kazakhstan time (UTC+5). After confirmation, `cron.php` sends approved posts even when the browser is closed. Keep the existing once-per-minute CLI cron with GD, cURL, mbstring and PDO MySQL enabled. The schema additions are automatic and additive.

TikTok uses the official Content Posting API for **photo posts**, not videos. The app needs Login Kit, `user.info.basic`, Direct Post, `video.publish`, its exact HTTPS callback, and verified URL/domain ownership. The owner must explicitly authorize the new publishing scope in Connections. Public visibility requires TikTok's app audit; unaudited clients are restricted to private accounts and SELF_ONLY. Provider approval cannot be granted by this code.

Before queueing, the app fetches current creator info, displays the account, and requires manually selected privacy, interaction/disclosure preferences and consent. AI content metadata is retained. The new AI idea mode prepares a draft for both networks; the owner reviews the generated image before scheduling TikTok. The existing explicitly authorized Instagram-only AI mode remains available.

Delivery queues commit atomically but run independently. Each retains its approved content, schedule and provider receipt. TikTok cancellation does not cancel Instagram. Credentials refresh under an account lock. Unknown init outcomes are never resent automatically; existing receipts are polled without another init call. A user can retry only a definitively failed/cancelled TikTok delivery after review. Changing account/settings may stop a scheduled delivery for renewed approval.

Approved images stay outside the webroot; TikTok fetches a random-token URL expiring after 48 hours. No credentials belong in Git. Removing a local connection does not remove already published content.

Official references: [Photo API](https://developers.tiktok.com/docs/en/content-posting-api-reference-photo-post), [sharing requirements](https://developers.tiktok.com/docs/en/content-sharing-guidelines).

## Verification

Run `npm test`. Against a dedicated local `itu_test_*` database and local PHP web server, set `AULIEATA_CONFIG` to the private test config and `AULIEATA_TEST_VIDEO` to a small valid MP4, then run:

```
php tests/lessons.php
php tests/tiktok-publishing.php
php tests/tiktok.php
php tests/tiktok-tokens.php
php tests/social.php
php tests/announcements.php
php tests/images.php
php tests/assistant.php
```

Provider calls are mocked. Tests cover role/CSRF boundaries, private media and seeking, progress isolation, stale edits, disclosure choices, dual scheduling, no duplicate sends, cancellation, timeouts, rotating credentials, AI generation and the existing Instagram workflow. Browser QA additionally covers admin upload/publish and user playback/search/progress in Russian and Kazakh, desktop and mobile. A real public TikTok delivery still requires a properly approved connected account and a user-approved post.
