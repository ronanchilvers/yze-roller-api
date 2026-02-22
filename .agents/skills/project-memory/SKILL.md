---
name: project-memory
description: >
  Use this skill when investigating or modifying an unfamiliar codebase and you need persistent, repo-local memory.
  Trigger on tasks involving: "understand the codebase", "investigate", "trace", "where is X defined", "how does X work",
  "why is this failing", "architecture overview", "refactor with context", or any request to "remember" findings.
  Do NOT use for trivial edits that don't require cross-file reasoning.
---

# Project Memory Skill

## Purpose
Maintain lightweight, repo-local, durable memory of the codebase by reading and updating a small set of context files.
This emulates "persistent memory" without external plugins.

## Memory files (repo-local)
Use these files; create them if missing:

- `AGENTS.md` (if present): project working agreements and norms.
- `docs/MEMORY.md`: stable facts about the system (high signal, low churn).
- `docs/DECISIONS.md`: decision log (what/why/alternatives).
- `docs/TODO_INVESTIGATION.md`: open questions, leads, next steps.

## When starting a task (always do this first)
1. Read `AGENTS.md` if it exists.
2. Read `docs/MEMORY.md` and `docs/DECISIONS.md` if they exist.
3. If `docs/TODO_INVESTIGATION.md` exists, skim it for active leads relevant to the user’s request.
4. Summarize *only what is relevant to the current task* in <= 8 bullets, including:
   - key modules/files involved (paths),
   - important invariants/assumptions,
   - anything risky (tests, migrations, security, backwards compatibility).

## During investigation / implementation (use the memory loop)
Whenever you learn something durable and reusable:
- Record it in `docs/MEMORY.md` as a bullet with:
  - **What** (fact),
  - **Where** (file path + symbol/function/class),
  - **Evidence** (1 short reason: call chain, test name, config reference, etc.).

Whenever you discover a "why" (intent, tradeoff, constraint):
- Record it in `docs/DECISIONS.md` with a short ADR-lite entry:
  - Date, Title
  - Context
  - Decision
  - Consequences
  - Alternatives considered (optional)

Whenever you identify an unresolved question or lead:
- Add it to `docs/TODO_INVESTIGATION.md` with:
  - Question
  - Best lead(s): file paths, commands, logs to check
  - Status: open / partially answered

## At the end of a task (close-out)
1. Update memory files with any durable findings (prefer a few high-value bullets over many low-value ones).
2. If changes were made, ensure memory references match the final code (paths, names).
3. Keep memory concise: delete/merge redundant bullets.

## Formatting rules (important)
- Prefer bullet lists.
- Include file paths and symbol names whenever possible.
- Avoid speculative statements; if uncertain, label as "Hypothesis" and include how to verify.

## Safety / scope guardrails
- Do not store secrets (keys, tokens, passwords) in memory files.
- Do not copy large code blocks into memory; prefer references (paths/symbols) and short summaries.
- If the user explicitly asks not to modify files, do not write memory—only summarize in-chat.
