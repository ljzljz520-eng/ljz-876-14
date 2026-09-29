<?php

namespace App\Services;

use App\Exceptions\PaperGenerationException;
use App\Models\ExamPaperTemplate;
use App\Models\GeneratedPaper;
use App\Models\Question;
use App\Models\QuestionCategory;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 随机组卷服务：按模板规则为每个学生生成不同试卷，并保持总体难度接近。
 *
 * 算法：
 * 1. 按知识点 + 难度区间过滤题库，按题型分组；
 * 2. 必考题固定入卷，剩余名额按题型配额用种子随机数抽取（可复现、可审计）；
 * 3. 计算试卷加权平均难度（按每题分值加权）；
 * 4. 若偏离目标难度超过容差，执行"换题修复"：把卷内偏离方向的题换成同题型、
 *    难度更有利于收敛的题库题，直至收敛或达到迭代上限；
 * 5. 预检 = 静态缺题检查 + 多次模拟组卷，发布前报告缺哪类题。
 */
class PaperGeneratorService
{
    public const MAX_REPAIR_ITERATIONS = 300;
    public const SIMULATION_RUNS = 30;
    public const VARIETY_WARNING_FACTOR = 2;

    public const TYPE_LABELS = [
        'single_choice' => '单选题',
        'multiple_choice' => '多选题',
        'true_false' => '判断题',
        'fill_blank' => '填空题',
        'essay' => '问答题',
    ];

    /**
     * 学生开考时调用：获取该学生已生成的试卷，没有则现场生成。
     */
    public function getOrGenerate(ExamPaperTemplate $template, int $userId): GeneratedPaper
    {
        $existing = GeneratedPaper::where('template_id', $template->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            // 模板重新发布后关联试卷可能变化，保持引用同步
            if ($existing->exam_paper_id !== $template->exam_paper_id) {
                $existing->exam_paper_id = $template->exam_paper_id;
                $existing->save();
            }
            return $existing;
        }

        $seed = random_int(1, PHP_INT_MAX);
        $result = $this->buildPaper($template, $seed);

        try {
            return GeneratedPaper::create([
                'template_id' => $template->id,
                'exam_paper_id' => $template->exam_paper_id,
                'user_id' => $userId,
                'seed' => $seed,
                'difficulty_value' => $result['difficulty_value'],
                'question_snapshot' => $result['questions'],
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // 并发重复请求：返回已生成的试卷
            return GeneratedPaper::where('template_id', $template->id)
                ->where('user_id', $userId)
                ->firstOrFail();
        }
    }

    /**
     * 核心组卷：用给定种子生成一份试卷。
     *
     * @return array{questions: array, difficulty_value: float}
     * @throws PaperGenerationException 题库不足或配置冲突时抛出，消息指明缺哪类题
     */
    public function buildPaper(ExamPaperTemplate $template, int $seed): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));
        $scoreByType = $template->scoreByType();

        $pool = $this->loadPool($template);
        $mandatory = $this->loadMandatoryQuestions($template);

        // 必考题固定入卷
        $selected = [];
        foreach ($mandatory as $q) {
            $selected[$q->id] = $q;
        }

        // 按题型配额抽取剩余题目
        foreach ($template->type_config ?? [] as $cfg) {
            $type = $cfg['type'];
            $required = (int) $cfg['count'];
            if ($required <= 0) {
                continue;
            }

            $mandatoryOfType = $mandatory->where('type', $type)->count();
            $need = $required - $mandatoryOfType;
            if ($need < 0) {
                throw new PaperGenerationException(
                    sprintf('必考题中%s数量（%d）超过题型配额（%d），请调整题型比例或必考题',
                        self::TYPE_LABELS[$type] ?? $type, $mandatoryOfType, $required),
                    ['kind' => 'config', 'type' => $type]
                );
            }
            if ($need === 0) {
                continue;
            }

            $candidates = collect($pool[$type] ?? [])
                ->reject(fn ($q) => isset($selected[$q->id]))
                ->values();

            if ($candidates->count() < $need) {
                throw new PaperGenerationException(
                    $this->shortageMessage($template, $type, $need, $candidates->count()),
                    ['kind' => 'shortage', 'type' => $type, 'required' => $need, 'available' => $candidates->count()]
                );
            }

            $shuffled = $randomizer->shuffleArray($candidates->all());
            foreach (array_slice($shuffled, 0, $need) as $q) {
                $selected[$q->id] = $q;
            }
        }

        // 难度平衡修复
        [$selected, $difficulty] = $this->repairDifficulty($template, $selected, $pool, $randomizer);

        // 组装快照（按题型配置顺序、题号排序）
        $snapshot = $this->buildSnapshot($template, $selected, $scoreByType);

