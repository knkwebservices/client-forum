<?php
/**
 * lib/Webhook.php
 * Sends staff notifications to Slack and/or Discord when a new thread
 * is posted (including threads created via ticket promotion). Both are
 * simple incoming-webhook POSTs — no OAuth, no bot setup required on
 * the Slack/Discord side, just a webhook URL pasted into the module's
 * config.
 *
 * DESIGN NOTE: this class only sends HTTP requests; it doesn't know
 * anything about WHMCS's addon-module config storage. clientforum.php
 * reads the configured webhook URLs (via clientforum_get_setting()) and
 * passes them in here — keeps this class testable/reusable without a
 * WHMCS config dependency baked into it.
 */

namespace ClientForum;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Webhook
{
    const TIMEOUT_SECONDS = 5;

    /**
     * Fire both webhooks (whichever are configured) for a new thread.
     * Never throws — a webhook failure (bad URL, Slack/Discord being
     * down, network timeout) must never block or roll back the thread
     * that was already successfully created. Failures are returned as a
     * list of strings for the caller to log or surface to an admin, not
     * raised as exceptions.
     */
    public static function notifyNewThread($slackUrl, $discordUrl, $threadTitle, $boardName, $authorName, $threadUrl, $context = 'New thread')
    {
        $errors = [];

        $plainMessage = "{$context}: \"{$threadTitle}\" in {$boardName} by {$authorName}\n{$threadUrl}";

        if (!empty($slackUrl)) {
            $error = self::sendSlack($slackUrl, $plainMessage);
            if ($error) $errors[] = "Slack: {$error}";
        }

        if (!empty($discordUrl)) {
            $error = self::sendDiscord($discordUrl, $plainMessage);
            if ($error) $errors[] = "Discord: {$error}";
        }

        return $errors;
    }

    /** Returns null on success, or an error message string on failure. */
    public static function sendSlack($webhookUrl, $text)
    {
        return self::post($webhookUrl, ['text' => $text]);
    }

    /** Returns null on success, or an error message string on failure. */
    public static function sendDiscord($webhookUrl, $text)
    {
        // Discord webhooks cap message content at 2000 characters.
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 1997) . '...';
        }
        return self::post($webhookUrl, ['content' => $text]);
    }

    protected static function post($url, array $payload)
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $url)) {
            return 'Webhook URL is missing or not a valid https:// URL.';
        }

        if (!function_exists('curl_init')) {
            return 'PHP curl extension is not available on this server.';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            return "Request failed: {$curlError}";
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return "Unexpected response (HTTP {$httpCode}).";
        }

        return null;
    }
}
