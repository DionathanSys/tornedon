<?php

namespace App\Traits;

trait HandlesServiceResponse
{
    protected bool $success = false;
    protected ?string $message = null;
    protected array $data = [];
    protected array $errors = [];
    protected int $status = 200;

    public function setSuccess(string|null $message = null, array $data = [], int $status = 200): void
    {
        $this->success  = true;
        $this->message  = $message;
        $this->data     = $data;
        $this->errors   = [];
        $this->status   = $status;
    }

    public function setError(string|null $message = null, array $errors = [], int $status = 422): void
    {
        $this->success  = false;
        $this->message  = $message;
        $this->errors   = $errors;
        $this->data     = [];
        $this->status   = $status;
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
        return !empty($this->errors);
    }

    public function getMessage(): string
    {
        return $this->message;
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

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'errors' => $this->errors,
            'status' => $this->status,
        ];
    }

    public function resetResponse(): self
    {
        $this->success = false;
        $this->message = '';
        $this->data = [];
        $this->errors = [];
        $this->status = 200;
        return $this;
    }
}