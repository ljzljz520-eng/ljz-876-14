<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamPaperTemplate;
use App\Models\GeneratedPaper;
use App\Models\Question;
use App\Services\PaperGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaperTemplateController extends Controller
{
    public function __construct(protected PaperGeneratorService $generator)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeManager($request);

        $query = ExamPaperTemplate::with('creator:id,username,real_name')
            ->withCount('generatedPapers');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('keyword')) {
            $query->where('title', 'like', '%' . $request->keyword . '%');
        }
        // 教师只能看自己创建的模板
        if ($request->user()->role !== 'admin') {
            $query->where('created_by', $request->user()->id);
        }

        $templates = $query->orderBy('id', 'desc')->paginate($request->input('per_page', 15));

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request)
    {
        $this->authorizeManager($request);

        $validator = $this->makeValidator($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $template = new ExamPaperTemplate($this->templateAttributes($request));
        $template->status = ExamPaperTemplate::STATUS_DRAFT;
        $template->created_by = $request->user()->id;
        $template->recalculateTotals();
        $template->save();

        return response()->json([
            'message' => '创建成功',
            'template' => $template,
        ], 201);
    }

    public function show(Request $request, ExamPaperTemplate $paperTemplate)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        $paperTemplate->load('creator:id,username,real_name');

        return response()->json(['template' => $paperTemplate]);
    }

    public function update(Request $request, ExamPaperTemplate $paperTemplate)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        if ($paperTemplate->status === ExamPaperTemplate::STATUS_PUBLISHED) {
            return response()->json(['message' => '模板已发布，请先下线再修改'], 422);
        }

        $validator = $this->makeValidator($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $paperTemplate->fill($this->templateAttributes($request));
        $paperTemplate->recalculateTotals();
        $paperTemplate->save();

        return response()->json([
            'message' => '更新成功',
            'template' => $paperTemplate,
        ]);
    }

    public function destroy(Request $request, ExamPaperTemplate $paperTemplate)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        if ($paperTemplate->status === ExamPaperTemplate::STATUS_PUBLISHED) {
            return response()->json(['message' => '模板已发布，请先下线再删除'], 422);
        }

        if ($paperTemplate->exam_paper_id) {
            ExamPaper::where('id', $paperTemplate->exam_paper_id)->delete();
        }
        $paperTemplate->generatedPapers()->delete();
        $paperTemplate->delete();

        return response()->json(['message' => '删除成功']);
    }

    /**
     * 发布前预检：报告缺哪类题、配置问题与模拟组卷结果。
     */
    public function precheck(Request $request, ExamPaperTemplate $paperTemplate)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        $report = $this->generator->precheck($paperTemplate);

        return response()->json($report);
    }

    /**
     * 发布：预检通过后生成对学生可见的考试试卷。
     */
    public function publish(Request $request, ExamPaperTemplate $paperTemplate)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        if ($paperTemplate->status === ExamPaperTemplate::STATUS_PUBLISHED) {
            return response()->json(['message' => '模板已是发布状态'], 422);
        }

        $report = $this->generator->precheck($paperTemplate);
        if (!$report['can_publish']) {
            return response()->json([
                'message' => '题库检查未通过，无法发布',
                'precheck' => $report,
            ], 422);
        }

        $paperTemplate->recalculateTotals();

        if ($paperTemplate->exam_paper_id && $paper = ExamPaper::find($paperTemplate->exam_paper_id)) {
            $paper->update([
                'title' => $paperTemplate->title,
                'description' => $paperTemplate->description,
                'total_score' => $paperTemplate->total_score,
                'total_time' => $paperTemplate->total_time,
                'question_count' => $paperTemplate->question_count,
                'type' => ExamPaper::TYPE_RANDOM,
                'status' => 1,
            ]);
        } else {
            $paper = ExamPaper::create([
                'title' => $paperTemplate->title,
                'description' => $paperTemplate->description,
                'total_score' => $paperTemplate->total_score,
                'total_time' => $paperTemplate->total_time,
                'question_count' => $paperTemplate->question_count,
                'type' => ExamPaper::TYPE_RANDOM,
                'created_by' => $request->user()->id,
                'status' => 1,
            ]);
            $paperTemplate->exam_paper_id = $paper->id;
        }

        $paperTemplate->status = ExamPaperTemplate::STATUS_PUBLISHED;
        $paperTemplate->save();

        return response()->json([
            'message' => '发布成功，学生现在可以参加该考试',
            'template' => $paperTemplate->fresh(),
            'precheck' => $report,
        ]);
    }

    /**
     * 下线：学生不可再进入考试，已生成的试卷保留供抽检。
     */
    public function unpublish(Request $request, ExamPaperTemplate $paperTemplate)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        if ($paperTemplate->status !== ExamPaperTemplate::STATUS_PUBLISHED) {
            return response()->json(['message' => '模板未处于发布状态'], 422);
        }

        if ($paperTemplate->exam_paper_id) {
            ExamPaper::where('id', $paperTemplate->exam_paper_id)->update(['status' => 0]);
        }
        $paperTemplate->status = ExamPaperTemplate::STATUS_CLOSED;
        $paperTemplate->save();

        return response()->json([
            'message' => '已下线',
            'template' => $paperTemplate,
        ]);
    }

    /**
     * 已生成试卷列表 + 难度平衡统计（抽样检查）。
     */
    public function generatedPapers(Request $request, ExamPaperTemplate $paperTemplate)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        $papers = GeneratedPaper::with('user:id,username,real_name')
            ->where('template_id', $paperTemplate->id)
            ->orderBy('id')
            ->paginate($request->input('per_page', 20));

        $target = (float) $paperTemplate->target_difficulty;
        $tolerance = (float) $paperTemplate->balance_tolerance;

        $papers->getCollection()->transform(function ($paper) use ($target, $tolerance) {
            $paper->is_outlier = abs((float) $paper->difficulty_value - $target) > $tolerance;
            return $paper;
        });

        return response()->json([
            'papers' => $papers,
            'report' => $this->generator->balanceReport($paperTemplate),
        ]);
    }

    /**
     * 查看某份生成试卷的完整题目（抽样检查详情）。
     */
    public function showGeneratedPaper(Request $request, ExamPaperTemplate $paperTemplate, GeneratedPaper $generatedPaper)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        if ($generatedPaper->template_id !== $paperTemplate->id) {
            return response()->json(['message' => '试卷不属于该模板'], 404);
        }

        $generatedPaper->load('user:id,username,real_name');

        $snapshot = collect($generatedPaper->question_snapshot ?? []);
        $questions = Question::whereIn('id', $snapshot->pluck('id'))->get()->keyBy('id');

        $items = $snapshot->map(function ($item) use ($questions) {
            $q = $questions->get($item['id']);
            return [
                'sort' => $item['sort'],
                'id' => $item['id'],
                'type' => $item['type'],
                'difficulty' => $item['difficulty'],
                'score' => $item['score'],
                'title' => $q?->title,
                'options' => $q?->options,
                'answer' => $q?->answer,
            ];
        });

        return response()->json([
            'paper' => $generatedPaper,
            'questions' => $items,
        ]);
    }

    /**
     * 导出抽样检查 CSV：汇总统计 + 每份试卷的难度与题目明细。
     */
    public function export(Request $request, ExamPaperTemplate $paperTemplate)
    {
        $this->authorizeManager($request);
        $this->authorizeOwner($request, $paperTemplate);

        $papers = GeneratedPaper::with('user:id,username,real_name')
            ->where('template_id', $paperTemplate->id)
            ->orderBy('id')
            ->get();

        $report = $this->generator->balanceReport($paperTemplate);
        $target = $report['target_difficulty'];
        $tolerance = $report['balance_tolerance'];

        $typeLabels = PaperGeneratorService::TYPE_LABELS;
        $difficultyLabels = Question::DIFFICULTIES;

        $filename = 'paper-template-' . $paperTemplate->id . '-sampling.csv';

        return response()->streamDownload(function () use ($papers, $report, $paperTemplate, $target, $tolerance, $typeLabels, $difficultyLabels) {
            $out = fopen('php://output', 'w');
            // BOM 便于 Excel 正确识别中文
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['随机组卷抽样检查报告']);
            fputcsv($out, ['模板', $paperTemplate->title]);
            fputcsv($out, ['目标难度', $target, '容差', '±' . $tolerance]);
            fputcsv($out, [
                '试卷数', $report['paper_count'],
                '平均难度', $report['difficulty_avg'],
                '最低难度', $report['difficulty_min'],
                '最高难度', $report['difficulty_max'],
                '标准差', $report['difficulty_stddev'],
                '异常卷数', $report['outlier_count'],
            ]);
            fputcsv($out, []);

            $header = ['学生ID', '学生账号', '学生姓名', '试卷ID', '难度值', '是否异常', '总分'];
            foreach ($difficultyLabels as $label) {
                $header[] = $label . '题数';
            }
            foreach ($typeLabels as $label) {
                $header[] = $label . '题数';
            }
            $header[] = '题目ID列表';
            fputcsv($out, $header);

            foreach ($papers as $paper) {
                $snapshot = collect($paper->question_snapshot ?? []);
                $isOutlier = abs((float) $paper->difficulty_value - $target) > $tolerance;

                $row = [
                    $paper->user_id,
                    $paper->user?->username,
                    $paper->user?->real_name,
                    $paper->id,
                    $paper->difficulty_value,
                    $isOutlier ? '异常' : '正常',
                    $snapshot->sum('score'),
                ];
                foreach (array_keys($difficultyLabels) as $d) {
                    $row[] = $snapshot->where('difficulty', $d)->count();
                }
                foreach (array_keys($typeLabels) as $t) {
                    $row[] = $snapshot->where('type', $t)->count();
                }
                $row[] = $snapshot->pluck('id')->implode(' ');
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function makeValidator(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'integer|exists:question_categories,id',
            'type_config' => 'required|array|min:1',
            'type_config.*.type' => 'required|in:' . implode(',', Question::TYPES),
            'type_config.*.count' => 'required|integer|min:0|max:200',
            'type_config.*.score' => 'required|numeric|min:0.5|max:100',
            'difficulty_min' => 'required|integer|in:1,2,3',
            'difficulty_max' => 'required|integer|in:1,2,3|gte:difficulty_min',
            'target_difficulty' => 'required|numeric|between:1,3',
            'balance_tolerance' => 'nullable|numeric|between:0.05,1',
            'mandatory_question_ids' => 'nullable|array',
            'mandatory_question_ids.*' => 'integer|exists:questions,id',
            'total_time' => 'nullable|integer|min:1|max:600',
        ], [
            'category_ids.required' => '请选择知识点范围',
            'type_config.required' => '请配置题型比例',
            'difficulty_max.gte' => '难度区间无效',
            'target_difficulty.between' => '目标难度需在 1-3 之间',
        ]);
    }

    protected function templateAttributes(Request $request): array
    {
        // 只保留题数大于 0 的题型配置
        $typeConfig = collect($request->input('type_config', []))
            ->filter(fn ($c) => (int) ($c['count'] ?? 0) > 0)
            ->map(fn ($c) => [
                'type' => $c['type'],
                'count' => (int) $c['count'],
                'score' => (float) $c['score'],
            ])
            ->values()
            ->all();

        return [
            'title' => $request->title,
            'description' => $request->description,
            'category_ids' => array_map('intval', $request->input('category_ids', [])),
            'type_config' => $typeConfig,
            'difficulty_min' => (int) $request->difficulty_min,
            'difficulty_max' => (int) $request->difficulty_max,
            'target_difficulty' => round((float) $request->target_difficulty, 2),
            'balance_tolerance' => round((float) ($request->balance_tolerance ?? 0.30), 2),
            'mandatory_question_ids' => array_map('intval', $request->input('mandatory_question_ids', [])),
            'total_time' => (int) ($request->total_time ?? 60),
        ];
    }

    protected function authorizeManager(Request $request): void
    {
        if (!in_array($request->user()->role, ['admin', 'teacher'])) {
            abort(response()->json(['message' => '无权限管理组卷模板'], 403));
        }
    }

    protected function authorizeOwner(Request $request, ExamPaperTemplate $template): void
    {
        $user = $request->user();
        if ($user->role !== 'admin' && $template->created_by !== $user->id) {
            abort(response()->json(['message' => '只能操作自己创建的组卷模板'], 403));
        }
    }
}
