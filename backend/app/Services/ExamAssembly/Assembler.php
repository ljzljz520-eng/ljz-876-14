<?php

namespace App\Services\ExamAssembly;

/**
 * 组卷装配器
 *
 * 目标：为每个学生生成一份不同的试卷，同时让所有试卷的整卷加权难度
 * 尽量接近，避免某个学生明显拿到更简单的一套。
 *
 * 均衡策略（两层）
 *  1) 装配阶段 —— 难度分层 + 蛇形发牌：
 *     每个「知识点×题型」格子内，把候选题按难度排序切成 m 个等宽层
 *     （m = 该格每卷抽题数），再在层内给各学生发牌，相邻层正反向交替
 *     （蛇形）。这样每份卷在每个格子里都取遍「易→难」全谱，难度天然
 *     对齐；题库富余时不同学生取不同题，题库紧张时允许复用但同卷不重。
 *  2) 修复阶段 —— 最难卷/最易卷成对交换：
 *     用实际分值重算每份卷加权难度，反复在同格内把最难卷的高难度题与
 *     最易卷的低难度题互换，直到全部落入目标区间且卷间极差收敛。
 *
 * 必考题不参与随机抽取与交换，全员一致。
 */
class Assembler
{
    /** 难度交换修复的最大迭代轮数 */
    public const REPAIR_ROUNDS = 2000;

    /**
     * @param array $cfg       组卷配置
     * @param array $questions 题库（题数组，含 id/type/kp/difficulty/score）
     * @param array $plan      Blueprint::plan() 结果
     * @param array $students  学生标识列表（如学号/用户 id）
     * @param int   $seed      随机种子（可复现）
     */
    public static function assemble(array $cfg, array $questions, array $plan, array $students, int $seed): array
    {
        $rng = new SeededRandom($seed);
        $matrix = $plan['matrix'];
        $required = $plan['required'];

        $byId = [];
        $pools = []; // "kp\0type" => 候选题（排除必考题）
        foreach ($questions as $q) {
            $byId[$q['id']] = $q;
        }
        foreach ($matrix as $kp => $types) {
            foreach ($types as $type => $quota) {
                $key = self::cellKey($kp, $type);
                $reqIds = $required[$kp][$type] ?? [];
                $pool = [];
                foreach ($questions as $q) {
                    if ($q['kp'] === $kp && $q['type'] === $type && !in_array($q['id'], $reqIds, true)) {
                        $pool[] = $q;
                    }
                }
                // 难度升序；难度相同则随机，保证可复现又不死板
                usort($pool, function ($a, $b) use ($rng) {
                    $cmp = ((float) $a['difficulty']) <=> ((float) $b['difficulty']);
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                    return $rng->below(2) === 0 ? -1 : 1;
                });
                $pools[$key] = $pool;
            }
        }

        // 初始化每份卷：先放必考题
        $papers = [];
        foreach ($students as $sIdx => $student) {
            $papers[$sIdx] = [
                'student' => $student,
                'entries' => [],
            ];
            foreach ($required as $kp => $types) {
                foreach ($types as $type => $ids) {
                    foreach ($ids as $rid) {
                        $q = $byId[$rid];
                        $papers[$sIdx]['entries'][] = self::entry($q, $kp, $type, true);
                    }
                }
            }
        }

        // 供修复阶段定向替换使用的 cell 池快照
        self::$cellPoolSnapshot = $pools;

        // 分层蛇形发牌
        foreach ($matrix as $kp => $types) {
            foreach ($types as $type => $quota) {
                $m = $quota - count($required[$kp][$type] ?? []);
                if ($m <= 0) {
                    continue;
                }
                $key = self::cellKey($kp, $type);
                $pool = $pools[$key];
                $n = count($students);

                // 把池切成 m 个难度层，大小用最大余数法分配（和为 L）
                $layerSizes = LargestRemainder::allocate(count($pool), array_fill(0, $m, 1));
                $layers = [];
                $cursor = 0;
                foreach ($layerSizes as $size) {
                    $layer = array_slice($pool, $cursor, $size);
                    // 层内洗牌：难度相近的题之间随机，避免总取同一道
                    $layers[] = $rng->shuffle($layer);
                    $cursor += $size;
                }

                for ($sIdx = 0; $sIdx < $n; $sIdx++) {
                    for ($k = 0; $k < $m; $k++) {
                        $layer = $layers[$k] ?? [];
                        if (empty($layer)) {
                            continue; // 预检本应拦住，防御性跳过
                        }
                        $size = count($layer);
                        // 蛇形：偶数层正向、奇数层反向，抹平相邻层难度趋势
                        $pos = ($k % 2 === 0)
                            ? $sIdx % $size
                            : ($size - 1 - ($sIdx % $size));
                        $q = $layer[($pos + $size) % $size];
                        $papers[$sIdx]['entries'][] = self::entry($q, $kp, $type, false);
                    }
                }
            }
        }

        // 非 unique 模式防御性去重（预检已保证 unique 模式题量），理论不会触发
        foreach ($papers as $sIdx => &$paper) {
            $paper['entries'] = self::dedupeWithinPaper($paper['entries'], $pools, $byId, $rng);
        }
        unset($paper);

        // 修复阶段：定向替换把每份卷难度拉入区间并压缩极差
        $papers = self::repair($papers, $cfg, $rng);

        // 排序输出：题型 -> 知识点，必考题在前
        $typeOrder = array_keys($cfg['type_ratio'] ?? []);
        foreach ($papers as &$paper) {
            usort($paper['entries'], function ($a, $b) use ($typeOrder) {
                $ta = array_search($a['type'], $typeOrder, true);
                $tb = array_search($b['type'], $typeOrder, true);
                if ($ta !== $tb) {
                    return $ta <=> $tb;
                }
                if ($a['kp'] !== $b['kp']) {
                    return strcmp($a['kp'], $b['kp']);
                }
                if ($a['required'] !== $b['required']) {
                    return $b['required'] <=> $a['required'];
                }
                return $a['question_id'] <=> $b['question_id'];
            });
            $paper = self::finalize($paper, $cfg);
        }
        unset($paper);

        return [
            'papers' => array_values($papers),
            'stats' => self::statistics($papers, $cfg),
        ];
    }

