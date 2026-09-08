# CarrierPony Push Gateway

A small content-free push service for CarrierPony. It exists for one job: let a
self-hosted relay wake an App Store or Play install without holding the app
publisher's APNs and Firebase credentials.

Push on a self-hosted relay is off by default. A user can opt in, in the app,
to use this gateway. When they do, the app registers its push token here and
receives a random per-device wake token, which it hands to its own relay. When
that relay stores a message, it POSTs the wake token to this gateway, and the
gateway sends a content-free push that nudges the app to poll its relay. The
gateway never sees the sender, the message, or any plaintext, only that a device
was woken and when.

This service is meant to run as its own vhost (for example push.carrierpony.com)
alongside the main relay, on the same box, so the APNs and FCM credentials stay
in one place. The code is open source under Apache-2.0; it holds no secrets. The
APNs key, the Firebase service account, and the database password all live in
files outside the repo that config.php points to, and config.php itself is
gitignored. Read the code to confirm the gateway only ever sends a content-free
wake and keeps no message data.

## Endpoints

- `POST /v1/register-push` — `{ device_id, push_token, platform, apns_env? }`.
  Upserts the device and returns a stable `wake_token`.
- `POST /v1/wake` — `{ wake_token, silent? }`. Sends a content-free push to that
  device, coalesced to at most one per device per window.
- `POST /v1/deregister` — `{ wake_token }`. Drops the device (opt-out).

## Setup

Copy `config.sample.php` to `config.php`, fill in the database and the APNs and
FCM credentials (the same ones the relay uses), load `schema.sql`, point a vhost
at `public/`, and run `src/reaper.php` from cron to expire idle tokens.
