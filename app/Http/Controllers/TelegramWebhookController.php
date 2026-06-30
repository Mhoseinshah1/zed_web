<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramCommandRouter;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Telegram updates for the admin management bot.
 *
 * SECURITY:
 *   1) The request must carry X-Telegram-Bot-Api-Secret-Token matching the
 *      stored (encrypted) secret — otherwise 403, no processing.
 *   2) Only messages from the configured management group whose sender is an
 *      allowed admin are processed; everything else is silently ignored (no
 *      reply) to avoid leaking the bot's existence/behaviour.
 *
 * Processing is wrapped in try/catch and ALWAYS returns 200 (so Telegram never
 * retries on our errors); a webhook error can never affect any business flow.
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, TelegramSettings $settings): Response
    {
        // Bot off / not configured → accept silently (don't reveal anything).
        if (! $settings->enabled() || ! $settings->hasToken()) {
            return response('', 200);
        }

        // ── Security layer 1: secret token ──────────────────────────────────
        $secret = $settings->webhookSecret();
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($secret === '' || ! hash_equals($secret, $header)) {
            return response('forbidden', 403);
        }

        try {
            $update = $request->json()->all();
            if (! is_array($update) || $update === []) {
                $update = $request->all();
            }

            $message = $update['message'] ?? $update['edited_message'] ?? null;
            if (! is_array($message)) {
                return response('', 200);
            }

            // ── Security layer 2: group + allowed admin ─────────────────────
            $chatId = (string) ($message['chat']['id'] ?? '');
            $fromId = (string) ($message['from']['id'] ?? '');

            if ($chatId === '' || $chatId !== $settings->chatId()) {
                return response('', 200); // not our management group
            }
            if (! in_array($fromId, $settings->allowedAdminIds(), true)) {
                return response('', 200); // not an allowed admin
            }

            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '' || $text[0] !== '/') {
                return response('', 200); // not a command
            }

            $threadId = isset($message['message_thread_id']) ? (int) $message['message_thread_id'] : null;

            app(TelegramCommandRouter::class)->dispatch($text, $chatId, $threadId);
        } catch (\Throwable $e) {
            // Never 500 (would make Telegram retry); just log a token-free note.
            TelegramSettings::safeLog('webhook processing error', ['error' => class_basename($e)]);
        }

        return response('', 200);
    }
}
