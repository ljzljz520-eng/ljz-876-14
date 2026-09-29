<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionCategory;
use App\Models\RandomExamConfig;
use App\Models\RandomPaperInstance;
use App\Services\ExamPaperAssemblyService;
use App\Services\ExamAssembly\Exporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RandomExamController extends Controller
{
    public function __construct(private ExamPaperAssemblyService $service)
    {
    }

    /** 组卷配置页所需的题库元数据：知识点（分类）、各题型题量、题目简表 */
    public function meta()
    {
        $categories = QuestionCategory::where('status', 1)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'parent_id']);

        // 每个知识点 × 题型 × 难度 的可用题量
        $stock = Question::where('status', 1)
            ->selectRaw('category_id, type, difficulty, COUNT(*) AS cnt')
            ->groupBy('category_id', 'type', 'difficulty')
            ->get();

        $catNames = $categories->pluck('name', 'id');
        $stockMap = [];
        foreach ($stock as $row) {
            $kp = $catNames[$row->category_id] ?? ('分类#' . $row->category_id);
            $stockMap[$kp][$row->type][(int) $row->difficulty] = (int) $row->cnt;
        }

        $questions = Question::where('status', 1)
            ->orderBy('id')
            ->get(['id', 'category_id', 'type', 'title', 'difficulty', 'score'])
            ->map(fn($q) => [
                'id' => $q->id,
                'category_id' => $q->category_id,
                'knowledge_point' => $catNames[$q->category_id] ?? ('分类#' . $q->category_id),
                'type' => $q->type,
                'title' => mb_substr($q->title, 0, 40),
                'difficulty' => (float) $q->difficulty,
                'score' => (float) $q->score,
            ]);

        return response()->json([
            'categories' => $categories,
            'type_labels' => Question::TYPES,
            'difficulty_labels' => Question::DIFFICULTIES,
            'stock' => $stockMap,
            'questions' => $questions,
        ]);
    }

    /** 发布前预检：返回缺口清单与配额矩阵（不阻断页面，前端按 blocked 提示） */
    public function preview(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'teacher'], true)) {
            return response()->json(['error' => '无权限'], 403);
        }

        $norm = $this->service->normalizeConfig($request->all());
        if (!empty($norm['errors'])) {
            return response()->json(['errors' => $norm['errors']], 422);
        }

        $preview = $this->service->preview($norm['config']);

        return response()->json([
            'blocked' => $preview['blocked'],
            'issues' => $preview['issues'],
            'matrix' => $preview['matrix'],
            'pool_size' => $preview['pool_size'],
            'student_count' => $preview['student_count'],
        ]);
    }

    /** 正式发布：预检通过才组卷落库；否则 422 返回缺口 */
    public function publish(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'teacher'], true)) {
            return response()->json(['error' => '无权限发布考试'], 403);
        }

        $norm = $this->service->normalizeConfig($request->all());
        if (!empty($norm['errors'])) {
            return response()->json(['errors' => $norm['errors']], 422);
        }

        $force = $request->boolean('force'); // 仅越界但非缺口时，允许管理员强制发布
        $outcome = $this->service->publish(
            $norm['config'],
            $user->id,
            $request->input('seed') !== null ? (int) $request->input('seed') : null,
            $force
        );

        if (!$outcome['ok']) {
            // 缺口类阻断
            if (!empty($outcome['issues'])) {
                return response()->json([
                    'message' => '题库数量不足或难度区间不可达，已阻止发布，请按以下清单补充题目',
                    'blocked' => true,
                    'issues' => $outcome['issues'],
                ], 422);
            }
            // 组卷后仍有越界卷
            return response()->json([
                'message' => '组卷后仍有 ' . ($outcome['stats']['out_of_band_count'] ?? 0)
                    . ' 份试卷难度超出区间，建议调整必考题或区间；如确认发布请勾选强制。',
                'blocked' => true,
                'out_of_band_students' => $outcome['out_of_band'] ?? [],
                'stats' => $this->publicStats($outcome['stats'] ?? null),
            ], 422);
        }

        $stats = $outcome['result']['stats'];

        return response()->json([
            'message' => '随机考试发布成功，已为 ' . $stats['student_count'] . ' 名学生生成等难卷',
            'config' => $outcome['config'],
            'stats' => $this->publicStats($stats),
        ], 201);
    }

    /** 已发布随机考试列表 */
    public function index()
    {
        $configs = RandomExamConfig::with('creator')
            ->orderByDesc('id')
            ->paginate(15);
        return response()->json(['configs' => $configs]);
    }

    /** 某场考试的抽样检查数据（最难/最易/随机抽样逐题对比） */
    public function audit(RandomExamConfig $randomExam)
    {
        $papers = $randomExam->instances()->orderBy('difficulty')->get()->map(function ($ins) {
            return [
                'student' => 'S' . $ins->user_id,
                'user_id' => $ins->user_id,
                'difficulty' => $ins->difficulty,
                'total_score' => $ins->total_score,
                'out_of_band' => $ins->out_of_band,
                'entries' => $ins->entries_json,
            ];
        })->all();

        $result = ['papers' => $papers, 'stats' => $randomExam->stats_json];
        $sample = Exporter::pickSample($papers, 5);

        return response()->json([
            'config' => $randomExam,
            'stats' => $this->publicStats($randomExam->stats_json),
            'sample' => array_values($sample),
            'sample_tags' => array_keys($sample),
        ]);
    }

    /** 导出抽样 CSV */
    public function exportSample(RandomExamConfig $randomExam): StreamedResponse
    {
        $papers = $randomExam->instances()->orderBy('difficulty')->get()->map(fn($ins) => [
            'student' => 'S' . $ins->user_id,
            'difficulty' => $ins->difficulty,
            'total_score' => $ins->total_score,
            'out_of_band' => $ins->out_of_band,
            'entries' => $ins->entries_json,
        ])->all();
        $csv = Exporter::toSampleCsv(['papers' => $papers, 'stats' => $randomExam->stats_json]);

        return $this->csvResponse($csv, "random_exam_{$randomExam->id}_sample.csv");
    }

    /** 导出全部试卷 JSON */
    public function exportPapers(RandomExamConfig $randomExam): StreamedResponse
    {
        $papers = $randomExam->instances()->orderBy('user_id')->get()->map(fn($ins) => [
            'student' => 'S' . $ins->user_id,
            'difficulty' => $ins->difficulty,
            'total_score' => $ins->total_score,
            'out_of_band' => $ins->out_of_band,
            'entries' => $ins->entries_json,
        ])->all();
        $json = Exporter::toPapersJson(
            ['papers' => $papers, 'stats' => $randomExam->stats_json],
            $randomExam->config_json,
            (int) $randomExam->seed
        );

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, "random_exam_{$randomExam->id}_papers.json", [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    /** 导出 Markdown 均衡报告 */
    public function exportReport(RandomExamConfig $randomExam)
    {
        $papers = $randomExam->instances()->orderBy('difficulty')->get()->map(fn($ins) => [
            'student' => 'S' . $ins->user_id,
            'difficulty' => $ins->difficulty,
            'out_of_band' => $ins->out_of_band,
            'entries' => $ins->entries_json,
        ])->all();
        $md = Exporter::toReportMarkdown(
            ['papers' => $papers, 'stats' => $randomExam->stats_json],
            $randomExam->config_json
        );

        return response()->streamDownload(function () use ($md) {
            echo $md;
        }, "random_exam_{$randomExam->id}_report.md", [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    private function csvResponse(string $csv, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function publicStats(?array $stats): ?array
    {
        if (!$stats) {
            return null;
        }
        return [
            'student_count' => $stats['student_count'] ?? null,
            'difficulty_mean' => $stats['difficulty_mean'] ?? null,
            'difficulty_min_observed' => $stats['difficulty_min_observed'] ?? null,
            'difficulty_max_observed' => $stats['difficulty_max_observed'] ?? null,
            'difficulty_range' => $stats['difficulty_range'] ?? null,
            'difficulty_stddev' => $stats['difficulty_stddev'] ?? null,
            'out_of_band_count' => $stats['out_of_band_count'] ?? 0,
            'max_question_usage' => $stats['max_question_usage'] ?? null,
            'fairness_pass' => $stats['fairness_pass'] ?? false,
        ];
    }
}
