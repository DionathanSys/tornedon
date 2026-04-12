<?php

namespace App\Traits;

use Illuminate\Support\Arr;

trait HandlesServiceResponse
{
    use GeneratesErrorCode;

    protected bool $success = false;
    protected ?string $message = null;
    protected array $data = [];
    protected array $errors = [];
    protected int $status = 200;
    protected ?string $errorCode = null;

    public function setSuccess(string|null $message = null, array $data = [], int $status = 200): void
    {
        $this->success  = true;
        $this->message  = $message;
        $this->data     = $data;
        $this->errors   = [];
        $this->status   = $status;
    }

    public function setError(string|null $message = null, array $errors = [], int $status = 422, ?string $errorCode = null): void
    {
        $this->success   = false;
        $this->message   = $message;
        $this->errors    = $errors;
        $this->data      = [];
        $this->status    = $status;
        $this->errorCode = $errorCode ?? $this->generateErrorCode();
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function hasError(): bool
    {
        return !empty($this->errors) || !$this->success;
    }

    public function getMessage(): string
    {
        return $this->message ?? '';
    }

    public function getMessageUser(): string
    {
        $baseMessage = $this->message ?? '';

        if (! empty($this->errors)) {
            $errors = implode('<br> ', $this->formatErrorsForMessage($this->getErrors()));
            return $baseMessage . ';<br> ' . $errors;
        }

        return $baseMessage;
    }

    private function formatErrorsForMessage(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return [(string) $value];
        }

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            return [json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }

        if ($this->isStructuredApiError($value)) {
            return [$this->stringifyStructuredApiError($value)];
        }

        $messages = [];

        foreach ($value as $item) {
            $messages = [...$messages, ...$this->formatErrorsForMessage($item)];
        }

        return array_values(array_filter($messages, static fn ($message): bool => $message !== ''));
    }

    private function isStructuredApiError(array $value): bool
    {
        return Arr::isAssoc($value)
            && (array_key_exists('campo', $value)
                || array_key_exists('erro', $value)
                || array_key_exists('descricao', $value)
                || array_key_exists('detalhes', $value));
    }

    private function stringifyStructuredApiError(array $value): string
    {
        $parts = array_filter([
            $value['campo'] ?? null,
            $value['erro'] ?? null,
            $value['descricao'] ?? null,
            $value['detalhes'] ?? null,
        ], static fn ($part): bool => filled($part));

        return implode(' | ', $parts);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'errors' => $this->errors,
            'status' => $this->status,
            'error_code' => $this->errorCode,
        ];
    }

    public function resetResponse(): self
    {
        $this->success = false;
        $this->message = '';
        $this->data = [];
        $this->errors = [];
        $this->status = 200;
        $this->errorCode = null;
        return $this;
    }
}
