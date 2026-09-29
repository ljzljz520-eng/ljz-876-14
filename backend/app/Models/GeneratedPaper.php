<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedPaper extends Model
{
    protected $fillable = [
        'template_id',
        'exam_paper_id',
        'user_id',
        'seed',
        'difficulty_value',
        'question_snapshot',
    ];

    protected $casts = [
        'template_id' => 'integer',
        'exam_paper_id' => 'integer',
        'user_id' => 'integer',
        'seed' => 'integer',
        'difficulty_value' => 'decimal:3',
        'question_snapshot' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(ExamPaperTemplate::class, 'template_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
