<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 组卷失败异常：携带具体的缺题/配置问题明细
 */
class PaperGenerationException extends RuntimeException
{
    protected array $details;

    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
