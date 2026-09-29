<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\Question;
use App\Models\RandomExamConfig;
use App\Models\RandomPaperInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
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

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => now(),
            'status' => 'in_progress',
        ]);

        $questionsData = $this->questionsForStudent($examPaper, $request->user()->id);

        if ($questionsData === null) {
            $record->delete();
            return response()->json(['message' => '未找到为你生成的随机试卷，请联系管理员'], 404);
        }

        return response()->json([
            'message' => '考试开始',
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
                'type' => $examPaper->type,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $questionsData = $this->questionsForStudent($examPaper, $request->user()->id);

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
                'type' => $examPaper->type,
            ],
            'questions' => $questionsData ?? [],
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

        $totalScore = 0;

        if ($examPaper->type === ExamPaper::TYPE_RANDOM) {
            // 随机卷：只允许提交自己快照中的题目，分值以快照为准
            $instance = RandomPaperInstance::whereHas('config', function ($q) use ($examPaper) {
                $q->where('exam_paper_id', $examPaper->id);
            })->where('user_id', $record->user_id)->firstOrFail();

            $allowedIds = $instance->question_ids_json;
            $entryMap = [];
            foreach ($instance->entries_json as $e) {
                $entryMap[$e['question_id']] = $e;
            }

            foreach ($request->answers as $answerData) {
                $qid = $answerData['question_id'];
                if (!in_array($qid, $allowedIds, true)) {
                    continue; // 防止学生提交不在自己试卷里的题
                }
                $question = Question::find($qid);
                if (!$question) {
                    continue;
                }
                $isCorrect = $this->checkAnswer($question, $answerData['answer']);
                $score = $isCorrect ? (float) ($entryMap[$qid]['score'] ?? $question->score) : 0;

                ExamRecordAnswer::create([
                    'exam_record_id' => $record->id,
                    'question_id' => $qid,
                    'answer' => $answerData['answer'],
                    'is_correct' => $isCorrect,
                    'score' => $score,
                ]);
                $totalScore += $score;
            }
        } else {
            $questionMap = $examPaper->questions->keyBy('id');

            foreach ($request->answers as $answerData) {
                $question = $questionMap->get($answerData['question_id']);
                if (!$question) {
                    continue;
                }

                $isCorrect = $this->checkAnswer($question, $answerData['answer']);
                $score = $isCorrect ? $question->pivot->score : 0;

                ExamRecordAnswer::create([
                    'exam_record_id' => $record->id,
                    'question_id' => $answerData['question_id'],
                    'answer' => $answerData['answer'],
                    'is_correct' => $isCorrect,
                    'score' => $score,
                ]);

                $totalScore += $score;
            }
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

        return response()->json([
            'record' => $record,
        ]);
    }

    /**
     * 取得某学生在某场考试中应看到的题目。
     * - 固定卷：走试卷-题目公共关联
     * - 随机卷：取该生的个性化快照（题目与分值均不同）
     *
     * @return array|null 题目数组；随机卷但找不到快照时返回 null
     */
    protected function questionsForStudent(ExamPaper $examPaper, int $userId): ?array
    {
        if ($examPaper->type !== ExamPaper::TYPE_RANDOM) {
            return $examPaper->questions()->get()->map(function ($q) {
                return [
                    'id' => $q->id,
                    'type' => $q->type,
                    'title' => $q->title,
                    'options' => $q->options,
                    'score' => (float) $q->pivot->score,
                ];
            })->all();
        }

        $config = RandomExamConfig::where('exam_paper_id', $examPaper->id)->first();
        if (!$config) {
            return null;
        }
        $instance = RandomPaperInstance::where('random_exam_config_id', $config->id)
            ->where('user_id', $userId)
            ->first();
        if (!$instance) {
            return null;
        }

        $questionById = Question::whereIn('id', $instance->question_ids_json)->get()->keyBy('id');
        $data = [];
        foreach ($instance->entries_json as $entry) {
            $q = $questionById->get($entry['question_id']);
            if (!$q) {
                continue;
            }
            $data[] = [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => (float) $entry['score'],
            ];
        }
        return $data;
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
