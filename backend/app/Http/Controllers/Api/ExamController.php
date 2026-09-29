<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaperGenerationException;
use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamPaperTemplate;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\GeneratedPaper;
use App\Models\Question;
use App\Services\PaperGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    public function __construct(protected PaperGeneratorService $paperGenerator)
    {
    }

    public function index(Request $request)
    {
        $examPapers = ExamPaper::with('creator')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function start(Request $request, ExamPaper $examPaper)
    {
        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->first();

        if ($existingRecord) {
            return response()->json([
                'message' => '您已经开始这场考试',
                'exam_record' => $existingRecord,
            ]);
        }

        // 随机组卷：为当前学生生成（或复用）专属试卷
        $generated = null;
        if ($examPaper->type === ExamPaper::TYPE_RANDOM) {
            $generated = $this->generatedPaperFor($examPaper, $request->user()->id);
            if ($generated instanceof \Illuminate\Http\JsonResponse) {
                return $generated;
            }
        }

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => now(),
            'status' => 'in_progress',
        ]);

        return response()->json([
            'message' => '考试开始',
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $this->questionsForStudent($examPaper, $request->user()->id),
        ]);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $this->questionsForStudent($examPaper, $request->user()->id),
        ]);
    }

    public function submit(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        // 题目与分值：固定卷取自试卷关联，随机卷取自该学生的生成快照
        if ($examPaper->type === ExamPaper::TYPE_RANDOM) {
            $generated = GeneratedPaper::where('exam_paper_id', $examPaper->id)
                ->where('user_id', $request->user()->id)
                ->first();
            if (!$generated) {
                return response()->json(['message' => '未找到您的随机试卷，请重新开始考试'], 404);
            }
            $snapshot = collect($generated->question_snapshot ?? []);
            $questionMap = Question::whereIn('id', $snapshot->pluck('id'))->get()->keyBy('id');
            $scoreMap = $snapshot->keyBy('id')->map(fn ($item) => (float) $item['score']);
        } else {
            $questionMap = $examPaper->questions->keyBy('id');
            $scoreMap = $examPaper->questions->keyBy('id')->map(fn ($q) => (float) $q->pivot->score);
        }

        $totalScore = 0;

        foreach ($request->answers as $answerData) {
            $question = $questionMap->get($answerData['question_id']);
            if (!$question || !isset($scoreMap[$answerData['question_id']])) {
                continue;
            }

            $isCorrect = $this->checkAnswer($question, $answerData['answer']);
            $score = $isCorrect ? $scoreMap[$answerData['question_id']] : 0;

            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $answerData['question_id'],
                'answer' => $answerData['answer'],
                'is_correct' => $isCorrect,
                'score' => $score,
            ]);

            $totalScore += $score;
        }

        $record->update([
            'end_time' => now(),
            'score' => $totalScore,
            'status' => 'graded',
        ]);

        return response()->json([
            'message' => '提交成功',
            'score' => $totalScore,
            'exam_record' => $record->load('answers'),
        ]);
    }

    public function myRecords(Request $request)
    {
        $records = ExamRecord::with('examPaper')
            ->where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'records' => $records,
        ]);
    }

    public function showRecord(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $record->load(['examPaper.questions', 'answers.question']);

        // 随机卷：把该学生实际抽到的题目挂到 examPaper.questions 上，便于前端展示
        $examPaper = $record->examPaper;
        if ($examPaper && $examPaper->type === ExamPaper::TYPE_RANDOM) {
            $generated = GeneratedPaper::where('exam_paper_id', $examPaper->id)
                ->where('user_id', $record->user_id)
                ->first();
            if ($generated) {
                $snapshot = collect($generated->question_snapshot ?? []);
                $questions = Question::whereIn('id', $snapshot->pluck('id'))->get()->keyBy('id');
                $ordered = $snapshot->map(function ($item) use ($questions) {
                    $q = $questions->get($item['id']);
                    if ($q) {
                        $q->setRelation('pivot', (object) ['score' => $item['score'], 'sort_order' => $item['sort']]);
                    }
                    return $q;
                })->filter()->values();
                $examPaper->setRelation('questions', $ordered);
            }
        }

        return response()->json([
            'record' => $record,
        ]);
    }

    /**
     * 学生本场考试使用的题目（不含答案）。
     */
    protected function questionsForStudent(ExamPaper $examPaper, int $userId): \Illuminate\Support\Collection
    {
        if ($examPaper->type === ExamPaper::TYPE_RANDOM) {
            $generated = GeneratedPaper::where('exam_paper_id', $examPaper->id)
                ->where('user_id', $userId)
                ->first();
            if (!$generated) {
                return collect();
            }
            $snapshot = collect($generated->question_snapshot ?? []);
            $questions = Question::whereIn('id', $snapshot->pluck('id'))->get()->keyBy('id');

            return $snapshot->map(function ($item) use ($questions) {
                $q = $questions->get($item['id']);
                if (!$q) {
                    return null;
                }
                return [
                    'id' => $q->id,
                    'type' => $q->type,
                    'title' => $q->title,
                    'options' => $q->options,
                    'score' => $item['score'],
                ];
            })->filter()->values();
        }

        return $examPaper->questions()->get()->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });
    }

    /**
     * 为随机卷生成（或复用）学生专属试卷；失败时返回 JSON 错误响应。
     */
    protected function generatedPaperFor(ExamPaper $examPaper, int $userId): GeneratedPaper|\Illuminate\Http\JsonResponse
    {
        $template = ExamPaperTemplate::where('exam_paper_id', $examPaper->id)
            ->where('status', ExamPaperTemplate::STATUS_PUBLISHED)
            ->first();

        if (!$template) {
            return response()->json(['message' => '该考试的组卷配置不存在或已下线'], 404);
        }

        try {
            return $this->paperGenerator->getOrGenerate($template, $userId);
        } catch (PaperGenerationException $e) {
            return response()->json([
                'message' => '组卷失败：' . $e->getMessage(),
                'details' => $e->getDetails(),
            ], 422);
        }
    }

    protected function checkAnswer(Question $question, string $userAnswer): bool
    {
        $correctAnswer = $question->answer;

        switch ($question->type) {
            case 'single_choice':
            case 'true_false':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            case 'multiple_choice':
                $userAnswers = explode(',', strtoupper(trim($userAnswer)));
                $correctAnswers = explode(',', strtoupper(trim($correctAnswer)));
                sort($userAnswers);
                sort($correctAnswers);
                return $userAnswers === $correctAnswers;
            case 'fill_blank':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            default:
                return false;
        }
    }
}
