<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">随机组卷</h1>
      <button @click="openEditModal(null)" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">创建组卷模板</button>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      <p class="mt-2 text-gray-500">加载中...</p>
    </div>

    <div v-else-if="templates.length === 0" class="text-center py-8 text-gray-500 bg-white rounded-lg shadow">
      暂无组卷模板，请点击"创建组卷模板"
    </div>

    <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">标题</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">题型构成</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">题数/总分</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">难度区间</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">目标难度</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">已生成</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="tpl in templates" :key="tpl.id" class="hover:bg-gray-50">
            <td class="px-4 py-4">
              <div class="font-medium text-gray-900">{{ tpl.title }}</div>
              <div class="text-xs text-gray-400">必考题 {{ (tpl.mandatory_question_ids || []).length }} 道</div>
            </td>
            <td class="px-4 py-4 text-xs text-gray-600">
              <span v-for="cfg in tpl.type_config" :key="cfg.type" class="inline-block bg-gray-100 rounded px-1.5 py-0.5 mr-1 mb-1">
                {{ typeLabel(cfg.type) }}×{{ cfg.count }}
              </span>
            </td>
            <td class="px-4 py-4 text-sm">{{ tpl.question_count }} 题 / {{ tpl.total_score }} 分</td>
            <td class="px-4 py-4 text-sm">{{ difficultyLabel(tpl.difficulty_min) }} ~ {{ difficultyLabel(tpl.difficulty_max) }}</td>
            <td class="px-4 py-4 text-sm">{{ tpl.target_difficulty }} ±{{ tpl.balance_tolerance }}</td>
            <td class="px-4 py-4">
              <span class="px-2 py-1 text-xs rounded-full" :class="statusClass(tpl.status)">{{ statusLabel(tpl.status) }}</span>
            </td>
            <td class="px-4 py-4 text-sm">{{ tpl.generated_papers_count || 0 }} 份</td>
            <td class="px-4 py-4 space-x-2 text-sm whitespace-nowrap">
              <button @click="runPrecheck(tpl)" class="text-amber-600 hover:text-amber-900">预检</button>
              <button v-if="tpl.status !== 'published'" @click="publishTemplate(tpl)" class="text-green-600 hover:text-green-900">发布</button>
              <button v-else @click="unpublishTemplate(tpl)" class="text-orange-600 hover:text-orange-900">下线</button>
              <button @click="openSamplingModal(tpl)" class="text-purple-600 hover:text-purple-900">抽检</button>
              <button v-if="tpl.status !== 'published'" @click="openEditModal(tpl)" class="text-indigo-600 hover:text-indigo-900">编辑</button>
              <button v-if="tpl.status !== 'published'" @click="deleteTemplate(tpl)" class="text-red-600 hover:text-red-900">删除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 创建/编辑模板模态框 -->
    <Teleport to="body">
      <div v-if="showEditModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div class="px-6 py-4 border-b sticky top-0 bg-white z-10">
              <h3 class="text-lg font-semibold">{{ editingTemplate ? '编辑组卷模板' : '创建组卷模板' }}</h3>
            </div>
            <div class="px-6 py-4 space-y-5">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">模板标题 <span class="text-red-500">*</span></label>
                <input v-model="form.title" type="text" class="w-full border rounded px-3 py-2" placeholder="例如：计算机基础期末随机考" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">描述</label>
                <textarea v-model="form.description" rows="2" class="w-full border rounded px-3 py-2" placeholder="可选"></textarea>
              </div>

              <!-- 知识点 -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">知识点范围 <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-3 gap-2 border rounded p-3 max-h-36 overflow-y-auto">
                  <label v-for="cat in categories" :key="cat.id" class="flex items-center space-x-2 text-sm">
                    <input type="checkbox" :value="cat.id" v-model="form.category_ids" class="h-4 w-4 text-indigo-600 rounded" @change="onScopeChanged" />
                    <span>{{ cat.name }}</span>
                  </label>
                </div>
              </div>

              <!-- 题型比例 -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">题型比例（每卷题数与每题分值）<span class="text-red-500">*</span></label>
                <div class="border rounded divide-y">
                  <div v-for="row in form.type_config" :key="row.type" class="flex items-center px-3 py-2 space-x-4">
                    <span class="w-20 text-sm font-medium">{{ typeLabel(row.type) }}</span>
                    <label class="flex items-center space-x-1 text-sm text-gray-600">
                      <span>题数</span>
                      <input v-model.number="row.count" type="number" min="0" max="200" class="w-20 border rounded px-2 py-1" @input="onScopeChanged" />
                    </label>
                    <label class="flex items-center space-x-1 text-sm text-gray-600">
                      <span>每题分值</span>
                      <input v-model.number="row.score" type="number" min="0.5" max="100" step="0.5" class="w-20 border rounded px-2 py-1" />
                    </label>
                    <span class="text-xs text-gray-400">小计 {{ (row.count * row.score) || 0 }} 分</span>
                  </div>
                </div>
                <p class="mt-1 text-xs text-gray-500">合计 {{ totalCount }} 题，{{ totalScore }} 分</p>
              </div>

              <!-- 难度区间与目标 -->
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">难度区间 <span class="text-red-500">*</span></label>
                  <div class="flex items-center space-x-2">
                    <select v-model.number="form.difficulty_min" class="border rounded px-2 py-2 flex-1" @change="onScopeChanged">
                      <option :value="1">简单</option>
                      <option :value="2">中等</option>
                      <option :value="3">困难</option>
                    </select>
                    <span>~</span>
                    <select v-model.number="form.difficulty_max" class="border rounded px-2 py-2 flex-1" @change="onScopeChanged">
                      <option :value="1">简单</option>
                      <option :value="2">中等</option>
                      <option :value="3">困难</option>
                    </select>
                  </div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">考试时长（分钟）</label>
                  <input v-model.number="form.total_time" type="number" min="1" max="600" class="w-full border rounded px-3 py-2" />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">目标难度（1-3）</label>
                  <input v-model.number="form.target_difficulty" type="number" min="1" max="3" step="0.1" class="w-full border rounded px-3 py-2" />
                  <p class="mt-1 text-xs text-gray-500">每份试卷的加权平均难度将尽量接近该值</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">平衡容差（±）</label>
                  <input v-model.number="form.balance_tolerance" type="number" min="0.05" max="1" step="0.05" class="w-full border rounded px-3 py-2" />
                  <p class="mt-1 text-xs text-gray-500">容差越小各卷难度越接近，但题库要求越高</p>
                </div>
              </div>

              <!-- 必考题 -->
              <div>
                <div class="flex justify-between items-center mb-1">
                  <label class="block text-sm font-medium text-gray-700">必考题（每份试卷都包含）</label>
                  <span class="text-xs text-gray-500">已选 {{ form.mandatory_question_ids.length }} 道</span>
                </div>
                <div class="border rounded max-h-48 overflow-y-auto">
                  <div v-if="candidateQuestions.length === 0" class="text-center text-gray-400 text-sm py-4">
                    当前知识点与难度区间内暂无题目
                  </div>
                  <label v-for="q in candidateQuestions" :key="q.id" class="flex items-start space-x-2 px-3 py-2 border-b last:border-b-0 hover:bg-gray-50 text-sm cursor-pointer">
                    <input type="checkbox" :value="q.id" v-model="form.mandatory_question_ids" class="mt-1 h-4 w-4 text-indigo-600 rounded" />
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2 text-xs">
                        <span class="text-indigo-600">{{ typeLabel(q.type) }}</span>
                        <span :class="difficultyTextClass(q.difficulty)">{{ difficultyLabel(q.difficulty) }}</span>
                        <span class="text-gray-400">{{ q.category?.name }}</span>
                      </div>
                      <p class="truncate">{{ q.title }}</p>
                    </div>
                  </label>
                </div>
              </div>
            </div>
            <div class="px-6 py-4 border-t flex justify-end space-x-3 sticky bottom-0 bg-white">
              <button @click="showEditModal = false" class="px-4 py-2 border rounded hover:bg-gray-50">取消</button>
              <button @click="saveTemplate" :disabled="saving" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50">
                {{ saving ? '保存中...' : '保存' }}
              </button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="showEditModal = false"></div>
      </div>
    </Teleport>

    <!-- 预检结果模态框 -->
    <Teleport to="body">
      <div v-if="showPrecheckModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[85vh] overflow-y-auto">
            <div class="px-6 py-4 border-b flex justify-between items-center">
              <h3 class="text-lg font-semibold">发布前检查 - {{ precheckTemplate?.title }}</h3>
              <span v-if="precheckResult" class="px-3 py-1 text-sm rounded-full" :class="precheckResult.can_publish ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                {{ precheckResult.can_publish ? '✓ 可以发布' : '✗ 暂不可发布' }}
              </span>
            </div>
            <div class="px-6 py-4 space-y-4">
              <div v-if="precheckLoading" class="text-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
                <p class="mt-2 text-gray-500">正在检查题库并模拟组卷...</p>
              </div>
              <template v-else-if="precheckResult">
                <!-- 错误：缺题明细 -->
                <div v-if="precheckResult.errors.length > 0" class="bg-red-50 border border-red-200 rounded-lg p-4">
                  <h4 class="font-semibold text-red-800 mb-2">缺少以下题目（需补充题库后才能发布）：</h4>
                  <ul class="space-y-1 text-sm text-red-700">
                    <li v-for="(err, i) in precheckResult.errors" :key="i" class="flex items-start">
                      <span class="mr-2">•</span><span>{{ err.message }}</span>
                    </li>
                  </ul>
                </div>
                <!-- 警告 -->
                <div v-if="precheckResult.warnings.length > 0" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                  <h4 class="font-semibold text-yellow-800 mb-2">风险提示：</h4>
                  <ul class="space-y-1 text-sm text-yellow-700">
                    <li v-for="(warn, i) in precheckResult.warnings" :key="i" class="flex items-start">
                      <span class="mr-2">•</span><span>{{ warn.message }}</span>
                    </li>
                  </ul>
                </div>
                <!-- 题库统计 -->
                <div v-if="precheckResult.per_type_stats?.length" class="border rounded-lg overflow-hidden">
                  <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                      <tr>
                        <th class="px-4 py-2 text-left">题型</th>
                        <th class="px-4 py-2 text-left">每卷需要</th>
                        <th class="px-4 py-2 text-left">题库可用</th>
                        <th class="px-4 py-2 text-left">状态</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y">
                      <tr v-for="stat in precheckResult.per_type_stats" :key="stat.type">
                        <td class="px-4 py-2">{{ stat.type_label }}</td>
                        <td class="px-4 py-2">{{ stat.required_per_paper }} 道</td>
                        <td class="px-4 py-2">{{ stat.available }} 道</td>
                        <td class="px-4 py-2">
                          <span v-if="stat.available >= stat.required_per_paper * 2" class="text-green-600">充足</span>
                          <span v-else-if="stat.available >= stat.required_per_paper" class="text-yellow-600">勉强够用</span>
                          <span v-else class="text-red-600">不足</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <!-- 模拟组卷结果 -->
                <div v-if="precheckResult.simulation?.runs" class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 text-sm">
                  <h4 class="font-semibold text-indigo-800 mb-2">模拟组卷（{{ precheckResult.simulation.runs }} 次）</h4>
                  <p class="text-indigo-700">
                    成功 {{ precheckResult.simulation.success }} 次，失败 {{ precheckResult.simulation.failed }} 次
                    <template v-if="precheckResult.simulation.difficulty_avg != null">
                      <br />生成试卷难度：平均 {{ precheckResult.simulation.difficulty_avg }}，
                      区间 {{ precheckResult.simulation.difficulty_min }} ~ {{ precheckResult.simulation.difficulty_max }}
                    </template>
                  </p>
                </div>
              </template>
            </div>
            <div class="px-6 py-4 border-t flex justify-end space-x-3">
              <button @click="showPrecheckModal = false" class="px-4 py-2 border rounded hover:bg-gray-50">关闭</button>
              <button v-if="precheckResult?.can_publish && precheckTemplate?.status !== 'published'" @click="publishTemplate(precheckTemplate, true)"
                class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                立即发布
              </button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="showPrecheckModal = false"></div>
      </div>
    </Teleport>

    <!-- 抽样检查模态框 -->
    <Teleport to="body">
      <div v-if="showSamplingModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-5xl max-h-[90vh] overflow-y-auto">
            <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white z-10">
              <h3 class="text-lg font-semibold">抽样检查 - {{ samplingTemplate?.title }}</h3>
              <button @click="exportCsv" :disabled="exporting" class="px-3 py-1.5 bg-purple-600 text-white text-sm rounded hover:bg-purple-700 disabled:opacity-50">
                {{ exporting ? '导出中...' : '导出 CSV' }}
              </button>
            </div>
            <div class="px-6 py-4 space-y-4">
              <div v-if="samplingLoading" class="text-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
              </div>
              <template v-else>
                <!-- 平衡统计 -->
                <div v-if="samplingReport" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                  <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500">目标难度</div>
                    <div class="text-xl font-bold">{{ samplingReport.target_difficulty }} ±{{ samplingReport.balance_tolerance }}</div>
                  </div>
                  <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500">已生成 / 异常</div>
                    <div class="text-xl font-bold">
                      {{ samplingReport.paper_count }} 份
                      <span v-if="samplingReport.outlier_count > 0" class="text-red-600">/ {{ samplingReport.outlier_count }} 异常</span>
                      <span v-else class="text-green-600">/ 0 异常</span>
                    </div>
                  </div>
                  <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500">难度范围</div>
                    <div class="text-xl font-bold">
                      <template v-if="samplingReport.paper_count">{{ samplingReport.difficulty_min }} ~ {{ samplingReport.difficulty_max }}</template>
                      <template v-else>-</template>
                    </div>
                  </div>
                  <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="text-xs text-gray-500">平均难度 / 标准差</div>
                    <div class="text-xl font-bold">
                      <template v-if="samplingReport.paper_count">{{ samplingReport.difficulty_avg }} / {{ samplingReport.difficulty_stddev }}</template>
                      <template v-else>-</template>
                    </div>
                  </div>
                </div>

                <div v-if="samplingPapers.length === 0" class="text-center text-gray-400 py-8">
                  暂无学生试卷，学生开始考试后会自动生成
                </div>
                <div v-else class="border rounded-lg overflow-hidden">
                  <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                      <tr>
                        <th class="px-4 py-2 text-left">试卷ID</th>
                        <th class="px-4 py-2 text-left">学生</th>
                        <th class="px-4 py-2 text-left">难度值</th>
                        <th class="px-4 py-2 text-left">与目标偏差</th>
                        <th class="px-4 py-2 text-left">状态</th>
                        <th class="px-4 py-2 text-left">生成时间</th>
                        <th class="px-4 py-2 text-left">操作</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y">
                      <tr v-for="paper in samplingPapers" :key="paper.id" :class="{'bg-red-50': paper.is_outlier}">
                        <td class="px-4 py-2">#{{ paper.id }}</td>
                        <td class="px-4 py-2">{{ paper.user?.real_name || paper.user?.username }}</td>
                        <td class="px-4 py-2 font-mono">{{ paper.difficulty_value }}</td>
                        <td class="px-4 py-2 font-mono">{{ deviation(paper) }}</td>
                        <td class="px-4 py-2">
                          <span v-if="paper.is_outlier" class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700">异常偏{{ deviationDirection(paper) }}</span>
                          <span v-else class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-700">正常</span>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ formatTime(paper.created_at) }}</td>
                        <td class="px-4 py-2">
                          <button @click="viewGeneratedPaper(paper)" class="text-indigo-600 hover:text-indigo-900">查看试卷</button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </template>
            </div>
            <div class="px-6 py-4 border-t flex justify-end sticky bottom-0 bg-white">
              <button @click="showSamplingModal = false" class="px-4 py-2 border rounded hover:bg-gray-50">关闭</button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="showSamplingModal = false"></div>
      </div>
    </Teleport>

    <!-- 单个生成试卷详情模态框 -->
    <Teleport to="body">
      <div v-if="showPaperDetailModal" class="fixed inset-0 z-[60] overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[85vh] overflow-y-auto">
            <div class="px-6 py-4 border-b sticky top-0 bg-white z-10">
              <h3 class="text-lg font-semibold">
                试卷 #{{ paperDetail?.paper?.id }} - {{ paperDetail?.paper?.user?.real_name || paperDetail?.paper?.user?.username }}
                <span class="ml-2 text-sm font-normal text-gray-500">难度 {{ paperDetail?.paper?.difficulty_value }}</span>
              </h3>
            </div>
            <div class="px-6 py-4">
              <div v-if="paperDetailLoading" class="text-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
              </div>
              <div v-else class="space-y-3">
                <div v-for="q in paperDetail?.questions || []" :key="q.id" class="border rounded-lg p-4">
                  <div class="flex items-center gap-2 text-xs mb-1">
                    <span class="bg-gray-200 px-2 py-0.5 rounded">{{ q.sort }}</span>
                    <span class="text-indigo-600">{{ typeLabel(q.type) }}</span>
                    <span :class="difficultyTextClass(q.difficulty)">{{ difficultyLabel(q.difficulty) }}</span>
                    <span class="text-orange-600">{{ q.score }} 分</span>
                    <span class="text-gray-400">题号 #{{ q.id }}</span>
                  </div>
                  <p class="text-sm">{{ q.title }}</p>
                  <p class="text-xs text-green-700 mt-1">答案：{{ q.answer }}</p>
                </div>
              </div>
            </div>
            <div class="px-6 py-4 border-t flex justify-end sticky bottom-0 bg-white">
              <button @click="showPaperDetailModal = false" class="px-4 py-2 border rounded hover:bg-gray-50">关闭</button>
            </div>
          </div>
        </div>
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 -z-10" @click="showPaperDetailModal = false"></div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'

