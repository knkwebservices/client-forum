<?php
/**
 * lib/Csrf.php
 * Minimal per-form CSRF token helper, session-backed. Not tied to
 * WHMCS's own internal CSRF token (WHMCS admin pages have their own
 * separate mechanism) — this covers the module's own client-area and
 * admin-area POST/state-changing GET requests.
 */

namespace ClientForum;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Csrf
{
    const SESSION_KEY = 'clientforum_csrf_tokens';

    /** Generate (or reuse) a token for a named form/action and return it for embedding in a hidden field. */
    public static function token($formName)
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        if (empty($_SESSION[self::SESSION_KEY][$formName])) {
            $_SESSION[self::SESSION_KEY][$formName] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY][$formName];
    }

    /**
     * Validate a submitted token against the stored one for that form name.
     * Tokens are single-use: on successful validation they're rotated so a
     * replayed request (e.g. double-submit) fails the second time.
     */
    public static function validate($formName, $submittedToken)
    {
        if (empty($_SESSION[self::SESSION_KEY][$formName]) || empty($submittedToken)) {
            return false;
        }

        $valid = hash_equals($_SESSION[self::SESSION_KEY][$formName], $submittedToken);

        // Rotate regardless of outcome so tokens can't be reused.
        unset($_SESSION[self::SESSION_KEY][$formName]);

        return $valid;
    }

    /** Convenience for inline hidden-field HTML in raw-PHP admin output. */
    public static function field($formName)
    {
        $token = htmlspecialchars(self::token($formName), ENT_QUOTES, 'UTF-8');
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
    }
}
