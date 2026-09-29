<?php

namespace App\Services\ExamAssembly;

/**
 * 组卷规划：把管理员的组卷规则（知识点配额、题型比例、必考题）
 * 转成一张「知识点 × 题型」的抽题配额矩阵，并在发布前完成预检。
 *
 * 设计要点
 *  - 必考题先占位：必考题占用它所属 (知识点, 题型) 格子的配额；
 *  - 剩余名额用最大余数法按比例分配，保证总题数严格守恒；
 *  - 若某格子可用题量不足，记录 SHORTAGE 缺口（缺哪类题一目了然）；
 *  - 依据题库难度的极小/极大可行组合，检查「难度区间」是否可达。
 */
class Blueprint
{
    /** @var string 题型配额完全无可用题 */
    public const SHORTAGE = 'SHORTAGE';
    /** @var string 必考题本身不存在/不可用 */
    public const REQUIRED_INVALID = 'REQUIRED_INVALID';
    /** @var string 必考题难度偏离目标区间（警告，不阻断） */
    public const REQUIRED_DRIFT = 'REQUIRED_DRIFT';
    /** @var string 目标难度区间在当前题库下不可达 */
    public const DIFFICULTY_UNREACHABLE = 'DIFFICULTY_UNREACHABLE';
    /** @var string 要求跨学生不重题，但题量不足 */
    public const UNIQUE_INSUFFICIENT = 'UNIQUE_INSUFFICIENT';

