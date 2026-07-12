// Qonhub AI 客户资料采集表 — Word 文档生成器 v2
const fs = require("fs");
const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  Header, Footer, AlignmentType, HeadingLevel, BorderStyle,
  WidthType, ShadingType, PageNumber, PageBreak, LevelFormat
} = require("docx");

const BLUE = "2E75B6";
const LIGHT_BLUE = "D5E8F0";
const WHITE = "FFFFFF";
const DARK = "1A1A1A";
const GRAY = "666666";
const BORDER_GRAY = "BBBBBB";
const PAGE_W = 11906;
const CONTENT_W = 9026;

const border = { style: BorderStyle.SINGLE, size: 1, color: BORDER_GRAY };
const borders = { top: border, bottom: border, left: border, right: border };
const noBorders = { top: { style: BorderStyle.NONE, size: 0 }, bottom: { style: BorderStyle.NONE, size: 0 }, left: { style: BorderStyle.NONE, size: 0 }, right: { style: BorderStyle.NONE, size: 0 } };
const cellMargins = { top: 80, bottom: 80, left: 120, right: 120 };
const headerShading = { fill: BLUE, type: ShadingType.CLEAR };
const altShading = { fill: "F5F9FC", type: ShadingType.CLEAR };

function title(text) {
  return new Paragraph({ spacing: { before: 480, after: 120 }, children: [new TextRun({ text, bold: true, size: 34, font: "Microsoft YaHei", color: BLUE })] });
}
function subtitle(text) {
  return new Paragraph({ spacing: { before: 0, after: 360 }, children: [new TextRun({ text, size: 20, font: "Microsoft YaHei", color: GRAY, italics: true })] });
}
function sectionTitle(num, text) {
  return new Paragraph({ spacing: { before: 480, after: 200 }, border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: BLUE, space: 4 } }, children: [new TextRun({ text: "第" + num + "部分：" + text, bold: true, size: 26, font: "Microsoft YaHei", color: BLUE })] });
}
function bodyText(text, opts = {}) {
  return new Paragraph({ spacing: { before: 80, after: 80 }, children: [new TextRun({ text, size: 21, font: "Microsoft YaHei", color: DARK, ...opts })] });
}
function hintText(text) {
  return new Paragraph({ spacing: { before: 40, after: 40 }, children: [new TextRun({ text, size: 18, font: "Microsoft YaHei", color: GRAY, italics: true })] });
}
function headerRow(labels, widths) {
  return new TableRow({ height: { value: 400, rule: "atLeast" }, children: labels.map((label, i) => new TableCell({ borders, width: { size: widths[i], type: WidthType.DXA }, margins: cellMargins, shading: headerShading, children: [new Paragraph({ children: [new TextRun({ text: label, bold: true, size: 20, font: "Microsoft YaHei", color: WHITE })] })] })) });
}
function dataRow(labels, widths, rowIndex = 0) {
  return new TableRow({ height: { value: 340, rule: "atLeast" }, children: labels.map((label, i) => new TableCell({ borders, width: { size: widths[i], type: WidthType.DXA }, margins: cellMargins, shading: i === 0 && rowIndex % 2 === 0 ? altShading : undefined, children: [new Paragraph({ children: [new TextRun({ text: label, size: 20, font: "Microsoft YaHei", color: DARK })] })] })) });
}
function fieldTable(fields, widths, hasNotes) {
  const rows = [];
  if (hasNotes) { rows.push(headerRow(["字段名称", "填写内容", "备注"], widths)); fields.forEach(function(f, i) { rows.push(dataRow([f[0], "", f[1] || ""], widths, i)); }); }
  else { rows.push(headerRow(["字段名称", "填写内容"], widths)); fields.forEach(function(f, i) { rows.push(dataRow([(typeof f === "string") ? f : f[0], ""], widths, i)); }); }
  return new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: widths, rows: rows });
}
function checkboxItem(label) {
  return new Paragraph({ spacing: { before: 40, after: 40 }, children: [new TextRun({ text: "  ☐  " + label, size: 21, font: "Microsoft YaHei", color: DARK })] });
}
function spacer(h) { return new Paragraph({ spacing: { before: h || 200, after: 0 }, children: [] }); }

