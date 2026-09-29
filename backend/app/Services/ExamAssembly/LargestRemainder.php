<?php

namespace App\Services\ExamAssembly;

/**
 * 最大余数法（Hare-Niemeyer / Hamilton 法）
 *
 * 把一个整数总量按权重（比例）分配为整数配额，保证：
 *  1) 配额之和严格等于总量；
 *  2) 每个配额先取 floor(weight / sum * total)，
 *     余下名额按小数部分从大到小各补 1；
 *  3) 小数部分相同时用稳定的 key 顺序打破平局，结果可复现。
 */
class LargestRemainder
{
    /**
     * @param int   $total    需要分配的整数总量（>=0）
     * @param array $weights  key => 非负权重（比例）
     * @return array          key => 整数配额
     */
    public static function allocate(int $total, array $weights): array
    {
        $total = max(0, $total);
        $keys = array_keys($weights);

        if ($total === 0 || empty($keys)) {
            return array_fill_keys($keys, 0);
        }

        $sum = array_sum($weights);
        if ($sum <= 0) {
            // 没有任何有效权重：全部给第一个 key，保持总和守恒
            $alloc = array_fill_keys($keys, 0);
            $alloc[$keys[0]] = $total;
            return $alloc;
        }

        $floors = [];
        $remainders = [];
        foreach ($keys as $key) {
            $exact = ($weights[$key] / $sum) * $total;
            $floor = (int) floor($exact);
            $floors[$key] = $floor;
            $remainders[$key] = $exact - $floor;
        }

        $allocated = array_sum($floors);
        $left = $total - $allocated;

        // 按余数降序，平局按 key 升序，保证确定性
        $sorted = $keys;
        usort($sorted, function ($a, $b) use ($remainders) {
            if ($remainders[$a] === $remainders[$b]) {
                return strcmp((string) $a, (string) $b);
            }
            return $remainders[$b] <=> $remainders[$a];
        });

        foreach ($sorted as $key) {
            if ($left <= 0) {
                break;
            }
            $floors[$key]++;
            $left--;
        }

        return $floors;
    }
}
