<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamPaperTemplate extends Model
{
    protected $fillable = [
        'title',
        'description',
        'category_ids',
        'type_config',
        'difficulty_min',
        'difficulty_max',
        'target_difficulty',
        'balance_tolerance',
        'mandatory_question_ids',
        'question_count',
        'total_score',
        'total_time',
        'exam_paper_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'category_ids' => 'array',
        'type_config' => 'array',
        'mandatory_question_ids' => 'array',
        'difficulty_min' => 'integer',
        'difficulty_max' => 'integer',
        'target_difficulty' => 'decimal:2',
        'balance_tolerance' => 'decimal:2',
        'question_count' => 'integer',
        'total_score' => 'decimal:2',
        'total_time' => 'integer',
        'exam_paper_id' => 'integer',
        'created_by' => 'integer',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_PUBLISHED => '已发布',
        self::STATUS_CLOSED => '已下线',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function generatedPapers()
    {
        return $this->hasMany(GeneratedPaper::class, 'template_id');
    }

    /**
     * 题型每题分值映射: ['single_choice' => 2.0, ...]
     */
    public function scoreByType(): array
    {
        $map = [];
        foreach ($this->type_config ?? [] as $cfg) {
            $map[$cfg['type']] = (float) $cfg['score'];
        }
        return $map;
    }

    /**
     * 重新计算总题数与总分（基于题型配置）
     */
    public function recalculateTotals(): void
    {
        $count = 0;
        $score = 0;
        foreach ($this->type_config ?? [] as $cfg) {
            $count += (int) $cfg['count'];
            $score += (int) $cfg['count'] * (float) $cfg['score'];
        }
        $this->question_count = $count;
        $this->total_score = $score;
    }
}
