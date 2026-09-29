<?php

namespace App\Services;

use App\Models\ExamPaper;
use App\Models\Question;
use App\Models\QuestionCategory;
use App\Models\RandomExamConfig;
use App\Models\RandomPaperInstance;
use App\Models\User;
use App\Services\ExamAssembly\Assembler;
use App\Services\ExamAssembly\Blueprint;
use App\Services\ExamAssembly\Exporter;
use Illuminate\Support\Facades\DB;

/**
 * 随机组卷应用服务：负责「数据库 ⇄ 组卷引擎」之间的转换与编排。
 *
 * 流程：
 *   validateConfig → loadQuestionPool → Blueprint 预检
 *     ├─ 有阻断问题：返回缺口清单，拒绝发布（明确告知缺哪类题）
 *     └─ 通过：Assembler 为每个学生生成试卷 → 存快照 → 导出抽样
 */
class ExamPaperAssemblyService
{
    /**
     * 校验并归一化前端传入的组卷配置
     * @return array{config:array, errors:array}
     */
    public function normalizeConfig(array $input): array
    {
        $errors = [];

        $questionCount = (int) ($input['question_count'] ?? 0);
        if ($questionCount <= 0) {
            $errors[] = ['field' => 'question_count', 'message' => '总题数必须大于 0'];
        }

        $dMin = (float) ($input['difficulty_min'] ?? 1);
        $dMax = (float) ($input['difficulty_max'] ?? 3);
        if ($dMin < 1 || $dMax > 3 || $dMin > $dMax) {
            $errors[] = ['field' => 'difficulty', 'message' => '难度区间需满足 1 ≤ 最小值 ≤ 最大值 ≤ 3'];
        }

        $typeRatio = $input['type_ratio'] ?? [];
        if (!is_array($typeRatio) || empty($typeRatio)) {
            $errors[] = ['field' => 'type_ratio', 'message' => '至少配置一种题型比例'];
        } else {
            $sum = 0.0;
            foreach ($typeRatio as $type => $ratio) {
                if (!in_array($type, Question::TYPES, true)) {
                    $errors[] = ['field' => "type_ratio.$type", 'message' => "未知题型：{$type}"];
                    continue;
                }
                $ratio = (float) $ratio;
                if ($ratio < 0) {
                    $errors[] = ['field' => "type_ratio.$type", 'message' => '题型比例不能为负'];
                }
                $typeRatio[$type] = $ratio;
                $sum += $ratio;
            }
            if (abs($sum - 1.0) > 0.01) {
                $errors[] = ['field' => 'type_ratio', 'message' => '题型比例之和需为 100%，当前为 ' . round($sum * 100, 1) . '%'];
            }
        }

        $kps = $input['knowledge_points'] ?? [];
        if (!is_array($kps) || empty($kps)) {
            $errors[] = ['field' => 'knowledge_points', 'message' => '至少选择一个知识点（题目分类）'];
        }

        $requiredIds = $input['required_question_ids'] ?? [];
        if (!is_array($requiredIds)) {
            $errors[] = ['field' => 'required_question_ids', 'message' => '必考题格式不正确'];
            $requiredIds = [];
        }

        $kpQuota = $input['knowledge_point_quota'] ?? null;
        if (is_array($kpQuota)) {
            foreach ($kpQuota as $k => $v) {
                $kpQuota[$k] = (int) $v;
            }
        }

        if (!empty($errors)) {
            return ['config' => [], 'errors' => $errors];
        }

        $config = [
            'title' => trim((string) ($input['title'] ?? '随机考试')),
            'description' => (string) ($input['description'] ?? ''),
            'total_time' => (int) ($input['total_time'] ?? 60),
            'question_count' => $questionCount,
            'difficulty_min' => $dMin,
            'difficulty_max' => $dMax,
            'fairness_range' => (float) ($input['fairness_range'] ?? 0.08),
            'knowledge_points' => array_values($kps),
            'knowledge_point_quota' => $kpQuota,
            'type_ratio' => $typeRatio,
            'required_question_ids' => array_values(array_map('intval', $requiredIds)),
            'unique_across_students' => (bool) ($input['unique_across_students'] ?? false),
            'student_count' => (int) ($input['student_count'] ?? 0),
        ];

        return ['config' => $config, 'errors' => []];
    }

