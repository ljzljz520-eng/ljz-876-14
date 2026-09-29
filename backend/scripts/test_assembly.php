<?php
/**
 * 随机组卷引擎离线自测（无需 Laravel / 数据库，直接 php scripts/test_assembly.php）
 *
 * 覆盖：最大余数分配、配额守恒、缺口预检、难度可达性、必考题、
 *       跨学生不重题、卷间难度极差/标准差、同卷不重题、可复现、导出产物。
 */

require __DIR__ . '/../app/Services/ExamAssembly/LargestRemainder.php';
require __DIR__ . '/../app/Services/ExamAssembly/SeededRandom.php';
require __DIR__ . '/../app/Services/ExamAssembly/Blueprint.php';
require __DIR__ . '/../app/Services/ExamAssembly/Assembler.php';
require __DIR__ . '/../app/Services/ExamAssembly/Exporter.php';

use App\Services\ExamAssembly\Assembler;
use App\Services\ExamAssembly\Blueprint;
use App\Services\ExamAssembly\Exporter;
use App\Services\ExamAssembly\LargestRemainder;

$failures = 0;
function check(bool $cond, string $msg): void
{
    global $failures;
    if ($cond) {
        echo "  ✓ {$msg}\n";
    } else {
        echo "  ✗ FAIL: {$msg}\n";
        $failures++;
    }
}

// 题库：3 知识点 × 5 题型 × 每格 30 题，难度 1/1/2/2/3/3 循环
$typeScore = [
    'single_choice' => 2, 'multiple_choice' => 3, 'true_false' => 1,
    'fill_blank' => 2, 'essay' => 5,
];
$questions = [];
$id = 1;
foreach (['操作系统', '数据结构', '数据库'] as $kp) {
    foreach (array_keys($typeScore) as $type) {
        for ($i = 0; $i < 30; $i++) {
            $diff = in_array($i % 6, [0, 1], true) ? 1 : (in_array($i % 6, [2, 3], true) ? 2 : 3);
            $questions[] = ['id' => $id++, 'type' => $type, 'kp' => $kp, 'difficulty' => $diff, 'score' => $typeScore[$type]];
        }
    }
}

$cfg = [
    'question_count' => 30,
    'difficulty_min' => 1.9,
    'difficulty_max' => 2.1,
    'fairness_range' => 0.08,
    'knowledge_points' => ['操作系统', '数据结构', '数据库'],
    'type_ratio' => [
        'single_choice' => 0.4, 'multiple_choice' => 0.2, 'true_false' => 0.1,
        'fill_blank' => 0.1, 'essay' => 0.2,
    ],
    'required_question_ids' => [],
    'unique_across_students' => false,
    'student_count' => 20,
];

echo "[1] 最大余数法\n";
$a = LargestRemainder::allocate(10, ['a' => 0.5, 'b' => 0.3, 'c' => 0.2]);
check(array_sum($a) === 10 && $a === ['a' => 5, 'b' => 3, 'c' => 2], '按比例精确分配且总和守恒');

echo "[2] 充足题库预检\n";
$plan = Blueprint::plan($cfg, $questions);
check(!Blueprint::hasErrors($plan['issues']), '无阻断问题');
$sum = 0;
foreach ($plan['matrix'] as $ts) {
    foreach ($ts as $q) {
        $sum += $q;
    }
}
check($sum === 30, '配额矩阵总和=总题数(30)');

echo "[3] 组卷与难度均衡\n";
$students = array_map(fn($i) => "S{$i}", range(1, 20));
$r = Assembler::assemble($cfg, $questions, $plan, $students, 20260929);
$s = $r['stats'];
echo "   均值={$s['difficulty_mean']} 极差={$s['difficulty_range']} 标准差={$s['difficulty_stddev']} 越界={$s['out_of_band_count']}\n";
check(count($r['papers']) === 20, '生成 20 份卷');
check($s['out_of_band_count'] === 0, '无难度越界卷');
check($s['difficulty_range'] <= 0.08, "卷间极差≤0.08（{$s['difficulty_range']}）");
foreach ($r['papers'] as $p) {
    $ids = array_column($p['entries'], 'question_id');
    check(count($p['entries']) === 30, "{$p['student']} 30 题");
    check(count($ids) === count(array_unique($ids)), "{$p['student']} 同卷不重题");
}