        return [
            'questions' => $snapshot,
            'difficulty_value' => $difficulty,
        ];
    }

    /**
     * 发布前预检：静态缺题检查 + 模拟组卷。
     * 返回结构化报告，errors 非空则不允许发布。
     */
    public function precheck(ExamPaperTemplate $template): array
    {
        $errors = [];
        $warnings = [];

        // ---- 基础配置检查 ----
        $categoryIds = $template->category_ids ?? [];
        if (empty($categoryIds)) {
            $errors[] = ['kind' => 'config', 'message' => '未选择知识点范围'];
        }
        $typeConfig = collect($template->type_config ?? [])->filter(fn ($c) => (int) $c['count'] > 0)->values();
        if ($typeConfig->isEmpty()) {
            $errors[] = ['kind' => 'config', 'message' => '题型比例未配置：至少需要一种题型的题数大于 0'];
        }
        if ($template->difficulty_min > $template->difficulty_max) {
            $errors[] = ['kind' => 'config', 'message' => '难度区间无效：最低难度不能大于最高难度'];
        }

        $categoryNames = QuestionCategory::whereIn('id', $categoryIds)->pluck('name')->implode('、');

        // ---- 必考题检查 ----
        $mandatoryIds = $template->mandatory_question_ids ?? [];
        $mandatory = $mandatoryIds ? Question::whereIn('id', $mandatoryIds)->get() : collect();
        foreach ($mandatoryIds as $id) {
            $q = $mandatory->firstWhere('id', $id);
            if (!$q) {
                $errors[] = ['kind' => 'mandatory', 'message' => "必考题 #{$id} 不存在"];
                continue;
            }
            if (!$q->status) {
                $errors[] = ['kind' => 'mandatory', 'message' => "必考题 #{$id} 已停用：{$this->clip($q->title)}"];
            }
            if (!in_array($q->category_id, $categoryIds)) {
                $warnings[] = ['kind' => 'mandatory', 'message' => "必考题 #{$id} 不属于所选知识点范围：{$this->clip($q->title)}"];
            }
            if ($q->difficulty < $template->difficulty_min || $q->difficulty > $template->difficulty_max) {
                $warnings[] = ['kind' => 'mandatory', 'message' => "必考题 #{$id} 难度（" . Question::DIFFICULTIES[$q->difficulty] . "）超出难度区间：{$this->clip($q->title)}"];
            }
        }
        // 必考题数量是否超过题型配额
        foreach ($typeConfig as $cfg) {
            $type = $cfg['type'];
            $mandatoryOfType = $mandatory->where('type', $type)->count();
            if ($mandatoryOfType > (int) $cfg['count']) {
                $errors[] = ['kind' => 'config', 'message' => sprintf(
                    '必考题中%s数量（%d）超过题型配额（%d）',
                    self::TYPE_LABELS[$type] ?? $type, $mandatoryOfType, (int) $cfg['count']
                )];
            }
        }
        // 必考题的题型没有对应配额
        foreach ($mandatory->groupBy('type') as $type => $group) {
            $cfg = $typeConfig->firstWhere('type', $type);
            if (!$cfg) {
                $errors[] = ['kind' => 'config', 'message' => sprintf(
                    '必考题包含 %d 道%s，但题型比例中未配置该题型',
                    $group->count(), self::TYPE_LABELS[$type] ?? $type
                )];
            }
        }

        // ---- 题库静态缺题检查（按题型） ----
        $pool = empty($categoryIds) ? collect() : $this->loadPool($template);
        $perTypeStats = [];
        foreach ($typeConfig as $cfg) {
            $type = $cfg['type'];
            $required = (int) $cfg['count'];
            $available = count($pool[$type] ?? []);
            $mandatoryOfType = $mandatory->where('type', $type)->count();
            $need = max(0, $required - $mandatoryOfType);
            $availableExcludingMandatory = collect($pool[$type] ?? [])
                ->reject(fn ($q) => in_array($q->id, $mandatoryIds))->count();

            $perTypeStats[] = [
                'type' => $type,
                'type_label' => self::TYPE_LABELS[$type] ?? $type,
                'required_per_paper' => $required,
                'available' => $available,
            ];

            if ($availableExcludingMandatory < $need) {
                $errors[] = [
                    'kind' => 'shortage',
                    'type' => $type,
                    'required' => $need,
                    'available' => $availableExcludingMandatory,
                    'message' => $this->shortageMessage($template, $type, $need, $availableExcludingMandatory, $categoryNames),
                ];
            } elseif ($availableExcludingMandatory < $need * self::VARIETY_WARNING_FACTOR) {
                $warnings[] = [
                    'kind' => 'variety',
                    'type' => $type,
                    'message' => sprintf('%s可用题仅 %d 道（每卷需 %d 道），不同学生的试卷可能高度雷同',
                        self::TYPE_LABELS[$type] ?? $type, $availableExcludingMandatory, $need),
                ];
            }

            // 难度层空洞提示：某难度层在区间内但没有题，会限制平衡空间
            for ($d = $template->difficulty_min; $d <= $template->difficulty_max; $d++) {
                $countAtDifficulty = collect($pool[$type] ?? [])->where('difficulty', $d)->count();
                if ($countAtDifficulty === 0) {
                    $warnings[] = [
                        'kind' => 'difficulty_hole',
                        'type' => $type,
                        'difficulty' => $d,
                        'message' => sprintf('%s在难度「%s」没有题目，难度平衡空间受限',
                            self::TYPE_LABELS[$type] ?? $type, Question::DIFFICULTIES[$d] ?? $d),
                    ];
                }
            }
        }

        // ---- 模拟组卷 ----
        $simulation = ['runs' => 0, 'success' => 0, 'failed' => 0, 'failure_messages' => []];
        if (empty($errors)) {
            $simulation['runs'] = self::SIMULATION_RUNS;
            $difficulties = [];
            for ($i = 0; $i < self::SIMULATION_RUNS; $i++) {
                try {
                    $result = $this->buildPaper($template, random_int(1, PHP_INT_MAX));
                    $simulation['success']++;
                    $difficulties[] = $result['difficulty_value'];
                } catch (PaperGenerationException $e) {
                    $simulation['failed']++;
                    $simulation['failure_messages'][] = $e->getMessage();
                }
            }
            $simulation['failure_messages'] = array_values(array_unique($simulation['failure_messages']));
            if ($simulation['failed'] > 0) {
                foreach ($simulation['failure_messages'] as $msg) {
                    $errors[] = ['kind' => 'simulation', 'message' => "模拟组卷失败：{$msg}"];
                }
            }
            if (!empty($difficulties)) {
                $target = (float) $template->target_difficulty;
                $tolerance = (float) $template->balance_tolerance;
                $outOfRange = count(array_filter($difficulties, fn ($d) => abs($d - $target) > $tolerance));
                $simulation['difficulty_min'] = round(min($difficulties), 3);
                $simulation['difficulty_max'] = round(max($difficulties), 3);
                $simulation['difficulty_avg'] = round(array_sum($difficulties) / count($difficulties), 3);
                if ($outOfRange > 0) {
                    $warnings[] = [
                        'kind' => 'balance',
                        'message' => "模拟组卷有 {$outOfRange}/" . count($difficulties) . " 份超出难度容差（目标 {$target}±{$tolerance}），建议放宽容差或补充题库",
                    ];
                }
            }
        }

        return [
            'can_publish' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'simulation' => $simulation,
            'per_type_stats' => $perTypeStats,
        ];
    }

    /**
     * 已生成试卷的难度平衡统计（用于抽样检查页面）。
     */
    public function balanceReport(ExamPaperTemplate $template): array
    {
        $papers = $template->generatedPapers()->with('user:id,username,real_name')->get();
        $target = (float) $template->target_difficulty;
        $tolerance = (float) $template->balance_tolerance;

        $values = $papers->map(fn ($p) => (float) $p->difficulty_value);
        $count = $values->count();

        $avg = $count ? $values->avg() : 0;
        $variance = $count ? $values->map(fn ($v) => ($v - $avg) ** 2)->sum() / $count : 0;

        return [
            'target_difficulty' => $target,
            'balance_tolerance' => $tolerance,
            'paper_count' => $count,
            'difficulty_avg' => $count ? round($avg, 3) : null,
            'difficulty_min' => $count ? round($values->min(), 3) : null,
            'difficulty_max' => $count ? round($values->max(), 3) : null,
            'difficulty_stddev' => $count ? round(sqrt($variance), 3) : null,
            'outlier_count' => $values->filter(fn ($v) => abs($v - $target) > $tolerance)->count(),
        ];
    }

    /**
     * 加载题库池：知识点范围内、难度区间内、启用状态，按题型分组。
     */
    protected function loadPool(ExamPaperTemplate $template): \Illuminate\Support\Collection
    {
        return Question::where('status', 1)
            ->whereIn('category_id', $template->category_ids ?? [])
            ->whereBetween('difficulty', [$template->difficulty_min, $template->difficulty_max])
            ->get(['id', 'category_id', 'type', 'difficulty', 'score'])
            ->groupBy('type');
    }

    /**
     * 加载并校验必考题。
     */
    protected function loadMandatoryQuestions(ExamPaperTemplate $template): \Illuminate\Support\Collection
    {
        $ids = $template->mandatory_question_ids ?? [];
        if (empty($ids)) {
            return collect();
        }

        $questions = Question::whereIn('id', $ids)->get();
        foreach ($ids as $id) {
            $q = $questions->firstWhere('id', $id);
            if (!$q) {
                throw new PaperGenerationException("必考题 #{$id} 不存在", ['kind' => 'mandatory', 'question_id' => $id]);
            }
            if (!$q->status) {
                throw new PaperGenerationException("必考题 #{$id} 已停用：{$this->clip($q->title)}", ['kind' => 'mandatory', 'question_id' => $id]);
            }
        }
        return $questions;
    }

    /**
     * 难度平衡修复：试卷加权难度偏离目标时，用题库中更合适的题替换卷内题。
     *
     * @param array $selected 已选题目（id => Question）
     * @return array{0: array, 1: float} 修复后的题目集合与最终难度值
     */
    protected function repairDifficulty(ExamPaperTemplate $template, array $selected, $pool, Randomizer $randomizer): array
    {
        $target = (float) $template->target_difficulty;
        $tolerance = (float) $template->balance_tolerance;
        $scoreByType = $template->scoreByType();
        $mandatoryIds = $template->mandatory_question_ids ?? [];

        $difficulty = $this->weightedDifficulty($selected, $scoreByType);
        $iterations = 0;

        while (abs($difficulty - $target) > $tolerance && $iterations < self::MAX_REPAIR_ITERATIONS) {
            $iterations++;
            $needLower = $difficulty > $target;

            // 卷内可交换的题（非必考），按"最偏离目标"优先
            $swappable = collect($selected)
                ->reject(fn ($q) => in_array($q->id, $mandatoryIds))
                ->filter(fn ($q) => $needLower ? $q->difficulty > $template->difficulty_min : $q->difficulty < $template->difficulty_max);
            $swappable = $needLower ? $swappable->sortByDesc('difficulty') : $swappable->sortBy('difficulty');

            $swapped = false;
            foreach ($swappable as $current) {
                $candidates = collect($pool[$current->type] ?? [])
                    ->reject(fn ($q) => isset($selected[$q->id]))
                    ->filter(fn ($q) => $needLower
                        ? $q->difficulty < $current->difficulty
                        : $q->difficulty > $current->difficulty)
                    ->values();

                if ($candidates->isEmpty()) {
                    continue;
                }

                $replacement = $randomizer->shuffleArray($candidates->all())[0];
                unset($selected[$current->id]);
                $selected[$replacement->id] = $replacement;
                $swapped = true;
                break;
            }

            if (!$swapped) {
                break;
            }
            $difficulty = $this->weightedDifficulty($selected, $scoreByType);
        }

        return [$selected, $difficulty];
    }

    /**
     * 试卷加权平均难度：Σ(难度 × 分值) / Σ(分值)。
     */
    protected function weightedDifficulty(array $selected, array $scoreByType): float
    {
        $totalScore = 0.0;
        $weighted = 0.0;
        foreach ($selected as $q) {
            $score = $scoreByType[$q->type] ?? (float) $q->score;
            $totalScore += $score;
            $weighted += $q->difficulty * $score;
        }
        return $totalScore > 0 ? round($weighted / $totalScore, 3) : 0.0;
    }

    /**
     * 组装题目快照：按题型配置顺序排列，记录每题分值。
     */
    protected function buildSnapshot(ExamPaperTemplate $template, array $selected, array $scoreByType): array
    {
        $typeOrder = [];
        foreach ($template->type_config ?? [] as $cfg) {
            $typeOrder[$cfg['type']] = count($typeOrder);
        }

        $questions = collect($selected)->values()->sortBy([
            fn ($a, $b) => ($typeOrder[$a->type] ?? 99) <=> ($typeOrder[$b->type] ?? 99),
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();

        $snapshot = [];
        foreach ($questions as $index => $q) {
            $snapshot[] = [
                'id' => $q->id,
                'type' => $q->type,
                'difficulty' => $q->difficulty,
                'score' => $scoreByType[$q->type] ?? (float) $q->score,
                'sort' => $index + 1,
            ];
        }
        return $snapshot;
    }

    protected function shortageMessage(ExamPaperTemplate $template, string $type, int $need, int $available, ?string $categoryNames = null): string
    {
        $categoryNames ??= QuestionCategory::whereIn('id', $template->category_ids ?? [])->pluck('name')->implode('、');
        return sprintf(
            '%s不足：每卷需要 %d 道，题库中仅有 %d 道（缺 %d 道）。范围：知识点[%s]，难度 %s-%s',
            self::TYPE_LABELS[$type] ?? $type,
            $need,
            $available,
            max(0, $need - $available),
            $categoryNames ?: '未设置',
            Question::DIFFICULTIES[$template->difficulty_min] ?? $template->difficulty_min,
            Question::DIFFICULTIES[$template->difficulty_max] ?? $template->difficulty_max
        );
    }

    protected function clip(string $text, int $length = 30): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }
}
