---
title: '独立站 GEO 自查清单'
slug: geo-checklist-zh
date: '2026-09-23'
status: published
summary: '一份面向独立站作者的 GEO（生成式引擎优化）自查清单：从摘要、命名实体、FAQ、结构化数据到信息来源，逐项检查文章是否便于 AI 搜索理解与引用。'
entities: |-
  生成式引擎优化
  Schema.org
  FAQPage
  llms.txt
topics:
  - GEO
  - 独立站
sources:
  - 'https://schema.org/FAQPage'
internal_links:
  - /articles/what-is-geo/
faq:
  - { question: '独立站需要单独做 GEO 吗？', answer: '需要。AI 搜索只会在回答里引用少数来源，清晰的摘要、实体和来源能显著降低模型理解你内容的成本。' }
  - { question: 'GEO 会改写我的文章吗？', answer: '在 HolyMD 中不会。AI 建议只作用于元数据，每一条都需要作者确认后才生效。' }
structured_data:
  '@context': 'https://schema.org'
  '@type': BlogPosting
  headline: '独立站 GEO 自查清单'
  inLanguage: zh-CN
  datePublished: '2026-09-23'
---
发布前，花两分钟按下面的清单检查一遍。概念介绍见 [What Is GEO?](/articles/what-is-geo/)。

## 1. 摘要

- 开头是否直接回答了这篇文章要解决的问题？
- 摘要是否能脱离正文单独成立？

## 2. 命名实体

- 文章涉及的人物、产品、概念是否以完整名称出现？
- 缩写第一次出现时是否给出全称？

## 3. FAQ

- 是否整理了读者最可能向 AI 助手提出的两三个问题？

## 4. 结构化数据

- 是否提供了 Schema.org `BlogPosting`，并包含作者与发布者？

## 5. 来源与内链

- 关键论断是否附有可验证的外部来源？
- 是否链接到站内相关文章，帮助模型理解主题之间的关系？

HolyMD 的 GEO 评分会自动检查以上各项，并在后台看板中汇总全站情况。
