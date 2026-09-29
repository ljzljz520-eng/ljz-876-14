<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">随机平衡考试</h1>
      <router-link to="/random-exams/create"
        class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">创建随机考试</router-link>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="configs.length === 0" class="text-center py-12 text-gray-500 bg-white rounded-lg shadow">
      暂无随机考试，点击右上角创建。
    </div>

    <div v-else class="bg-white shadow overflow-hidden rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs text-gray-500">ID</th>
            <th class="px-4 py-3 text-left text-xs text-gray-500">标题</th>
            <th class="px-4 py-3 text-left text-xs text-gray-500">人数</th>
            <th class="px-4 py-3 text-left text-xs text-gray-500">难度均值</th>
            <th class="px-4 py-3 text-left text-xs text-gray-500">卷间极差</th>
            <th class="px-4 py-3 text-left text-xs text-gray-500">标准差</th>
            <th class="px-4 py-3 text-left text-xs text-gray-500">越界</th>
            <th class="px-4 py-3 text-left text-xs text-gray-500">公平性</th>
            <th class="px-4 py-3 text-left text-xs text-gray-500">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="c in configs" :key="c.id" class="hover:bg-gray-50">
            <td class="px-4 py-3">{{ c.id }}</td>
            <td class="px-4 py-3 font-medium">{{ c.title }}</td>
            <td class="px-4 py-3">{{ c.student_count }}</td>
            <td class="px-4 py-3">{{ c.difficulty_mean }}</td>
            <td class="px-4 py-3">{{ c.difficulty_range }}</td>
            <td class="px-4 py-3">{{ c.difficulty_stddev }}</td>
            <td class="px-4 py-3">
              <span :class="c.out_of_band_count ? 'text-red-600' : 'text-gray-400'">{{ c.out_of_band_count }}</span>
            </td>
            <td class="px-4 py-3">
              <span v-if="c.fairness_pass" class="text-green-600">✓ 通过</span>
              <span v-else class="text-red-600">✗ 未过</span>
            </td>
            <td class="px-4 py-3 space-x-3 whitespace-nowrap">
              <router-link :to="`/random-exams/${c.id}/audit`" class="text-indigo-600 hover:underline">抽样检查</router-link>
              <a :href="exportUrl(c.id,'sample')" class="text-green-600 hover:underline">抽样CSV</a>
              <a :href="exportUrl(c.id,'report')" class="text-gray-600 hover:underline">报告</a>
              <a :href="exportUrl(c.id,'papers')" class="text-gray-600 hover:underline">全部JSON</a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const configs = ref([])
const loading = ref(true)

const exportUrl = (id, kind) => {
  const base = api.defaults.baseURL
  return `${base}/random-exams/${id}/export/${kind}?token=${encodeURIComponent(localStorage.getItem('token') || '')}`
}

onMounted(async () => {
  try {
    const res = await api.get('/random-exams')
    configs.value = res.data.configs.data
  } finally {
    loading.value = false
  }
})
</script>