// ── 文档内容 ─────────────────────────────────────────
const children = [];

// 标题页
children.push(new Paragraph({ spacing: { before: 2400 }, children: [] }));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 120 }, children: [new TextRun({ text: "Qonhub AI", bold: true, size: 56, font: "Microsoft YaHei", color: BLUE })] }));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 60 }, children: [new TextRun({ text: "客户资料采集表", bold: true, size: 44, font: "Microsoft YaHei", color: DARK })] }));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: BLUE, space: 8 } }, spacing: { after: 200 }, children: [new TextRun({ text: "GEO 生成式引擎优化 — 客户基础信息与素材清单", size: 22, font: "Microsoft YaHei", color: GRAY })] }));
children.push(spacer(400));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: "本表所有信息仅用于 GEO 内容优化，严格保密", size: 20, font: "Microsoft YaHei", color: GRAY })] }));
children.push(spacer(200));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: "填表日期：_________", size: 22, font: "Microsoft YaHei", color: DARK })] }));
children.push(new Paragraph({ children: [new PageBreak()] }));

// ── 第1部分：企业基本信息 ──
children.push(sectionTitle("一", "企业基本信息"));
children.push(hintText("→ 对应系统：工作空间 + 企业档案 NAP+W"));
children.push(spacer(120));
children.push(fieldTable([
  ["公司全称（营业执照）", "必填，用于 AI 引用一致性，确保各大模型引用的公司名统一"],
  ["品牌简称（对外品牌名）", "必填，用于工作空间命名，如'XX涂料'而非'广州市XX涂料有限公司'"],
  ["统一社会信用代码", "用于企业资质核验，可选填"],
  ["法定代表人", "可选填"],
  ["注册资本", "可选填，增强文章数据密度"],
  ["成立日期", "可选填，用于品牌历史内容生成"],
  ["所属行业", "如：工业涂料 / 机械设备 / 软件开发 / 化工 / 建材"],
  ["经营范围", "必填，AI 将基于此生成内容方向"],
  ["公司所在省份", "用于地域关键词生成"],
  ["公司所在城市", "用于地域关键词生成"],
  ["公司详细地址", "必填，NAP+W 四要素之一，AI 引用地址一致性"],
  ["公司联系电话", "必填，NAP+W 四要素之一，文章末尾自动植入"],
  ["公司邮箱", "可选填，文章末尾自动植入"],
  ["公司官网 URL", "必填，NAP+W 四要素之一"],
  ["企业资质/荣誉", "如：ISO9001 / 高新技术企业 / 专精特新 / CE 认证"],
], [3400, 3200, 2426], true));
children.push(spacer(400));

// ── 第2部分：产品、业务与 GEO 目标 ──
children.push(sectionTitle("二", "产品、业务与 GEO 目标"));
children.push(hintText("→ 对应系统：关键词蒸馏 + 标题生成 + AI 写作素材 + 运营策略"));
children.push(spacer(120));
children.push(fieldTable([
  "主营产品/服务（至少3个）",
  "产品核心卖点/优势",
  "目标客户群体",
  "应用场景/行业案例",
  "核心业务关键词（客户认为重要的搜索词，5-10个）",
  "地域关键词（业务覆盖城市，如'广州 涂料 厂家'）",
  "竞品名称（2-5个，用于差异化对比文章）",
], [4200, 4826]));
children.push(spacer(200));
children.push(bodyText("GEO 优化期望目标（可多选）：", { bold: true }));
children.push(checkboxItem("AI 搜索品牌曝光 — 用户在 ChatGPT/豆包/文心一言/Kimi 等搜索行业问题时，出现我的品牌"));
children.push(checkboxItem("行业关键词排名 — 用户在百度/头条搜索核心关键词时，我的内容排在前面"));
children.push(checkboxItem("B2B 平台企业信息覆盖 — 天眼查/1688/慧聪网等平台搜到我公司的完整信息"));
children.push(checkboxItem("全都要 — 以上三个目标全部覆盖"));
children.push(spacer(400));

