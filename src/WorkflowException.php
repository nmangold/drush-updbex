<?php

declare(strict_types=1);

namespace YourVendor\DrushUpdbex;

use RuntimeException;
use Throwable;

final class WorkflowException extends RuntimeException
{
    public function __construct(public readonly string $operation, Throwable $previous)
    {
        $code = (int) $previous->getCode();
        parent::__construct(
            sprintf('%s failed: %s', $operation, $previous->getMessage()),
            $code !== 0 ? $code : 1,
            $previous,
        );
    }
}