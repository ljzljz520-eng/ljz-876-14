# 在线考试题库系统（876）

## 项目类型
- 全栈 Web 项目（`frontend` + `backend`）

## 项目简介
本项目是一个基于 Vue 3 + Laravel 12 的在线考试与题库管理系统，支持多角色登录、题库管理、试卷管理、在线考试与成绩统计。

## 技术栈
### 前端
- Vue 3
- Vite
- Pinia
- Vue Router
- Axios
- TailwindCSS

### 后端
- Laravel 12（PHP 8.2）
- Laravel Sanctum（Token 鉴权）
- MySQL 8.0

### 运行方式
- Docker Compose（推荐，当前项目默认方式）

## 目录结构
```text
876/
├── docker-compose.yml
├── README.md
├── frontend/
│   ├── Dockerfile
│   ├── nginx.conf
│   ├── package.json
│   └── src/
├── backend/
│   ├── Dockerfile
│   ├── composer.json
│   ├── app/
│   └── routes/
├── docs/
│   ├── ARCHITECTURE.md
│   └── Database.sql
├── scripts/
└── evidence/
```

说明：`node_modules/`、`vendor/` 等依赖目录由 Docker 构建时自动安装，不需要打包提交。

## 启动与重建
在仓库根目录执行：

```bash
docker compose down
docker compose up -d --build
docker compose ps
```

## 服务地址
| 服务 | 地址 | 说明 |
|---|---|---|
| 前端 | http://localhost:8080 | 用户界面 |
| 后端 API | http://localhost:9000/api | Laravel API |
| MySQL | localhost:3307 | 数据库端口映射 |

## 测试账号
| 角色 | 邮箱 | 密码 |
|------|-------|----------|
| Admin | admin@example.com | password |
| Teacher | teacher@example.com | password |
| Student | student1@example.com | password |

> 登录页已移除快捷测试账号模块，请手动输入账号密码。

## README 与测试账号清单同步（必跑）
在截图前、提交前执行以下命令：

```bash
node scripts/sync-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
node scripts/verify-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
```

阻断规则：任一命令失败都应视为 `README_TEST_CREDENTIALS_MISMATCH`，不得继续提交流程。

## 核心功能
1. 用户认证：注册、登录、退出。
2. 题库管理：题目增删改查、分类管理。
3. 试卷管理：试卷创建、编辑、题目关联。
4. 在线考试：开始考试、提交答卷、自动评分。
5. 成绩统计：个人成绩与管理端统计数据。
6. 随机组卷（难度平衡）：按知识点、题型比例、难度区间与必考题配置组卷模板，系统为每位学生生成不同试卷且总体难度接近；发布前预检报告缺题明细；支持导出 CSV 抽样检查各卷难度分布。

## 随机组卷使用说明
1. 进入「随机组卷」页面，创建组卷模板：选择知识点范围、配置各题型题数与分值、设置难度区间与目标难度（容差）、勾选必考题。
2. 点击「预检」：系统静态检查题库并按模板模拟组卷 30 次，题库不足时会明确报告缺哪类题（题型、数量、知识点与难度范围），预检通过才能发布。
3. 点击「发布」：学生即可在「在线考试」中看到该考试；每位学生开始考试时系统按模板规则现场生成专属试卷（同一种子可复现，同一学生重复进入复用同一份试卷）。
4. 点击「抽检」：查看已生成试卷的难度分布统计（平均/最低/最高/标准差），超出容差的试卷标红为异常卷；可查看任意学生试卷的完整题目，或「导出 CSV」留存检查。
5. 「下线」后学生不可再进入考试，已生成试卷保留供抽检；模板下线后才能编辑或删除。

> 数据库说明：全新部署由 `docker-compose.yml` 的 `db-init` 自动创建 `exam_paper_templates` 与 `generated_papers` 两张表；已有数据库请执行 `scripts/migrations/2026_09_29_add_random_paper_tables.sql`。

## 角色权限
| 角色 | 可访问模块 |
|---|---|
| Student | 在线考试、我的成绩 |
| Teacher | 在线考试、我的成绩、题库管理、试卷管理 |
| Admin | 全部功能（含数据统计） |

## 人工验证步骤（建议）
1. 打开登录页：`http://localhost:8080/login`。
2. 使用测试账号手动登录，确认菜单与角色权限一致。
3. 进入题库管理，验证新增/编辑/删除流程。
4. 进入试卷管理，验证题目关联与试卷删除流程。
5. 学生账号完成一次在线考试并查看成绩。
6. Admin 查看统计页数据。
7. API 冒烟：

```bash
docker compose exec backend sh -lc "curl -s -o /tmp/unauth.txt -w '%{http_code}\n' http://localhost:8080/api/exams"
docker compose exec backend sh -lc "curl -s -X POST http://localhost:8080/api/auth/login -H 'Content-Type: application/json' -d '{\"email\":\"admin@example.com\",\"password\":\"password\"}'"
```

预期：未登录访问受保护接口返回 `401`；登录接口返回包含 `token` 的 JSON。

## 安全与质量说明
- 密码为哈希存储（bcrypt）。
- API 使用 Sanctum Token 鉴权。
- 接口包含输入校验与错误处理。
- CORS 与基础限流已配置。

## 数据库说明
当前初始化后包含 10 张核心表（含用户、题目、试卷、考试记录、答案记录等）。

详见：
- `docs/Database.sql`
- `docker-compose.yml` 中 `db-init` 初始化段

## 证据目录
测试与质检证据统一放在 `evidence/`（含 `evidence/run-slot*/`）目录。

---
如需进行质检修复闭环，请配合 `qa/qc-feedback-inbox.md`、`qa/qc-fix-send-template.md`、`qa/qc-fix-loop-template.md` 使用。