const { alert, confirm } = useModal()
const toast = useToast()

const TYPE_LABELS = {
  single_choice: '单选题',
  multiple_choice: '多选题',
  true_false: '判断题',
  fill_blank: '填空题',
  essay: '问答题'
}
const DIFFICULTY_LABELS = { 1: '简单', 2: '中等', 3: '困难' }

const templates = ref([])
const categories = ref([])
const loading = ref(true)
const saving = ref(false)

const showEditModal = ref(false)
const editingTemplate = ref(null)
const candidateQuestions = ref([])

const showPrecheckModal = ref(false)
const precheckTemplate = ref(null)
const precheckResult = ref(null)
const precheckLoading = ref(false)

const showSamplingModal = ref(false)
const samplingTemplate = ref(null)
const samplingPapers = ref([])
const samplingReport = ref(null)
const samplingLoading = ref(false)
const exporting = ref(false)

const showPaperDetailModal = ref(false)
const paperDetail = ref(null)
const paperDetailLoading = ref(false)

const emptyForm = () => ({
  title: '',
  description: '',
  category_ids: [],
  type_config: [
    { type: 'single_choice', count: 0, score: 2 },
    { type: 'multiple_choice', count: 0, score: 3 },
    { type: 'true_false', count: 0, score: 1 },
    { type: 'fill_blank', count: 0, score: 2 },
    { type: 'essay', count: 0, score: 10 }
  ],
  difficulty_min: 1,
  difficulty_max: 3,
  target_difficulty: 2.0,
  balance_tolerance: 0.3,
  mandatory_question_ids: [],
  total_time: 60
})
const form = ref(emptyForm())

