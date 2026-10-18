<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

class ValidateSignedUrl
{
    /**
     * Handle an incoming request.
     *
     * The middleware validates the signature only when the request carries one.
     * Any additional middleware arguments are treated as query parameters to ignore.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$ignoredParameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$ignoredParameters): Response
    {

        // If ignored parameters has "all" as value, ignore all query parameters except "signature" and "expires"
        $ignoredParameters =
            in_array('all', $ignoredParameters) ? array_diff($request->query->keys(), ['signature', 'expires'])
            :
            $ignoredParameters = array_values(array_unique(array_filter($ignoredParameters, static function ($parameter): bool {
                return $parameter !== '';
            })));

        if (!$request->query->has('signature')) {
            return $next($request);
        }

        if (
            !$request->hasValidSignature() &&
            !$request->hasValidSignatureWhileIgnoring($ignoredParameters)
        ) {
            throw new InvalidSignatureException;
        }

        return $next($request);
    }
}
