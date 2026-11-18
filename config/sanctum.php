<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sanctum, with the cookie fallback REMOVED - PF-218
    |--------------------------------------------------------------------------
    |
    | Only the keys this application decides differently from the package are
    | here; Sanctum merges its own defaults underneath for the rest.
    |
    | `guard` is the one that matters, and it is empty on purpose.
    |
    | Sanctum's default is `['web']`: before looking at the bearer token, its
    | guard asks the session guard whether anybody is signed in, and if so
    | authenticates them with a TransientToken - a token that answers `can()`
    | with true for every ability. On this API that would be two holes at
    | once.
    |
    | The first is CSRF. A browser attaches cookies to a cross-site POST on
    | its own; the API carries no CSRF middleware because a bearer token
    | cannot be sent by a third-party page. Accepting the cookie here would
    | make every write reachable from any site the user visits.
    |
    | The second is the scope model. PF-218 asks that token scopes mirror
    | permissions exactly, so that there is one authorization model and not a
    | second, weaker one for machines. A TransientToken is that second model:
    | it holds every ability, so an API call made with a session cookie would
    | bypass the scope check entirely while looking identical in a log.
    |
    | So the API authenticates by bearer token and by nothing else. The
    | application's own screens use the `web` guard and the session, which is
    | right for a browser and is a different plane.
    |
    */

    'guard' => [],

    /*
     | No global expiry.
     |
     | A fleet-wide "all tokens older than N minutes are dead" would expire a
     | customer's nightly integration at an hour nobody chose, and the
     | symptom - a 401 at 02:00 on a token nobody touched - is one of the
     | worse ones to diagnose. Expiry is per token, set when it is issued by
     | whoever knows what the token is for, and `api_tokens.expires_at` is
     | where it lives.
     */
    'expiration' => null,

    /*
     | A prefix on every issued token, so that a secret scanner can recognise
     | one in a commit and tell the owner before somebody else finds it.
     |
     | Hardcoded rather than read from the environment: a prefix that differs
     | between deployments is a prefix no scanner can be configured for.
     */
    'token_prefix' => 'pfat_',
];
