<template>
  <div class="space-y-6 max-w-5xl mx-auto">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">创建随机平衡考试</h1>
      <router-link to="/exam-papers" class="text-indigo-600 hover:underline text-sm">返回试卷管理</router-link>
    </div>

    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      <p class="mt-2 text-gray-500">正在加载题库...</p>
    </div>

    <template v-else>
      <section class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-800 mb-4">1. 基础信息</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">考试标题</label>
            <input v-model="form.title" class="w-full border rounded px-3 py-2" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">时长(分钟)</label>
            <input v-model.number="form.total_time" type="number" min="1" class="w-full border rounded px-3 py-2" />
          </div>
          <div class="md:col-span-3">
            <label class="block text-sm font-medium text-gray-700 mb-1">说明</label>
            <input v-model="form.description" class="w-full border rounded px-3 py-2" />
          </div>
        </div>
      </section>

      <section class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-800 mb-4">2. 知识点与总题数</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
          <label v-for="cat in categories" :key="cat.id"
            class="flex items-center gap-2 border rounded px-3 py-2 cursor-pointer hover:bg-indigo-50"
            :class="{ 'bg-indigo-50 border-indigo-400': form.knowledge_points.includes(cat.name) }">
            <input type="checkbox" :value="cat.name" v-model="form.knowledge_points" class="accent-indigo-600" />
            <span class="text-sm">{{ cat.name }}</span>
          </label>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">每份试卷总题数</label>
            <input v-model.number="form.question_count" type="number" min="1" class="w-full border rounded px-3 py-2" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">卷间难度允许极差</label>
            <input v-model.number="form.fairness_range" type="number" step="0.01" min="0.01" max="1" class="w-full border rounded px-3 py-2" />
          </div>
        </div>
        <div v-if="form.knowledge_points.length" class="mt-4">
          <p class="text-sm text-gray-600 mb-2">每个知识点题数（可调整，留空则平均分配）</p>
          <div class="flex flex-wrap gap-3">
            <div v-for="kp in form.knowledge_points" :key="kp" class="flex items-center gap-2">
              <span class="text-sm text-gray-600">{{ kp }}</span>
              <input v-model.number="kpQuota[kp]" type="number" min="0" class="w-20 border rounded px-2 py-1 text-sm" />
            </div>
          </div>
        </div>
      </section>

      <section class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-800 mb-4">3. 题型比例</h2>
        <div class="space-y-3">
          <div v-for="t in usedTypes" :key="t.key" class="flex items-center gap-3">
            <input type="checkbox" :value="t.key" v-model="enabledTypes" class="accent-indigo-600" />
            <span class="w-20 text-sm">{{ t.label }}</span>
            <input v-model.number="ratioPct[t.key]" type="number" min="0" max="100" step="5"
              :disabled="!enabledTypes.includes(t.key)"
              class="w-24 border rounded px-2 py-1 text-sm disabled:bg-gray-100" />
            <span class="text-sm text-gray-400">%</span>
            <div class="flex-1 h-2 bg-gray-100 rounded overflow-hidden">
              <div class="h-full bg-indigo-500" :style="{ width: (enabledTypes.includes(t.key) ? ratioPct[t.key] : 0) + '%' }"></div>
            </div>
          </div>
        </div>
        <p class="mt-3 text-sm" :class="Math.abs(ratioSum - 100) <= 1 ? 'text-green-600' : 'text-red-600'">
          合计 {{ ratioSum }}%（需为 100%）
        </p>
      </section>

      <section class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-800 mb-4">4. 目标难度区间</h2>
        <div class="flex items-center gap-4">
          <span class="text-sm text-gray-600">最易</span>
          <input v-model.number="form.difficulty_min" type="number" step="0.1" min="1" max="3" class="w-20 border rounded px-2 py-1" />
          <input type="range" min="1" max="3" step="0.05" :value="form.difficulty_min"
            @input="form.difficulty_min = Math.min(Number($event.target.value), form.difficulty_max)"
            class="flex-1 accent-indigo-600" />
          <input type="range" min="1" max="3" step="0.05" :value="form.difficulty_max"
            @input="form.difficulty_max = Math.max(Number($event.target.value), form.difficulty_min)"
            class="flex-1 accent-indigo-600" />
          <input v-model.number="form.difficulty_max" type="number" step="0.1" min="1" max="3" class="w-20 border rounded px-2 py-1" />
          <span class="text-sm text-gray-600">最难</span>
        </div>
        <p class="text-xs text-gray-400 mt-2">难度 1=简单，2=中等，3=困难；系统按每题分值加权计算整卷难度。</p>
      </section>

      <section class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-800 mb-4">5. 必考题与题量策略</h2>
        <label class="flex items-start gap-2 mb-4">
          <input type="checkbox" v-model="form.unique_across_students" class="accent-indigo-600 mt-1" />
          <span class="text-sm text-gray-700">跨学生不重题（题量要求更高；不勾选则允许跨学生复用，但同一学生卷内不重题）</span>
        </label>
        <p class="text-sm text-gray-600 mb-2">必考题（每位学生都会考到）</p>
        <div class="flex gap-2 mb-3">
          <select v-model="requiredFilter.kp" class="border rounded px-2 py-1 text-sm">
            <option value="">全部知识点</option>
            <option v-for="kp in form.knowledge_points" :key="kp" :value="kp">{{ kp }}</option>
          </select>
          <select v-model="requiredFilter.type" class="border rounded px-2 py-1 text-sm">
            <option value="">全部题型</option>
            <option v-for="t in usedTypes" :key="t.key" :value="t.key">{{ t.label }}</option>
          </select>
        </div>
        <div class="max-h-56 overflow-y-auto border rounded divide-y">
          <label v-for="q in filteredRequiredChoices" :key="q.id"
            class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" :value="q.id" v-model="form.required_question_ids" class="accent-indigo-600" />
            <span class="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-700">{{ typeLabel(q.type) }}</span>
            <span class="text-xs px-2 py-0.5 rounded" :class="diffClass(q.difficulty)">难度{{ q.difficulty }}</span>
            <span class="text-xs text-orange-600">{{ q.score }}分</span>
            <span class="text-sm text-gray-700 truncate flex-1">{{ q.title }}</span>
          </label>
          <p v-if="filteredRequiredChoices.length === 0" class="p-4 text-sm text-gray-400 text-center">请先选择知识点和题型</p>
        </div>
        <p v-if="form.required_question_ids.length" class="text-xs text-gray-500 mt-2">已选必考 {{ form.required_question_ids.length }} 题</p>
      </section>

      <section class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-gray-800">发布前预检</h2>
          <button @click="runPreview" :disabled="checking"
            class="px-4 py-2 border border-indigo-600 text-indigo-600 rounded hover:bg-indigo-50 text-sm disabled:opacity-50">
            {{ checking ? '检查中...' : '重新检查题库' }}
          </button>
        </div>

        <p v-if="!preview" class="text-sm text-gray-400">填写规则后点击"重新检查题库"，系统会试算题量与难度可达性。</p>

        <template v-else>
          <div v-if="preview.blocked" class="mb-4">
            <p class="text-red-700 bg-red-50 border border-red-200 rounded px-4 py-3 font-medium mb-3">
              当前题库无法满足规则，发布已被阻止，请补充以下缺口题目：
            </p>
            <ul class="space-y-2">
              <li v-for="(iss, i) in errorIssues" :key="'e' + i"
                class="flex gap-2 text-sm text-red-700 bg-red-50 border border-red-100 rounded px-3 py-2">
                <span class="font-mono text-xs bg-red-200 rounded px-1.5 h-fit">{{ issueCodeText(iss.code) }}</span>
                <span>{{ iss.message }}</span>
              </li>
            </ul>
          </div>

          <div v-if="warningIssues.length" class="mb-4 space-y-2">
            <p v-for="(iss, i) in warningIssues" :key="'w' + i"
              class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
              ⚠ {{ iss.message }}
            </p>
          </div>

          <p v-if="!preview.blocked"
            class="text-green-700 bg-green-50 border border-green-200 rounded px-3 py-3 text-sm mb-4">
            ✓ 题量充足、难度区间可达，可发布。预计参考学生 {{ preview.student_count }} 人，题库候选 {{ preview.pool_size }} 题。
          </p>

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-3 py-2 text-left text-xs text-gray-500">知识点</th>
                  <th class="px-3 py-2 text-left text-xs text-gray-500">题型</th>
                  <th class="px-3 py-2 text-right text-xs text-gray-500">需要</th>
                  <th class="px-3 py-2 text-right text-xs text-gray-500">已有</th>
                  <th class="px-3 py-2 text-right text-xs text-gray-500">缺口</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="(m, i) in preview.matrix" :key="'m' + i" :class="m.missing > 0 ? 'bg-red-50' : ''">
                  <td class="px-3 py-2">{{ m.knowledge_point }}</td>
                  <td class="px-3 py-2">{{ typeLabel(m.type) }}</td>
                  <td class="px-3 py-2 text-right">{{ m.need }}</td>
                  <td class="px-3 py-2 text-right">{{ m.available }}</td>
                  <td class="px-3 py-2 text-right" :class="m.missing > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'">
                    <template v-if="m.missing > 0">缺 {{ m.missing }}</template>
                    <template v-else>—</template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <section class="bg-white rounded-lg shadow p-6 flex items-center justify-between">
        <label class="flex items-start gap-2 text-sm text-gray-600">
          <input type="checkbox" v-model="forcePublish" :disabled="preview && preview.blocked" class="accent-indigo-600 mt-1" />
          <span>即使组卷后仍有个别试卷难度略超区间也强制发布（一般不建议）</span>
        </label>
        <div class="flex gap-3">
          <button @click="$router.push('/exam-papers')" class="px-5 py-2 border rounded">取消</button>
          <button @click="publish" :disabled="publishing || (preview && preview.blocked)"
            class="px-5 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50">
            {{ publishing ? '正在组卷...' : '发布随机考试' }}
          </button>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../api'