// ── 第3部分：内容与风格偏好 ──
children.push(sectionTitle("三", "内容与风格偏好"));
children.push(hintText("→ 对应系统：AI 生成任务 + 弹药库模板"));
children.push(spacer(120));
children.push(bodyText("期望内容类型（可多选）：", { bold: true }));
children.push(checkboxItem("产品介绍 — 产品功能、规格参数、适用场景"));
children.push(checkboxItem("行业科普 — 行业知识、技术原理、标准解读"));
children.push(checkboxItem("FAQ 问答 — 常见客户问题与专业解答"));
children.push(checkboxItem("案例分享 — 客户合作案例、项目纪实"));
children.push(checkboxItem("技术白皮书 — 深度技术分析、解决方案"));
children.push(checkboxItem("新闻稿 — 公司动态、新品发布、行业活动"));
children.push(spacer(120));
children.push(bodyText("文章语言风格：", { bold: true }));
children.push(checkboxItem("专业严谨 — 技术术语准确，数据详实"));
children.push(checkboxItem("通俗易懂 — 面向终端用户，语言接地气"));
children.push(checkboxItem("营销型 — 突出卖点，引导询盘"));
children.push(checkboxItem("技术型 — 面向工程师/采购，重参数对比"));
children.push(spacer(120));
children.push(fieldTable([
  "期望每周产出文章数量（建议 3-7 篇）",
  "文章发布前是否需要客户审核（☐ 是  ☐ 否）",
  "品牌调性/禁用语",
  "是否需要 SEO 标题优化（☐ 是  ☐ 否）",
  "公司 Logo（☐ 已提供  ☐ 未提供）",
  "公司宣传册/PPT（☐ 已提供  ☐ 未提供）",
], [4200, 4826]));
children.push(spacer(400));

// ── 第4部分：素材资料清单 ──
children.push(sectionTitle("四", "素材资料清单"));
children.push(hintText("→ 对应系统：知识库上传 → KnowledgeKeyExtractor 结构化提取 → AI 写作 Prompt 注入"));
children.push(spacer(120));
const matWidths = [500, 2200, 1200, 5126];
const materials = [
  ["1", "公司介绍 / 品牌手册", "Word / PDF / PPT", "提取企业关键事实（成立时间、规模、资质），注入每篇 AI 文章作为必用素材"],
  ["2", "产品说明书 / 技术规格书", "Word / PDF / 图片", "提取产品参数、技术指标、数值数据，增强文章数据密度，提升 GEO 评分"],
  ["3", "行业资质 / 检测报告 / 认证证书", "PDF / 图片", "提取专家信号（认证编号、检测机构），增强文章权威背书，被 AI 引用概率 +41%"],
  ["4", "客户案例 / 合作记录 / 项目清单", "Word / Excel", "提取应用场景、真实数据（'服务 200+ 客户''年产值 X 亿'），生成案例型文章"],
  ["5", "新闻稿 / 公司公告 / 大事记", "Word / 网页链接", "了解品牌历史事件，生成新闻型内容，丰富品牌故事线"],
  ["6", "竞品资料 / 行业分析报告", "任意格式", "差异化分析素材，生成'XX vs 竞品'对比型文章，卡位搜索意图"],
  ["7", "常见客户问题（FAQ / 客服记录）", "Word / Excel / 文本", "提取 Q&A 对，直接生成 FAQ 文章，问答结构被 AI 引用概率翻倍"],
  ["8", "宣传图片 / 产品图 / 工厂实拍", "JPG / PNG / 高清图", "文章配图素材，图文并茂提升阅读体验和 AI 引用率"],
];
const matRows = [headerRow(["序号", "资料名称", "建议格式", "系统用途说明"], matWidths)];
materials.forEach(function(m, i) { matRows.push(dataRow(m, matWidths, i)); });
matRows.push(new TableRow({ children: [
  new TableCell({ borders, width: { size: 500, type: WidthType.DXA }, margins: cellMargins, children: [new Paragraph({ children: [] })] }),
  new TableCell({ borders, width: { size: 2200, type: WidthType.DXA }, margins: cellMargins, shading: { fill: LIGHT_BLUE, type: ShadingType.CLEAR }, children: [new Paragraph({ children: [new TextRun({ text: "填写示例", size: 18, font: "Microsoft YaHei", color: BLUE, italics: true })] })] }),
  new TableCell({ borders, width: { size: 1200, type: WidthType.DXA }, margins: cellMargins, shading: { fill: LIGHT_BLUE, type: ShadingType.CLEAR }, children: [new Paragraph({ children: [new TextRun({ text: "PDF", size: 18, font: "Microsoft YaHei", color: BLUE, italics: true })] })] }),
  new TableCell({ borders, width: { size: 5126, type: WidthType.DXA }, margins: cellMargins, shading: { fill: LIGHT_BLUE, type: ShadingType.CLEAR }, children: [new Paragraph({ children: [new TextRun({ text: "☑ 已提供（文件名：XX公司简介2025.pdf）", size: 18, font: "Microsoft YaHei", color: BLUE, italics: true })] })] }),
] }));
children.push(new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: matWidths, rows: matRows }));
children.push(spacer(400));