    /**
     * 难度修复：每轮锁定当前「最易卷」与「最难卷」，
     *  - 最易卷在某 cell 内用「更难、且本卷未用过」的题做最优单题替换；
     *  - 最难卷反之用更易的题替换。
     * 目标函数对越界施加重罚（优先全部进入区间），入区间后继续朝区间中点
     * 收敛（压缩极差），直到全部达标或无更优替换。
     *
     * 相比"两卷互换"，单卷定向替换能整体抬高/压低全体卷面，
     * 解决了目标区间偏离题库均值时大家卡在均值不动的问题。
     */
    protected static function repair(array $papers, array $cfg, SeededRandom $rng): array
    {
        $minDiff = (float) $cfg['difficulty_min'];
        $maxDiff = (float) $cfg['difficulty_max'];
        $target = ($minDiff + $maxDiff) / 2;
        $fairRange = (float) ($cfg['fairness_range'] ?? 0.08);
        $requiredIds = array_flip($cfg['required_question_ids'] ?? []);

        // 本轮装配各 cell 的候选题池（题数组），用于定向替换
        $cellPools = self::$cellPoolSnapshot;

        $inBand = function (float $d) use ($minDiff, $maxDiff) {
            return $d >= $minDiff - 1e-9 && $d <= $maxDiff + 1e-9;
        };
        $error = function (float $d) use ($minDiff, $maxDiff, $target) {
            if ($d < $minDiff) {
                return ($minDiff - $d) * 1000 + 100;
            }
            if ($d > $maxDiff) {
                return ($d - $maxDiff) * 1000 + 100;
            }
            return abs($d - $target);
        };

        for ($round = 0; $round < self::REPAIR_ROUNDS; $round++) {
            foreach ($papers as &$p) {
                self::recompute($p);
            }
            unset($p);

            $high = 0;
            $low = 0;
            foreach ($papers as $i => $p) {
                if ($p['difficulty'] > $papers[$high]['difficulty']) {
                    $high = $i;
                }
                if ($p['difficulty'] < $papers[$low]['difficulty']) {
                    $low = $i;
                }
            }

            $allIn = true;
            foreach ($papers as $p) {
                if (!$inBand($p['difficulty'])) {
                    $allIn = false;
                    break;
                }
            }
            if ($allIn && ($papers[$high]['difficulty'] - $papers[$low]['difficulty']) <= $fairRange + 1e-9) {
                break;
            }

            $moved = false;
            foreach ([$low => 'up', $high => 'down'] as $idx => $direction) {
                $cur = $papers[$idx]['difficulty'];
                $own = [];
                foreach ($papers[$idx]['entries'] as $e) {
                    $own[$e['question_id']] = true;
                }
                $bestEntry = -1;
                $bestQuestion = null;
                $bestGain = 1e-12;

                foreach ($papers[$idx]['entries'] as $ei => $e) {
                    if ($e['required'] || isset($requiredIds[$e['question_id']])) {
                        continue;
                    }
                    foreach ($cellPools[$e['slot']] ?? [] as $cand) {
                        if (isset($own[$cand['id']])) {
                            continue;
                        }
                        if ($direction === 'up' && (float) $cand['difficulty'] <= (float) $e['difficulty'] + 1e-12) {
                            continue;
                        }
                        if ($direction === 'down' && (float) $cand['difficulty'] >= (float) $e['difficulty'] - 1e-12) {
                            continue;
                        }
                        $newD = ($papers[$idx]['weighted_sum']
                                - ((float) $e['difficulty'] * (float) $e['score'])
                                + ((float) $cand['difficulty'] * (float) $e['score']))
                            / $papers[$idx]['total_score'];
                        $gain = $error($cur) - $error($newD);
                        if ($gain > $bestGain) {
                            $bestGain = $gain;
                            $bestEntry = $ei;
                            $bestQuestion = $cand;
                        }
                    }
                }

                if ($bestQuestion !== null) {
                    $kp = $papers[$idx]['entries'][$bestEntry]['kp'];
                    $type = $papers[$idx]['entries'][$bestEntry]['type'];
                    $oldId = $papers[$idx]['entries'][$bestEntry]['question_id'];
                    unset($own[$oldId]);
                    $papers[$idx]['entries'][$bestEntry] = self::entry($bestQuestion, $kp, $type, false);
                    $own[$bestQuestion['id']] = true;
                    $moved = true;
                }
            }

            if (!$moved) {
                break;
            }
        }

        foreach ($papers as &$p) {
            self::recompute($p);
            unset($p['weighted_sum']);
        }
        unset($p);
        return $papers;
    }