echo "[4] 可复现\n";
$r2 = Assembler::assemble($cfg, $questions, $plan, $students, 20260929);
check(json_encode($r['papers']) === json_encode($r2['papers']), '相同种子生成完全相同试卷');

echo "[5] 缺题预检\n";
$small = array_values(array_filter($questions, fn($q) => !($q['kp'] === '数据库' && $q['type'] === 'single_choice' && $q['id'] % 7 !== 0)));
$planSmall = Blueprint::plan($cfg, $small);
check(Blueprint::hasErrors($planSmall['issues']), '题量不足被拦截');
check((function () use ($planSmall) {
    foreach ($planSmall['issues'] as $i) {
        if ($i['code'] === Blueprint::SHORTAGE) {
            return true;
        }
    }
    return false;
})(), '明确返回 SHORTAGE 缺口（缺哪类题）');

echo "[6] 必考题\n";
$reqId = null;
foreach ($questions as $q) {
    if ($q['kp'] === '数据库' && $q['type'] === 'essay') {
        $reqId = $q['id'];
        break;
    }
}
$cfgReq = $cfg;
$cfgReq['required_question_ids'] = [$reqId];
$planReq = Blueprint::plan($cfgReq, $questions);
check(!Blueprint::hasErrors($planReq['issues']), '含必考题可组卷');
$rReq = Assembler::assemble($cfgReq, $questions, $planReq, $students, 7);
$allHave = true;
foreach ($rReq['papers'] as $p) {
    $found = false;
    foreach ($p['entries'] as $e) {
        if ($e['question_id'] === $reqId && $e['required']) {
            $found = true;
        }
    }
    if (!$found) {
        $allHave = false;
    }
}
check($allHave, "每份卷都含必考题 #{$reqId} 且标记必考");
check($rReq['stats']['difficulty_range'] <= 0.08, '含必考题仍保持均衡');

echo "[7] 跨学生不重题\n";
$cfgU = $cfg;
$cfgU['unique_across_students'] = true;
$planU = Blueprint::plan($cfgU, $questions);
check(!Blueprint::hasErrors($planU['issues']), 'unique 题量充足时通过');
$rU = Assembler::assemble($cfgU, $questions, $planU, $students, 8);
$seen = [];
$collision = false;
foreach ($rU['papers'] as $p) {
    foreach ($p['entries'] as $e) {
        if (isset($seen[$e['question_id']])) {
            $collision = true;
        }
        $seen[$e['question_id']] = true;
    }
}
check(!$collision, 'unique 模式跨学生无重复题');
$cfgU2 = $cfg;
$cfgU2['unique_across_students'] = true;
$cfgU2['student_count'] = 50;
$planU2 = Blueprint::plan($cfgU2, $questions);
check(Blueprint::hasErrors($planU2['issues']), 'unique 题量不足被拦截');

echo "[8] 导出产物\n";
check(str_contains(Exporter::toSampleCsv($r), '最难卷') && str_contains(Exporter::toSampleCsv($r), '最易卷'), '抽样 CSV 含最难/最易卷');
check(str_contains(Exporter::toReportMarkdown($r, $cfg), '难度标准差'), 'Markdown 报告含统计');
check(json_decode(Exporter::toPapersJson($r, $cfg, 20260929), true)['stats']['student_count'] === 20, 'papers.json 结构完整');

echo "\n";
if ($failures > 0) {
    echo "❌ {$failures} 项断言失败\n";
    exit(1);
}
echo "✅ 全部引擎自测通过\n";
