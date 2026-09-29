<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('random_exam_configs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 200)->comment('考试标题');
            $table->text('description')->nullable()->comment('考试描述');
            $table->unsignedBigInteger('exam_paper_id')->nullable()->comment('配套试卷容器ID(复用考试/评分流程)');
            $table->integer('total_time')->default(60)->comment('考试时长(分钟)');
            $table->json('config_json')->comment('组卷规则:知识点/题型比例/难度区间/必考题等');
            $table->json('matrix_json')->nullable()->comment('知识点x题型配额矩阵');
            $table->unsignedBigInteger('seed')->comment('随机种子(可复现)');
            $table->string('status', 20)->default('published')->comment('draft/published');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人');
            $table->integer('student_count')->default(0)->comment('参考学生数');
            $table->decimal('difficulty_mean', 5, 4)->nullable()->comment('全卷难度均值');
            $table->decimal('difficulty_range', 5, 4)->nullable()->comment('卷间难度极差');
            $table->decimal('difficulty_stddev', 5, 4)->nullable()->comment('难度标准差');
            $table->integer('out_of_band_count')->default(0)->comment('难度越界试卷数');
            $table->boolean('fairness_pass')->default(false)->comment('是否通过均衡性把关');
            $table->json('stats_json')->nullable()->comment('完整统计快照');
            $table->timestamps();

            $table->index('exam_paper_id');
            $table->index('created_by');
            $table->index('status');
        });

        Schema::create('random_paper_instances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('random_exam_config_id')->comment('随机考试配置ID');
            $table->unsignedBigInteger('user_id')->comment('学生ID');
            $table->json('question_ids_json')->comment('该生试卷题目ID有序列表');
            $table->json('entries_json')->comment('题目明细(含分值/难度/必考标记)');
            $table->decimal('difficulty', 5, 4)->comment('该生整卷加权难度');
            $table->decimal('total_score', 8, 2)->comment('该生试卷总分');
            $table->boolean('out_of_band')->default(false)->comment('难度是否越出目标区间');
            $table->timestamps();

            $table->unique(['random_exam_config_id', 'user_id'], 'uk_random_exam_user');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('random_paper_instances');
        Schema::dropIfExists('random_exam_configs');
    }
};