const totalCount = computed(() => form.value.type_config.reduce((s, c) => s + (Number(c.count) || 0), 0))
const totalScore = computed(() => form.value.type_config.reduce((s, c) => s + (Number(c.count) || 0) * (Number(c.score) || 0), 0))

const typeLabel = (t) => TYPE_LABELS[t] || t
const difficultyLabel = (d) => DIFFICULTY_LABELS[d] || d
const difficultyTextClass = (d) => ({ 1: 'text-green-600', 2: 'text-yellow-600', 3: 'text-red-600' }[d] || '')
const statusLabel = (s) => ({ draft: '草稿', published: '已发布', closed: '已下线' }[s] || s)
const statusClass = (s) => ({
  draft: 'bg-gray-100 text-gray-600',
  published: 'bg-green-100 text-green-700',
  closed: 'bg-orange-100 text-orange-700'
}[s] || 'bg-gray-100 text-gray-600')

const deviation = (paper) => {
  if (!samplingReport.value) return '-'
  const dev = (Number(paper.difficulty_value) - samplingReport.value.target_difficulty)
  return (dev >= 0 ? '+' : '') + dev.toFixed(3)
}
const deviationDirection = (paper) => {
  if (!samplingReport.value) return ''
  return Number(paper.difficulty_value) > samplingReport.value.target_difficulty ? '难' : '简单'
}
const formatTime = (t) => t ? new Date(t).toLocaleString() : '-'

