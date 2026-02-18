<?php

namespace App\Exceptions\Orders;

use Exception;
use Illuminate\Http\JsonResponse;

class OrderException extends Exception
{
     protected int $statusCode = 500;
    protected string $errorCode;
    protected array $context = [];

    public function __construct(
        string $message,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'context' => $this->context
            ]
        ], $this->statusCode);
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function report(): void
    {
        \Log::error($this->getMessage(), [
            'exception' => get_class($this),
            'error_code' => $this->errorCode,
            'context' => $this->context,
            'trace' => $this->getTraceAsString()
        ]);
    }
}