    protected static function dedupeWithinPaper(array $entries, array $pools, array $byId, SeededRandom $rng): array
    {
        $seen = [];
        $counts = [];
        foreach ($entries as $e) {
            $seen[$e['question_id']] = true;
            $counts[$e['question_id']] = ($counts[$e['question_id']] ?? 0) + 1;
        }
        foreach ($entries as $i => $e) {
            if ($e['required'] || $counts[$e['question_id']] <= 1) {
                continue;
            }
            $pool = $pools[$e['slot']] ?? [];
            $candidates = [];
            foreach ($pool as $q) {
                if (!isset($seen[$q['id']])) {
                    $candidates[] = $q;
                }
            }
            if (!empty($candidates)) {
                $pick = $candidates[$rng->below(count($candidates))];
                $oldId = $entries[$i]['question_id'];
                $entries[$i] = self::entry($pick, $e['kp'], $e['type'], false);
                $seen[$pick['id']] = true;
                $counts[$oldId]--;
                $counts[$pick['id']] = ($counts[$pick['id']] ?? 0) + 1;
            }
        }
        return $entries;
    }

    protected static function entry(array $q, string $kp, string $type, bool $required): array
    {
        return [
            'question_id' => $q['id'],
            'type' => $type,
            'kp' => $kp,
            'difficulty' => (float) $q['difficulty'],
            'score' => (float) ($q['score'] ?? 1),
            'required' => $required,
            'slot' => self::cellKey($kp, $type),
        ];
    }