// ── 第5部分：发布渠道 ──
children.push(sectionTitle("五", "发布渠道与账号"));
children.push(hintText("→ 对应系统：自媒体授权 + 分发渠道 + B2B/媒体锚点"));
children.push(spacer(120));

children.push(bodyText("【自媒体平台】（Cookie 授权，手动登录发布）", { bold: true }));
children.push(checkboxItem("头条号 — 推荐流量大，适合大众化内容"));
children.push(checkboxItem("百家号 — 百度搜索强关联，SEO 价值高"));
children.push(checkboxItem("小红书 — 年轻用户群，适合消费品/生活方式"));
children.push(checkboxItem("搜狐号 — 高权重新闻源，适合企业品牌曝光"));
children.push(checkboxItem("阿里 1688 — B2B 电商平台，适合工业品/批发"));
children.push(checkboxItem("百度爱采购 — 百度 B2B 垂直搜索，工业品必备"));
children.push(spacer(80));
children.push(bodyText("以上平台，客户是否已有账号？", { bold: true }));
children.push(checkboxItem("已有账号，可直接授权 Cookie（请提供账号名/ID）"));
children.push(checkboxItem("没有账号，需要我方协助注册"));
children.push(checkboxItem("部分有（请备注：________________）"));

children.push(spacer(120));
children.push(bodyText("【B2B 平台锚点】（30 个可选，运营手动认证）", { bold: true }));
children.push(checkboxItem("顶级：天眼查 / 企查查 / 百度爱采购 / 阿里 1688"));
children.push(checkboxItem("高权重：慧聪网 / 中国制造网 / 中国供应商 / 八方资源网"));
children.push(checkboxItem("中权重：黄页88 / 顺企网 / 世界工厂网 / 马可波罗网"));
children.push(checkboxItem("广覆盖：企业谷 / 万家商务网 / 九州资源网 / 其他：___"));

