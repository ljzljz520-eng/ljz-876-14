<template>
  <div class="space-y-6" v-if="data">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">抽样检查 - {{ data.config.title }}</h1>
        <p class="text-sm text-gray-500 mt-1">随机种子 {{ data.config.seed }}，共 {{ data.stats.student_count }} 份等难卷</p>
      </div>
      <div class="flex gap-2">
        <a :href="url('sample')" class="px-3 py-2 border border-green-600 text-green-700 rounded text-sm hover:bg-green-50">导出抽样CSV</a>
        <a :href="url('report')" class="px-3 py-2 border rounded text-sm">导出报告</a>
        <a :href="url('papers')" class="px-3 py-2 border rounded text-sm">导出全部JSON</a>
      </div>
    </div>

    <!-- 均衡指标 -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs text-gray-500">平均难度</p>
        <p class="text-2xl font-semibold mt-1">{{ data.stats.difficulty_mean }}</p>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs text-gray-500">卷间难度极差</p>
        <p class="text-2xl font-semibold mt-1" :class="data.stats.difficulty_range <= 0.08 ? 'text-green-600' : 'text-red-600'">
          {{ data.stats.difficulty_range }}
        </p>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs text-gray-500">难度标准差</p>
        <p class="text-2xl font-semibold mt-1">{{ data.stats.difficulty_stddev }}</p>
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs text-gray-500">越界试卷</p>
        <p class="text-2xl font-semibold mt-1" :class="data.stats.out_of_band_count ? 'text-red-600' : 'text-green-600'">
          {{ data.stats.out_of_band_count }}
        </p>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
      <div class="flex items-center gap-3">
        <span class="text-sm font-medium">区间：</span>
        <span>[{{ data.config.config_json.difficulty_min }}, {{ data.config.config_json.difficulty_max }}]</span>
        <span class="ml-4 text-sm">实际：</span>
        <span class="text-red-500">最易 {{ data.stats.difficulty_min_observed }}</span>
        <span>~</span>
        <span class="text-green-600">最难 {{ data.stats.difficulty_max_observed }}</span>
        <span v-if="data.stats.fairness_pass" class="ml-auto text-green-600 text-sm font-medium">✓ 公平性检查通过，没有学生拿到明显更简单的试卷</span>
        <span v-else class="ml-auto text-red-600 text-sm font-medium">✗ 公平性未达标，请查看越界学生</span>
      </div>
    </div>

    <!-- 抽样逐题对比 -->
    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
      <h2 class="font-semibold mb-3">抽样试卷逐题难度对比（最难卷 / 最易卷 / 随机抽样）</h2>
      <table class="min-w-full text-xs">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-2 py-2 text-left">抽样</th>
            <th class="px-2 py-2 text-left">学生</th>
            <th class="px-2 py-2 text-right">整卷难度</th>
            <th class="px-2 py-2 text-left">每题难度（●易 ●中 ●难）</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="(p, idx) in data.sample" :key="idx">
            <td class="px-2 py-2 whitespace-nowrap">{{ data.sample_tags[idx] }}</td>
            <td class="px-2 py-2 whitespace-nowrap">{{ p.student }}</td>
            <td class="px-2 py-2 text-right font-semibold" :class="p.out_of_band ? 'text-red-600' : ''">{{ p.difficulty }}</td>
            <td class="px-2 py-2">
              <div class="flex flex-wrap gap-1">
                <span v-for="(e, i) in p.entries" :key="i"
                  class="inline-flex items-center justify-center w-6 h-6 rounded text-white text-[10px]"
                  :title="`${e.kp} ${e.type} ${e.score}分` + (e.required ? ' 必考' : '')"
                  :class="e.difficulty <= 1.5 ? 'bg-green-400' : e.difficulty <= 2.5 ? 'bg-yellow-400' : 'bg-red-400'">
                  {{ e.required ? '必' : Math.round(e.difficulty * 10) / 10 }}
                </span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="text-xs text-gray-400 mt-3">色块：绿=简单(1)，黄=中等(2)，红=困难(3)；数字为难度，"必"为必考题。悬停可看知识点/题型/分值。</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'

const route = useRoute()
const data = ref(null)

const url = (kind) =>
  `${api.defaults.baseURL}/random-exams/${route.params.id}/export/${kind}?token=${encodeURIComponent(localStorage.getItem('token') || '')}`

onMounted(async () => {
  const res = await api.get(`/random-exams/${route.params.id}/audit`)
  data.value = res.data
})
</script>
