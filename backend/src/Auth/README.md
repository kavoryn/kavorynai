# API authentication boundary

The API accepts only a Supabase Auth **user access token** in
`Authorization: Bearer <access-token>`.

`SupabaseUserVerifier` validates the token by calling the project's
`/auth/v1/user` endpoint with the server-configured publishable key. It does
not decode unverified JWT claims or trust user IDs supplied in request bodies.
The authenticated user ID returned by this verifier is the only identity that
route handlers may use for ownership checks.

Configuration (server environment only):

- `SUPABASE_URL`: project URL.
- `SUPABASE_PUBLISHABLE_KEY`: publishable key. Never use the secret/service-role
  key for this identity check and never put a server secret in the Android app.
- PHP cURL extension with TLS certificate verification enabled.

The verifier uses short connect/total timeouts and fails closed if Auth is
unavailable. Do not log the bearer token or raw Auth response.

## Important boundary

This is a reusable verifier, not yet wired to protected routes. It does not
create accounts or sessions; Android must sign users in through Supabase Auth
and send the resulting access token. Route authorization, session revocation
policy, rate limiting and integration tests remain required before production.
