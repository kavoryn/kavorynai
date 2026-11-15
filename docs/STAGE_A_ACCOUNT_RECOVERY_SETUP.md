# Stage A — Android account recovery setup

Status: Android code and unit-test foundations are being added in a Draft PR. The live Supabase email-delivery and redirect configuration has **not** been changed or verified.

## Callback URL

The app handles this exact callback:

```text
com.kavoryn.app://auth-callback
```

The Android manifest registers this scheme and host. The app validates the scheme, host, callback type, token shape, and expiry before using any callback token.

In the Supabase Dashboard, add this exact value under **Authentication → URL Configuration → Redirect URLs** (also labelled Additional Redirect URLs in some dashboard views):

```text
com.kavoryn.app://auth-callback
```

Do not add wildcards. The callback must match exactly.

## Deep-link security limitation

The current Preview implementation uses the custom callback scheme `com.kavoryn.app://auth-callback`. This is acceptable only as a controlled implementation/test foundation, not as the final public-release deep-link design.

Android documents that multiple installed apps can register the same custom URI scheme. The user may see a chooser, or Android may route the link to the app selected as the default handler. Therefore, this custom scheme does not prove that a sensitive signup or password-recovery link will be delivered only to Kavoryn.

Before a small-group or public release, replace this transport with a verified HTTPS Android App Link on a domain Kavoryn controls:
- Add an HTTPS callback intent filter with `android:autoVerify="true"`.
- Publish the Android Digital Asset Links file at `https://<controlled-domain>/.well-known/assetlinks.json`, using the correct Android application ID and signing-certificate SHA-256 fingerprint.
- Configure the matching HTTPS callback URL in Supabase Auth Redirect URLs and update the callback parser/tests.
- Verify Android domain association on physical devices and test the link-routing behavior.

This migration requires separate domain/hosting ownership work. It is **not** done by this PR, and this PR must not change the existing `kavorynai.com.ng` business website or its DNS. Until verified App Links are configured, restrict callback testing to a dedicated test device/account and do not treat the custom scheme as launch-ready.

References:
- Android deep-link routing and custom-scheme behavior: https://developer.android.com/training/app-links/create-deeplinks
- Android verified App Links setup: https://developer.android.com/training/app-links/add-applinks

## Email delivery

Password reset and signup confirmation depend on Supabase Auth email delivery. The default email service is intended for trying the flow and has strict sending limits; a production launch should use a verified custom SMTP provider and appropriate rate limits. Do not assume successful API response means an email reached the inbox.

The app uses Supabase Auth's public client endpoints only:
- `POST /auth/v1/recover?redirect_to=...` for password reset email.
- `POST /auth/v1/resend?redirect_to=...` with `type=signup` to resend signup confirmation.
- `GET /auth/v1/user` with the one-time callback access token to resolve the just-confirmed user.
- `PUT /auth/v1/user` with the recovery access token to set the new password.

No service-role or Supabase secret key is included in Android.

## Privacy and security behavior

- Password reset and resend screens show generic success notices that do not disclose whether an email address belongs to an account.
- Supabase Auth rate-limit and error responses are surfaced as generic retry guidance.
- Callback tokens are parsed only from the app's custom URI fragment after exact scheme/host validation.
- Duplicate parameters, missing tokens, unsupported callback types, invalid token shapes, and expired callbacks are rejected.
- Recovery access tokens remain in Activity/Compose memory while the reset form is open. They are not written to the regular encrypted sign-in session store.
- After a successful password update, the recovery token is discarded and the user is asked to sign in with the new password.
- Do not log callback URLs, access tokens, refresh tokens, email contents, or passwords.

## Required manual Preview test

Use a dedicated test account and test mailbox—not a real customer's account or documents.

1. Create a new test account with email confirmation enabled.
2. Verify the confirmation email arrives and opens Kavoryn from the callback URL.
3. Confirm the app resolves the account through Supabase Auth and stores the resulting regular session securely.
4. Request a password reset for the test address; confirm the UI message is generic.
5. Open the reset email; confirm the app displays the new-password screen.
6. Update the password, then sign in with the new password.
7. Verify expired, malformed, unsupported, and reused recovery links are rejected.
8. Verify resend and recovery rate limits do not expose whether an account exists.

## Outstanding configuration and release gates

- Add the callback URL to the Supabase Auth redirect allowlist.
- Verify the signup and recovery email templates keep the redirect destination and verification flow intact.
- Configure and verify a suitable SMTP sender before a small-group or public release.
- Verify redirect behavior on an installed Android build and test on a physical device.
- Confirm Supabase's configured password policy and email confirmation policy match the product's user-facing guidance.

No Supabase dashboard setting, email template, SMTP configuration, schema, policy, migration, or user data was changed by this code PR.
