-- ============================================
-- 随机组卷难度平衡功能 - 数据库迁移
-- 适用于已有数据库（docker-compose 的 db-init 已包含相同语句，全新部署无需执行）
-- 执行方式: mysql -h 127.0.0.1 -P 3307 -uroot -proot exam_system < scripts/migrations/2026_09_29_add_random_paper_tables.sql
-- ============================================
USE exam_system;

CREATE TABLE IF NOT EXISTS exam_paper_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL COMMENT '模板标题',
    description TEXT COMMENT '模板描述',
    category_ids JSON NOT NULL COMMENT '知识点(分类)范围',
    type_config JSON NOT NULL COMMENT '题型配置: [{type,count,score}]',
    difficulty_min TINYINT(1) DEFAULT 1 COMMENT '难度区间下限: 1-简单 2-中等 3-困难',
    difficulty_max TINYINT(1) DEFAULT 3 COMMENT '难度区间上限',
    target_difficulty DECIMAL(3,2) DEFAULT 2.00 COMMENT '目标加权平均难度',
    balance_tolerance DECIMAL(3,2) DEFAULT 0.30 COMMENT '难度平衡容差',
    mandatory_question_ids JSON COMMENT '必考题ID列表',
    question_count INT DEFAULT 0 COMMENT '每卷题数',
    total_score DECIMAL(6,2) DEFAULT 0.00 COMMENT '每卷总分',
    total_time INT DEFAULT 60 COMMENT '考试时长(分钟)',
    exam_paper_id BIGINT UNSIGNED NULL COMMENT '发布后关联的试卷ID',
    status ENUM('draft', 'published', 'closed') DEFAULT 'draft' COMMENT '状态: draft-草稿 published-已发布 closed-已下线',
    created_by BIGINT UNSIGNED COMMENT '创建人ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exam_paper_id (exam_paper_id),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='随机组卷模板表';

CREATE TABLE IF NOT EXISTS generated_papers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL COMMENT '组卷模板ID',
    exam_paper_id BIGINT UNSIGNED NOT NULL COMMENT '关联的试卷ID',
    user_id BIGINT UNSIGNED NOT NULL COMMENT '学生ID',
    seed BIGINT UNSIGNED NOT NULL COMMENT '随机种子(可复现)',
    difficulty_value DECIMAL(4,3) NOT NULL COMMENT '试卷加权平均难度',
    question_snapshot JSON NOT NULL COMMENT '题目快照: [{id,type,difficulty,score,sort}]',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_paper_user (exam_paper_id, user_id),
    INDEX idx_template_id (template_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='学生随机试卷表';
