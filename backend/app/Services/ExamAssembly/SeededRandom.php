<?php

namespace App\Services\ExamAssembly;

/**
 * 确定性伪随机数（xorshift32）
 *
 * 组卷必须可复现：同样的题库 + 规则 + 随机种子，必须生成完全相同的结果，
 * 便于审计、复测与争议追溯。不依赖全局 mt_srand，避免污染框架其他随机调用。
 */
class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        // 0 会让 xorshift 卡死，映射为非零初值
        $this->state = ($seed & 0xFFFFFFFF) !== 0 ? ($seed & 0xFFFFFFFF) : 0x2545F491;
    }

    /** 返回 [0, 2^32-1] 的伪随机整数 */
    public function nextInt(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;
        return $this->state;
    }

    /** 返回 [0, $max) 的整数，$max 必须为正 */
    public function below(int $max): int
    {
        if ($max <= 1) {
            return 0;
        }
        return $this->nextInt() % $max;
    }

    /** 返回 [0, 1) 的浮点数 */
    public function float(): float
    {
        return $this->nextInt() / 0x100000000;
    }

    /** Fisher–Yates 洗牌（原地，返回同一数组） */
    public function shuffle(array $items): array
    {
        $keys = array_keys($items);
        $n = count($keys);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = $this->below($i + 1);
            $tmp = $items[$keys[$i]];
            $items[$keys[$i]] = $items[$keys[$j]];
            $items[$keys[$j]] = $tmp;
        }
        return $items;
    }
}