    /**
     * 从题库加载候选题（限定知识点/启用状态），转为引擎数组
     */
    public function loadQuestionPool(array $config): array
    {
        $categoryIds = QuestionCategory::whereIn('name', $config['knowledge_points'])->pluck('id')->all();
        // 也允许直接传分类 id 名称为数字的情况（前端实际传名称）
        $questions = Question::where('status', 1)
            ->whereIn('category_id', $categoryIds)
            ->whereIn('type', array_keys($config['type_ratio']))
            ->get();

        $catNames = QuestionCategory::whereIn('id', $categoryIds)->pluck('name', 'id');

        return $questions->map(function (Question $q) use ($catNames) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'kp' => $catNames[$q->category_id] ?? ('分类#' . $q->category_id),
                'difficulty' => (float) $q->difficulty,
                'score' => (float) $q->score,
                'title' => $q->title,
            ];
        })->values()->all();
    }

    /**
     * 仅预检（发布前试算）：不落库，返回配额、问题、题量分布
     */
    public function preview(array $config, ?int $studentCount = null): array
    {
        if ($studentCount !== null) {
            $config['student_count'] = $studentCount;
        }
        if (empty($config['student_count'])) {
            // 不重题检查需要人数；普通缺口检查不需要
            $config['student_count'] = $this->estimateStudentCount();
        }

        $pool = $this->loadQuestionPool($config);
        $plan = Blueprint::plan($config, $pool);
        $errors = Blueprint::hasErrors($plan['issues']);

        return [
            'blocked' => $errors,
            'issues' => $plan['issues'],
            'matrix' => $this->matrixForDisplay($plan['matrix'], $pool),
            'pool_size' => count($pool),
            'student_count' => $config['student_count'],
        ];
    }

    /**
     * 正式发布：预检通过后为所有学生组卷并落库
     *
     * @param bool $force 是否允许在仍有越界卷时强制发布（缺口类阻断不受此参数影响）
     * @return array{ok:bool, blocked?:bool, issues?:array, out_of_band?:array, config?:RandomExamConfig, result?:array}
     */
    public function publish(array $config, int $creatorId, ?int $seed = null, bool $force = false): array
    {
        $students = User::where('role', 'student')->where('status', 1)->orderBy('id')->get();
        $config['student_count'] = $students->count();
        $seed = $seed ?? (int) (microtime(true) * 1000) % 2147483647;

        $pool = $this->loadQuestionPool($config);
        $plan = Blueprint::plan($config, $pool);

        if (Blueprint::hasErrors($plan['issues'])) {
            return ['ok' => false, 'blocked' => true, 'issues' => $plan['issues']];
        }

        $studentLabels = $students->map(fn($s) => 'S' . $s->id . ' ' . ($s->real_name ?: $s->username))->all();

        $result = Assembler::assemble($config, $pool, $plan, $studentLabels, $seed);

        // 题量足够但仍有越界卷（极端分值/必考导致）且未强制时，不落库直接返回
        if (!$force && $result['stats']['out_of_band_count'] > 0) {
            return [
                'ok' => false,
                'blocked' => true,
                'out_of_band' => $result['stats']['out_of_band_students'],
                'stats' => $result['stats'],
                'issues' => [],
            ];
        }

        return DB::transaction(function () use ($config, $creatorId, $seed, $students, $result, $plan) {
            // 配套试卷容器：type=random，用于复用考试记录/计时/评分/排名流程。
            // 题目不挂载到公共关联表（每人题目不同），由 instances 快照提供。
            $paper = ExamPaper::create([
                'title' => $config['title'],
                'description' => $config['description'] ?: '随机难度平衡考试（每位学生试卷不同）',
                'total_score' => $result['papers'][0]['total_score'] ?? 0,
                'total_time' => $config['total_time'],
                'question_count' => $config['question_count'],
                'type' => ExamPaper::TYPE_RANDOM,
                'created_by' => $creatorId,
                'status' => 1,
            ]);

            $row = RandomExamConfig::create([
                'title' => $config['title'],
                'description' => $config['description'],
                'exam_paper_id' => $paper->id,
                'total_time' => $config['total_time'],
                'config_json' => $config,
                'matrix_json' => $plan['matrix'],
                'seed' => $seed,
                'status' => 'published',
                'created_by' => $creatorId,
                'student_count' => $config['student_count'],
                'difficulty_mean' => $result['stats']['difficulty_mean'],
                'difficulty_range' => $result['stats']['difficulty_range'],
                'difficulty_stddev' => $result['stats']['difficulty_stddev'],
                'out_of_band_count' => $result['stats']['out_of_band_count'],
                'fairness_pass' => $result['stats']['fairness_pass'],
                'stats_json' => $result['stats'],
            ]);

            // 建立学生 id 与组卷标签的对应（标签形如 "S5 张三"）
            $instances = [];
            foreach ($students->values() as $i => $student) {
                $paper = $result['papers'][$i];
                $instances[] = [
                    'random_exam_config_id' => $row->id,
                    'user_id' => $student->id,
                    'question_ids_json' => array_map(fn($e) => $e['question_id'], $paper['entries']),
                    'entries_json' => $paper['entries'],
                    'difficulty' => $paper['difficulty'],
                    'total_score' => $paper['total_score'],
                    'out_of_band' => $paper['out_of_band'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            RandomPaperInstance::insert($instances);

            return ['ok' => true, 'config' => $row, 'result' => $result];
        });
    }

    /**
     * 为某个学生取出（或惰性生成）他的个性化试卷。
     * 发布时已预生成；此处直接读取快照，保证整场考试稳定不变。
     */
    public function getStudentPaper(RandomExamConfig $config, int $userId): ?RandomPaperInstance
    {
        return RandomPaperInstance::where('random_exam_config_id', $config->id)
            ->where('user_id', $userId)
            ->first();
    }

    /** 导出内容打包（供控制器下载） */
    public function buildExports(array $result, array $config, int $seed, array $issues = []): array
    {
        return [
            'papers_json' => Exporter::toPapersJson($result, $config, $seed),
            'sample_csv' => Exporter::toSampleCsv($result),
            'usage_csv' => Exporter::toUsageCsv($result),
            'report_md' => Exporter::toReportMarkdown($result, $config, $issues),
        ];
    }

    protected function estimateStudentCount(): int
    {
        return (int) User::where('role', 'student')->where('status', 1)->count();
    }

    /** 配额矩阵附带每格可用题量，前端展示「需要 X / 有 Y」 */
    protected function matrixForDisplay(array $matrix, array $pool): array
    {
        $avail = [];
        foreach ($pool as $q) {
            $key = $q['kp'] . "\0" . $q['type'];
            $avail[$key] = ($avail[$key] ?? 0) + 1;
        }
        $out = [];
        foreach ($matrix as $kp => $types) {
            foreach ($types as $type => $quota) {
                $have = $avail[$kp . "\0" . $type] ?? 0;
                $out[] = [
                    'knowledge_point' => $kp,
                    'type' => $type,
                    'need' => $quota,
                    'available' => $have,
                    'missing' => max(0, $quota - $have),
                ];
            }
        }
        return $out;
    }
}
