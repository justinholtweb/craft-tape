<?php

namespace justinholtweb\tape\models;

use craft\base\Model;

/** The outcome of one server-side call. */
class DispatchResult extends Model
{
    public bool $ok = false;

    public ?int $statusCode = null;

    public string $body = '';

    public ?string $error = null;

    /** True when the platform was never asked — no credentials, no mapping, consent withheld. */
    public bool $skipped = false;

    public static function skipped(string $why): self
    {
        return new self(['skipped' => true, 'error' => $why]);
    }

    public function getSummary(): string
    {
        if ($this->skipped) {
            return (string)$this->error;
        }

        if ($this->ok) {
            return 'HTTP ' . $this->statusCode;
        }

        return trim('HTTP ' . $this->statusCode . ' ' . ($this->error ?? '')) . ($this->body !== '' ? ' — ' . mb_substr($this->body, 0, 500) : '');
    }
}