children.push(spacer(120));
children.push(bodyText("【官媒发稿锚点】（12 个可选，运营手动发稿）", { bold: true }));
children.push(checkboxItem("山西科技报 / 河青新闻网 / 科技新闻网 / 淄博新闻网 / 盐城网 / 咸宁网 / 其他：___"));
children.push(spacer(120));
children.push(bodyText("【行业媒体锚点】（12 个可选）", { bold: true }));
children.push(checkboxItem("博客园（顶级） / 商业新知 / 涂料在线 / 沥青在线 / 华网 / 其他：___"));
children.push(spacer(120));
children.push(fieldTable([
  "是否已有 WordPress 站点（☐ 是，网址：___  ☐ 否）",
  "是否需要我们搭建目标分发站点",
  "目标站点域名（如有）",
  "其他分发渠道需求",
], [5000, 4026]));
children.push(spacer(400));

// ── 第6部分：联系方式与交付 ──
children.push(sectionTitle("六", "联系方式与交付信息"));
children.push(spacer(120));
children.push(fieldTable([
  "客户联系人",
  "联系电话",
  "微信号",
  "期望上线时间",
  "运营对接人（我方）",
  "备注/特殊需求",
], [3800, 5226]));
children.push(spacer(600));

// ── 签章 ──
children.push(new Paragraph({ border: { top: { style: BorderStyle.SINGLE, size: 2, color: BLUE, space: 8 } }, spacing: { before: 400 }, children: [] }));
children.push(spacer(200));
children.push(bodyText("本人确认以上所填信息真实、完整，授权 Qonhub AI 团队基于此信息进行 GEO 内容优化与发布。", { bold: true }));
children.push(spacer(300));
children.push(new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: [4513, 4513], rows: [new TableRow({ children: [
  new TableCell({ borders: noBorders, width: { size: 4513, type: WidthType.DXA }, margins: cellMargins, children: [new Paragraph({ children: [new TextRun({ text: "客户确认签字：_______________", size: 22, font: "Microsoft YaHei", color: DARK })] })] }),
  new TableCell({ borders: noBorders, width: { size: 4513, type: WidthType.DXA }, margins: cellMargins, children: [new Paragraph({ children: [new TextRun({ text: "日期：_______________", size: 22, font: "Microsoft YaHei", color: DARK })] })] }),
] })] }));

// ── 总装输出 ─────────────────────────────────────────
const doc = new Document({
  styles: {
    default: { document: { run: { font: "Microsoft YaHei", size: 21 } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true, run: { size: 32, bold: true, font: "Microsoft YaHei", color: BLUE }, paragraph: { spacing: { before: 240, after: 240 }, outlineLevel: 0 } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true, run: { size: 28, bold: true, font: "Microsoft YaHei", color: BLUE }, paragraph: { spacing: { before: 180, after: 180 }, outlineLevel: 1 } },
    ],
  },
  sections: [{
    properties: { page: { size: { width: 11906, height: 16838 }, margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } } },
    headers: { default: new Header({ children: [new Paragraph({ border: { bottom: { style: BorderStyle.SINGLE, size: 2, color: BLUE, space: 4 } }, children: [
      new TextRun({ text: "Qonhub AI", bold: true, size: 18, font: "Microsoft YaHei", color: BLUE }),
      new TextRun({ text: " — 客户资料采集表（机密）", size: 17, font: "Microsoft YaHei", color: GRAY }),
    ] })] }) },
    footers: { default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [
      new TextRun({ text: "第 ", size: 17, font: "Microsoft YaHei", color: GRAY }),
      new TextRun({ children: [PageNumber.CURRENT], size: 17, font: "Microsoft YaHei", color: GRAY }),
      new TextRun({ text: " 页", size: 17, font: "Microsoft YaHei", color: GRAY }),
    ] })] }) },
    children: children,
  }],
});

Packer.toBuffer(doc).then(function(buffer) {
  var outPath = "E:\\Qonhubgeo\\GEOFlow-main\\docs\\Qonhub客户资料采集表_v2.docx";
  fs.writeFileSync(outPath, buffer);
  console.log("✅ 文档已生成: " + outPath);
  console.log("   大小: " + (buffer.length / 1024).toFixed(1) + " KB");
});
