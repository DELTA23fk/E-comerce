<?php

namespace App\Exceptions\Cva;

use Exception;

class CvaApiException extends Exception
{
     protected int $statusCode;
    protected ?array $responseData;

    public function __construct(
        string $message, 
        int $statusCode = 500, 
        ?array $responseData = null
    ) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->responseData = $responseData;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