    /**
     * 生成配额矩阵
     *
     * @param array $cfg 组卷配置（见 Assembler::buildConfig 字段说明）
     * @param array $questions 题库题目数组（已按知识点/题型过滤）
     * @return array{matrix:array, required:array, issues:array}
     *   matrix: [kpKey][type] => quota
     *   required: 每格必考题 id 列表
     *   issues: 预检问题列表
     */
    public static function plan(array $cfg, array $questions): array
    {
        $issues = [];
        $kpList = array_values(array_unique($cfg['knowledge_points'] ?? []));
        $typeRatio = $cfg['type_ratio'] ?? [];
        $total = (int) ($cfg['question_count'] ?? 0);
        $kpQuota = $cfg['knowledge_point_quota'] ?? null; // 可选：每知识点精确题数
        $requiredIds = array_values(array_unique($cfg['required_question_ids'] ?? []));
        $minDiff = (float) $cfg['difficulty_min'];
        $maxDiff = (float) $cfg['difficulty_max'];

        // 题库按 (知识点, 题型) 建立索引
        $cells = [];      // kp => type => [question...]
        $byId = [];
        foreach ($questions as $q) {
            $kp = $q['kp'];
            $type = $q['type'];
            $cells[$kp][$type][] = $q;
            $byId[$q['id']] = $q;
        }

        // 1) 必考题占位
        $requiredInCell = []; // kp => type => [ids]
        $usedRequired = [];
        foreach ($requiredIds as $rid) {
            if (!isset($byId[$rid])) {
                $issues[] = [
                    'code' => self::REQUIRED_INVALID,
                    'severity' => 'error',
                    'message' => "必考题 #{$rid} 不存在、已停用或不在所选知识点范围内，无法保证每位学生都考到。",
                    'question_id' => $rid,
                ];
                continue;
            }
            $q = $byId[$rid];
            if (!in_array($q['type'], array_keys($typeRatio), true)) {
                $issues[] = [
                    'code' => self::REQUIRED_INVALID,
                    'severity' => 'error',
                    'message' => "必考题 #{$rid} 的题型「{$q['type']}」不在题型比例中，无法纳入配额。",
                    'question_id' => $rid,
                ];
                continue;
            }
            $requiredInCell[$q['kp']][$q['type']][] = $rid;
            $usedRequired[$rid] = true;

            $d = (float) $q['difficulty'];
            if ($d < $minDiff - 1e-9 || $d > $maxDiff + 1e-9) {
                $issues[] = [
                    'code' => self::REQUIRED_DRIFT,
                    'severity' => 'warning',
                    'message' => sprintf(
                        '必考题 #%d 难度 %.2f 在目标区间 [%.2f, %.2f] 之外，可能把整套卷难度拉偏（系统会尽量用其余题抵消）。',
                        $rid, $d, $minDiff, $maxDiff
                    ),
                    'question_id' => $rid,
                ];
            }
        }

        // 2) 知识点题数配额
        if (is_array($kpQuota) && !empty($kpQuota)) {
            // 显式指定每个知识点题数（只采用出现在 kpList 中的项）
            $kpAlloc = [];
            foreach ($kpList as $kp) {
                $kpAlloc[$kp] = (int) ($kpQuota[$kp] ?? 0);
            }
            // 若显式配额之和与总题数不一致，以最大余数法把差额补/减到各知识点
            $sum = array_sum($kpAlloc);
            if ($sum !== $total && $total > 0) {
                $weights = [];
                foreach ($kpList as $kp) {
                    $weights[$kp] = $kpAlloc[$kp] > 0 ? $kpAlloc[$kp] : 1;
                }
                $kpAlloc = LargestRemainder::allocate($total, $weights);
            }
        } else {
            // 默认各知识点均分
            $weights = array_fill_keys($kpList, 1.0);
            $kpAlloc = LargestRemainder::allocate($total, $weights);
        }

        // 3) 每个知识点内部按题型比例分配（先扣必考题占位）
        $matrix = [];
        foreach ($kpList as $kp) {
            $need = $kpAlloc[$kp];
            $base = [];
            $avail = $need;
            foreach ($typeRatio as $type => $ratio) {
                $cntReq = count($requiredInCell[$kp][$type] ?? []);
                $base[$type] = $cntReq;
                $avail -= $cntReq;
            }

            $extra = [];
            if ($avail > 0) {
                $extra = LargestRemainder::allocate($avail, $typeRatio);
            } else {
                $extra = array_fill_keys(array_keys($typeRatio), 0);
            }

            foreach ($typeRatio as $type => $_) {
                $matrix[$kp][$type] = $base[$type] + ($extra[$type] ?? 0);
            }
        }

        // 4) 缺口检查：配额 > 可用题量（必考题必然可用，只可能是抽取部分不够）
        foreach ($kpList as $kp) {
            foreach ($typeRatio as $type => $_) {
                $quota = $matrix[$kp][$type];
                $reqCount = count($requiredInCell[$kp][$type] ?? []);
                $pool = $cells[$kp][$type] ?? [];
                $availCount = count($pool);
                if ($quota > $availCount) {
                    $issues[] = [
                        'code' => self::SHORTAGE,
                        'severity' => 'error',
                        'message' => sprintf(
                            '知识点「%s」的题型「%s」需要 %d 题（含必考 %d），题库仅有 %d 题，缺 %d 题。',
                            $kp, $type, $quota, $reqCount, $availCount, $quota - $availCount
                        ),
                        'knowledge_point' => $kp,
                        'type' => $type,
                        'required' => $quota,
                        'available' => $availCount,
                        'missing' => $quota - $availCount,
                    ];
                }
            }
        }

        // 5) 跨学生不重题的题量检查
        $studentCount = (int) ($cfg['student_count'] ?? 0);
        if (!empty($cfg['unique_across_students']) && $studentCount > 0) {
            foreach ($kpList as $kp) {
                foreach ($typeRatio as $type => $_) {
                    $quota = $matrix[$kp][$type] ?? 0;
                    $reqIds = $requiredInCell[$kp][$type] ?? [];
                    $poolCount = count($cells[$kp][$type] ?? []);
                    // 必考题全员相同，非必考题需互不相同
                    $needUnique = ($quota - count($reqIds)) * $studentCount + count($reqIds);
                    if ($needUnique > $poolCount) {
                        $issues[] = [
                            'code' => self::UNIQUE_INSUFFICIENT,
                            'severity' => 'error',
                            'message' => sprintf(
                                '「不重题」模式下，知识点「%s」题型「%s」需 %d 份不同题（必考 %d + %d 学生×%d），题库仅 %d 题，缺 %d 题。',
                                $kp, $type, $needUnique, count($reqIds), $studentCount,
                                $quota - count($reqIds), $poolCount, $needUnique - $poolCount
                            ),
                            'knowledge_point' => $kp,
                            'type' => $type,
                            'required' => $needUnique,
                            'available' => $poolCount,
                            'missing' => $needUnique - $poolCount,
                        ];
                    }
                }
            }
        }

        // 6) 难度可达性：必考（按分值）锁定 + 其余 slot 取题库最难/最易
        $scoreOf = fn($q) => (float) ($q['score'] ?? 1);
        $reqWeighted = 0.0;
        $reqScoreSum = 0.0;
        $freeSlots = []; // 每个自由抽题 slot: [可用题难度最小, 最大, 分值]
        $freeScoreSum = 0.0;
        foreach ($kpList as $kp) {
            foreach ($typeRatio as $type => $_) {
                $quota = $matrix[$kp][$type] ?? 0;
                $pool = $cells[$kp][$type] ?? [];
                $reqIds = $requiredInCell[$kp][$type] ?? [];
                foreach ($reqIds as $rid) {
                    $q = $byId[$rid];
                    $s = $scoreOf($q);
                    $reqWeighted += (float) $q['difficulty'] * $s;
                    $reqScoreSum += $s;
                }
                if ($quota - count($reqIds) > 0 && !empty($pool)) {
                    $diffs = array_map(fn($q) => (float) $q['difficulty'], $pool);
                    // 同题型默认同分值；取池中第一题分值为代表（装配时按实际分值重算）
                    $repScore = $scoreOf($pool[0]);
                    $freeSlots[] = [
                        'count' => $quota - count($reqIds),
                        'min' => min($diffs),
                        'max' => max($diffs),
                        'score' => $repScore,
                    ];
                    $freeScoreSum += ($quota - count($reqIds)) * $repScore;
                }
            }
        }

        $totalScore = $reqScoreSum + $freeScoreSum;
        if ($totalScore > 0) {
            $minWeighted = $reqWeighted;
            $maxWeighted = $reqWeighted;
            foreach ($freeSlots as $slot) {
                $minWeighted += $slot['min'] * $slot['score'] * $slot['count'];
                $maxWeighted += $slot['max'] * $slot['score'] * $slot['count'];
            }
            $feasibleMin = $minWeighted / $totalScore;
            $feasibleMax = $maxWeighted / $totalScore;

            if ($feasibleMin > $maxDiff + 1e-9 || $feasibleMax < $minDiff - 1e-9) {
                $issues[] = [
                    'code' => self::DIFFICULTY_UNREACHABLE,
                    'severity' => 'error',
                    'message' => sprintf(
                        '目标难度区间 [%.2f, %.2f] 不可达：当前题库能组合出的整卷加权难度只在 [%.2f, %.2f]。请放宽区间或补充对应难度的题。',
                        $minDiff, $maxDiff, $feasibleMin, $feasibleMax
                    ),
                    'feasible_min' => round($feasibleMin, 4),
                    'feasible_max' => round($feasibleMax, 4),
                ];
            }
        }

        return [
            'matrix' => $matrix,
            'required' => $requiredInCell,
            'issues' => $issues,
        ];
    }

    /** 是否存在阻断性（error）问题 */
    public static function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'error') {
                return true;
            }
        }
        return false;
    }
}