import { useToast } from '../../composables/useToast'

const router = useRouter()
const toast = useToast()
const loading = ref(true)
const checking = ref(false)
const publishing = ref(false)
const preview = ref(null)
const forcePublish = ref(false)

const categories = ref([])
const allQuestions = ref([])
const stock = ref({})

const usedTypes = [
  { key: 'single_choice', label: '单选题' },
  { key: 'multiple_choice', label: '多选题' },
  { key: 'true_false', label: '判断题' },
  { key: 'fill_blank', label: '填空题' },
  { key: 'essay', label: '问答题' }
]

const form = reactive({
  title: '随机平衡测验',
  description: '',
  total_time: 60,
  question_count: 20,
  fairness_range: 0.08,
  knowledge_points: [],
  difficulty_min: 1.8,
  difficulty_max: 2.2,
  required_question_ids: [],
  unique_across_students: false
})

const ratioPct = reactive({ single_choice: 40, multiple_choice: 20, true_false: 10, fill_blank: 10, essay: 20 })
const enabledTypes = ref(['single_choice', 'multiple_choice', 'true_false', 'fill_blank', 'essay'])
const kpQuota = reactive({})
const requiredFilter = reactive({ kp: '', type: '' })

const typeLabel = (t) => usedTypes.find(x => x.key === t)?.label || t
const diffClass = (d) => d == 1 ? 'bg-green-100 text-green-700' : d == 2 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'