const fetchTemplates = async () => {
  loading.value = true
  try {
    const response = await api.get('/paper-templates')
    templates.value = response.data.templates.data
  } catch (e) {
    console.error('Failed to fetch templates:', e)
  } finally {
    loading.value = false
  }
}

const fetchCategories = async () => {
  try {
    const response = await api.get('/questions/categories')
    categories.value = response.data.categories
  } catch (e) {
    console.error('Failed to fetch categories:', e)
  }
}

// 加载必考题候选：当前知识点 + 难度区间内的启用题目
const fetchCandidateQuestions = async () => {
  if (form.value.category_ids.length === 0) {
    candidateQuestions.value = []
    return
  }
  try {
    const results = []
    for (const catId of form.value.category_ids) {
      const response = await api.get('/questions', {
        params: { category_id: catId, per_page: 100 }
      })
      results.push(...response.data.questions.data)
    }
    candidateQuestions.value = results.filter(q =>
      q.status && q.difficulty >= form.value.difficulty_min && q.difficulty <= form.value.difficulty_max
    )
  } catch (e) {
    console.error('Failed to fetch candidate questions:', e)
  }
}

let scopeTimer = null
const onScopeChanged = () => {
  clearTimeout(scopeTimer)
  scopeTimer = setTimeout(fetchCandidateQuestions, 300)
}

