<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 随机考试配置（一场难度平衡的随机考试对应一行）
 */
class RandomExamConfig extends Model
{
    protected $table = 'random_exam_configs';

    protected $fillable = [
        'title',
        'description',
        'exam_paper_id',
        'total_time',
        'config_json',
        'matrix_json',
        'seed',
        'status',
        'created_by',
        'student_count',
        'difficulty_mean',
        'difficulty_range',
        'difficulty_stddev',
        'out_of_band_count',
        'fairness_pass',
        'stats_json',
    ];

    protected $casts = [
        'config_json' => 'array',
        'matrix_json' => 'array',
        'stats_json' => 'array',
        'seed' => 'integer',
        'exam_paper_id' => 'integer',
        'total_time' => 'integer',
        'student_count' => 'integer',
        'difficulty_mean' => 'float',
        'difficulty_range' => 'float',
        'difficulty_stddev' => 'float',
        'out_of_band_count' => 'integer',
        'fairness_pass' => 'boolean',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function instances()
    {
        return $this->hasMany(RandomPaperInstance::class, 'random_exam_config_id');
    }
}
