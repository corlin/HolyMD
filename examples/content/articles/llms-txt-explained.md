---
title: 'llms.txt Explained'
slug: llms-txt-explained
date: '2026-09-24'
status: published
summary: 'A short introduction to llms.txt, a proposed Markdown file that gives language models a curated map of a website.'
topics:
  - GEO
---
`llms.txt` is a proposed convention: a Markdown file at the root of a site that tells language models what the site is about and where its most useful pages live. See [llmstxt.org](https://llmstxt.org/) for the proposal.

Where `robots.txt` says what crawlers *may* fetch, `llms.txt` suggests what is *worth* reading.

## What HolyMD generates

Every publish writes two files:

- `/llms.txt` — the site name, description, and a linked list of published articles.
- `/llms-full.txt` — the full text of every public article in one file.

Open them on this demo site to see the output.

## Try the GEO score

This article is intentionally sparse: it has a summary and a topic, but no FAQ, entities, or structured data. Compare its GEO score with [What Is GEO?](/articles/what-is-geo/) in the admin dashboard, then open it in the writing studio to see what is missing.
