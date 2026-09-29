<?php

namespace App\Services\ExamAssembly;

/**
 * 组卷结果导出与抽样检查
 *
 * 提供三类产物，发布前用于人工复核，避免某个学生拿到明显更简单的一套：
 *  - papers.json   全部学生试卷明细（审计/存档/复现）
 *  - sample.csv    抽样试卷：固定抽样 + 最难卷 + 最易卷，逐题对照难度
 *  - report.md     均衡性汇总报告：难度均值/极差/标准差、越界卷、题目复用
 */
class Exporter
{
    /**
     * 固定抽样大小（再额外加入最难/最易各一套）
     */
    public const DEFAULT_SAMPLE = 5;

    public static function toPapersJson(array $result, array $cfg, int $seed): string
    {
        $payload = [
            'generated_at' => date('c'),
            'seed' => $seed,
            'config' => [
                'title' => $cfg['title'] ?? '',
                'question_count' => $cfg['question_count'],
                'difficulty_min' => $cfg['difficulty_min'],
                'difficulty_max' => $cfg['difficulty_max'],
                'fairness_range' => $cfg['fairness_range'] ?? 0.08,
                'knowledge_points' => $cfg['knowledge_points'],
                'type_ratio' => $cfg['type_ratio'],
                'required_question_ids' => $cfg['required_question_ids'] ?? [],
                'unique_across_students' => $cfg['unique_across_students'] ?? false,
            ],
            'statistics' => $result['stats'],
            'papers' => $result['papers'],
        ];
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 抽样 CSV：每份被抽中的试卷占多行（每题一行），
     * 最前两列是难度最高/最低卷与若干均匀抽样，便于横向比对。
     */
    public static function toSampleCsv(array $result, int $sampleSize = self::DEFAULT_SAMPLE, ?int $seed = null): string
    {
        $papers = $result['papers'];
        $chosen = self::pickSample($papers, $sampleSize, $seed);

        $fh = fopen('php://temp', 'r+');
        // BOM 让 Excel 正确识别 UTF-8 中文
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['抽样类型', '学生', '整卷难度', '是否越界', '题号', '题型', '知识点', '题目难度', '分值', '是否必考']);
        foreach ($chosen as $tag => $paper) {
            foreach ($paper['entries'] as $i => $e) {
                fputcsv($fh, [
                    $tag,
                    $paper['student'],
                    $paper['difficulty'],
                    $paper['out_of_band'] ? '是' : '否',
                    $i + 1,
                    $e['type'],
                    $e['kp'],
                    $e['difficulty'],
                    $e['score'],
                    $e['required'] ? '必考' : '',
                ]);
            }
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * 题目复用 CSV：question_id, 使用次数（检查是否某题被过度使用）
     */
    public static function toUsageCsv(array $result): string
    {
        $usage = $result['stats']['question_usage'] ?? [];
        arsort($usage);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['题目ID', '被使用次数']);
        foreach ($usage as $qid => $cnt) {
            fputcsv($fh, [$qid, $cnt]);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * Markdown 汇总报告
     */
    public static function toReportMarkdown(array $result, array $cfg, array $issues = []): string
    {
        $s = $result['stats'];
        $lines = [];
        $lines[] = '# 随机组卷难度均衡报告';
        $lines[] = '';
        $lines[] = '## 组卷规则';
        $lines[] = '- 总题数：' . ($cfg['question_count'] ?? '-');
        $lines[] = '- 目标难度区间：[' . ($cfg['difficulty_min'] ?? '-') . ', ' . ($cfg['difficulty_max'] ?? '-') . ']';
        $lines[] = '- 公平阈值（卷间难度极差）：≤ ' . ($cfg['fairness_range'] ?? 0.08);
        $lines[] = '- 知识点：' . implode('、', $cfg['knowledge_points'] ?? []);
        $ratioText = [];
        foreach (($cfg['type_ratio'] ?? []) as $t => $r) {
            $ratioText[] = "{$t} " . round($r * 100, 1) . '%';
        }
        $lines[] = '- 题型比例：' . implode('，', $ratioText);
        $lines[] = '- 跨学生不重题：' . (empty($cfg['unique_across_students']) ? '否（允许复用，同卷内不重）' : '是');
        $lines[] = '- 学生人数：' . $s['student_count'];
        $lines[] = '';
        $lines[] = '## 难度均衡结果';
        $lines[] = '| 指标 | 数值 |';
        $lines[] = '|---|---|';
        $lines[] = '| 平均难度 | ' . $s['difficulty_mean'] . ' |';
        $lines[] = '| 最高难度卷 | ' . $s['difficulty_max_observed'] . ' |';
        $lines[] = '| 最低难度卷 | ' . $s['difficulty_min_observed'] . ' |';
        $lines[] = '| 卷间难度极差 | ' . $s['difficulty_range'] . ' |';
        $lines[] = '| 难度标准差 | ' . $s['difficulty_stddev'] . ' |';
        $lines[] = '| 越界试卷数 | ' . $s['out_of_band_count'] . ' |';
        $lines[] = '| 单题最大使用次数 | ' . $s['max_question_usage'] . ' |';
        $lines[] = '| 公平性判定 | ' . ($s['fairness_pass'] ? '✅ 通过' : '❌ 未通过') . ' |';
        $lines[] = '';

        if (!empty($s['out_of_band_students'])) {
            $lines[] = '## ⚠️ 难度越界的试卷';
            foreach ($s['out_of_band_students'] as $stu) {
                $lines[] = "- 学生 {$stu}";
            }
            $lines[] = '';
        }

        $warnings = array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'warning');
        if (!empty($warnings)) {
            $lines[] = '## 警告（不阻断发布）';
            foreach ($warnings as $w) {
                $lines[] = '- ' . $w['message'];
            }
            $lines[] = '';
        }

        $lines[] = '## 抽样建议';
        $lines[] = '请打开 sample.csv，重点核对「最难卷」与「最易卷」每题难度分布；';
        $lines[] = '若两者难度差仍在阈值内且各自落在目标区间，则可发布。';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * 选择抽样：均匀抽取若干份 + 最难 + 最易
     * @return array<string,array> tag => paper
     */
    public static function pickSample(array $papers, int $sampleSize, ?int $seed = null): array
    {
        if (empty($papers)) {
            return [];
        }
        $chosen = [];

        // 最难 / 最易
        $high = $papers[0];
        $low = $papers[0];
        foreach ($papers as $p) {
            if ($p['difficulty'] > $high['difficulty']) {
                $high = $p;
            }
            if ($p['difficulty'] < $low['difficulty']) {
                $low = $p;
            }
        }
        $chosen['最难卷'] = $high;
        if ($low !== $high) {
            $chosen['最易卷'] = $low;
        }

        // 等距抽样：在全部试卷里均匀取 sampleSize 个，避免人为挑题偏差
        $n = count($papers);
        $sampleSize = max(0, min($sampleSize, $n));
        for ($k = 0; $k < $sampleSize; $k++) {
            $idx = (int) floor(($k + 0.5) * $n / $sampleSize);
            $idx = min($idx, $n - 1);
            $p = $papers[$idx];
            $duplicate = false;
            foreach ($chosen as $c) {
                if ($c['student'] === $p['student']) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $chosen['随机抽样' . ($k + 1)] = $p;
            }
        }

        return $chosen;
    }
}
