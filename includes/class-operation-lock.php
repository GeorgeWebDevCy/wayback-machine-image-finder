<?php

declare(strict_types=1);

namespace Wayback_Image_Restorer;

if (!defined('ABSPATH')) {
    exit;
}

final class Operation_Lock
{
    private array $owned_tokens = [];

    public function acquire(string $operation, int $ttl, array $meta = []): bool
    {
        $option_name = $this->get_option_name($operation);
        $token = wp_generate_uuid4();
        $payload = [
            'operation' => $operation,
            'token' => $token,
            'started_at' => current_time('c'),
            'expires_at' => time() + $ttl,
            'user_id' => get_current_user_id(),
            'meta' => $meta,
        ];

        if (add_option($option_name, $payload, '', false)) {
            $this->owned_tokens[$operation] = $token;
            return true;
        }

        $existing = get_option($option_name, null);
        if (!is_array($existing)) {
            delete_option($option_name);
            if (add_option($option_name, $payload, '', false)) {
                $this->owned_tokens[$operation] = $token;
                return true;
            }

            return false;
        }

        $expires_at = (int) ($existing['expires_at'] ?? 0);
        if ($expires_at > 0 && $expires_at < time()) {
            delete_option($option_name);
            if (add_option($option_name, $payload, '', false)) {
                $this->owned_tokens[$operation] = $token;
                return true;
            }
        }

        return false;
    }

    public function release(string $operation): void
    {
        $option_name = $this->get_option_name($operation);
        $token = $this->owned_tokens[$operation] ?? '';
        $existing = get_option($option_name, null);

        if (!is_array($existing)) {
            unset($this->owned_tokens[$operation]);
            return;
        }

        if ($token !== '' && (string) ($existing['token'] ?? '') === $token) {
            delete_option($option_name);
        }

        unset($this->owned_tokens[$operation]);
    }

    public function get_active_lock(string $operation): ?array
    {
        $existing = get_option($this->get_option_name($operation), null);
        if (!is_array($existing)) {
            return null;
        }

        $expires_at = (int) ($existing['expires_at'] ?? 0);
        if ($expires_at > 0 && $expires_at < time()) {
            delete_option($this->get_option_name($operation));
            return null;
        }

        return $existing;
    }

    private function get_option_name(string $operation): string
    {
        return 'wir_lock_' . sanitize_key($operation);
    }
}