const openEditModal = async (tpl) => {
  editingTemplate.value = tpl
  if (tpl) {
    const typeConfig = emptyForm().type_config.map(row => {
      const existing = (tpl.type_config || []).find(c => c.type === row.type)
      return existing ? { type: row.type, count: existing.count, score: Number(existing.score) } : row
    })
    form.value = {
      title: tpl.title,
      description: tpl.description || '',
      category_ids: [...(tpl.category_ids || [])],
      type_config: typeConfig,
      difficulty_min: tpl.difficulty_min,
      difficulty_max: tpl.difficulty_max,
      target_difficulty: Number(tpl.target_difficulty),
      balance_tolerance: Number(tpl.balance_tolerance),
      mandatory_question_ids: [...(tpl.mandatory_question_ids || [])],
      total_time: tpl.total_time
    }
  } else {
    form.value = emptyForm()
  }
  showEditModal.value = true
  await fetchCandidateQuestions()
}

const saveTemplate = async () => {
  if (!form.value.title.trim()) {
    toast.warning('请输入模板标题')
    return
  }
  if (form.value.category_ids.length === 0) {
    toast.warning('请选择知识点范围')
    return
  }
  if (totalCount.value === 0) {
    toast.warning('请配置题型比例：至少一种题型的题数大于 0')
    return
  }
  saving.value = true
  try {
    const payload = { ...form.value, type_config: form.value.type_config.filter(c => c.count > 0) }
    if (editingTemplate.value) {
      await api.put(`/paper-templates/${editingTemplate.value.id}`, payload)
      toast.success('更新成功')
    } else {
      await api.post('/paper-templates', payload)
      toast.success('创建成功，请执行"预检"后再发布')
    }
    showEditModal.value = false
    await fetchTemplates()
  } catch (e) {
    console.error('Failed to save template:', e)
  } finally {
    saving.value = false
  }
}

