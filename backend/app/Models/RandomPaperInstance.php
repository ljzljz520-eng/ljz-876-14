<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 学生个性化试卷快照：每个学生一场随机考试一行
 */
class RandomPaperInstance extends Model
{
    protected $table = 'random_paper_instances';

    protected $fillable = [
        'random_exam_config_id',
        'user_id',
        'question_ids_json',
        'entries_json',
        'difficulty',
        'total_score',
        'out_of_band',
    ];

    protected $casts = [
        'question_ids_json' => 'array',
        'entries_json' => 'array',
        'difficulty' => 'float',
        'total_score' => 'float',
        'out_of_band' => 'boolean',
    ];

    public function config()
    {
        return $this->belongsTo(RandomExamConfig::class, 'random_exam_config_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