const ratioSum = computed(() =>
  enabledTypes.value.reduce((s, t) => s + (Number(ratioPct[t]) || 0), 0)
)

const typeRatio = computed(() => {
  const obj = {}
  enabledTypes.value.forEach(t => { obj[t] = (Number(ratioPct[t]) || 0) / 100 })
  return obj
})

const filteredRequiredChoices = computed(() => {
  return allQuestions.value.filter(q => {
    if (!form.knowledge_points.includes(q.knowledge_point)) return false
    if (requiredFilter.kp && q.knowledge_point !== requiredFilter.kp) return false
    if (requiredFilter.type && q.type !== requiredFilter.type) return false
    return true
  })
})

const errorIssues = computed(() => (preview.value?.issues || []).filter(i => i.severity === 'error'))
const warningIssues = computed(() => (preview.value?.issues || []).filter(i => i.severity === 'warning'))
const issueCodeText = (code) => ({
  SHORTAGE: '缺题',
  REQUIRED_INVALID: '必考题无效',
  DIFFICULTY_UNREACHABLE: '难度不可达',
  UNIQUE_INSUFFICIENT: '不重题题量不足'
}[code] || code)

const payload = () => ({
  ...form,
  type_ratio: typeRatio.value,
  knowledge_point_quota: Object.keys(kpQuota).length ? kpQuota : null
})

const runPreview = async () => {
  if (!form.title) { toast.warning('请先填写标题'); return }
  if (!form.knowledge_points.length) { toast.warning('请至少选择一个知识点'); return }
  if (Math.abs(ratioSum.value - 100) > 1) { toast.warning('题型比例合计需为100%'); return }
  checking.value = true
  try {
    const res = await api.post('/random-exams/preview', payload())
    preview.value = res.data
  } catch (e) {
    if (e.response?.data?.errors) {
      toast.error(e.response.data.errors.map(x => x.message).join('；'))
    }
  } finally {
    checking.value = false
  }
}

const publish = async () => {
  if (!preview.value) { toast.warning('请先进行发布前预检'); return }
  if (preview.value.blocked) { toast.error('存在题库缺口，无法发布'); return }
  publishing.value = true
  try {
    await api.post('/random-exams/publish', { ...payload(), force: forcePublish.value })
    toast.success('发布成功：已为每位学生生成难度接近的不同试卷')
    router.push('/random-exams')
  } catch (e) {
    const d = e.response?.data
    if (d?.blocked && d.issues) {
      preview.value = { ...preview.value, blocked: true, issues: d.issues }
      toast.error('发布被阻止，请查看缺口清单')
    } else if (d?.blocked) {
      toast.error(d.message || '组卷后仍有越界试卷')
    }
  } finally {
    publishing.value = false
  }
}

onMounted(async () => {
  try {
    const res = await api.get('/random-exams/meta')
    categories.value = res.data.categories
    allQuestions.value = res.data.questions
    stock.value = res.data.stock
    // 默认选中三个有充足题量的知识点
    form.knowledge_points = ['操作系统', '数据结构', 'MySQL']
    form.knowledge_points.forEach(kp => { kpQuota[kp] = 0 })
  } finally {
    loading.value = false
  }
})

watch(() => form.knowledge_points, (kps) => {
  kps.forEach(kp => { if (kpQuota[kp] == null) kpQuota[kp] = 0 })
}, { deep: true })
</script>
