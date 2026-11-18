<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Support\Iso\LanguageRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the API, `Accept-Language` wins - PF-219.
 *
 * ## Why the precedence is inverted here
 *
 * {@see \App\Http\Middleware\SetLocale} puts `Accept-Language` LAST, behind
 * the user's own preference and the tenant's default, and gives the reason: a
 * browser set to English does not mean a Jordanian accountant wants English
 * invoices, and the tenant's own choice outranks a setting nobody chose
 * deliberately.
 *
 * That reasoning is about browsers, and it does not transfer. An integration
 * sending `Accept-Language: en` has stated the language of the system - or
 * the person - that will read the failure, per request, in code somebody
 * wrote on purpose. The tenant default describes the office; the header
 * describes this request.
 *
 * PF-219 says it plainly: messages are localized by `Accept-Language`. With
 * the web precedence, a tenant whose default is Arabic would get Arabic
 * problem details however loudly its integrator asked for English, and the
 * integrator's only recourse would be to parse `title` - which is the one
 * part of a problem document that must never be localized and that they must
 * not switch on.
 *
 * ## Absent, it changes nothing
 *
 * No header means the tenant's default still applies, because that is the
 * better guess than ours. This middleware only ever acts on a language
 * somebody asked for.
 */
final class NegotiateApiLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $asked = $request->getPreferredLanguage(LanguageRegistry::served());

        if (\is_string($asked)
            && $request->headers->has('Accept-Language')
            && LanguageRegistry::exists($asked)) {
            app()->setLocale($asked);
        }

        $response = $next($request);

        /*
         | And the response says which language it answered in.
         |
         | A client that asked for English and received Arabic has either sent
         | the header wrongly or reached a deployment that ignores it, and
         | `Content-Language` is how it can tell those apart without guessing
         | from the script.
         */
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