    protected static function cellKey(string $kp, string $type): string
    {
        return $kp . "\0" . $type;
    }

    /** 计算整卷加权难度与总分（分值加权，更能反映真实卷面难度） */
    protected static function recompute(array &$paper): void
    {
        $sum = 0.0;
        $score = 0.0;
        foreach ($paper['entries'] as $e) {
            $sum += $e['difficulty'] * $e['score'];
            $score += $e['score'];
        }
        $paper['total_score'] = round($score, 2);
        $paper['weighted_sum'] = $sum;
        $paper['difficulty'] = $score > 0 ? $sum / $score : 0.0;
    }

    /** 终态：补齐统计字段与难度越界标记 */
    protected static function finalize(array $paper, array $cfg): array
    {
        self::recompute($paper);
        unset($paper['weighted_sum']);
        $paper['difficulty'] = round($paper['difficulty'], 4);
        $paper['question_count'] = count($paper['entries']);
        $paper['required_count'] = count(array_filter($paper['entries'], fn($e) => $e['required']));
        $paper['out_of_band'] = $paper['difficulty'] < (float) $cfg['difficulty_min'] - 1e-9
            || $paper['difficulty'] > (float) $cfg['difficulty_max'] + 1e-9;
        return $paper;
    }

    /** 全部试卷的横向统计，用于抽样检查与发布把关 */
    public static function statistics(array $papers, array $cfg): array
    {
        $diffs = array_map(fn($p) => $p['difficulty'], $papers);
        $mean = count($diffs) ? array_sum($diffs) / count($diffs) : 0.0;
        $variance = 0.0;
        foreach ($diffs as $d) {
            $variance += ($d - $mean) ** 2;
        }
        $variance = count($diffs) ? $variance / count($diffs) : 0.0;
        $outOfBand = array_values(array_filter($papers, fn($p) => $p['out_of_band']));

        // 题目使用次数（抽样检查：防某道题过度集中）
        $usage = [];
        foreach ($papers as $p) {
            foreach ($p['entries'] as $e) {
                $usage[$e['question_id']] = ($usage[$e['question_id']] ?? 0) + 1;
            }
        }
        $maxUsage = $usage ? max($usage) : 0;

        return [
            'student_count' => count($papers),
            'difficulty_min_observed' => $diffs ? round(min($diffs), 4) : null,
            'difficulty_max_observed' => $diffs ? round(max($diffs), 4) : null,
            'difficulty_mean' => round($mean, 4),
            'difficulty_range' => $diffs ? round(max($diffs) - min($diffs), 4) : 0,
            'difficulty_stddev' => round(sqrt($variance), 4),
            'out_of_band_count' => count($outOfBand),
            'out_of_band_students' => array_map(fn($p) => $p['student'], $outOfBand),
            'max_question_usage' => $maxUsage,
            'question_usage' => $usage,
            'fairness_range' => (float) ($cfg['fairness_range'] ?? 0.08),
            'fairness_pass' => count($outOfBand) === 0
                && $diffs
                && (max($diffs) - min($diffs)) <= (float) ($cfg['fairness_range'] ?? 0.08) + 1e-9,
        ];
    }
}