const deleteTemplate = async (tpl) => {
  if (!(await confirm(`确定删除组卷模板「${tpl.title}」吗？已生成的学生试卷将一并删除。`, '删除确认'))) return
  try {
    await api.delete(`/paper-templates/${tpl.id}`)
    toast.success('删除成功')
    await fetchTemplates()
  } catch (e) {
    console.error('Failed to delete template:', e)
  }
}

const runPrecheck = async (tpl) => {
  precheckTemplate.value = tpl
  precheckResult.value = null
  precheckLoading.value = true
  showPrecheckModal.value = true
  try {
    const response = await api.get(`/paper-templates/${tpl.id}/precheck`)
    precheckResult.value = response.data
  } catch (e) {
    showPrecheckModal.value = false
    console.error('Precheck failed:', e)
  } finally {
    precheckLoading.value = false
  }
}

const publishTemplate = async (tpl, fromPrecheck = false) => {
  if (!fromPrecheck && !(await confirm(`发布「${tpl.title}」后学生即可进入考试，系统将按模板规则为每位学生随机组卷。确定发布吗？`, '发布确认'))) return
  try {
    const response = await api.post(`/paper-templates/${tpl.id}/publish`)
    toast.success(response.data.message || '发布成功')
    showPrecheckModal.value = false
    await fetchTemplates()
  } catch (e) {
    // 发布失败时展示预检明细
    if (e.response?.data?.precheck) {
      precheckTemplate.value = tpl
      precheckResult.value = e.response.data.precheck
      precheckLoading.value = false
      showPrecheckModal.value = true
    }
    console.error('Publish failed:', e)
  }
}

const unpublishTemplate = async (tpl) => {
  if (!(await confirm(`下线「${tpl.title}」后学生将无法进入该考试（已生成的试卷保留供抽检）。确定下线吗？`, '下线确认'))) return
  try {
    await api.post(`/paper-templates/${tpl.id}/unpublish`)
    toast.success('已下线')
    await fetchTemplates()
  } catch (e) {
    console.error('Unpublish failed:', e)
  }
}

const openSamplingModal = async (tpl) => {
  samplingTemplate.value = tpl
  samplingPapers.value = []
  samplingReport.value = null
  samplingLoading.value = true
  showSamplingModal.value = true
  try {
    const response = await api.get(`/paper-templates/${tpl.id}/generated-papers`, { params: { per_page: 100 } })
    samplingPapers.value = response.data.papers.data
    samplingReport.value = response.data.report
  } catch (e) {
    console.error('Failed to fetch generated papers:', e)
  } finally {
    samplingLoading.value = false
  }
}

const viewGeneratedPaper = async (paper) => {
  paperDetailLoading.value = true
  paperDetail.value = null
  showPaperDetailModal.value = true
  try {
    const response = await api.get(`/paper-templates/${samplingTemplate.value.id}/generated-papers/${paper.id}`)
    paperDetail.value = response.data
  } catch (e) {
    showPaperDetailModal.value = false
    console.error('Failed to fetch paper detail:', e)
  } finally {
    paperDetailLoading.value = false
  }
}

const exportCsv = async () => {
  exporting.value = true
  try {
    const response = await api.get(`/paper-templates/${samplingTemplate.value.id}/export`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `组卷抽检-${samplingTemplate.value.title}.csv`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
    toast.success('导出成功')
  } catch (e) {
    console.error('Export failed:', e)
  } finally {
    exporting.value = false
  }
}

onMounted(() => {
  fetchTemplates()
  fetchCategories()
})
</script>
